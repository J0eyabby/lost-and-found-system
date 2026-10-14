<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/account_guard.php";
require_once __DIR__ . "/activity_log.php";
enforceActiveAccount($conn);

$userId = (int) ($_SESSION["user_id"] ?? 0);
if ($userId < 1) {
    header("Location: login.php");
    exit;
}

$claimId = filter_input(INPUT_GET, "claim_id", FILTER_VALIDATE_INT);
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $claimId = filter_input(INPUT_POST, "claim_id", FILTER_VALIDATE_INT);
}
if (!$claimId || $claimId < 1) {
    http_response_code(400);
    exit("Select a valid claim.");
}
if (empty($_SESSION["claim_feedback_csrf_token"])) {
    $_SESSION["claim_feedback_csrf_token"] = bin2hex(random_bytes(32));
}

$claimQuery = $conn->prepare(
    "SELECT claims.claim_id, claims.user_id AS claimant_id,
            claims.claim_status, claims.claimant_return_confirmed_at,
            items.user_id AS reporter_id, items.item_name,
            items.reporter_return_confirmed_at
     FROM claims
     INNER JOIN items ON items.item_id = claims.item_id
     WHERE claims.claim_id = ?"
);
$claimQuery->bind_param("i", $claimId);
$claimQuery->execute();
$claim = $claimQuery->get_result()->fetch_assoc();
$claimQuery->close();
$participants = $claim && in_array($userId, [(int) $claim["claimant_id"], (int) $claim["reporter_id"]], true);
$returnConfirmed = $claim
    && $claim["claim_status"] === "approved"
    && $claim["claimant_return_confirmed_at"] !== null
    && $claim["reporter_return_confirmed_at"] !== null;
if (!$participants || !$returnConfirmed) {
    http_response_code(403);
    exit("Feedback is available only to the finder or approved claimant after both confirm the return.");
}

$message = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";
    $rating = filter_input(INPUT_POST, "rating", FILTER_VALIDATE_INT);
    $feedback = trim((string) ($_POST["feedback"] ?? ""));
    if (!is_string($token) || !hash_equals($_SESSION["claim_feedback_csrf_token"], $token)) {
        $message = "Your session expired. Reload and try again.";
    } elseif (!$rating || $rating < 1 || $rating > 5 || strlen($feedback) > 1000) {
        $message = "Choose a rating from 1 to 5 and keep feedback within 1,000 characters.";
    } else {
        $save = $conn->prepare(
            "INSERT INTO claim_experience_feedback (claim_id, user_id, rating, message)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE rating = VALUES(rating), message = VALUES(message)"
        );
        $save->bind_param("iiis", $claimId, $userId, $rating, $feedback);
        if ($save->execute()) {
            $message = "Thank you. Your feedback was saved privately.";
            recordActivity($conn, $userId, "return_feedback_submitted", "Claim ID " . $claimId . "; rating " . $rating);
        } else {
            error_log("Unable to save return feedback: " . $save->error);
            $message = "Unable to save feedback right now.";
        }
        $save->close();
    }
}

$existingQuery = $conn->prepare(
    "SELECT rating, message FROM claim_experience_feedback WHERE claim_id = ? AND user_id = ?"
);
$existingQuery->bind_param("ii", $claimId, $userId);
$existingQuery->execute();
$existingFeedback = $existingQuery->get_result()->fetch_assoc() ?: ["rating" => "", "message" => ""];
$existingQuery->close();
function claimFeedbackEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Return Feedback</title>
    <link rel="stylesheet" href="css/style.css?v=theme-20261014-10">
</head>
<body>
<?php require __DIR__ . "/site_header.php"; ?>
<main class="container account-page">
    <h1 class="page-title">Return feedback</h1>
    <p>Rate your handover experience for <?= claimFeedbackEscape($claim["item_name"]) ?>. Only administrators can view your feedback; it will not be published.</p>
    <?php if ($message !== ""): ?><p class="feedback-banner" role="status"><?= claimFeedbackEscape($message) ?></p><?php endif; ?>
    <form method="post" class="stacked-form">
        <input type="hidden" name="csrf_token" value="<?= claimFeedbackEscape($_SESSION["claim_feedback_csrf_token"]) ?>">
        <input type="hidden" name="claim_id" value="<?= (int) $claimId ?>">
        <label for="rating">How was the return? (1 = poor, 5 = excellent)</label>
        <select id="rating" name="rating" required>
            <option value="">Select a rating</option>
            <?php for ($score = 1; $score <= 5; $score++): ?>
                <option value="<?= $score ?>" <?= (int) $existingFeedback["rating"] === $score ? "selected" : "" ?>><?= $score ?> / 5</option>
            <?php endfor; ?>
        </select>
        <label for="feedback">Optional comments</label>
        <textarea id="feedback" name="feedback" rows="4" maxlength="1000"><?= claimFeedbackEscape($existingFeedback["message"]) ?></textarea>
        <button type="submit">Save private feedback</button>
    </form>
    <p><a class="button button-small button-secondary" href="my_claims.php">Back to My Claims</a></p>
</main>
<?php require __DIR__ . "/site_footer.php"; ?>
</body>
</html>
<?php $conn->close(); ?>
