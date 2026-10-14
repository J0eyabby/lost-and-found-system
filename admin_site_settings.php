<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/activity_log.php";

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
if (empty($_SESSION["site_settings_csrf_token"])) {
    $_SESSION["site_settings_csrf_token"] = bin2hex(random_bytes(32));
}
$message = "";
$settings = [];
$result = $conn->query("SELECT setting_key, setting_value FROM site_settings");
while ($row = $result->fetch_assoc()) {
    $settings[$row["setting_key"]] = $row["setting_value"];
}
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";
    $siteName = trim($_POST["site_name"] ?? "");
    $contactEmail = trim($_POST["contact_email"] ?? "");
    $logoUrl = trim($_POST["site_logo"] ?? "");
    if (!is_string($token) || !hash_equals($_SESSION["site_settings_csrf_token"], $token)) {
        $message = "Your session expired. Refresh and try again.";
    } elseif ($siteName === "" || strlen($siteName) > 100
        || ($contactEmail !== "" && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL))
        || strlen($contactEmail) > 254 || strlen($logoUrl) > 500
        || ($logoUrl !== "" && !preg_match('/^(https:\/\/|uploads\/|assets\/)[A-Za-z0-9._\/-]+$/D', $logoUrl))) {
        $message = "Enter a site name, valid contact email, and an HTTPS, uploads/, or assets/ logo path.";
    } else {
        $save = $conn->prepare(
            "INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        $conn->begin_transaction();
        foreach (["site_name" => $siteName, "site_logo" => $logoUrl, "contact_email" => $contactEmail] as $key => $value) {
            $save->bind_param("ss", $key, $value);
            if (!$save->execute()) {
                $message = "Unable to save website settings.";
                break;
            }
        }
        $save->close();
        if ($message === "") {
            $conn->commit();
            $settings["site_name"] = $siteName;
            $settings["site_logo"] = $logoUrl;
            $settings["contact_email"] = $contactEmail;
            recordActivity($conn, $userId, "website_settings_updated", "Site name, logo, or contact information");
            $message = "Website settings saved.";
        } else {
            $conn->rollback();
        }
    }
}
function siteSettingsEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Website Settings</title><link rel="stylesheet" href="css/style.css?v=theme-20261014-12"></head>
<body><?php require __DIR__ . "/site_header.php"; ?>
<main class="container account-page">
    <h1 class="page-title">Website Settings</h1>
    <?php if ($message !== ""): ?><p class="feedback-banner" role="status"><?= siteSettingsEscape($message) ?></p><?php endif; ?>
    <form method="post" class="settings-card stacked-form">
        <input type="hidden" name="csrf_token" value="<?= siteSettingsEscape($_SESSION["site_settings_csrf_token"]) ?>">
        <label for="site-name">Site name</label>
        <input id="site-name" name="site_name" maxlength="100" value="<?= siteSettingsEscape($settings["site_name"] ?? "LOST AND FOUND") ?>" required>
        <label for="site-logo">Logo image URL/path (optional)</label>
        <input id="site-logo" name="site_logo" maxlength="500" value="<?= siteSettingsEscape($settings["site_logo"] ?? "") ?>" placeholder="uploads/site-logo.png">
        <label for="contact-email">Contact email</label>
        <input id="contact-email" name="contact_email" type="email" maxlength="254" value="<?= siteSettingsEscape($settings["contact_email"] ?? "") ?>">
        <button type="submit">Save Website Settings</button>
    </form>
</main>
<?php require __DIR__ . "/site_footer.php"; ?>
</body>
</html>
