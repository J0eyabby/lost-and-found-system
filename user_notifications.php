<?php
require_once __DIR__ . "/mail_notifications.php";

function getUserSettings(mysqli $conn, int $userId): array
{
    $stmt = $conn->prepare(
        "SELECT notification_email_claims, notification_email_items,
                notification_web_claims, notification_web_items,
                preferred_categories, preferred_location, theme
         FROM user_settings WHERE user_id = ?"
    );
    if ($stmt === false) {
        throw new RuntimeException("Unable to prepare account settings query: " . $conn->error);
    }
    $stmt->bind_param("i", $userId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException("Unable to load account settings: " . $error);
    }
    $settings = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $settings;
}

function addUserNotification(mysqli $conn, int $userId, string $kind, string $message): bool
{
    if (!in_array($kind, ["claims", "items"], true)) {
        throw new InvalidArgumentException("Invalid notification kind.");
    }
    $settings = getUserSettings($conn, $userId);
    $enabledKey = $kind === "claims" ? "notification_web_claims" : "notification_web_items";
    if (isset($settings[$enabledKey]) && (int) $settings[$enabledKey] === 0) {
        return true;
    }
    $stmt = $conn->prepare(
        "INSERT INTO user_notifications (user_id, kind, message) VALUES (?, ?, ?)"
    );
    if ($stmt === false) {
        error_log("Unable to prepare website notification: " . $conn->error);
        return false;
    }
    $stmt->bind_param("iss", $userId, $kind, $message);
    $saved = $stmt->execute();
    if (!$saved) {
        error_log("Unable to save website notification: " . $stmt->error);
    }
    $stmt->close();
    return $saved;
}

function notifyAdministratorsAboutClaim(mysqli $conn, int $claimId, string $itemName, string $event): bool
{
    $admins = $conn->query(
        "SELECT users.user_id
         FROM users
         LEFT JOIN user_account_status ON user_account_status.user_id = users.user_id
         LEFT JOIN user_settings ON user_settings.user_id = users.user_id
         WHERE users.role = 'admin'
           AND COALESCE(user_account_status.is_active, 1) = 1
           AND COALESCE(user_settings.notification_web_claims, 1) = 1"
    );
    if ($admins === false) {
        error_log("Unable to load administrators for claim notification: " . $conn->error);
        return false;
    }
    $message = $event . " for " . $itemName . " (claim #" . $claimId . ").";
    $kind = "claims";
    $notification = $conn->prepare(
        "INSERT INTO user_notifications (user_id, kind, message) VALUES (?, ?, ?)"
    );
    if ($notification === false) {
        error_log("Unable to prepare administrator claim notification: " . $conn->error);
        $admins->free();
        return false;
    }
    $allNotificationsSaved = true;
    while ($admin = $admins->fetch_assoc()) {
        $adminId = (int) $admin["user_id"];
        $notification->bind_param("iss", $adminId, $kind, $message);
        if (!$notification->execute()) {
            error_log("Unable to save administrator claim notification: " . $notification->error);
            $allNotificationsSaved = false;
        }
    }
    $notification->close();
    $admins->free();
    return $allNotificationsSaved;
}

function userAllowsEmailNotification(mysqli $conn, int $userId, string $kind): bool
{
    if (!in_array($kind, ["claims", "items"], true)) {
        throw new InvalidArgumentException("Invalid notification kind.");
    }
    $settings = getUserSettings($conn, $userId);
    $enabledKey = $kind === "claims" ? "notification_email_claims" : "notification_email_items";
    return !isset($settings[$enabledKey]) || (int) $settings[$enabledKey] === 1;
}

function notifyUsersAboutItem(
    mysqli $conn,
    int $reporterId,
    string $itemName,
    string $category,
    string $location
): void {
    $recipients = $conn->prepare(
        "SELECT users.user_id, users.email, user_settings.preferred_categories,
                user_settings.preferred_location
         FROM user_settings
         INNER JOIN users ON users.user_id = user_settings.user_id
         LEFT JOIN user_account_status ON user_account_status.user_id = users.user_id
         WHERE users.user_id <> ?
           AND COALESCE(user_account_status.is_active, 1) = 1
           AND (user_settings.notification_email_items = 1 OR user_settings.notification_web_items = 1)
         ORDER BY users.user_id DESC
         LIMIT 500"
    );
    if ($recipients === false) {
        error_log("Unable to prepare item-match notification query: " . $conn->error);
        return;
    }
    $recipients->bind_param("i", $reporterId);
    if (!$recipients->execute()) {
        error_log("Unable to query item-match notification recipients: " . $recipients->error);
        $recipients->close();
        return;
    }
    $result = $recipients->get_result();
    while ($recipient = $result->fetch_assoc()) {
        $preferredCategories = json_decode((string) $recipient["preferred_categories"], true);
        $preferredCategories = is_array($preferredCategories) ? $preferredCategories : [];
        $preferredLocation = trim((string) $recipient["preferred_location"]);
        if ($preferredCategories === [] && $preferredLocation === "") {
            continue;
        }
        if ($preferredCategories !== [] && !in_array($category, $preferredCategories, true)) {
            continue;
        }
        if ($preferredLocation !== "" && ($location === "" || stripos($location, $preferredLocation) === false)) {
            continue;
        }
        $userId = (int) $recipient["user_id"];
        addUserNotification($conn, $userId, "items", "A new listing may match your preferences: " . $itemName . ".");
        if (userAllowsEmailNotification($conn, $userId, "items")) {
            $body = "A new listing may match your preferred categories or location: {$itemName}.\n\n"
                . "Sign in to the Lost and Found site to browse recent listings.";
            sendCustomerNotification($recipient["email"], "A possible item match", $body);
        }
    }
    $recipients->close();
}
