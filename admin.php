<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once "db.php";

// Only administrators can access this page
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

// Verify the user's role using the database
$user_id = $_SESSION["user_id"];

$stmt = $conn->prepare(
    "SELECT role FROM users WHERE user_id = ?"
);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($role);
$userExists = $stmt->fetch();
$stmt->close();

if (!$userExists || $role !== "admin") {
    http_response_code(403);
    exit("Access denied. Administrators only.");
}

// Count registered users
$result = $conn->query(
    "SELECT COUNT(*) AS total FROM users"
);
$totalUsers = $result->fetch_assoc()["total"];

// Count lost items
$result = $conn->query(
    "SELECT COUNT(*) AS total FROM items WHERE status = 'lost'"
);
$totalLost = $result->fetch_assoc()["total"];

// Count found items
$result = $conn->query(
    "SELECT COUNT(*) AS total FROM items WHERE status = 'found'"
);
$totalFound = $result->fetch_assoc()["total"];

// Count claims
$result = $conn->query(
    "SELECT COUNT(*) AS total FROM claims"
);
$totalClaims = $result->fetch_assoc()["total"];

$trendResult = $conn->query(
    "SELECT
        SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) AS lost_reports,
        SUM(CASE WHEN status = 'found' THEN 1 ELSE 0 END) AS found_reports
     FROM items
     WHERE created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY)"
);
$monthlyTrend = $trendResult ? $trendResult->fetch_assoc() : ["lost_reports" => 0, "found_reports" => 0];
$pendingResult = $conn->query("SELECT COUNT(*) AS total FROM claims WHERE claim_status = 'pending'");
$pendingClaims = $pendingResult ? (int) $pendingResult->fetch_assoc()["total"] : 0;
$popularCategoryResult = $conn->query(
    "SELECT category, COUNT(*) AS total FROM items
     WHERE category IS NOT NULL AND category <> ''
     GROUP BY category ORDER BY total DESC LIMIT 1"
);
$popularCategory = $popularCategoryResult ? $popularCategoryResult->fetch_assoc() : null;
$feedbackSummaryResult = $conn->query(
    "SELECT COUNT(*) AS total_feedback, ROUND(AVG(rating), 1) AS average_rating
     FROM claim_experience_feedback"
);
$feedbackSummary = $feedbackSummaryResult ? $feedbackSummaryResult->fetch_assoc() : ["total_feedback" => 0, "average_rating" => null];
$recentFeedbackResult = $conn->query(
    "SELECT feedback.rating, feedback.message, feedback.created_at,
            users.full_name, items.item_name
     FROM claim_experience_feedback AS feedback
     INNER JOIN users ON users.user_id = feedback.user_id
     INNER JOIN claims ON claims.claim_id = feedback.claim_id
     INNER JOIN items ON items.item_id = claims.item_id
     ORDER BY feedback.created_at DESC LIMIT 8"
);

// Get recently reported items
$result = $conn->query(
    "SELECT items.item_id, items.item_name, items.status,
            items.location, items.created_at, users.full_name
     FROM items
     INNER JOIN users ON items.user_id = users.user_id
     ORDER BY items.created_at DESC
     LIMIT 10"
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrator Dashboard</title>

<link rel="stylesheet" href="css/style.css?v=theme-20261014-12">
<script src="navigation.js" defer></script>

</head>
<body class="admin-theme">
<?php require __DIR__ . "/site_header.php"; ?>
<main class="dashboard-container">
    <section class="dashboard-hero">
        <div>
            <p class="eyebrow">Operations Center</p>
            <h1>Administrator Dashboard</h1>
            <p class="dashboard-welcome">Welcome back, <?= htmlspecialchars($_SESSION["full_name"]) ?>. Here's what's happening across the platform.</p>
        </div>
    </section>

    <nav class="dashboard-links" aria-label="Administrator navigation">
        <a href="settings.php#profile-settings" class="button button-secondary">Settings</a>
        <a href="manage_items.php" class="button">Manage Items</a>
        <a href="manage_claims.php" class="button">Manage Claims</a>
        <a href="manage_users.php" class="button">Manage Users</a>
        <a href="system_activity_logs.php" class="button button-secondary">Activity Logs</a>
        <a href="admin_backup.php" class="button button-secondary">Database Backup</a>
        <a href="index.php" class="button button-secondary">View Website</a>
        <a href="logout.php" class="button button-ghost">Logout</a>
    </nav>

    <section class="dashboard-section">
        <div class="section-header">
            <p class="eyebrow">At a glance</p>
            <h2>System Overview</h2>
        </div>

    <div class="dashboard-cards">

        <a class="dashboard-card" href="manage_users.php" aria-label="View <?= (int) $totalUsers ?> registered users">
            <h3>Registered Users</h3>
            <p class="dashboard-number"><?= $totalUsers ?></p>
        </a>

        <a class="dashboard-card" href="lost_items.php" aria-label="View <?= (int) $totalLost ?> lost items">
            <h3>Lost Items</h3>
            <p class="dashboard-number"><?= $totalLost ?></p>
        </a>

        <a class="dashboard-card" href="found_items.php" aria-label="View <?= (int) $totalFound ?> found items">
            <h3>Found Items</h3>
            <p class="dashboard-number"><?= $totalFound ?></p>
        </a>

        <a class="dashboard-card" href="manage_claims.php" aria-label="View <?= (int) $totalClaims ?> total claims">
            <h3>Total Claims</h3>
            <p class="dashboard-number"><?= $totalClaims ?></p>
        </a>

        <a class="dashboard-card dashboard-card-alert" href="manage_claims.php#pending" aria-label="Review <?= (int) $pendingClaims ?> pending claims">
            <h3>Pending Claims</h3>
            <p class="dashboard-number"><?= $pendingClaims ?></p>
        </a>

    </div>
    </section>

    <section class="dashboard-section">
        <div class="section-header">
            <p class="eyebrow">Private to administrators</p>
            <h2>Return Experience Feedback</h2>
        </div>
        <p>
            <?= (int) $feedbackSummary["total_feedback"] ?> feedback entries;
            average rating <?= $feedbackSummary["average_rating"] === null ? "not rated yet" : htmlspecialchars((string) $feedbackSummary["average_rating"]) . " / 5" ?>.
            Feedback comments are not published.
        </p>
        <?php if ($recentFeedbackResult && $recentFeedbackResult->num_rows > 0): ?>
            <ul class="settings-activity-list">
                <?php while ($feedbackRow = $recentFeedbackResult->fetch_assoc()): ?>
                    <li>
                        <strong><?= (int) $feedbackRow["rating"] ?>/5 · <?= htmlspecialchars($feedbackRow["item_name"]) ?></strong>
                        <span>From <?= htmlspecialchars($feedbackRow["full_name"]) ?><?= trim((string) $feedbackRow["message"]) !== "" ? ": " . htmlspecialchars($feedbackRow["message"]) : "" ?></span>
                        <time datetime="<?= htmlspecialchars(date(DATE_ATOM, strtotime($feedbackRow["created_at"]))) ?>"><?= htmlspecialchars($feedbackRow["created_at"]) ?></time>
                    </li>
                <?php endwhile; ?>
            </ul>
        <?php else: ?>
            <p>No return feedback has been submitted yet.</p>
        <?php endif; ?>
    </section>

    <section class="dashboard-section">
        <div class="section-header">
            <p class="eyebrow">Local assistant summary</p>
            <h2>Recent Report Trends</h2>
        </div>
        <p>
            In the last 30 days, users submitted
            <?= (int) ($monthlyTrend["lost_reports"] ?? 0) ?> lost reports and
            <?= (int) ($monthlyTrend["found_reports"] ?? 0) ?> found reports.
            There are <?= $pendingClaims ?> claims awaiting administrator review.
            <?php if ($popularCategory): ?>
                The most frequently reported category is <?= htmlspecialchars($popularCategory["category"]) ?>
                (<?= (int) $popularCategory["total"] ?> reports overall).
            <?php else: ?>
                No item category trends are available yet.
            <?php endif; ?>
        </p>
        <p class="helper-text">This is a local data summary, not a prediction or an AI decision.</p>
    </section>

    <section class="dashboard-section">
        <div class="section-header">
            <p class="eyebrow">Latest activity</p>
            <h2>Recently Reported Items</h2>
        </div>

    <?php if ($result && $result->num_rows > 0): ?>

        <table>
            <thead>
                <tr>
                    <th>Item ID</th>
                    <th>Item Name</th>
                    <th>Status</th>
                    <th>Location</th>
                    <th>Reported By</th>
                    <th>Date Reported</th>
                </tr>
            </thead>

            <tbody>
                <?php while ($item = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= (int) $item["item_id"] ?></td>
                        <td><?= htmlspecialchars($item["item_name"]) ?></td>
                        <td><?= htmlspecialchars($item["status"]) ?></td>
                        <td><?= htmlspecialchars($item["location"] ?? "") ?></td>
                        <td><?= htmlspecialchars($item["full_name"]) ?></td>
                        <td><?= htmlspecialchars($item["created_at"]) ?></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>

    <?php else: ?>

        <p>No items have been reported yet.</p>

    <?php endif; ?>
    </section>
</main>
<?php require __DIR__ . "/site_footer.php"; ?>
</body>
</html>
<?php
$conn->close();
?>
