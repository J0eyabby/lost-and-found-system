<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";

if (empty($_SESSION["profile_csrf_token"])) {
    $_SESSION["profile_csrf_token"] = bin2hex(random_bytes(32));
}

$token = $_SERVER["REQUEST_METHOD"] === "POST"
    ? ($_POST["token"] ?? "")
    : ($_GET["token"] ?? "");
$validToken = is_string($token) && preg_match("/\A[a-f0-9]{64}\z/", $token) === 1;
$message = "";
$messageType = "error";
$verified = false;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";

    if (!is_string($csrfToken) || !hash_equals($_SESSION["profile_csrf_token"], $csrfToken)) {
        $message = "Your session expired. Refresh the page and try again.";
    } elseif (!$validToken) {
        $message = "This email verification link is invalid or has expired.";
    } else {
        $tokenHash = hash("sha256", $token);

        try {
            $conn->begin_transaction();
            $requestStmt = $conn->prepare(
                "SELECT user_id, pending_email
                 FROM email_change_requests
                 WHERE token_hash = ? AND expires_at > UTC_TIMESTAMP()
                 LIMIT 1 FOR UPDATE"
            );
            $requestStmt->bind_param("s", $tokenHash);
            $requestStmt->execute();
            $requestStmt->bind_result($userId, $pendingEmail);

            if (!$requestStmt->fetch()) {
                $requestStmt->close();
                $conn->rollback();
                $validToken = false;
                $message = "This email verification link is invalid or has expired.";
            } else {
                $requestStmt->close();
                $updateStmt = $conn->prepare(
                    "UPDATE users SET email = ? WHERE user_id = ?"
                );
                $updateStmt->bind_param("si", $pendingEmail, $userId);
                $updateStmt->execute();
                $updateStmt->close();

                $deleteStmt = $conn->prepare(
                    "DELETE FROM email_change_requests WHERE user_id = ?"
                );
                $deleteStmt->bind_param("i", $userId);
                $deleteStmt->execute();
                $deleteStmt->close();

                $conn->commit();
                $verified = true;
                $validToken = false;
                $message = "Your email address is verified and has been updated.";
                $messageType = "success";
            }
        } catch (Throwable $error) {
            $conn->rollback();
            error_log("Email change verification could not be completed: " . $error->getMessage());
            $message = "Unable to verify the email address. The address may already be in use.";
        }
    }
} elseif ($validToken) {
    $tokenHash = hash("sha256", $token);
    $checkStmt = $conn->prepare(
        "SELECT user_id FROM email_change_requests
         WHERE token_hash = ? AND expires_at > UTC_TIMESTAMP()
         LIMIT 1"
    );
    $checkStmt->bind_param("s", $tokenHash);
    $checkStmt->execute();
    $checkStmt->store_result();
    $validToken = $checkStmt->num_rows === 1;
    $checkStmt->close();

    if (!$validToken) {
        $message = "This email verification link is invalid or has expired.";
    }
} else {
    $message = "This email verification link is invalid or has expired.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Email Change - Lost and Found System</title>
    <link rel="stylesheet" href="css/style.css?v=theme-20261014-12">
</head>
<body>
<?php require __DIR__ . "/site_header.php"; ?>
<main class="auth-container">
    <h1>Verify Email Change</h1>

    <?php if ($message !== ""): ?>
        <p class="feedback-banner <?= htmlspecialchars($messageType) ?>" role="<?= $messageType === "success" ? "status" : "alert" ?>">
            <?= htmlspecialchars($message) ?>
        </p>
    <?php endif; ?>

    <?php if ($validToken && !$verified): ?>
        <p>Confirm that you want to use the new email address for your account.</p>
        <form method="POST" action="verify_email_change.php">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, "UTF-8") ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["profile_csrf_token"], ENT_QUOTES, "UTF-8") ?>">
            <button type="submit">Confirm New Email</button>
        </form>
    <?php endif; ?>

    <?php if (isset($_SESSION["user_id"])): ?>
        <p><a href="settings.php#profile-settings">Back to Settings</a></p>
    <?php else: ?>
        <p><a href="login.php">Back to Login</a></p>
    <?php endif; ?>
</main>
<?php require __DIR__ . "/site_footer.php"; ?>
</body>
</html>
