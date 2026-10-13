<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/account_guard.php";
require_once __DIR__ . "/user_notifications.php";
require_once __DIR__ . "/activity_log.php";
enforceActiveAccount($conn);

$userId = (int) ($_SESSION["user_id"] ?? 0);
if ($userId < 1) {
    header("Location: login.php");
    exit;
}
if (empty($_SESSION["lifecycle_csrf_token"])) {
    $_SESSION["lifecycle_csrf_token"] = bin2hex(random_bytes(32));
}
$message = "";
$hasClaimantConsentColumn = dbHasColumn($conn, "claims", "claimant_return_consent");

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";
    $claimId = filter_input(INPUT_POST, "claim_id", FILTER_VALIDATE_INT);
    $action = $_POST["action"] ?? "";
    if (!is_string($token) || !hash_equals($_SESSION["lifecycle_csrf_token"], $token)) {
        $message = "Your session expired. Please refresh and try again.";
    } elseif (!$claimId || $claimId < 1) {
        $message = "The selected claim is invalid.";
    } elseif ($action === "respond") {
        $answer = trim((string) ($_POST["owner_answer"] ?? ""));
        if ($answer === "" || strlen($answer) > 2000) {
            $message = "Enter the requested information using no more than 2,000 characters.";
        } else {
            $respond = $conn->prepare(
                "UPDATE claims
                 INNER JOIN items ON items.item_id = claims.item_id
                 SET claims.owner_answer = ?, claims.claim_status = 'pending',
                     claims.decision_reason = NULL, claims.reviewed_at = NOW()
                 WHERE claims.claim_id = ? AND claims.user_id = ?
                   AND claims.claim_status = 'needs_info'
                   AND items.resolved_at IS NULL"
            );
            $respond->bind_param("sii", $answer, $claimId, $userId);
            if ($respond->execute() && $respond->affected_rows === 1) {
                $message = "Your response was sent to the administrator for review.";
                recordActivity($conn, $userId, "claim_information_submitted", "Claim ID " . $claimId);
                $reporterQuery = $conn->prepare(
                    "SELECT items.user_id, items.item_name FROM claims
                     INNER JOIN items ON items.item_id = claims.item_id
                     WHERE claims.claim_id = ?"
                );
                $reporterQuery->bind_param("i", $claimId);
                $reporterQuery->execute();
                $reporter = $reporterQuery->get_result()->fetch_assoc();
                $reporterQuery->close();
                if ($reporter) {
                    addUserNotification($conn, (int) $reporter["user_id"], "claims", "The claimant provided additional information for " . $reporter["item_name"] . ".");
                    if (!notifyAdministratorsAboutClaim($conn, $claimId, (string) $reporter["item_name"], "A claimant responded to a request for more information")) {
                        $message = "Your response was saved, but the administrator notification could not be created. It remains available in the review queue.";
                    }
                }
            } else {
                $message = "Unable to submit your response. The claim may no longer be awaiting information.";
            }
            $respond->close();
        }
    } elseif ($action === "confirm_return") {
        $confirm = $conn->prepare(
            "UPDATE claims
             SET claimant_return_confirmed_at = NOW()
             WHERE claim_id = ? AND user_id = ? AND claim_status = 'approved'
               AND claimant_return_confirmed_at IS NULL"
        );
        $confirm->bind_param("ii", $claimId, $userId);
        if ($confirm->execute() && $confirm->affected_rows === 1) {
            $message = "Your return confirmation has been recorded.";
            recordActivity($conn, $userId, "item_return_confirmed", "Claim ID " . $claimId);
            $reporterQuery = $conn->prepare(
                "SELECT items.user_id, items.item_name FROM claims
                 INNER JOIN items ON items.item_id = claims.item_id
                 WHERE claims.claim_id = ?"
            );
            $reporterQuery->bind_param("i", $claimId);
            $reporterQuery->execute();
            $reporter = $reporterQuery->get_result()->fetch_assoc();
            $reporterQuery->close();
            if ($reporter) {
                addUserNotification($conn, (int) $reporter["user_id"], "claims", "The claimant confirmed receiving " . $reporter["item_name"] . ".");
            }
        } else {
            $message = "The return could not be confirmed. Check that this claim is approved and not already confirmed.";
        }
        $confirm->close();
    } elseif ($action === "consent" && $hasClaimantConsentColumn) {
        $consent = (int) ($_POST["consent"] ?? 0) === 1 ? 1 : 0;
        $stmt = $conn->prepare(
            "UPDATE items
             INNER JOIN claims ON claims.item_id = items.item_id
             SET claims.claimant_return_consent = ?
             WHERE claims.claim_id = ? AND claims.user_id = ?
               AND claims.claim_status = 'approved'
               AND items.resolved_at IS NOT NULL
               AND items.resolution_type = 'owner_claim'"
        );
        $stmt->bind_param("iii", $consent, $claimId, $userId);
        if ($stmt->execute() && $stmt->affected_rows === 1) {
            $message = "Your showcase preference has been saved.";
        } else {
            $message = "Unable to save your showcase preference.";
        }
        $stmt->close();
    } elseif ($action === "consent") {
        $message = "Showcase consent is not available because the database schema has not been updated yet.";
    } else {
        $message = "Invalid request.";
    }
}
$selectColumns = [
    "claims.claim_id",
    "claims.claim_date",
    "claims.claim_status",
    "claims.decision_reason",
    "claims.item_id",
    "items.user_id AS reporter_id",
    "items.item_name",
    "items.category",
    "items.resolved_at",
    "items.resolution_type",
    "items.private_handover_contact",
    "items.private_handover_latitude",
    "items.private_handover_longitude",
    "claims.claimant_return_confirmed_at",
    "items.reporter_return_confirmed_at"
];
if ($hasClaimantConsentColumn) {
    $selectColumns[] = "claims.claimant_return_consent";
}

$stmt = $conn->prepare(
    "SELECT " . implode(", ", $selectColumns) . "
     FROM claims
     INNER JOIN items ON items.item_id = claims.item_id
     WHERE claims.user_id = ?
     ORDER BY claims.claim_date DESC"
);
$stmt->bind_param("i", $userId);
$stmt->execute();
$claims = $stmt->get_result();
function myClaimsEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Claims - Lost and Found</title>
    <link rel="stylesheet" href="css/style.css?v=20261013">
    <script src="navigation.js" defer></script>
</head>
<body>
<?php require __DIR__ . "/site_header.php"; ?>
<main class="container account-page">
    <h1 class="page-title">My Claims</h1>
    <?php if ($message !== ""): ?><p class="feedback-banner" role="status"><?= myClaimsEscape($message) ?></p><?php endif; ?>
    <?php if ($claims->num_rows === 0): ?>
        <p class="empty-message">You have not submitted any claims yet.</p>
    <?php else: ?>
        <div class="account-list">
        <?php while ($claim = $claims->fetch_assoc()): ?>
            <article class="account-card" id="claim-<?= (int) $claim["claim_id"] ?>">
                <h2><?= myClaimsEscape($claim["item_name"]) ?></h2>
                <p><strong>Status:</strong> <span class="claim-status claim-status-<?= myClaimsEscape($claim["claim_status"]) ?>"><?= myClaimsEscape(ucfirst($claim["claim_status"])) ?></span></p>
                <p><strong>Submitted:</strong> <?= myClaimsEscape($claim["claim_date"]) ?></p>
                <?php if (trim((string) ($claim["decision_reason"] ?? "")) !== ""): ?>
                    <p class="feedback-banner"><strong>Administrator note:</strong> <?= nl2br(myClaimsEscape($claim["decision_reason"])) ?></p>
                <?php endif; ?>
                <?php if ($claim["claim_status"] === "needs_info"): ?>
                    <form method="post" class="stacked-form">
                        <input type="hidden" name="csrf_token" value="<?= myClaimsEscape($_SESSION["lifecycle_csrf_token"]) ?>">
                        <input type="hidden" name="claim_id" value="<?= (int) $claim["claim_id"] ?>">
                        <input type="hidden" name="action" value="respond">
                        <label for="claim-response-<?= (int) $claim["claim_id"] ?>">Your additional ownership information</label>
                        <textarea id="claim-response-<?= (int) $claim["claim_id"] ?>" name="owner_answer" rows="4" maxlength="2000" required><?= myClaimsEscape($claim["owner_answer"] ?? "") ?></textarea>
                        <button class="button button-small" type="submit">Send response for review</button>
                    </form>
                <?php endif; ?>
                <?php if ($claim["claim_status"] === "approved" && $claim["resolution_type"] === "owner_claim"): ?>
                    <p>Your claim was approved and this item has been removed from active listings.</p>
                    <a class="button button-small button-secondary" href="claim_messages.php?claim_id=<?= (int) $claim["claim_id"] ?>">Message the finder</a>
                    <?php if (
                        $claim["resolved_at"] !== null
                        && (
                            trim((string) $claim["private_handover_contact"]) !== ""
                            || ($claim["private_handover_latitude"] !== null && $claim["private_handover_longitude"] !== null)
                        )
                    ): ?>
                        <section class="private-handover-details" aria-label="Private handover details">
                            <h3>Private handover details</h3>
                            <p>These details are visible only to you because your claim was approved.</p>
                            <?php if (trim((string) $claim["private_handover_contact"]) !== ""): ?>
                                <p><strong>How to reach the finder:</strong><br><?= nl2br(myClaimsEscape($claim["private_handover_contact"])) ?></p>
                            <?php endif; ?>
                            <?php if ($claim["private_handover_latitude"] !== null && $claim["private_handover_longitude"] !== null): ?>
                                <?php $mapUrl = "https://www.google.com/maps?q=" . rawurlencode($claim["private_handover_latitude"] . "," . $claim["private_handover_longitude"]); ?>
                                <p><a href="<?= myClaimsEscape($mapUrl) ?>" target="_blank" rel="noopener noreferrer">View the suggested handover location</a></p>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>
                    <section class="return-confirmation-panel" aria-label="Return confirmation">
                        <h3>Item return</h3>
                        <p>Your confirmation: <?= $claim["claimant_return_confirmed_at"] !== null ? "Confirmed" : "Not confirmed" ?>.
                           Finder confirmation: <?= $claim["reporter_return_confirmed_at"] !== null ? "Confirmed" : "Not confirmed" ?>.</p>
                        <?php if ($claim["claimant_return_confirmed_at"] === null): ?>
                            <form method="post" class="inline-form" onsubmit="return confirm('Confirm that you received this item?');">
                                <input type="hidden" name="csrf_token" value="<?= myClaimsEscape($_SESSION["lifecycle_csrf_token"]) ?>">
                                <input type="hidden" name="claim_id" value="<?= (int) $claim["claim_id"] ?>">
                                <input type="hidden" name="action" value="confirm_return">
                                <button class="button button-small button-success" type="submit">Confirm I received the item</button>
                            </form>
                        <?php elseif ($claim["reporter_return_confirmed_at"] !== null): ?>
                            <a class="button button-small button-secondary" href="claim_feedback.php?claim_id=<?= (int) $claim["claim_id"] ?>">Leave private return feedback</a>
                        <?php endif; ?>
                    </section>
                    <?php if ($hasClaimantConsentColumn): ?>
                        <?php $claimantConsent = (int) ($claim["claimant_return_consent"] ?? 0); ?>
                        <form method="post" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= myClaimsEscape($_SESSION["lifecycle_csrf_token"]) ?>">
                            <input type="hidden" name="claim_id" value="<?= (int) $claim["claim_id"] ?>">
                            <input type="hidden" name="action" value="consent">
                            <input type="hidden" name="consent" value="<?= (int) !$claimantConsent ?>">
                            <button class="button button-small button-secondary" type="submit">
                                <?= $claimantConsent ? "Remove my showcase consent" : "Allow generic returned-item showcase" ?>
                            </button>
                        </form>
                    <?php endif; ?>
                <?php elseif ($claim["claim_status"] === "rejected"): ?>
                    <p>The administrator could not verify this claim. You may contact support if you believe this is an error.</p>
                <?php else: ?>
                    <p>Your claim is awaiting administrator review.</p>
                <?php endif; ?>
            </article>
        <?php endwhile; ?>
        </div>
    <?php endif; ?>
</main>
<?php require __DIR__ . "/site_footer.php"; ?>
</body>
</html>
<?php
$stmt->close();
$conn->close();
?>
