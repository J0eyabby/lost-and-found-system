<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/account_guard.php";
require_once __DIR__ . "/activity_log.php";
enforceActiveAccount($conn);

$userId = (int) ($_SESSION["user_id"] ?? 0);
$csrfKey = "settings_csrf_token";
if (empty($_SESSION[$csrfKey])) {
    $_SESSION[$csrfKey] = bin2hex(random_bytes(32));
}
if (empty($_SESSION["profile_csrf_token"])) {
    $_SESSION["profile_csrf_token"] = bin2hex(random_bytes(32));
}
if ($userId > 0) {
    $initializeSettings = $conn->prepare("INSERT IGNORE INTO user_settings (user_id) VALUES (?)");
    $initializeSettings->bind_param("i", $userId);
    $initializeSettings->execute();
    $initializeSettings->close();
}
$languages = [
    "en" => "English",
    "sw" => "Kiswahili",
    "fr" => "FranÃ§ais",
    "ar" => "Ø§Ù„Ø¹Ø±Ø¨ÙŠØ©",
];
$message = "";
$messageType = "error";
$activeSettingsPanel = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";
    $action = $_POST["action"] ?? "";
    $activeSettingsPanel = match ($action) {
        "notifications", "mark_notifications_read" => "notifications",
        "language" => "language",
        "appearance" => "appearance",
        "account_preferences" => "search-preferences",
        "feedback" => "feedback",
        default => "",
    };
    if (!is_string($token) || !hash_equals($_SESSION[$csrfKey], $token)) {
        $message = "Your session expired. Please reload the page and try again.";
    } elseif ($action === "language") {
        $language = $_POST["language"] ?? "";
        if (!is_string($language) || !isset($languages[$language])) {
            $message = "Choose a language from the available options.";
        } elseif ($userId > 0) {
            $save = $conn->prepare("UPDATE users SET preferred_language = ? WHERE user_id = ?");
            $save->bind_param("si", $language, $userId);
            if ($save->execute()) {
                $_SESSION["preferred_language"] = $language;
                recordActivity($conn, $userId, "language_preference_updated", $language);
                $message = "Your language preference has been saved.";
                $messageType = "success";
            } else {
                $message = "Unable to save your language preference.";
            }
            $save->close();
        } else {
            $_SESSION["preferred_language"] = $language;
            $message = "Your language preference is saved for this browser session.";
            $messageType = "success";
        }
    } elseif ($action === "notifications" && $userId > 0) {
        $emailClaims = isset($_POST["email_claims"]) ? 1 : 0;
        $emailItems = isset($_POST["email_items"]) ? 1 : 0;
        $webClaims = isset($_POST["web_claims"]) ? 1 : 0;
        $webItems = isset($_POST["web_items"]) ? 1 : 0;
        $save = $conn->prepare(
            "UPDATE user_settings SET notification_email_claims = ?,
             notification_email_items = ?, notification_web_claims = ?,
             notification_web_items = ? WHERE user_id = ?"
        );
        $save->bind_param("iiiii", $emailClaims, $emailItems, $webClaims, $webItems, $userId);
        if ($save->execute()) {
            recordActivity($conn, $userId, "notification_preferences_updated", "Claims and item update channels");
            $message = "Notification preferences saved.";
            $messageType = "success";
        } else {
            $message = "Unable to save notification preferences.";
        }
        $save->close();
    } elseif ($action === "appearance" && $userId > 0) {
        $theme = $_POST["theme"] ?? "";
        if (!is_string($theme) || !in_array($theme, ["light", "dark"], true)) {
            $message = "Choose a valid website theme.";
        } else {
            $save = $conn->prepare("UPDATE user_settings SET theme = ? WHERE user_id = ?");
            $save->bind_param("si", $theme, $userId);
            if ($save->execute()) {
                $_SESSION["site_theme"] = $theme;
                recordActivity($conn, $userId, "appearance_preference_updated", $theme);
                $message = "Appearance preferences saved.";
                $messageType = "success";
            } else {
                $message = "Unable to save appearance preferences.";
            }
            $save->close();
        }
    } elseif ($action === "account_preferences" && $userId > 0) {
        $selectedCategories = $_POST["preferred_categories"] ?? [];
        $preferredLocation = trim($_POST["preferred_location"] ?? "");
        $validCategories = [];
        $categoryResult = $conn->query("SELECT name FROM item_categories WHERE is_active = 1");
        while ($categoryRow = $categoryResult->fetch_assoc()) {
            $validCategories[] = $categoryRow["name"];
        }
        if (!is_array($selectedCategories) || strlen($preferredLocation) > 150) {
            $message = "Please select valid categories and a location of up to 150 characters.";
        } else {
            $selectedCategories = array_values(array_unique(array_filter(
                $selectedCategories,
                static fn ($category): bool => is_string($category) && in_array($category, $validCategories, true)
            )));
            $categoriesJson = json_encode($selectedCategories, JSON_THROW_ON_ERROR);
            $save = $conn->prepare("UPDATE user_settings SET preferred_categories = ?, preferred_location = ? WHERE user_id = ?");
            $save->bind_param("ssi", $categoriesJson, $preferredLocation, $userId);
            if ($save->execute()) {
                recordActivity($conn, $userId, "account_preferences_updated", "Preferred categories and location");
                $message = "Account preferences saved.";
                $messageType = "success";
            } else {
                $message = "Unable to save account preferences.";
            }
            $save->close();
        }
    } elseif ($action === "mark_notifications_read" && $userId > 0) {
        $markRead = $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE user_id = ?");
        $markRead->bind_param("i", $userId);
        if ($markRead->execute()) {
            $message = "Website notifications marked as read.";
            $messageType = "success";
        } else {
            $message = "Unable to update website notifications.";
        }
        $markRead->close();
    } elseif ($action === "feedback") {
        $category = trim($_POST["category"] ?? "");
        $feedback = trim($_POST["feedback"] ?? "");
        $allowedCategories = ["suggestion", "problem", "other"];
        if (!in_array($category, $allowedCategories, true) || $feedback === "" || strlen($feedback) > 5000) {
            $message = "Choose a feedback type and enter a message of up to 5,000 characters.";
        } else {
            $save = $conn->prepare("INSERT INTO site_feedback (user_id, category, message) VALUES (NULLIF(?, 0), ?, ?)");
            $save->bind_param("iss", $userId, $category, $feedback);
            if ($save->execute()) {
                $message = "Thank you. Your feedback has been received.";
                $messageType = "success";
            } else {
                error_log("Unable to save site feedback: " . $save->error);
                $message = "Unable to submit your feedback right now.";
            }
            $save->close();
        }
    } else {
        $message = "Invalid settings request.";
    }
}

$language = $_SESSION["preferred_language"] ?? "en";
$profileSummary = null;
$userSettings = [
    "notification_email_claims" => 1,
    "notification_email_items" => 1,
    "notification_web_claims" => 1,
    "notification_web_items" => 1,
    "preferred_categories" => "[]",
    "preferred_location" => "",
    "theme" => "light",
];
$categoryOptions = [];
$notifications = [];
$recentActivity = [];
$siteContactEmail = "";
$siteContactQuery = $conn->query("SELECT setting_value FROM site_settings WHERE setting_key = 'contact_email'");
if ($siteContactQuery && ($contactRow = $siteContactQuery->fetch_assoc())) {
    $siteContactEmail = $contactRow["setting_value"];
}
$categoryResult = $conn->query("SELECT name FROM item_categories WHERE is_active = 1 ORDER BY name");
while ($categoryRow = $categoryResult->fetch_assoc()) {
    $categoryOptions[] = $categoryRow["name"];
}
$settingsMessages = [
    "Your language preference has been saved." => "language_saved",
    "Your language preference is saved for this browser session." => "language_saved_session",
    "Thank you. Your feedback has been received." => "feedback_received",
    "Your session expired. Please reload the page and try again." => "session_expired",
];
$messageTranslationKey = $settingsMessages[$message] ?? "";
if ($userId > 0) {
    $preferenceQuery = $conn->prepare("SELECT preferred_language FROM users WHERE user_id = ?");
    $preferenceQuery->bind_param("i", $userId);
    $preferenceQuery->execute();
    $preferenceQuery->bind_result($savedLanguage);
    if ($preferenceQuery->fetch() && isset($languages[$savedLanguage])) {
        $language = $savedLanguage;
    }
    $preferenceQuery->close();
    $profileQuery = $conn->prepare(
        "SELECT full_name, email, phone, profile_photo FROM users WHERE user_id = ?"
    );
    $profileQuery->bind_param("i", $userId);
    $profileQuery->execute();
    $profileSummary = $profileQuery->get_result()->fetch_assoc();
    $profileQuery->close();
    $settingsQuery = $conn->prepare("SELECT notification_email_claims, notification_email_items, notification_web_claims, notification_web_items, preferred_categories, preferred_location, theme FROM user_settings WHERE user_id = ?");
    $settingsQuery->bind_param("i", $userId);
    $settingsQuery->execute();
    $userSettings = array_merge($userSettings, $settingsQuery->get_result()->fetch_assoc() ?: []);
    $settingsQuery->close();
    $notificationQuery = $conn->prepare("SELECT kind, message, is_read, created_at FROM user_notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
    $notificationQuery->bind_param("i", $userId);
    $notificationQuery->execute();
    $notifications = $notificationQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $notificationQuery->close();
    $activityQuery = $conn->prepare(
        "SELECT action, details, created_at FROM system_activity_logs
         WHERE actor_user_id = ? ORDER BY created_at DESC LIMIT 10"
    );
    $activityQuery->bind_param("i", $userId);
    $activityQuery->execute();
    $recentActivity = $activityQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $activityQuery->close();
}
$preferredCategories = json_decode((string) $userSettings["preferred_categories"], true);
if (!is_array($preferredCategories)) {
    $preferredCategories = [];
}
function settingsEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="<?= settingsEscape($language) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Settings - Lost and Found</title>
    <link rel="stylesheet" href="css/style.css?v=20261014">
</head>
<body data-settings-panel="<?= settingsEscape($activeSettingsPanel) ?>">
<?php require __DIR__ . "/site_header.php"; ?>
<main class="container settings-page">
    <p class="eyebrow" data-i18n="preferences_support">Preferences and support</p>
    <h1 data-i18n="settings">Settings</h1>
    <nav class="settings-menu" aria-label="Settings menu">
        <ol>
            <li class="settings-menu-group">My Account</li>
            <li><a href="#profile-settings" data-settings-panel="profile-settings"><strong>Profile settings</strong><span>Update your name and contact details.</span></a></li>
            <li><a href="#profile-photo" data-settings-panel="profile-settings"><strong>Profile picture</strong><span>Upload, change, or remove your profile photo.</span></a></li>
            <li><a href="#email-settings" data-settings-panel="email-settings"><strong>Email settings</strong><span>Manage your contact email and email alerts.</span></a></li>
            <li><a href="#privacy-security" data-settings-panel="privacy-security"><strong>Change password</strong><span>Update your account password securely.</span></a></li>
            <li><a href="#privacy-security" data-settings-panel="privacy-security"><strong>Privacy and security</strong><span>Review how your private information is used.</span></a></li>

            <li class="settings-menu-group">Preferences</li>
            <li><a href="#notifications" data-settings-panel="notifications"><strong>Notifications</strong><span>Choose which alerts you receive.</span></a></li>
            <li><a href="#appearance" data-settings-panel="appearance"><strong>Appearance and dark mode</strong><span>Choose a theme that is comfortable to use.</span></a></li>
            <li><a href="#language" data-settings-panel="language"><strong>Language</strong><span>Choose the website display language.</span></a></li>
            <li><a href="#location-preferences" data-settings-panel="search-preferences"><strong>Location</strong><span>Set your preferred town, campus, or area.</span></a></li>
            <li><a href="#accessibility" data-settings-panel="accessibility"><strong>Accessibility</strong><span>Adjust text size and improve readability.</span></a></li>

            <li class="settings-menu-group">My Lost &amp; Found</li>
            <li><a href="my_items.php"><strong>My reports</strong><span>Review your lost and found item reports.</span></a></li>
            <li><a href="my_claims.php"><strong>My claims</strong><span>Check claim decisions and return confirmations.</span></a></li>
            <li><a href="#search-preferences" data-settings-panel="search-preferences"><strong>Search preferences</strong><span>Save preferred categories and location filters.</span></a></li>
            <li><a href="my_claims.php"><strong>Return history</strong><span>Review claims and items that were returned.</span></a></li>

            <li class="settings-menu-group">Support</li>
            <li><a href="#help-support" data-settings-panel="help-support"><strong>Help centre</strong><span>Get help using Lost and Found.</span></a></li>
            <li><a href="#feedback" data-settings-panel="feedback"><strong>Report a problem or send feedback</strong><span>Tell us about an issue or share an idea.</span></a></li>
            <li><a href="terms.php"><strong>Terms and privacy policy</strong><span>Read the rules and how your data is handled.</span></a></li>

            <?php if ($userId > 0): ?>
                <li class="settings-menu-group">Account actions</li>
                <li><a href="#recent-activity" data-settings-panel="recent-activity"><strong>Recent activity</strong><span>See recent reports, claims, and account activity.</span></a></li>
                <li><a href="logout.php"><strong>Log out</strong><span>Securely sign out of your account.</span></a></li>
            <?php endif; ?>
        </ol>
    </nav>
    <?php if ($userId > 0 && $siteRole === "admin"): ?>
        <section class="settings-card settings-admin-menu" aria-labelledby="admin-settings-title">
            <p class="eyebrow">Administrator tools</p>
            <h2 id="admin-settings-title">Admin Settings</h2>
            <nav class="settings-admin-links" aria-label="Administrator settings">
                <a href="manage_users.php"><strong>User Management</strong><span>View, suspend, or manage registered accounts.</span></a>
                <a href="manage_items.php"><strong>Item Management</strong><span>Review, edit, or remove reported items.</span></a>
                <a href="manage_claims.php"><strong>Claim Management</strong><span>Approve or reject claims for found items.</span></a>
                <a href="manage_categories.php"><strong>Category Management</strong><span>Manage item categories.</span></a>
                <a href="admin_site_settings.php"><strong>Website Settings</strong><span>Change the site name, logo, and contact information.</span></a>
                <a href="system_activity_logs.php"><strong>System Activity Logs</strong><span>Review important actions taken by users and administrators.</span></a>
                <a href="admin_backup.php"><strong>Database Backup</strong><span>Download a protected export and verify restoration safely.</span></a>
            </nav>
        </section>
    <?php endif; ?>
    <dialog class="settings-dialog" id="settings-dialog" aria-labelledby="settings-dialog-title" open>
        <header class="settings-dialog-header">
            <h2 id="settings-dialog-title">Settings</h2>
            <button class="settings-dialog-close" type="button" aria-label="Close settings" data-settings-close>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg>
            </button>
        </header>
        <?php if ($message !== ""): ?>
            <p class="feedback-banner <?= settingsEscape($messageType) ?>" role="<?= $messageType === "success" ? "status" : "alert" ?>" <?= $messageTranslationKey !== "" ? 'data-i18n="' . settingsEscape($messageTranslationKey) . '"' : "" ?>><?= settingsEscape($message) ?></p>
        <?php endif; ?>
        <section class="settings-grid">
        <article class="settings-card" id="notifications">
            <p class="eyebrow">Notifications</p>
            <h2>Claims and item updates</h2>
            <?php if ($userId > 0): ?>
                <form method="post" class="stacked-form">
                    <input type="hidden" name="csrf_token" value="<?= settingsEscape($_SESSION[$csrfKey]) ?>">
                    <input type="hidden" name="action" value="notifications">
                    <label class="checkbox-label"><input type="checkbox" name="email_claims" value="1" <?= (int) $userSettings["notification_email_claims"] ? "checked" : "" ?>> <span>Email about claim submissions and decisions</span></label>
                    <label class="checkbox-label"><input type="checkbox" name="email_items" value="1" <?= (int) $userSettings["notification_email_items"] ? "checked" : "" ?>> <span>Email about item updates</span></label>
                    <label class="checkbox-label"><input type="checkbox" name="web_claims" value="1" <?= (int) $userSettings["notification_web_claims"] ? "checked" : "" ?>> <span>Website notifications about claims</span></label>
                    <label class="checkbox-label"><input type="checkbox" name="web_items" value="1" <?= (int) $userSettings["notification_web_items"] ? "checked" : "" ?>> <span>Website notifications about item updates</span></label>
                    <button type="submit">Save Notifications</button>
                </form>
                <?php if ($notifications !== []): ?>
                    <h3>Recent website notifications</h3>
                    <ul class="settings-notifications">
                        <?php foreach ($notifications as $notification): ?>
                            <li class="<?= (int) $notification["is_read"] ? "is-read" : "is-unread" ?>"><time><?= settingsEscape($notification["created_at"]) ?></time> <?= settingsEscape($notification["message"]) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= settingsEscape($_SESSION[$csrfKey]) ?>">
                        <input type="hidden" name="action" value="mark_notifications_read">
                        <button class="button button-small button-secondary" type="submit">Mark all as read</button>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <p>Sign in to manage notification preferences.</p>
                <a class="button button-small" href="login.php">Log in</a>
            <?php endif; ?>
        </article>
        <article class="settings-card" id="privacy-security">
            <p class="eyebrow">Privacy &amp; Security</p>
            <h2>Your information, your choice</h2>
            <p>Your contact details and private item verification clues are not shown on public listings. Returned-item stories are shared only with both people's consent.</p>
            <?php if ($userId > 0): ?>
                <a class="button button-small button-secondary" href="profile.php#account-security">Change password</a>
                <a class="button button-small button-secondary" href="my_items.php">Manage report privacy</a>
                <a class="button button-small button-secondary" href="my_claims.php">Manage claim privacy</a>
            <?php else: ?>
                <a class="button button-small button-secondary" href="login.php">Log in to manage account security</a>
            <?php endif; ?>
        </article>
        <article class="settings-card" id="appearance">
            <p class="eyebrow">Appearance</p>
            <h2>Website theme</h2>
            <?php if ($userId > 0): ?>
                <form method="post" class="stacked-form">
                    <input type="hidden" name="csrf_token" value="<?= settingsEscape($_SESSION[$csrfKey]) ?>">
                    <input type="hidden" name="action" value="appearance">
                    <label for="site-theme">Color theme</label>
                    <select id="site-theme" name="theme">
                        <option value="light" <?= $userSettings["theme"] === "light" ? "selected" : "" ?>>Light</option>
                        <option value="dark" <?= $userSettings["theme"] === "dark" ? "selected" : "" ?>>Dark</option>
                    </select>
                    <button type="submit">Save Appearance</button>
                </form>
            <?php else: ?>
                <p>Log in to save your appearance preferences.</p>
                <a class="button button-small" href="login.php">Log in</a>
            <?php endif; ?>
        </article>
        <article class="settings-card" id="search-preferences">
            <p class="eyebrow">Preferences</p>
            <h2>Search preferences</h2>
            <?php if ($userId > 0): ?>
                <form method="post" class="stacked-form">
                    <input type="hidden" name="csrf_token" value="<?= settingsEscape($_SESSION[$csrfKey]) ?>">
                    <input type="hidden" name="action" value="account_preferences">
                    <p>Choose categories and an area to tailor item-match alerts.</p>
                    <label>Preferred categories</label>
                    <?php foreach ($categoryOptions as $categoryName): ?>
                        <label class="checkbox-label"><input type="checkbox" name="preferred_categories[]" value="<?= settingsEscape($categoryName) ?>" <?= in_array($categoryName, $preferredCategories, true) ? "checked" : "" ?>> <span><?= settingsEscape($categoryName) ?></span></label>
                    <?php endforeach; ?>
                    <label id="location-preferences" for="preferred-location">Preferred location</label>
                    <input id="preferred-location" name="preferred_location" type="text" maxlength="150" value="<?= settingsEscape($userSettings["preferred_location"]) ?>">
                    <button type="submit">Save Preferences</button>
                </form>
            <?php else: ?>
                <p>Log in to save preferred categories and location.</p>
                <a class="button button-small" href="login.php">Log in</a>
            <?php endif; ?>
        </article>
        <article class="settings-card" id="accessibility">
            <p class="eyebrow">Accessibility</p>
            <h2>Text size</h2>
            <p>Choose a larger text size for easier reading. This preference is saved in this browser.</p>
            <label for="text-size-choice">Website text size</label>
            <select id="text-size-choice" data-text-size-setting>
                <option value="normal">Standard</option>
                <option value="large">Large</option>
                <option value="larger">Largest</option>
            </select>
        </article>
        <?php if ($userId > 0): ?>
            <article class="settings-card" id="recent-activity">
                <p class="eyebrow">My Account</p>
                <h2>Recent activity</h2>
                <p>Recent reports, claims, and account actions on your account.</p>
                <?php if ($recentActivity !== []): ?>
                    <ul class="settings-activity-list">
                        <?php foreach ($recentActivity as $activity): ?>
                            <li>
                                <strong><?= settingsEscape(ucwords(str_replace("_", " ", $activity["action"]))) ?></strong>
                                <?php if (trim((string) $activity["details"]) !== ""): ?><span><?= settingsEscape($activity["details"]) ?></span><?php endif; ?>
                                <time datetime="<?= settingsEscape(date(DATE_ATOM, strtotime($activity["created_at"]))) ?>"><?= settingsEscape($activity["created_at"]) ?></time>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p>No recent account activity is available.</p>
                <?php endif; ?>
            </article>
        <?php endif; ?>
        <article class="settings-card" id="email-settings">
            <p class="eyebrow">Email Settings</p>
            <h2>Contact email and alerts</h2>
            <p>Account contact email: <strong><?= settingsEscape($profileSummary["email"] ?? "Sign in to view your email") ?></strong></p>
            <p>Email alerts are controlled in <a href="#notifications">Notifications</a>.</p>
            <?php if ($userId > 0): ?><a class="button button-small button-secondary" href="profile.php#email-address">Change email address</a><?php endif; ?>
            <?php if ($siteContactEmail !== ""): ?><p>Site support: <a href="mailto:<?= settingsEscape($siteContactEmail) ?>"><?= settingsEscape($siteContactEmail) ?></a></p><?php endif; ?>
        </article>
        <article class="settings-card" id="language">
            <p class="eyebrow" data-i18n="language">Language</p>
            <h2 data-i18n="preferred_language">Preferred language</h2>
            <p data-i18n="language_description">Choose your preferred language for account and site preferences.</p>
            <form method="post" class="stacked-form">
                <input type="hidden" name="csrf_token" value="<?= settingsEscape($_SESSION[$csrfKey]) ?>">
                <input type="hidden" name="action" value="language">
                <label for="language-choice" data-i18n="language">Language</label>
                <select id="language-choice" name="language">
                    <?php foreach ($languages as $code => $label): ?>
                        <option value="<?= settingsEscape($code) ?>" <?= $language === $code ? "selected" : "" ?>><?= settingsEscape($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" data-i18n="save_language">Save Language</button>
            </form>
        </article>
        <article class="settings-card" id="help-support">
            <p class="eyebrow" data-i18n="help">Help</p>
            <h2 data-i18n="help_title">Need a hand?</h2>
            <p data-i18n="help_description">Browse found items to claim something that may be yours. Claims are reviewed by an administrator; check My Claims for updates.</p>
            <a class="button button-small button-secondary" href="found_items.php" data-i18n="browse_found_items">Browse found items</a>
            <?php if ($userId > 0): ?><a class="button button-small button-secondary" href="my_claims.php" data-i18n="view_claims">View my claims</a><?php endif; ?>
            <a class="button button-small button-secondary" href="#feedback">Report a problem or send feedback</a>
        </article>
        <article class="settings-card" id="feedback">
            <p class="eyebrow" data-i18n="feedback">Help and feedback</p>
            <h2 data-i18n="send_feedback">Send feedback</h2>
            <form method="post" class="stacked-form">
                <input type="hidden" name="csrf_token" value="<?= settingsEscape($_SESSION[$csrfKey]) ?>">
                <input type="hidden" name="action" value="feedback">
                <label for="feedback-category" data-i18n="feedback_category">What is this about?</label>
                <select id="feedback-category" name="category" required>
                    <option value="suggestion" data-i18n="suggestion">Suggestion</option>
                    <option value="problem" data-i18n="report_problem">Report a problem</option>
                    <option value="other" data-i18n="other">Other</option>
                </select>
                <label for="feedback-message" data-i18n="message">Message</label>
                <textarea id="feedback-message" name="feedback" rows="4" maxlength="5000" required></textarea>
                <button type="submit" data-i18n="send_feedback">Send Feedback</button>
            </form>
        </article>
        <article class="settings-card" id="profile-settings">
            <p class="eyebrow" data-i18n="account_profile">Account and profile</p>
            <h2 data-i18n="profile_settings">Profile</h2>
            <?php if ($profileSummary !== null): ?>
                <dl class="settings-profile-details">
                    <div><dt data-i18n="display_name">Display name</dt><dd><?= settingsEscape($profileSummary["full_name"]) ?></dd></div>
                    <div><dt data-i18n="email_address">Email address</dt><dd><?= settingsEscape($profileSummary["email"]) ?></dd></div>
                    <div><dt data-i18n="phone_number">Phone number</dt><dd><?= settingsEscape($profileSummary["phone"] ?: "Not provided") ?></dd></div>
                </dl>
                <form method="post" action="profile.php" enctype="multipart/form-data" class="stacked-form">
                    <input type="hidden" name="csrf_token" value="<?= settingsEscape($_SESSION["profile_csrf_token"]) ?>">
                    <input type="hidden" name="action" value="update_profile">
                    <label for="settings-full-name" data-i18n="display_name">Display name</label>
                    <input id="settings-full-name" type="text" name="full_name" maxlength="100" value="<?= settingsEscape($profileSummary["full_name"]) ?>" required>
                    <label for="settings-phone" data-i18n="phone_number">Phone number</label>
                    <input id="settings-phone" type="tel" name="phone" maxlength="20" value="<?= settingsEscape($profileSummary["phone"] ?? "") ?>">
                    <label id="profile-photo" for="settings-profile-photo" data-i18n="profile_photo">Profile photo</label>
                    <input id="settings-profile-photo" type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp">
                    <?php if (!empty($profileSummary["profile_photo"])): ?>
                        <label class="checkbox-label"><input type="checkbox" name="remove_photo" value="1"> <span data-i18n="remove_current_photo">Remove current photo</span></label>
                    <?php endif; ?>
                    <button class="button button-small" type="submit" data-i18n="save_profile">Save Profile</button>
                </form>
                <p><a href="profile.php#email-address" data-i18n="change_email">Change email</a> Â· <a href="profile.php#account-security" data-i18n="change_password">Change password</a></p>
            <?php else: ?>
                <p data-i18n="login_profile_settings">Log in to view and manage your profile settings.</p>
                <a class="button button-small" href="login.php" data-i18n="login">Log in</a>
            <?php endif; ?>
        </article>
        </section>
    </dialog>
    </main>
    <script src="settings-panels.js" defer></script>
    <?php require __DIR__ . "/site_footer.php"; ?>
    </body>
</html>
