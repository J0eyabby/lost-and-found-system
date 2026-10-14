<?php
require_once __DIR__ . "/session_bootstrap.php";
if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once __DIR__ . "/db.php";
}

$siteUserId = (int) ($_SESSION["user_id"] ?? 0);
$siteRole = "";
$siteProfilePhoto = "";
$siteFullName = (string) ($_SESSION["full_name"] ?? "");
$siteEmail = "";
$siteLanguage = (string) ($_SESSION["preferred_language"] ?? "en");
$siteTheme = "dark";
$siteBrand = "LOST AND FOUND";
$siteLogo = "";
$siteContactEmail = "";
$siteUnreadNotifications = 0;
$siteLanguages = ["en", "sw", "fr", "ar"];
$onDashboard = basename((string) ($_SERVER["SCRIPT_NAME"] ?? "")) === "dashboard.php";
$onHomePage = basename((string) ($_SERVER["SCRIPT_NAME"] ?? "")) === "index.php";
if ($siteUserId > 0) {
    $siteUserQuery = $conn->prepare(
        "SELECT users.role, users.profile_photo, users.preferred_language,
                users.full_name, users.email, COALESCE(user_settings.theme, 'light')
         FROM users
         LEFT JOIN user_settings ON user_settings.user_id = users.user_id
         WHERE users.user_id = ?"
    );
    if ($siteUserQuery === false) {
        error_log("Unable to prepare shared site-header account query: " . $conn->error);
        http_response_code(500);
        exit("Unable to load the account navigation.");
    }
    $siteUserQuery->bind_param("i", $siteUserId);
    if (!$siteUserQuery->execute()) {
        error_log("Unable to load shared site-header account: " . $siteUserQuery->error);
        http_response_code(500);
        exit("Unable to load the account navigation.");
    }
    $savedLanguage = "";
    $siteUserQuery->bind_result($siteRole, $siteProfilePhoto, $savedLanguage, $siteFullName, $siteEmail, $siteTheme);
    if ($siteUserQuery->fetch() && in_array($savedLanguage, $siteLanguages, true)) {
        $siteLanguage = $savedLanguage;
        $_SESSION["preferred_language"] = $siteLanguage;
    }
    $siteUserQuery->close();
    $notificationCount = $conn->prepare(
        "SELECT COUNT(*) FROM user_notifications WHERE user_id = ? AND is_read = 0"
    );
    if ($notificationCount === false) {
        error_log("Unable to prepare shared notification count query: " . $conn->error);
        http_response_code(500);
        exit("Unable to load account notifications.");
    }
    $notificationCount->bind_param("i", $siteUserId);
    if (!$notificationCount->execute()) {
        error_log("Unable to load shared notification count: " . $notificationCount->error);
        http_response_code(500);
        exit("Unable to load account notifications.");
    }
    $notificationCount->bind_result($siteUnreadNotifications);
    $notificationCount->fetch();
    $notificationCount->close();
}
if (!in_array($siteLanguage, $siteLanguages, true)) {
    $siteLanguage = "en";
}
$siteSettingsQuery = $conn->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('site_name', 'site_logo', 'contact_email')");
if ($siteSettingsQuery) {
    while ($siteSetting = $siteSettingsQuery->fetch_assoc()) {
        if ($siteSetting["setting_key"] === "site_name" && trim($siteSetting["setting_value"]) !== "") {
            $siteBrand = $siteSetting["setting_value"];
        } elseif ($siteSetting["setting_key"] === "site_logo") {
            $siteLogo = $siteSetting["setting_value"];
        } elseif ($siteSetting["setting_key"] === "contact_email") {
            $siteContactEmail = $siteSetting["setting_value"];
        }
    }
}
$siteLogo = trim($siteLogo);
$siteProfilePhoto = basename((string) $siteProfilePhoto);
?>
<script>document.documentElement.dataset.theme = "dark";</script>
<header class="site-header">
    <div class="site-header-inner">
        <nav class="site-header-actions" aria-label="Quick navigation" data-i18n-aria="quick_navigation">
            <?php if ($siteUserId > 0): ?>
                <a class="site-icon-link" href="<?= $onDashboard ? "index.php" : "dashboard.php" ?>"
                   aria-label="<?= $onDashboard ? "Close dashboard" : "Open dashboard" ?>"
                   title="<?= $onDashboard ? "Close dashboard" : "Dashboard" ?>"
                   data-i18n-aria="<?= $onDashboard ? "close_dashboard" : "dashboard" ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M4 6h16M4 12h16M4 18h16"></path>
                    </svg>
                </a>
            <?php endif; ?>
            <?php if (!$onHomePage): ?>
                <a class="site-icon-link site-back-link" href="index.php" data-history-back
                   aria-label="Go to previous page" title="Go to previous page">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M19 12H5"></path>
                        <path d="m11 18-6-6 6-6"></path>
                    </svg>
                </a>
            <?php endif; ?>
        </nav>
        <a class="site-brand" href="index.php">
            <img class="site-brand-logo"
                 src="<?= $siteLogo !== "" && preg_match('/^(https:\/\/|uploads\/|assets\/)[A-Za-z0-9._\/-]+$/D', $siteLogo) ? htmlspecialchars($siteLogo, ENT_QUOTES, "UTF-8") : "assets/logo.jpg" ?>"
                 alt="">
            <span><?= htmlspecialchars($siteBrand, ENT_QUOTES, "UTF-8") ?></span>
        </a>
        <div class="site-header-right">
            <?php if (!$onHomePage): ?>
                <a class="site-icon-link" href="index.php" aria-label="Go to homepage" title="Home" data-i18n-aria="home">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="m3 10 9-7 9 7"></path>
                        <path d="M5.5 9v11h13V9M9.5 20v-7h5v7"></path>
                    </svg>
                </a>
            <?php endif; ?>
            <?php if ($siteUserId > 0): ?>
                <a class="site-icon-link site-notification-link" href="notifications.php"
                   aria-label="Notifications<?= $siteUnreadNotifications > 0 ? ", " . (int) $siteUnreadNotifications . " unread" : "" ?>"
                   title="Notifications">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path>
                        <path d="M10 21h4"></path>
                    </svg>
                    <?php if ($siteUnreadNotifications > 0): ?>
                        <span class="notification-count"><?= $siteUnreadNotifications > 99 ? "99+" : (int) $siteUnreadNotifications ?></span>
                    <?php endif; ?>
                </a>
                <div class="site-icon-control">
                    <button class="site-profile-link site-icon-button" type="button"
                            aria-label="Open profile menu" title="Profile"
                            aria-expanded="false" aria-controls="profile-menu"
                            data-profile-menu-toggle>
                        <?php if ($siteProfilePhoto !== ""): ?>
                            <img src="uploads/profiles/<?= htmlspecialchars($siteProfilePhoto, ENT_QUOTES, "UTF-8") ?>" alt="">
                        <?php else: ?>
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <circle cx="12" cy="8" r="3.4"></circle>
                                <path d="M5.2 20c.5-3.5 3.1-5.5 6.8-5.5s6.3 2 6.8 5.5"></path>
                            </svg>
                        <?php endif; ?>
                    </button>
                    <div class="profile-menu" id="profile-menu" hidden>
                        <div class="profile-menu-summary">
                            <?php if ($siteProfilePhoto !== ""): ?>
                                <img src="uploads/profiles/<?= htmlspecialchars($siteProfilePhoto, ENT_QUOTES, "UTF-8") ?>" alt="">
                            <?php else: ?>
                                <span class="profile-menu-avatar" aria-hidden="true"><?= htmlspecialchars(strtoupper(substr($siteFullName !== "" ? $siteFullName : "U", 0, 1)), ENT_QUOTES, "UTF-8") ?></span>
                            <?php endif; ?>
                            <span>
                                <strong><?= htmlspecialchars($siteFullName, ENT_QUOTES, "UTF-8") ?></strong>
                                <small><?= htmlspecialchars($siteEmail, ENT_QUOTES, "UTF-8") ?></small>
                            </span>
                        </div>
                        <a class="profile-menu-link" href="profile_details.php" data-i18n="view_profile">View profile</a>
                        <a class="profile-menu-link" href="settings.php#profile-settings" data-i18n="settings">Settings</a>
                        <span class="profile-menu-separator" aria-hidden="true"></span>
                        <a class="profile-menu-link profile-menu-link-logout" href="logout.php" data-i18n="logout">Log out</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="site-icon-control">
                    <button class="site-profile-link site-icon-button" type="button"
                            aria-label="Open account menu" title="Log in or register"
                            aria-expanded="false" aria-controls="profile-menu"
                            data-profile-menu-toggle>
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <circle cx="12" cy="8" r="3.4"></circle>
                            <path d="M5.2 20c.5-3.5 3.1-5.5 6.8-5.5s6.3 2 6.8 5.5"></path>
                        </svg>
                    </button>
                    <div class="profile-menu" id="profile-menu" hidden>
                        <div class="profile-menu-summary">
                            <span class="profile-menu-avatar" aria-hidden="true">?</span>
                            <span><strong>Welcome</strong><small>Access your account</small></span>
                        </div>
                        <a class="profile-menu-link" href="login.php">Log in</a>
                        <a class="profile-menu-link" href="register.php">Create account</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</header>
<script>document.documentElement.lang = <?= json_encode($siteLanguage, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="language.js" defer></script>
<script src="site-header.js?v=theme-20261014" defer></script>
<script src="password-visibility.js" defer></script>
<script src="smart-assistant.js?v=chat-20261013" defer></script>
