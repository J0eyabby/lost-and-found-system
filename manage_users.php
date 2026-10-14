
<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once "db.php";
require_once __DIR__ . "/activity_log.php";

// Allow only logged-in administrators
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

// Verify the administrator's role from the database
$admin_id = $_SESSION["user_id"];

$stmt = $conn->prepare(
    "SELECT role FROM users WHERE user_id = ?"
);
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$stmt->bind_result($role);
$userExists = $stmt->fetch();
$stmt->close();

if (!$userExists || $role !== "admin") {
    http_response_code(403);
    exit("Access denied. Administrators only.");
}

if (empty($_SESSION["manage_users_csrf_token"])) {
    $_SESSION["manage_users_csrf_token"] = bin2hex(random_bytes(32));
}
$message = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";
    $targetId = filter_input(INPUT_POST, "user_id", FILTER_VALIDATE_INT);
    $active = (int) ($_POST["is_active"] ?? 0) === 1 ? 1 : 0;
    $suspensionReason = trim((string) ($_POST["suspension_reason"] ?? ""));
    if (!is_string($token) || !hash_equals($_SESSION["manage_users_csrf_token"], $token)) {
        $message = "Your session expired. Refresh the page and try again.";
    } elseif (!$targetId || $targetId < 1 || $targetId === (int) $admin_id) {
        $message = "You cannot suspend your own administrator account.";
    } else {
        $target = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
        $target->bind_param("i", $targetId);
        $target->execute();
        $target->bind_result($targetRole);
        $targetExists = $target->fetch();
        $target->close();
        if (!$targetExists || $targetRole === "admin") {
            $message = "Only existing non-administrator accounts can be suspended.";
        } elseif (!$active && ($suspensionReason === "" || strlen($suspensionReason) > 500)) {
            $message = "Provide a clear suspension reason of up to 500 characters.";
        } else {
            $statusUpdate = $conn->prepare(
                "INSERT INTO user_account_status (user_id, is_active, suspension_reason) VALUES (?, ?, NULLIF(?, ''))
                 ON DUPLICATE KEY UPDATE is_active = VALUES(is_active),
                 suspension_reason = VALUES(suspension_reason)"
            );
            $reasonValue = $active ? "" : $suspensionReason;
            $statusUpdate->bind_param("iis", $targetId, $active, $reasonValue);
            if ($statusUpdate->execute()) {
                recordActivity($conn, (int) $admin_id, $active ? "account_reactivated" : "account_suspended", "User ID " . $targetId);
                $message = $active ? "Account reactivated." : "Account suspended.";
            } else {
                $message = "Unable to update this account.";
            }
            $statusUpdate->close();
        }
    }
}

// Retrieve registered users without exposing passwords
$sql = "SELECT users.user_id, users.full_name, users.email, users.phone,
               users.role, users.created_at,
               COALESCE(user_account_status.is_active, 1) AS is_active,
               user_account_status.suspension_reason
        FROM users
        LEFT JOIN user_account_status ON user_account_status.user_id = users.user_id
        ORDER BY users.created_at DESC";

$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Registered Users</title>

<link rel="stylesheet" href="css/style.css?v=theme-20261014-10">
<script src="navigation.js" defer></script>

</head>
<body class="admin-theme">
<?php require __DIR__ . "/site_header.php"; ?>

    <main class="admin-page">
        <header class="admin-page-header">
            <div>
                <p class="eyebrow">Operations / Directory</p>
                <h1>Manage Registered Users</h1>
            </div>
            <nav class="admin-nav" aria-label="Administrator navigation">
                <a href="admin.php" class="button button-small">Dashboard</a>
                <a href="settings.php#profile-settings" class="button button-small button-secondary">Settings</a>
                <a href="manage_items.php" class="button button-small button-secondary">Items</a>
                <a href="manage_claims.php" class="button button-small button-secondary">Claims</a>
                <a href="dashboard.php" class="button button-small button-secondary">Dashboard</a>
                <a href="index.php" class="button button-small button-secondary">View Site</a>
                <a href="logout.php" class="button button-small button-ghost">Logout</a>
            </nav>
        </header>

    <section class="admin-panel">
        <?php if ($message !== ""): ?><p class="feedback-banner" role="status"><?= htmlspecialchars($message, ENT_QUOTES, "UTF-8") ?></p><?php endif; ?>
        <div class="section-header">
            <p class="eyebrow">Directory</p>
            <h2>Registered Accounts</h2>
        </div>

    <?php if ($result && $result->num_rows > 0): ?>

        <table border="1" cellpadding="8" cellspacing="0">
            <thead>
                <tr>
                    <th>User ID</th>
                    <th>Full Name</th>
                    <th>Email Address</th>
                    <th>Phone Number</th>
                    <th>Role</th>
                    <th>Account status</th>
                    <th>Restriction reason</th>
                    <th>Registration Date</th>
                    <th>Actions</th>
                </tr>
            </thead>

            <tbody>
                <?php while ($user = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= (int) $user["user_id"] ?></td>

                        <td>
                            <?= htmlspecialchars($user["full_name"]) ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($user["email"]) ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($user["phone"] ?? "Not provided") ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($user["role"]) ?>
                        </td>
                        <td><?= (int) $user["is_active"] ? "Active" : "Suspended" ?></td>
                        <td><?= (int) $user["is_active"] ? "—" : htmlspecialchars($user["suspension_reason"] ?? "Reason not recorded", ENT_QUOTES, "UTF-8") ?></td>

                        <td>
                            <?= htmlspecialchars($user["created_at"]) ?>
                        </td>
                        <td>
                            <?php if ($user["role"] !== "admin" && (int) $user["user_id"] !== (int) $admin_id): ?>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["manage_users_csrf_token"], ENT_QUOTES, "UTF-8") ?>">
                                    <input type="hidden" name="user_id" value="<?= (int) $user["user_id"] ?>">
                                    <input type="hidden" name="is_active" value="<?= (int) !$user["is_active"] ?>">
                                    <?php if ((int) $user["is_active"]): ?>
                                        <label for="suspension-reason-<?= (int) $user["user_id"] ?>">Reason for suspension</label>
                                        <textarea id="suspension-reason-<?= (int) $user["user_id"] ?>" name="suspension_reason" rows="2" maxlength="500" required></textarea>
                                        <button class="button button-small button-danger" type="submit">Suspend</button>
                                    <?php else: ?>
                                        <button class="button button-small button-secondary" type="submit">Reactivate</button>
                                    <?php endif; ?>
                                </form>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>

    <?php else: ?>

        <p>No registered users found.</p>

    <?php endif; ?>
    </section>
    </main>
    <?php require __DIR__ . "/site_footer.php"; ?>
</body>
</html>
<?php
$conn->close();
?>
