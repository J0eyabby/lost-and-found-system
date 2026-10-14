<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
$userId = (int) ($_SESSION["user_id"] ?? 0);
$role = "";
if ($userId > 0) {
    $adminQuery = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
    $adminQuery->bind_param("i", $userId);
    $adminQuery->execute();
    $adminQuery->bind_result($role);
    $adminQuery->fetch();
    $adminQuery->close();
}
if ($role !== "admin") {
    http_response_code(403);
    exit("Access denied. Administrators only.");
}
$actionFilter = trim((string) ($_GET["action"] ?? ""));
$search = trim((string) ($_GET["q"] ?? ""));
$actionFilter = strlen($actionFilter) <= 80 ? $actionFilter : "";
$search = strlen($search) <= 100 ? $search : "";
$actionLike = "%" . $conn->real_escape_string($actionFilter) . "%";
$searchLike = "%" . $conn->real_escape_string($search) . "%";
$logQuery = $conn->prepare(
    "SELECT logs.action, logs.details, logs.created_at, users.full_name
     FROM system_activity_logs AS logs
     LEFT JOIN users ON users.user_id = logs.actor_user_id
     WHERE (? = '' OR logs.action LIKE ?)
       AND (? = '' OR logs.details LIKE ? OR users.full_name LIKE ?)
     ORDER BY logs.created_at DESC LIMIT 300"
);
$logQuery->bind_param("sssss", $actionFilter, $actionLike, $search, $searchLike, $searchLike);
$logQuery->execute();
$logs = $logQuery->get_result();
function activityEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>System Activity Logs</title><link rel="stylesheet" href="css/style.css?v=theme-20261014-12"></head>
<body><?php require __DIR__ . "/site_header.php"; ?>
<main class="container account-page">
    <h1 class="page-title">System Activity Logs</h1>
    <p>Recent user and administrator actions (up to 300 records).</p>
    <div class="admin-panel">
        <form method="get" class="search-form" role="search">
            <label for="log-action">Action contains</label>
            <input id="log-action" name="action" maxlength="80" value="<?= activityEscape($actionFilter) ?>" placeholder="e.g. claim_approved">
            <label for="log-search">Account or details contain</label>
            <input id="log-search" name="q" maxlength="100" value="<?= activityEscape($search) ?>">
            <button type="submit">Search logs</button>
            <a class="button button-small button-secondary" href="system_activity_logs.php">Clear</a>
        </form>
        <table>
            <thead><tr><th>Time</th><th>Account</th><th>Action</th><th>Details</th></tr></thead>
            <tbody>
            <?php while ($log = $logs->fetch_assoc()): ?>
                <tr><td><?= activityEscape($log["created_at"]) ?></td><td><?= activityEscape($log["full_name"] ?? "Deleted account") ?></td><td><?= activityEscape($log["action"]) ?></td><td><?= activityEscape($log["details"]) ?></td></tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</main>
<?php require __DIR__ . "/site_footer.php"; ?>
</body>
</html>
<?php
$logQuery->close();
$conn->close();
?>
