<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/account_guard.php";
require_once __DIR__ . "/mail_notifications.php";
require_once __DIR__ . "/user_notifications.php";
require_once __DIR__ . "/activity_log.php";

$userId = (int) ($_SESSION["user_id"] ?? 0);
enforceActiveAccount($conn);
$itemId = filter_input(INPUT_POST, "item_id", FILTER_VALIDATE_INT);
if (!$itemId) {
    $itemId = filter_input(INPUT_GET, "item_id", FILTER_VALIDATE_INT);
}
$message = "";
$success = false;
$canClaim = false;
$itemName = "";
$ownerEmail = "";
$ownerHint = "";

if ($userId < 1) {
    if (!$itemId || $itemId < 1) {
        header("Location: login.php");
    } else {
        header("Location: login.php?next=" . rawurlencode("claim_item.php?item_id=" . $itemId));
    }
    exit;
}
if (empty($_SESSION["claim_csrf_token"])) {
    $_SESSION["claim_csrf_token"] = bin2hex(random_bytes(32));
}

if (!$itemId || $itemId < 1) {
    $message = "The selected item is invalid.";
} else {
    $itemCheck = $conn->prepare(
        "SELECT items.user_id, items.status, items.resolved_at, items.is_hidden,
                items.item_name, users.email, items.owner_hint
         FROM items INNER JOIN users ON users.user_id = items.user_id
         WHERE items.item_id = ?"
    );
    $itemCheck->bind_param("i", $itemId);
    $itemCheck->execute();
    $itemCheck->bind_result($ownerId, $itemStatus, $resolvedAt, $isHidden, $itemName, $ownerEmail, $ownerHint);
    if ($itemCheck->fetch()) {
        if ((int) $isHidden === 1) {
            $message = "This item is unavailable for claims.";
        } elseif ($itemStatus !== "found" || $resolvedAt !== null) {
            $message = "Only active found items can be claimed.";
        } elseif ((int) $ownerId === $userId) {
            $message = "You cannot claim an item you reported.";
        } elseif (trim((string) $ownerHint) === "") {
            $message = "This item cannot be claimed until the reporter adds a private ownership clue.";
        } else {
            $canClaim = true;
        }
    } else {
        $message = "This item is no longer available.";
    }
    $itemCheck->close();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";
    $ownerAnswer = trim($_POST["owner_answer"] ?? "");
    if (!is_string($token) || !hash_equals($_SESSION["claim_csrf_token"], $token)) {
        $message = "Your session expired. Please reload the page and try again.";
    } elseif (!$canClaim) {
        // Keep the eligibility message from the item check.
    } elseif ($ownerAnswer === "" || strlen($ownerAnswer) > 2000) {
        $message = "Please provide the private ownership detail (up to 2,000 characters).";
    } else {
        $conn->begin_transaction();
        try {
            $availability = $conn->prepare(
                "SELECT item_id FROM items
                 WHERE item_id = ? AND user_id <> ? AND status = 'found'
                   AND resolved_at IS NULL AND is_hidden = 0
                   AND owner_hint IS NOT NULL AND TRIM(owner_hint) <> ''
                 FOR UPDATE"
            );
            $availability->bind_param("ii", $itemId, $userId);
            $availability->execute();
            $availability->store_result();
            $stillAvailable = $availability->num_rows === 1;
            $availability->close();

            if (!$stillAvailable) {
                $conn->rollback();
                $canClaim = false;
                $message = "This item is no longer available for claims.";
            } else {
            $check = $conn->prepare(
                "SELECT claim_id FROM claims WHERE item_id = ? AND user_id = ? LIMIT 1 FOR UPDATE"
            );
            $check->bind_param("ii", $itemId, $userId);
            $check->execute();
            $check->store_result();
            $alreadyClaimed = $check->num_rows > 0;
            $check->close();

            if ($alreadyClaimed) {
                $conn->rollback();
                $message = "You have already submitted a claim for this item.";
            } else {
                $insert = $conn->prepare(
                    "INSERT INTO claims (item_id, user_id, claim_status, owner_answer)
                     VALUES (?, ?, 'pending', ?)"
                );
                $insert->bind_param("iis", $itemId, $userId, $ownerAnswer);
                $insert->execute();
                $claimId = (int) $conn->insert_id;
                $insert->close();
                $conn->commit();
                $success = true;
                $message = "Your claim was submitted and is pending administrator review.";

                addUserNotification($conn, (int) $ownerId, "claims", "A possible owner submitted a claim for " . $itemName . ".");
                if (!notifyAdministratorsAboutClaim($conn, $claimId, $itemName, "A new claim needs review")) {
                    $message .= " The claim was saved, but the administrator notification could not be created.";
                }
                if (userAllowsEmailNotification($conn, (int) $ownerId, "claims")
                    && !sendCustomerNotification(
                        $ownerEmail,
                        "Possible match for your reported item",
                        "A possible owner has submitted a claim for your item: {$itemName}. Sign in to review the claim and private ownership answer."
                    )) {
                    $message .= " The claim was saved, but the reporter notification could not be sent.";
                }
                addUserNotification($conn, $userId, "claims", "Your claim for " . $itemName . " was submitted for administrator review.");
                recordActivity($conn, $userId, "claim_submitted", "Item ID " . $itemId);
            }
            }
        } catch (Throwable $error) {
            $conn->rollback();
            error_log("Claim submission failed: " . $error->getMessage());
            $message = "Unable to submit your claim. Please try again later.";
        }
    }
}

function claimEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Claim Found Item</title>
    <link rel="stylesheet" href="css/style.css?v=20261014">
    <script src="navigation.js" defer></script>
</head>
<body>
<?php require __DIR__ . "/site_header.php"; ?>
<main class="auth-container">
    <h1>Claim Found Item</h1>
    <?php if ($message !== ""): ?>
        <p class="feedback-banner <?= $success ? "success" : "error" ?>" role="<?= $success ? "status" : "alert" ?>"><?= claimEscape($message) ?></p>
    <?php endif; ?>
    <?php if ($canClaim && !$success): ?>
        <p>Submit a claim for <?= claimEscape($itemName) ?>. An administrator will review it.</p>
        <form method="post" action="claim_item.php" class="stacked-form" onsubmit="return confirm('Submit a claim for this found item?');">
            <input type="hidden" name="item_id" value="<?= (int) $itemId ?>">
            <input type="hidden" name="csrf_token" value="<?= claimEscape($_SESSION["claim_csrf_token"]) ?>">
            <label for="owner_answer">Private ownership detail *</label>
            <p class="helper-text">Describe a detail that helps verify this item is yours. Your answer is visible only to the reporter and administrators.</p>
            <textarea id="owner_answer" name="owner_answer" rows="4" maxlength="2000" required><?= claimEscape($_POST["owner_answer"] ?? "") ?></textarea>
            <button type="submit">Claim This Item</button>
        </form>
    <?php endif; ?>
    <p class="auth-navigation">
        <a href="found_items.php" class="button button-small button-secondary">View Found Items</a>
    </p>
</main>
<?php require __DIR__ . "/site_footer.php"; ?>
</body>
</html>
