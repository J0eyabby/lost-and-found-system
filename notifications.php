<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/account_guard.php";
enforceActiveAccount($conn);

$userId = (int) ($_SESSION["user_id"] ?? 0);
if ($userId < 1) {
    header("Location: login.php?next=" . rawurlencode("notifications.php"));
    exit;
}

$roleQuery = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
$roleQuery->bind_param("i", $userId);
$roleQuery->execute();
$role = (string) ($roleQuery->get_result()->fetch_assoc()["role"] ?? "");
$roleQuery->close();

if (empty($_SESSION["notifications_csrf_token"])) {
    $_SESSION["notifications_csrf_token"] = bin2hex(random_bytes(32));
}

$message = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";
    $action = $_POST["action"] ?? "";
    if (!is_string($token) || !hash_equals($_SESSION["notifications_csrf_token"], $token)) {
        $message = "Your session expired. Refresh the page and try again.";
    } elseif ($action === "mark_all_read") {
        $markRead = $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE user_id = ?");
        $markRead->bind_param("i", $userId);
        if ($markRead->execute()) {
            $message = "All notifications marked as read.";
        } else {
            $message = "Unable to update notifications. Please try again.";
        }
        $markRead->close();
    } elseif ($action === "mark_read") {
        $notificationId = filter_input(INPUT_POST, "notification_id", FILTER_VALIDATE_INT);
        if (!$notificationId || $notificationId < 1) {
            $message = "Select a valid notification.";
        } else {
            $markRead = $conn->prepare(
                "UPDATE user_notifications SET is_read = 1
                 WHERE notification_id = ? AND user_id = ?"
            );
            $markRead->bind_param("ii", $notificationId, $userId);
            if ($markRead->execute()) {
                $message = "Notification marked as read.";
            } else {
                $message = "Unable to update the notification. Please try again.";
            }
            $markRead->close();
        }
    } elseif ($action === "view") {
        $notificationId = filter_input(INPUT_POST, "notification_id", FILTER_VALIDATE_INT);
        if (!$notificationId || $notificationId < 1) {
            $message = "Select a valid notification.";
        } else {
            $notificationQuery = $conn->prepare(
                "SELECT kind, message FROM user_notifications
                 WHERE notification_id = ? AND user_id = ?"
            );
            $notificationQuery->bind_param("ii", $notificationId, $userId);
            $notificationQuery->execute();
            $notification = $notificationQuery->get_result()->fetch_assoc();
            $notificationQuery->close();
            if (!$notification) {
                $message = "This notification is no longer available.";
            } else {
                $markRead = $conn->prepare(
                    "UPDATE user_notifications SET is_read = 1
                     WHERE notification_id = ? AND user_id = ?"
                );
                $markRead->bind_param("ii", $notificationId, $userId);
                if (!$markRead->execute()) {
                    $markRead->close();
                    $message = "Unable to update the notification. Please try again.";
                } else {
                    $markRead->close();
                    header("Location: " . notificationDestination(
                        (string) $notification["kind"],
                        $role,
                        (string) $notification["message"]
                    ));
                    exit;
                }
            }
        }
    } else {
        $message = "Choose a valid notification action.";
    }
}

$notificationsQuery = $conn->prepare(
    "SELECT notification_id, kind, message, is_read, created_at
     FROM user_notifications WHERE user_id = ?
     ORDER BY created_at DESC, notification_id DESC LIMIT 100"
);
$notificationsQuery->bind_param("i", $userId);
$notificationsQuery->execute();
$notifications = $notificationsQuery->get_result()->fetch_all(MYSQLI_ASSOC);
$notificationsQuery->close();

function notificationsEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

function notificationDestination(string $kind, string $role, string $message): string
{
    if ($kind === "claims") {
        if ($role === "admin") {
            $page = "manage_claims.php";
        } elseif (str_starts_with($message, "A possible owner submitted") ||
            str_starts_with($message, "The claimant provided additional information")) {
            $page = "my_items.php";
        } else {
            $page = "my_claims.php";
        }
        if (preg_match('/claim #(\d+)/i', $message, $matches) === 1) {
            return $page . "#claim-" . (int) $matches[1];
        }
        return $page;
    }
    return "index.php";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Notifications - Lost and Found</title>
    <link rel="stylesheet" href="css/style.css?v=20261014">
</head>
<body>
<?php require __DIR__ . "/site_header.php"; ?>
<main class="container notifications-page">
    <header class="section-header notifications-heading">
        <div>
            <p class="eyebrow">Account updates</p>
            <h1>Notifications</h1>
        </div>
        <?php if ($notifications !== []): ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= notificationsEscape($_SESSION["notifications_csrf_token"]) ?>">
                <input type="hidden" name="action" value="mark_all_read">
                <button class="button button-small button-secondary" type="submit">Mark all as read</button>
            </form>
        <?php endif; ?>
    </header>
    <?php if ($message !== ""): ?>
        <p class="feedback-banner" role="status"><?= notificationsEscape($message) ?></p>
    <?php endif; ?>
    <?php if ($notifications === []): ?>
        <p class="empty-message">You have no notifications yet.</p>
    <?php else: ?>
        <div class="notification-list">
            <?php foreach ($notifications as $notification): ?>
                <article class="notification-card <?= (int) $notification["is_read"] === 1 ? "is-read" : "is-unread" ?>">
                    <div class="notification-card-content">
                        <span class="notification-kind"><?= notificationsEscape(ucfirst((string) $notification["kind"])) ?></span>
                        <p><?= notificationsEscape($notification["message"]) ?></p>
                        <time datetime="<?= notificationsEscape(date(DATE_ATOM, strtotime((string) $notification["created_at"]))) ?>">
                            <?= notificationsEscape($notification["created_at"]) ?>
                        </time>
                    </div>
                    <div class="notification-card-actions">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= notificationsEscape($_SESSION["notifications_csrf_token"]) ?>">
                            <input type="hidden" name="action" value="view">
                            <input type="hidden" name="notification_id" value="<?= (int) $notification["notification_id"] ?>">
                            <button class="button button-small button-secondary" type="submit">View</button>
                        </form>
                        <?php if ((int) $notification["is_read"] === 0): ?>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= notificationsEscape($_SESSION["notifications_csrf_token"]) ?>">
                                <input type="hidden" name="action" value="mark_read">
                                <input type="hidden" name="notification_id" value="<?= (int) $notification["notification_id"] ?>">
                                <button class="button button-small button-ghost" type="submit">Mark read</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
<?php require __DIR__ . "/site_footer.php"; ?>
</body>
</html>
