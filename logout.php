
<?php
require_once __DIR__ . "/session_bootstrap.php";

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/activity_log.php";
$loggingOutUserId = (int) ($_SESSION["user_id"] ?? 0);
if ($loggingOutUserId > 0) {
    recordActivity($conn, $loggingOutUserId, "user_logout", "Signed out");
}

// Remove all session data
$_SESSION = [];

// Destroy the current session
session_destroy();
expireAppSessionCookie();

// Return to the homepage
header("Location: index.php");
exit;
?>
