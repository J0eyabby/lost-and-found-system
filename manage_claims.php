<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once "db.php";
require_once "mail_notifications.php";
require_once __DIR__ . "/user_notifications.php";
require_once __DIR__ . "/item_date_validation.php";
require_once __DIR__ . "/activity_log.php";
require_once __DIR__ . "/smart_assistant.php";

// Allow only administrators
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION["user_id"];

$checkAdmin = $conn->prepare(
    "SELECT role FROM users WHERE user_id = ?"
);
$checkAdmin->bind_param("i", $user_id);
$checkAdmin->execute();
$checkAdmin->bind_result($role);
$userExists = $checkAdmin->fetch();
$checkAdmin->close();

if (!$userExists || $role !== "admin") {
    http_response_code(403);
    exit("Access denied. Administrators only.");
}

// Create a security token
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$message = "";
$otherClaimants = [];

// Approve or reject a claim
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";
    $claim_id = filter_input(
        INPUT_POST,
        "claim_id",
        FILTER_VALIDATE_INT
    );
    $new_status = $_POST["claim_status"] ?? "";
    $decisionReason = trim((string) ($_POST["decision_reason"] ?? ""));

    if (
        !is_string($token) ||
        !hash_equals($_SESSION["csrf_token"], $token)
    ) {
        $message = "Invalid request. Please refresh the page.";
    } elseif (
        !$claim_id ||
        $claim_id < 1 ||
        !in_array($new_status, ["approved", "rejected", "needs_info"], true) ||
        strlen($decisionReason) > 1000 ||
        (in_array($new_status, ["rejected", "needs_info"], true) && $decisionReason === "")
    ) {
        $message = "Choose a valid decision and provide a reason of up to 1,000 characters when rejecting or requesting more information.";
    } else {
        $conn->begin_transaction();
        try {
            $claimInfo = $conn->prepare(
                "SELECT claims.claim_status, claims.user_id, items.item_id,
                        items.status, items.resolved_at, items.is_hidden, items.item_name,
                        users.email
                 FROM claims
                 INNER JOIN items ON items.item_id = claims.item_id
                 INNER JOIN users ON users.user_id = claims.user_id
                 WHERE claims.claim_id = ? FOR UPDATE"
            );
            $claimInfo->bind_param("i", $claim_id);
            $claimInfo->execute();
            $claimInfo->bind_result($currentStatus, $claimantId, $itemId, $itemStatus, $resolvedAt, $isHidden, $itemName, $claimantEmail);
            $foundClaim = $claimInfo->fetch();
            $claimInfo->close();

            if (!$foundClaim || $currentStatus !== "pending") {
                $conn->rollback();
                $message = "This claim is no longer pending.";
            } elseif ($new_status === "approved" && ($itemStatus !== "found" || $resolvedAt !== null || (int) $isHidden === 1)) {
                $conn->rollback();
                $message = "This item is no longer available for approval.";
            } else {
                if ($new_status === "approved") {
                    $pendingOthers = $conn->prepare(
                        "SELECT claims.claim_id, claims.user_id, users.email
                         FROM claims
                         INNER JOIN users ON users.user_id = claims.user_id
                         WHERE claims.item_id = ? AND claims.claim_id <> ?
                           AND claims.claim_status IN ('pending', 'needs_info')
                         FOR UPDATE"
                    );
                    $pendingOthers->bind_param("ii", $itemId, $claim_id);
                    $pendingOthers->execute();
                    $otherResult = $pendingOthers->get_result();
                    while ($other = $otherResult->fetch_assoc()) {
                        $otherClaimants[] = [
                            "claim_id" => (int) $other["claim_id"],
                            "user_id" => (int) $other["user_id"],
                            "email" => $other["email"],
                        ];
                    }
                    $pendingOthers->close();
                }
                $update = $conn->prepare(
                    "UPDATE claims SET claim_status = ?, decision_reason = NULLIF(?, ''),
                     reviewed_at = NOW() WHERE claim_id = ? AND claim_status = 'pending'"
                );
                $update->bind_param("ssi", $new_status, $decisionReason, $claim_id);
                $update->execute();
                if ($update->affected_rows !== 1) {
                    throw new RuntimeException("Claim status did not update.");
                }
                $update->close();
                if ($new_status === "approved") {
                    if ($otherClaimants !== []) {
                        $rejectOthers = $conn->prepare(
                            "UPDATE claims SET claim_status = 'rejected',
                             decision_reason = 'Another claim for this item was approved.',
                             reviewed_at = NOW()
                             WHERE item_id = ? AND claim_id <> ? AND claim_status IN ('pending', 'needs_info')"
                        );
                        $rejectOthers->bind_param("ii", $itemId, $claim_id);
                        $rejectOthers->execute();
                        if ($rejectOthers->affected_rows !== count($otherClaimants)) {
                            throw new RuntimeException("Pending claims could not be closed.");
                        }
                        $rejectOthers->close();
                    }
                    $resolve = $conn->prepare(
                        "UPDATE items SET resolved_at = NOW(), resolution_type = 'owner_claim'
                         WHERE item_id = ? AND resolved_at IS NULL"
                    );
                    $resolve->bind_param("i", $itemId);
                    $resolve->execute();
                    if ($resolve->affected_rows !== 1) {
                        throw new RuntimeException("Item could not be resolved.");
                    }
                    $resolve->close();
                }
                $conn->commit();
                $decisionLabel = $new_status === "needs_info" ? "More information requested" : "Claim " . $new_status;
                $message = $decisionLabel . " successfully.";
                addUserNotification($conn, (int) $claimantId, "claims", $decisionLabel . " for " . $itemName . ($decisionReason !== "" ? ": " . $decisionReason : "."));
                if (userAllowsEmailNotification($conn, (int) $claimantId, "claims") && !sendCustomerNotification(
                    $claimantEmail,
                    $decisionLabel . " for your claim",
                    $decisionLabel . " for your claim on \"{$itemName}\". " . ($decisionReason !== "" ? $decisionReason . " " : "") . "Sign in to view your claim status."
                )) {
                    $message .= " The decision was saved, but the claimant notification could not be sent.";
                }
                foreach ($otherClaimants as $otherClaimant) {
                    addUserNotification($conn, $otherClaimant["user_id"], "claims", "Your claim for " . $itemName . " was rejected because another claim was approved.");
                    if (userAllowsEmailNotification($conn, $otherClaimant["user_id"], "claims") && !sendCustomerNotification(
                        $otherClaimant["email"],
                        "Your claim was rejected",
                        "Your claim for \"{$itemName}\" was rejected because another claim was approved. Sign in to view your claim status."
                    )) {
                        $message .= " One or more other claimants could not be notified.";
                        break;
                    }
                }
                recordActivity($conn, (int) $user_id, "claim_" . $new_status, "Claim ID " . $claim_id . "; item ID " . $itemId . ($decisionReason !== "" ? "; reason: " . $decisionReason : ""));
            }
        } catch (Throwable $error) {
            $conn->rollback();
            error_log("Claim decision failed: " . $error->getMessage());
            $message = "Unable to update the claim. Please try again.";
        }
    }
}

// Retrieve claims, with a review-status filter for the admin queue.
$claimFilter = $_GET["status"] ?? "all";
$dateFrom = trim($_GET["date_from"] ?? "");
$dateTo = trim($_GET["date_to"] ?? "");
$dateError = "";
if (!isValidPastOrPresentDateRange($dateFrom, $dateTo)) {
    $dateError = "Choose valid claim dates up to today, with the From date no later than the To date.";
    $dateFrom = "";
    $dateTo = "";
}
if (!in_array($claimFilter, ["all", "pending", "approved", "rejected", "needs_info"], true)) {
    $claimFilter = "all";
}
$sql = "SELECT
            claims.claim_id,
            claims.claim_date,
            claims.claim_status,
            claims.decision_reason,
            claims.reviewed_at,
            claims.item_id,
            items.item_name,
            items.status AS item_status,
            items.owner_hint,
            claims.owner_answer,
            users.full_name AS claimant_name,
            users.email AS claimant_email,
            owners.full_name AS reporter_name
        FROM claims
        INNER JOIN items
            ON claims.item_id = items.item_id
        INNER JOIN users
            ON claims.user_id = users.user_id
        INNER JOIN users AS owners
            ON items.user_id = owners.user_id
        WHERE (? = 'all' OR claims.claim_status = ?)
          AND (? = '' OR DATE(claims.claim_date) >= ?)
          AND (? = '' OR DATE(claims.claim_date) <= ?)
        ORDER BY claims.claim_date DESC
        LIMIT 100";

$claimsQuery = $conn->prepare($sql);
$claimsQuery->bind_param("ssssss", $claimFilter, $claimFilter, $dateFrom, $dateFrom, $dateTo, $dateTo);
$claimsQuery->execute();
$result = $claimsQuery->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Claims</title>

<link rel="stylesheet" href="css/style.css?v=theme-20261014-12">
<script src="navigation.js" defer></script>
<script src="date-range.js" defer></script>

</head>
<body class="admin-theme">
<?php require __DIR__ . "/site_header.php"; ?>

    <main class="admin-page">
        <header class="admin-page-header">
            <div>
                <p class="eyebrow">Operations / Review queue</p>
                <h1 id="pending">Manage Claims</h1>
            </div>
            <nav class="admin-nav" aria-label="Administrator navigation">
                <a href="admin.php" class="button button-small">Dashboard</a>
                <a href="settings.php#profile-settings" class="button button-small button-secondary">Settings</a>
                <a href="manage_items.php" class="button button-small button-secondary">Items</a>
                <a href="manage_users.php" class="button button-small button-secondary">Users</a>
                <a href="dashboard.php" class="button button-small button-secondary">Dashboard</a>
                <a href="index.php" class="button button-small button-secondary">View Site</a>
                <a href="logout.php" class="button button-small button-ghost">Logout</a>
            </nav>
        </header>

    <?php if ($message !== ""): ?>
        <p>
            <strong><?= htmlspecialchars($message) ?></strong>
        </p>
    <?php endif; ?>

    <section class="admin-panel">
    <div class="section-header">
        <p class="eyebrow">Review queue</p>
        <h2>Submitted Claims</h2>
        <nav class="filter-pills" aria-label="Filter claims">
            <?php foreach (["all" => "All", "pending" => "Pending", "needs_info" => "Needs information", "approved" => "Approved", "rejected" => "Rejected"] as $claimStatus => $claimLabel): ?>
                <a class="button button-small <?= $claimFilter === $claimStatus ? "" : "button-secondary" ?>"
                    href="manage_claims.php?status=<?= urlencode($claimStatus) ?>#pending">
                    <?= htmlspecialchars($claimLabel) ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <form method="get" class="date-filter-form">
            <input type="hidden" name="status" value="<?= htmlspecialchars($claimFilter, ENT_QUOTES, "UTF-8") ?>">
            <label for="claim-date-from">Submitted from</label>
            <input id="claim-date-from" name="date_from" type="date" max="<?= date("Y-m-d") ?>" value="<?= htmlspecialchars($dateFrom, ENT_QUOTES, "UTF-8") ?>">
            <label for="claim-date-to">Submitted to</label>
            <input id="claim-date-to" name="date_to" type="date" max="<?= date("Y-m-d") ?>" value="<?= htmlspecialchars($dateTo, ENT_QUOTES, "UTF-8") ?>">
            <button class="button button-small" type="submit">Apply dates</button>
            <?php if ($dateFrom !== "" || $dateTo !== ""): ?>
                <a class="button button-small button-secondary" href="manage_claims.php?status=<?= urlencode($claimFilter) ?>">Clear dates</a>
            <?php endif; ?>
        </form>
        <?php if ($dateError !== ""): ?>
            <p class="form-error" role="alert"><?= htmlspecialchars($dateError, ENT_QUOTES, "UTF-8") ?></p>
        <?php endif; ?>
    </div>

    <?php if ($result && $result->num_rows > 0): ?>

        <div class="claims-table-scroll">
        <table class="claims-table" border="1" cellpadding="8" cellspacing="0">
            <thead>
                <tr>
                    <th>Claim ID</th>
                    <th>Item Name</th>
                    <th>Item Status</th>
                    <th>Claimant</th>
                    <th>Claimant Email</th>
                    <th>Reported by</th>
                    <th>Private ownership clue</th>
                    <th>Claimant answer</th>
                    <th>Local review signal</th>
                    <th>Claim Date</th>
                    <th>Claim Status</th>
                    <th>Latest response</th>
                    <th>Decision / request details</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>
                <?php while ($claim = $result->fetch_assoc()): ?>
                    <tr id="claim-<?= (int) $claim["claim_id"] ?>">
                        <td data-label="Claim ID">
                            <?= (int) $claim["claim_id"] ?>
                        </td>

                        <td data-label="Item name">
                            <?= htmlspecialchars($claim["item_name"]) ?>
                        </td>

                        <td data-label="Item status">
                            <?= htmlspecialchars($claim["item_status"]) ?>
                        </td>

                        <td data-label="Claimant">
                            <?= htmlspecialchars($claim["claimant_name"]) ?>
                        </td>

                        <td data-label="Claimant email">
                            <?= htmlspecialchars($claim["claimant_email"]) ?>
                        </td>

                        <td data-label="Reported by"><?= htmlspecialchars($claim["reporter_name"]) ?></td>
                        <td data-label="Private ownership clue"><?= nl2br(htmlspecialchars($claim["owner_hint"] ?? "")) ?></td>
                        <td data-label="Claimant answer"><?= nl2br(htmlspecialchars($claim["owner_answer"] ?? "")) ?></td>
                        <?php $answerOverlap = (int) round(smartAssistantSimilarity((string) ($claim["owner_hint"] ?? ""), (string) ($claim["owner_answer"] ?? "")) * 100); ?>
                        <td data-label="Local review signal">
                            <strong><?= $answerOverlap ?>% keyword overlap</strong>
                            <br><small>Text-only support signal. It cannot verify ownership; review all evidence yourself.</small>
                        </td>

                        <td data-label="Claim date">
                            <?= htmlspecialchars($claim["claim_date"]) ?>
                        </td>

                        <td data-label="Claim status">
                            <?= htmlspecialchars($claim["claim_status"]) ?>
                        </td>
                        <td data-label="Latest response">
                            <?php if ($claim["claim_status"] === "pending" && $claim["reviewed_at"] !== null): ?>
                                <strong>Claimant responded — review required</strong><br>
                                <small><?= htmlspecialchars($claim["reviewed_at"]) ?></small>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td data-label="Decision / request details"><?= nl2br(htmlspecialchars($claim["decision_reason"] ?? "", ENT_QUOTES, "UTF-8")) ?></td>

                        <td data-label="Action">
                            <?php if ($claim["claim_status"] === "pending"): ?>

                                <form method="POST" action="manage_claims.php">
                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="claim_id"
                                        value="<?= (int) $claim["claim_id"] ?>"
                                    >
                                    <label for="decision-reason-<?= (int) $claim["claim_id"] ?>">Reason / requested information</label>
                                    <textarea id="decision-reason-<?= (int) $claim["claim_id"] ?>" name="decision_reason" rows="2" maxlength="1000"></textarea>

                                    <button class="button-success"
                                        type="submit"
                                        name="claim_status"
                                        value="approved"
                                        onclick="return confirm('Approve this claim?');"
                                    >
                                        Approve
                                    </button>

                                    <button class="button-danger"
                                        type="submit"
                                        name="claim_status"
                                        value="rejected"
                                        onclick="return confirm('Reject this claim?');"
                                    >
                                        Reject
                                    </button>
                                    <button class="button button-secondary"
                                        type="submit"
                                        name="claim_status"
                                        value="needs_info"
                                        onclick="return confirm('Ask this claimant for more information?');"
                                    >
                                        Request more info
                                    </button>
                                </form>

                            <?php else: ?>

                                No action available

                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        </div>

    <?php else: ?>

        <p>No claims have been submitted yet.</p>

    <?php endif; ?>
    </section>
    </main>
    <?php require __DIR__ . "/site_footer.php"; ?>
</body>
</html>
<?php
$conn->close();
?>
