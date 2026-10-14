<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . "/vendor/autoload.php";
require_once __DIR__ . "/db.php";

require_once __DIR__ . "/session_bootstrap.php";

if (empty($_SESSION["auth_csrf_token"])) {
    $_SESSION["auth_csrf_token"] = bin2hex(random_bytes(32));
}

$message = "";
$messageType = "error";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";
    $email = trim($_POST["email"] ?? "");

    if (!is_string($token) || !hash_equals($_SESSION["auth_csrf_token"], $token)) {
        $message = "Your session expired. Please refresh the page and try again.";
    } elseif (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->bind_result($userId);

        if ($stmt->fetch()) {
            $stmt->close();

            $resetToken = bin2hex(random_bytes(32));
            $tokenHash = hash("sha256", $resetToken);
            $expiresAt = gmdate("Y-m-d H:i:s", time() + 3600);

            $saveStmt = $conn->prepare(
                "INSERT INTO password_resets (user_id, token_hash, expires_at)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), expires_at = VALUES(expires_at)"
            );
            $saveStmt->bind_param("iss", $userId, $tokenHash, $expiresAt);

            if ($saveStmt->execute()) {

$baseUrl = "http://localhost/lost_found";

                $resetUrl = $baseUrl . "/reset_password.php?token=" . urlencode($resetToken);
                $subject = "Reset your Lost and Found password";
                $body = "We received a request to reset your password.\n\n"
                    . "Use this link within one hour to choose a new password:\n"
                    . $resetUrl
                    . "\n\nIf you did not request this, you can ignore this email.";

$subject = "Reset your Lost and Found password";
$body = "We received a request to reset your password.\n\n"
    . "Use this link within one hour to choose a new password:\n"
    . $resetUrl
    . "\n\nIf you did not request this, you can ignore this email.";

$mailSent = false;

try {
    $mailConfig = require __DIR__ . "/mail_config.php";

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $mailConfig["host"];
    $mail->SMTPAuth = true;
    $mail->Username = $mailConfig["username"];
    $mail->Password = $mailConfig["password"];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = (int) $mailConfig["port"];

    $mail->setFrom($mailConfig["username"], "Lost and Found System");
    $mail->addAddress($email);
    $mail->Subject = $subject;
    $mail->Body = $body;
    $mail->send();

    $mailSent = true;
} catch (Exception $error) {
    error_log("Password reset email failed: " . $error->getMessage());
}

                $saveStmt->close();

                if (!$mailSent) {
                    error_log("Password reset email could not be sent.");
                    $removeStmt = $conn->prepare(
                        "DELETE FROM password_resets WHERE user_id = ? AND token_hash = ?"
                    );
                    $removeStmt->bind_param("is", $userId, $tokenHash);
                    $removeStmt->execute();
                    $removeStmt->close();
                }
            } else {
                error_log("Password reset token could not be stored.");
                $saveStmt->close();
            }
        } else {
            $stmt->close();
        }

        $message = "If an account exists for that email, a password reset link will be sent shortly.";
        $messageType = "success";
    } else {
        $message = "Enter a valid email address.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Lost and Found System</title>
    <link rel="stylesheet" href="css/style.css?v=20261014">
    <script src="navigation.js" defer></script>
</head>
<body>
<?php require __DIR__ . "/site_header.php"; ?>
<main class="auth-container">
    <h1>Forgot Password</h1>
    <p>Enter your account email and weâ€™ll send a password reset link if it matches an account.</p>

    <?php if ($message !== ""): ?>
        <p class="feedback-banner <?= htmlspecialchars($messageType) ?>" role="<?= $messageType === "success" ? "status" : "alert" ?>">
            <?= htmlspecialchars($message) ?>
        </p>
    <?php endif; ?>

    <form method="POST" action="forgot_password.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["auth_csrf_token"], ENT_QUOTES, "UTF-8") ?>">
        <label for="email">Email Address</label>
        <input type="email" id="email" name="email" maxlength="100" required>
        <button type="submit">Send Reset Link</button>
    </form>

    <p class="auth-navigation">
        <a href="login.php" class="button button-small button-secondary">Login</a>
    </p>
</main>
<?php require __DIR__ . "/site_footer.php"; ?>
</body>
</html>
