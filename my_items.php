<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/account_guard.php";
require_once __DIR__ . "/user_notifications.php";
require_once __DIR__ . "/activity_log.php";
require_once __DIR__ . "/smart_assistant.php";
require_once __DIR__ . "/assets/image_helper.php";
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
$hasReporterConsentColumn = dbHasColumn($conn, "items", "reporter_return_consent");
$hasClaimantConsentColumn = dbHasColumn($conn, "claims", "claimant_return_consent");

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";
    $itemId = filter_input(INPUT_POST, "item_id", FILTER_VALIDATE_INT);
    $action = $_POST["action"] ?? "";
    if (!is_string($token) || !hash_equals($_SESSION["lifecycle_csrf_token"], $token)) {
        $message = "Your session expired. Please refresh and try again.";
    } elseif (!$itemId || $itemId < 1) {
        $message = "The selected item is invalid.";
    } elseif ($action === "mark_found") {
        $stmt = $conn->prepare(
            "UPDATE items SET resolved_at = NOW(), resolution_type = 'reporter_found'
             WHERE item_id = ? AND user_id = ? AND status = 'lost' AND resolved_at IS NULL"
        );
        $stmt->bind_param("ii", $itemId, $userId);
        if ($stmt->execute() && $stmt->affected_rows === 1) {
            $message = "Marked as found. The item has been removed from active listings.";
            recordActivity($conn, $userId, "report_marked_found", "Item ID " . $itemId);
        } else {
            $message = "This item could not be marked as found; it may already be resolved.";
        }
        $stmt->close();
    } elseif ($action === "consent") {
        if (!$hasReporterConsentColumn) {
            $message = "Showcase consent is not available because the database schema has not been updated yet.";
        } else {
            $consent = (int) ($_POST["consent"] ?? 0) === 1 ? 1 : 0;
            $stmt = $conn->prepare(
                "UPDATE items
                 SET reporter_return_consent = ?
                 WHERE item_id = ? AND user_id = ? AND resolved_at IS NOT NULL
                   AND resolution_type = 'owner_claim'
                   AND EXISTS (SELECT 1 FROM claims WHERE claims.item_id = items.item_id AND claims.claim_status = 'approved')"
            );
            $stmt->bind_param("iii", $consent, $itemId, $userId);
            if ($stmt->execute()) {
                $message = "Your showcase preference has been saved.";
            } else {
                $message = "Unable to save your showcase preference.";
            }
            $stmt->close();
        }
    } elseif ($action === "renew") {
        $renew = $conn->prepare(
            "UPDATE items SET last_confirmed_at = NOW()
             WHERE item_id = ? AND user_id = ? AND resolved_at IS NULL
               AND COALESCE(last_confirmed_at, created_at) <= DATE_SUB(NOW(), INTERVAL 90 DAY)"
        );
        $renew->bind_param("ii", $itemId, $userId);
        if ($renew->execute() && $renew->affected_rows === 1) {
            $message = "Your report is confirmed and remains active.";
            recordActivity($conn, $userId, "item_listing_renewed", "Item ID " . $itemId);
        } else {
            $message = "This listing could not be renewed; it may not be due for review or may already be resolved.";
        }
        $renew->close();
    } elseif ($action === "confirm_return") {
        $confirm = $conn->prepare(
            "UPDATE items SET reporter_return_confirmed_at = NOW()
             WHERE item_id = ? AND user_id = ? AND resolved_at IS NOT NULL
               AND resolution_type = 'owner_claim'
               AND reporter_return_confirmed_at IS NULL
               AND EXISTS (SELECT 1 FROM claims WHERE claims.item_id = items.item_id AND claims.claim_status = 'approved')"
        );
        $confirm->bind_param("ii", $itemId, $userId);
        if ($confirm->execute() && $confirm->affected_rows === 1) {
            $message = "Your return confirmation has been recorded.";
            recordActivity($conn, $userId, "item_return_confirmed", "Item ID " . $itemId);
            $claimants = $conn->prepare("SELECT user_id FROM claims WHERE item_id = ? AND claim_status = 'approved'");
            $claimants->bind_param("i", $itemId);
            $claimants->execute();
            $claimantRows = $claimants->get_result();
            while ($claimant = $claimantRows->fetch_assoc()) {
                addUserNotification($conn, (int) $claimant["user_id"], "claims", "The finder confirmed the return. Please confirm it in My Claims.");
            }
            $claimants->close();
        } else {
            $message = "The return could not be confirmed. Check that an approved claim exists and that you have not already confirmed it.";
        }
        $confirm->close();
    } elseif ($action === "match_feedback") {
        $matchedItemId = filter_input(INPUT_POST, "matched_item_id", FILTER_VALIDATE_INT);
        $feedback = $_POST["feedback"] ?? "";
        if (!$matchedItemId || $matchedItemId < 1 || !in_array($feedback, ["useful", "not_match", "dismissed"], true)) {
            $message = "The match feedback is invalid.";
        } else {
            $saveFeedback = $conn->prepare(
                "INSERT INTO claim_match_feedback (user_id, source_item_id, matched_item_id, feedback)
                 SELECT ?, source.item_id, matched.item_id, ?
                 FROM items AS source
                 INNER JOIN items AS matched ON matched.item_id = ?
                 WHERE source.item_id = ? AND source.user_id = ?
                   AND source.status <> matched.status AND source.is_hidden = 0
                   AND matched.is_hidden = 0 AND matched.resolved_at IS NULL
                 ON DUPLICATE KEY UPDATE feedback = VALUES(feedback)"
            );
            $saveFeedback->bind_param("isiii", $userId, $feedback, $matchedItemId, $itemId, $userId);
            if ($saveFeedback->execute() && $saveFeedback->affected_rows > 0) {
                $message = "Your match feedback has been saved.";
                recordActivity($conn, $userId, "smart_match_feedback", "Report ID " . $itemId . "; match ID " . $matchedItemId . "; " . $feedback);
            } else {
                $message = "Unable to save feedback for this match.";
            }
            $saveFeedback->close();
        }
    } else {
        $message = "Invalid request.";
    }
}

$selectColumns = [
    "items.item_id",
    "items.item_name",
    "items.status",
    "items.resolved_at",
    "items.resolution_type",
    "items.is_hidden",
    "items.moderation_reason",
    "items.owner_hint",
    "items.description",
    "items.image",
    "items.category",
    "items.location",
    "items.date_lost_found",
    "items.created_at",
    "items.last_confirmed_at",
    "items.reporter_return_confirmed_at",
    "claims.claim_id",
    "claims.claimant_return_confirmed_at",
    "claims.claim_status",
    "claims.owner_answer",
    "CASE WHEN COALESCE(items.last_confirmed_at, items.created_at) <= DATE_SUB(NOW(), INTERVAL 90 DAY) THEN 1 ELSE 0 END AS review_due"
];
if ($hasReporterConsentColumn) {
    $selectColumns[] = "items.reporter_return_consent";
}
if ($hasClaimantConsentColumn) {
    $selectColumns[] = "claims.claimant_return_consent";
}

$sql = "SELECT " . implode(", ", $selectColumns) . "
     FROM items
     LEFT JOIN claims ON claims.item_id = items.item_id
     WHERE items.user_id = ?
     ORDER BY items.created_at DESC, claims.claim_date DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$items = $stmt->get_result();
$candidateQuery = $conn->prepare(
    "SELECT item_id, item_name, description, category, status, location, date_lost_found
     FROM items
     WHERE user_id <> ? AND resolved_at IS NULL AND is_hidden = 0"
);
$candidateQuery->bind_param("i", $userId);
$candidateQuery->execute();
$candidateResult = $candidateQuery->get_result();
$matchCandidates = $candidateResult->fetch_all(MYSQLI_ASSOC);
$candidateQuery->close();
$feedbackQuery = $conn->prepare(
    "SELECT source_item_id, matched_item_id, feedback
     FROM claim_match_feedback WHERE user_id = ?"
);
$feedbackQuery->bind_param("i", $userId);
$feedbackQuery->execute();
$matchFeedback = [];
$feedbackResult = $feedbackQuery->get_result();
while ($feedbackRow = $feedbackResult->fetch_assoc()) {
    $matchFeedback[(int) $feedbackRow["source_item_id"] . ":" . (int) $feedbackRow["matched_item_id"]] = $feedbackRow["feedback"];
}
$feedbackQuery->close();
$assistantLanguage = (string) ($_SESSION["preferred_language"] ?? "en");
function myItemsEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Reports - Lost and Found</title>
    <link rel="stylesheet" href="css/style.css?v=20261014">
    <script src="navigation.js" defer></script>
</head>
<body>
<?php require __DIR__ . "/site_header.php"; ?>
<main class="container account-page">
    <h1 class="page-title">My Reports</h1>
    <?php if ($message !== ""): ?><p class="feedback-banner" role="status"><?= myItemsEscape($message) ?></p><?php endif; ?>
    <?php if ($items->num_rows === 0): ?>
        <p class="empty-message">You have not reported any items yet.</p>
    <?php else: ?>
        <div class="account-list">
        <?php while ($item = $items->fetch_assoc()): ?>
            <article class="account-card">
                <?php $reportImageUrl = getItemImageUrl($item["image"] ?? null); ?>
                <?php if ($reportImageUrl !== null): ?>
                    <img class="account-card-photo" src="<?= myItemsEscape($reportImageUrl) ?>" alt="<?= myItemsEscape($item["item_name"]) ?>">
                <?php else: ?>
                    <div class="account-card-photo item-thumbnail-placeholder" role="img" aria-label="No photo available">
                        <span>No photo available</span>
                    </div>
                <?php endif; ?>
                <h2><?= myItemsEscape($item["item_name"]) ?></h2>
                <p><strong>Type:</strong> <?= myItemsEscape(ucfirst($item["status"])) ?></p>
                <p><strong>Listing:</strong> <?= $item["resolved_at"] === null ? "Active" : "Resolved" ?></p>
                <?php if ((int) $item["is_hidden"] === 1): ?>
                    <p class="feedback-banner error"><strong>Hidden from public listings.</strong> Reason: <?= myItemsEscape($item["moderation_reason"] ?? "Contact support for details.") ?></p>
                <?php endif; ?>
                <?php if ($item["resolved_at"] === null && (int) $item["review_due"] === 1): ?>
                    <section class="feedback-banner" aria-label="Listing review reminder">
                        <p>This report is over 90 days old. Confirm it is still active to keep it current.</p>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= myItemsEscape($_SESSION["lifecycle_csrf_token"]) ?>">
                            <input type="hidden" name="item_id" value="<?= (int) $item["item_id"] ?>">
                            <input type="hidden" name="action" value="renew">
                            <button class="button button-small" type="submit">Confirm report is still active</button>
                        </form>
                    </section>
                <?php endif; ?>
                <?php if ($item["resolved_at"] === null && (int) $item["is_hidden"] === 0): ?>
                    <?php
                    $possibleMatches = [];
                    foreach ($matchCandidates as $candidate) {
                        if ($candidate["status"] === $item["status"]) {
                            continue;
                        }
                        $match = smartAssistantMatch($item, $candidate, $assistantLanguage);
                        if ($match["score"] >= 20) {
                            $possibleMatches[] = ["item" => $candidate, "analysis" => $match];
                        }
                    }
                    usort($possibleMatches, static fn(array $a, array $b): int => $b["analysis"]["score"] <=> $a["analysis"]["score"]);
                    $possibleMatches = array_slice($possibleMatches, 0, 3);
                    ?>
                    <?php if ($possibleMatches !== []): ?>
                        <section class="smart-match-panel" aria-label="Possible item matches">
                            <h3><?= $assistantLanguage === "sw" ? "Huenda ikawa imefanana" : "Possible matches" ?></h3>
                            <p class="helper-text"><?= $assistantLanguage === "sw" ? "Mapendekezo ya ndani yanayotegemea maandishi; si uthibitisho wa umiliki." : "Private, on-site text-based suggestions—not proof of ownership." ?></p>
                            <?php foreach ($possibleMatches as $possibleMatch): ?>
                                <?php $candidate = $possibleMatch["item"]; $analysis = $possibleMatch["analysis"]; ?>
                                <?php $matchKey = (int) $item["item_id"] . ":" . (int) $candidate["item_id"]; $savedFeedback = $matchFeedback[$matchKey] ?? ""; ?>
                                <?php if ($savedFeedback === "dismissed") { continue; } ?>
                                <article class="smart-match-result">
                                    <a href="item.php?item_id=<?= (int) $candidate["item_id"] ?>"><?= myItemsEscape($candidate["item_name"]) ?></a>
                                    <strong><?= (int) $analysis["score"] ?>% match</strong>
                                    <p><?= myItemsEscape(implode(" ", $analysis["reasons"])) ?></p>
                                    <?php if ($savedFeedback !== ""): ?>
                                        <p class="helper-text">Feedback saved: <?= myItemsEscape(str_replace("_", " ", $savedFeedback)) ?>.</p>
                                    <?php else: ?>
                                        <form method="post" class="inline-form">
                                            <input type="hidden" name="csrf_token" value="<?= myItemsEscape($_SESSION["lifecycle_csrf_token"]) ?>">
                                            <input type="hidden" name="item_id" value="<?= (int) $item["item_id"] ?>">
                                            <input type="hidden" name="matched_item_id" value="<?= (int) $candidate["item_id"] ?>">
                                            <input type="hidden" name="action" value="match_feedback">
                                            <button class="button button-small button-secondary" name="feedback" value="useful" type="submit">Helpful</button>
                                            <button class="button button-small button-secondary" name="feedback" value="not_match" type="submit">Not a match</button>
                                            <button class="button button-small button-ghost" name="feedback" value="dismissed" type="submit">Hide suggestion</button>
                                        </form>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </section>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($item["status"] === "lost" && $item["resolved_at"] === null): ?>
                    <form method="post" class="inline-form" onsubmit="return confirm('Mark this lost item as found and remove it from active listings?');">
                        <input type="hidden" name="csrf_token" value="<?= myItemsEscape($_SESSION["lifecycle_csrf_token"]) ?>">
                        <input type="hidden" name="item_id" value="<?= (int) $item["item_id"] ?>">
                        <input type="hidden" name="action" value="mark_found">
                        <button class="button button-small button-success" type="submit">I found it</button>
                    </form>
                <?php endif; ?>
                <?php if ($item["claim_status"] !== null): ?>
                    <?php $reporterConsent = $hasReporterConsentColumn ? (int) ($item["reporter_return_consent"] ?? 0) : 0; ?>
                    <div class="private-claim-details">
                        <p><strong>Claim status:</strong> <?= myItemsEscape(ucfirst($item["claim_status"])) ?></p>
                        <?php if ($item["claim_status"] === "pending"): ?>
                            <p><strong>Private clue:</strong> <?= nl2br(myItemsEscape($item["owner_hint"])) ?></p>
                            <p><strong>Claimant's private answer:</strong> <?= nl2br(myItemsEscape($item["owner_answer"])) ?></p>
                        <?php endif; ?>
                        <?php if ($item["claim_status"] === "approved" && $item["claim_id"] !== null): ?>
                            <a class="button button-small button-secondary" href="claim_messages.php?claim_id=<?= (int) $item["claim_id"] ?>">Message the claimant</a>
                            <p>Return confirmation: you <?= $item["reporter_return_confirmed_at"] !== null ? "confirmed" : "have not confirmed" ?>; claimant <?= $item["claimant_return_confirmed_at"] !== null ? "confirmed" : "has not confirmed" ?>.</p>
                            <?php if ($item["reporter_return_confirmed_at"] === null): ?>
                                <form method="post" class="inline-form" onsubmit="return confirm('Confirm that you handed the item to the claimant?');">
                                    <input type="hidden" name="csrf_token" value="<?= myItemsEscape($_SESSION["lifecycle_csrf_token"]) ?>">
                                    <input type="hidden" name="item_id" value="<?= (int) $item["item_id"] ?>">
                                    <input type="hidden" name="action" value="confirm_return">
                                    <button class="button button-small button-success" type="submit">Confirm I returned the item</button>
                                </form>
                            <?php elseif ($item["claimant_return_confirmed_at"] !== null): ?>
                                <a class="button button-small button-secondary" href="claim_feedback.php?claim_id=<?= (int) $item["claim_id"] ?>">Leave private return feedback</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ($item["resolution_type"] === "owner_claim" && $hasReporterConsentColumn): ?>
                    <form method="post" class="inline-form">
                        <input type="hidden" name="csrf_token" value="<?= myItemsEscape($_SESSION["lifecycle_csrf_token"]) ?>">
                        <input type="hidden" name="item_id" value="<?= (int) $item["item_id"] ?>">
                        <input type="hidden" name="action" value="consent">
                        <input type="hidden" name="consent" value="<?= (int) !$reporterConsent ?>">
                        <button class="button button-small button-secondary" type="submit">
                            <?= $reporterConsent ? "Remove my showcase consent" : "Allow generic returned-item showcase" ?>
                        </button>
                    </form>
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
