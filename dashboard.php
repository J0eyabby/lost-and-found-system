<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/account_guard.php";
enforceActiveAccount($conn);

$userId = (int) ($_SESSION["user_id"] ?? 0);
if ($userId < 1) {
    header("Location: login.php?next=" . rawurlencode("dashboard.php"));
    exit;
}

$role = "";
$reportCount = 0;
$claimCount = 0;
$unreadCount = 0;
$recentReports = [];
$recentNotifications = [];
if ($userId > 0) {
    $roleQuery = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
    $roleQuery->bind_param("i", $userId);
    $roleQuery->execute();
    $roleQuery->bind_result($role);
    $roleQuery->fetch();
    $roleQuery->close();

    $summaryQuery = $conn->prepare(
        "SELECT
            (SELECT COUNT(*) FROM items WHERE user_id = ?) AS report_count,
            (SELECT COUNT(*) FROM claims WHERE user_id = ?) AS claim_count,
            (SELECT COUNT(*) FROM user_notifications WHERE user_id = ? AND is_read = 0) AS unread_count"
    );
    if ($summaryQuery !== false) {
        $summaryQuery->bind_param("iii", $userId, $userId, $userId);
        if ($summaryQuery->execute()) {
            $summaryQuery->bind_result($reportCount, $claimCount, $unreadCount);
            $summaryQuery->fetch();
        }
        $summaryQuery->close();

        $reportsQuery = $conn->prepare(
            "SELECT item_id, item_name, status, created_at
             FROM items WHERE user_id = ? ORDER BY created_at DESC LIMIT 3"
        );
        if ($reportsQuery !== false) {
            $reportsQuery->bind_param("i", $userId);
            if ($reportsQuery->execute()) {
                $recentReports = $reportsQuery->get_result()->fetch_all(MYSQLI_ASSOC);
            }
            $reportsQuery->close();
        }

        $notificationsQuery = $conn->prepare(
            "SELECT message, is_read, created_at
             FROM user_notifications WHERE user_id = ?
             ORDER BY created_at DESC, notification_id DESC LIMIT 3"
        );
        if ($notificationsQuery !== false) {
            $notificationsQuery->bind_param("i", $userId);
            if ($notificationsQuery->execute()) {
                $recentNotifications = $notificationsQuery->get_result()->fetch_all(MYSQLI_ASSOC);
            }
            $notificationsQuery->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard - Lost and Found</title>
    <link rel="stylesheet" href="css/style.css?v=20261013">
</head>
<body>
<?php require __DIR__ . "/site_header.php"; ?>
<main class="container hub-page">
    <p class="eyebrow" data-i18n="quick_access">Quick access</p>
    <h1 data-i18n="dashboard">Dashboard</h1>
    <p class="lead" data-i18n="dashboard_intro">Find your way around the Lost and Found community.</p>
    <?php if ($userId > 0): ?>
        <section class="dashboard-summary" aria-label="Your activity summary">
            <a class="dashboard-summary-card" href="my_items.php" aria-label="View your <?= (int) $reportCount ?> reported items">
                <span class="dashboard-summary-icon" aria-hidden="true">&#9673;</span>
                <div><strong><?= (int) $reportCount ?></strong><span>Your reports</span></div>
            </a>
            <a class="dashboard-summary-card" href="my_claims.php" aria-label="View your <?= (int) $claimCount ?> claims">
                <span class="dashboard-summary-icon" aria-hidden="true">&#10003;</span>
                <div><strong><?= (int) $claimCount ?></strong><span>Your claims</span></div>
            </a>
            <a class="dashboard-summary-card" href="notifications.php" aria-label="View your <?= (int) $unreadCount ?> unread notifications">
                <span class="dashboard-summary-icon" aria-hidden="true">&#9679;</span>
                <div><strong><?= (int) $unreadCount ?></strong><span>Unread notifications</span></div>
            </a>
        </section>
    <?php endif; ?>
    <?php if ($userId > 0): ?>
        <section class="dashboard-preview-grid" aria-label="Recent account activity">
            <article class="dashboard-preview-card">
                <div class="dashboard-preview-heading"><h2>Recent reports</h2><a href="my_items.php">View all</a></div>
                <?php if ($recentReports === []): ?>
                    <p class="dashboard-preview-empty">No reports yet. <a href="report_item.php">Report an item</a>.</p>
                <?php else: ?>
                    <ul>
                        <?php foreach ($recentReports as $report): ?>
                            <li><a href="my_items.php"><strong><?= htmlspecialchars($report["item_name"]) ?></strong><span><?= htmlspecialchars(ucfirst($report["status"])) ?> &middot; <?= htmlspecialchars(date("M j, Y", strtotime($report["created_at"]))) ?></span></a></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </article>
            <article class="dashboard-preview-card">
                <div class="dashboard-preview-heading"><h2>Recent notifications</h2><a href="notifications.php">View all</a></div>
                <?php if ($recentNotifications === []): ?>
                    <p class="dashboard-preview-empty">No notifications yet.</p>
                <?php else: ?>
                    <ul>
                        <?php foreach ($recentNotifications as $notification): ?>
                            <li class="<?= (int) $notification["is_read"] === 0 ? "is-unread" : "" ?>"><a href="notifications.php"><strong><?= htmlspecialchars($notification["message"]) ?></strong><span><?= htmlspecialchars(date("M j, Y", strtotime($notification["created_at"]))) ?></span></a></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </article>
        </section>
    <?php endif; ?>
    <nav class="hub-grid" aria-label="Dashboard links">
        <?php if ($userId > 0): ?>
            <a class="hub-card" href="report_item.php"><span aria-hidden="true">&#43;</span><strong data-i18n="report_item">Report Item</strong><small data-i18n="report_description">Post a lost or found item</small></a>
        <?php endif; ?>
        <?php if ($userId > 0 && $role === "admin"): ?>
            <a class="hub-card" href="admin.php"><span aria-hidden="true">&#9670;</span><strong data-i18n="admin">Admin</strong><small data-i18n="admin_description">Open administrator tools</small></a>
        <?php endif; ?>
        <a class="hub-card" href="lost_items.php"><span aria-hidden="true">&#9906;</span><strong data-i18n="lost_items">Lost Items</strong><small data-i18n="lost_items_description">Browse missing item reports</small></a>
        <a class="hub-card" href="found_items.php"><span aria-hidden="true">&#9906;</span><strong data-i18n="found_items">Found Items</strong><small data-i18n="found_items_description">Browse found item reports</small></a>
        <?php if ($userId > 0): ?>
            <a class="hub-card" href="my_items.php"><span aria-hidden="true">&#9673;</span><strong data-i18n="my_reports">My Reports</strong><small data-i18n="my_reports_description">Manage reported items</small></a>
            <a class="hub-card" href="my_claims.php"><span aria-hidden="true">&#10003;</span><strong data-i18n="my_claims">My Claims</strong><small data-i18n="my_claims_description">Track claim decisions</small></a>
            <a class="hub-card" href="settings.php"><span aria-hidden="true">&#9881;</span><strong data-i18n="settings">Settings</strong><small data-i18n="settings_description">Privacy, language and help</small></a>
            <a class="hub-card" href="logout.php"><span aria-hidden="true">&#10150;</span><strong data-i18n="logout">Log out</strong><small data-i18n="logout_description">Securely end your session</small></a>
        <?php else: ?>
            <a class="hub-card" href="login.php"><span aria-hidden="true">&#10146;</span><strong data-i18n="login">Log in</strong><small data-i18n="login_description">Access your account</small></a>
            <a class="hub-card" href="register.php"><span aria-hidden="true">&#43;</span><strong data-i18n="create_account">Create account</strong><small data-i18n="create_account_description">Join the community</small></a>
            <a class="hub-card" href="settings.php"><span aria-hidden="true">&#9881;</span><strong data-i18n="settings">Settings</strong><small data-i18n="language_help_feedback">Language, help and feedback</small></a>
        <?php endif; ?>
    </nav>
    </main>
    <?php require __DIR__ . "/site_footer.php"; ?>
    </body>
</html>
