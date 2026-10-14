<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/account_guard.php";
require_once __DIR__ . "/activity_log.php";
require_once __DIR__ . "/user_notifications.php";
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
if (empty($_SESSION["claim_messages_csrf_token"])) {
    $_SESSION["claim_messages_csrf_token"] = bin2hex(random_bytes(32));
}

$claimQuery = $conn->prepare(
    "SELECT claims.claim_id, claims.claim_status, claims.user_id AS claimant_id,
            items.user_id AS reporter_id, items.item_name
     FROM claims
     INNER JOIN items ON items.item_id = claims.item_id
     WHERE claims.claim_id = ?"
);
$claimQuery->bind_param("i", $claimId);
$claimQuery->execute();
$claim = $claimQuery->get_result()->fetch_assoc();
$claimQuery->close();
if (!$claim || $claim["claim_status"] !== "approved"
    || !in_array($userId, [(int) $claim["claimant_id"], (int) $claim["reporter_id"]], true)) {
    http_response_code(403);
    exit("Private messages are available only to the finder and approved claimant.");
}

$message = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";
    $body = trim((string) ($_POST["message_body"] ?? ""));
    if (!is_string($token) || !hash_equals($_SESSION["claim_messages_csrf_token"], $token)) {
        $message = "Your session expired. Reload and try again.";
    } elseif ($body === "" || strlen($body) > 2000) {
        $message = "Enter a message of up to 2,000 characters.";
    } else {
        $insert = $conn->prepare(
            "INSERT INTO claim_messages (claim_id, sender_user_id, message_body)
             VALUES (?, ?, ?)"
        );
        $insert->bind_param("iis", $claimId, $userId, $body);
        if ($insert->execute()) {
            $message = "Your message was sent.";
            $recipientId = $userId === (int) $claim["claimant_id"]
                ? (int) $claim["reporter_id"]
                : (int) $claim["claimant_id"];
            $notification = "You have a new private handover message about " . $claim["item_name"] . ".";
            if (!addUserNotification($conn, $recipientId, "claims", $notification)) {
                $message .= " Your message was saved, but the recipient could not be notified.";
            }
            recordActivity($conn, $userId, "claim_message_sent", "Claim ID " . $claimId);
        } else {
            error_log("Unable to save private claim message: " . $insert->error);
            $message = "Unable to send your message right now.";
        }
        $insert->close();
    }
}

$messagesQuery = $conn->prepare(
    "SELECT claim_messages.message_body, claim_messages.created_at,
            claim_messages.sender_user_id, users.full_name
     FROM claim_messages
     INNER JOIN users ON users.user_id = claim_messages.sender_user_id
     WHERE claim_messages.claim_id = ?
     ORDER BY claim_messages.created_at ASC, claim_messages.message_id ASC"
);
$messagesQuery->bind_param("i", $claimId);
$messagesQuery->execute();
$messages = $messagesQuery->get_result();
function claimMessagesEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Private Handover Messages</title>
    <link rel="stylesheet" href="css/style.css?v=theme-20261014-10">
</head>
<body>
<?php require __DIR__ . "/site_header.php"; ?>
<main class="container account-page">
    <h1 class="page-title">Private handover messages</h1>
    <p>Conversation about <?= claimMessagesEscape($claim["item_name"]) ?>. Only you and the approved claimant or finder can read these messages.</p>
    <p class="helper-text">Arrange handovers safely. Meet in a public place where appropriate and do not share unnecessary sensitive information.</p>
    <?php if ($message !== ""): ?><p class="feedback-banner" role="status"><?= claimMessagesEscape($message) ?></p><?php endif; ?>
    <section class="account-list" aria-label="Conversation">
        <?php if ($messages->num_rows === 0): ?>
            <p class="empty-message">No messages yet. You can start the handover conversation below.</p>
        <?php else: ?>
            <?php while ($row = $messages->fetch_assoc()): ?>
                <article class="account-card">
                    <h2><?= claimMessagesEscape($row["full_name"]) ?><?= (int) $row["sender_user_id"] === $userId ? " (you)" : "" ?></h2>
                    <p><?= nl2br(claimMessagesEscape($row["message_body"])) ?></p>
                    <time datetime="<?= claimMessagesEscape(date(DATE_ATOM, strtotime($row["created_at"]))) ?>"><?= claimMessagesEscape($row["created_at"]) ?></time>
                </article>
            <?php endwhile; ?>
        <?php endif; ?>
    </section>
    <form method="post" class="stacked-form">
        <input type="hidden" name="csrf_token" value="<?= claimMessagesEscape($_SESSION["claim_messages_csrf_token"]) ?>">
        <input type="hidden" name="claim_id" value="<?= (int) $claimId ?>">
        <label for="message-body">Message</label>
        <textarea id="message-body" name="message_body" rows="4" maxlength="2000" required></textarea>
        <button type="submit">Send message</button>
    </form>
    <p><a class="button button-small button-secondary" href="my_claims.php">Back to My Claims</a></p>
</main>
<?php require __DIR__ . "/site_footer.php"; ?>
</body>
</html>
<?php
$messagesQuery->close();
$conn->close();
?>
