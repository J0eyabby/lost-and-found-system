<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once "db.php";

if (empty($_SESSION["auth_csrf_token"])) {
    $_SESSION["auth_csrf_token"] = bin2hex(random_bytes(32));
}

function passwordMeetsPolicy(string $password): bool
{
    return strlen($password) >= 8
        && preg_match("/[A-Z]/", $password) === 1
        && preg_match("/[0-9]/", $password) === 1
        && preg_match("/[^A-Za-z0-9]/", $password) === 1;
}

$resetToken = $_SERVER["REQUEST_METHOD"] === "POST"
    ? ($_POST["token"] ?? "")
    : ($_GET["token"] ?? "");
$message = "";
$messageType = "error";
$resetComplete = false;
$validToken = is_string($resetToken) && preg_match("/\\A[a-f0-9]{64}\\z/", $resetToken) === 1;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";
    $password = $_POST["password"] ?? "";
    $confirmPassword = $_POST["confirm_password"] ?? "";

    if (!is_string($csrfToken) || !hash_equals($_SESSION["auth_csrf_token"], $csrfToken)) {
        $message = "Your session expired. Please refresh the page and try again.";
    } elseif (!$validToken) {
        $message = "This password reset link is invalid or has expired.";
    } elseif (!passwordMeetsPolicy($password)) {
        $message = "Password must be at least 8 characters and include an uppercase letter, a number, and a special character.";
    } elseif ($password !== $confirmPassword) {
        $message = "The passwords do not match.";
    } else {
        $tokenHash = hash("sha256", $resetToken);

        try {
            $conn->begin_transaction();
            $lookup = $conn->prepare(
                "SELECT user_id FROM password_resets
                 WHERE token_hash = ? AND expires_at > UTC_TIMESTAMP()
                 LIMIT 1 FOR UPDATE"
            );
            $lookup->bind_param("s", $tokenHash);
            $lookup->execute();
            $lookup->bind_result($userId);

            if (!$lookup->fetch()) {
                $message = "This password reset link is invalid or has expired.";
                $lookup->close();
                $conn->rollback();
                $validToken = false;
            } else {
                $lookup->close();
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $update = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                $update->bind_param("si", $passwordHash, $userId);
                $update->execute();
                $update->close();

                $delete = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
                $delete->bind_param("i", $userId);
                $delete->execute();
                $delete->close();

                $conn->commit();
                $resetComplete = true;
                $validToken = false;
                $messageType = "success";
                $message = "Your password has been changed. You can now log in.";
            }
        } catch (Throwable $error) {
            $conn->rollback();
            error_log("Password reset failed: " . $error->getMessage());
            $message = "Unable to reset your password right now. Please try again later.";
        }
    }
}

if (!$resetComplete && !$validToken && $message === "") {
    $message = "This password reset link is invalid or has expired.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Lost and Found System</title>
    <link rel="stylesheet" href="css/style.css?v=20261013">
    <script src="navigation.js" defer></script>
</head>
<body>
<?php require __DIR__ . "/site_header.php"; ?>
<main class="auth-container">
    <h1>Reset Password</h1>

    <?php if ($message !== ""): ?>
        <p class="feedback-banner <?= htmlspecialchars($messageType) ?>" role="<?= $messageType === "success" ? "status" : "alert" ?>">
            <?= htmlspecialchars($message) ?>
        </p>
    <?php endif; ?>

    <?php if ($validToken && !$resetComplete): ?>
        <form method="POST" action="reset_password.php">
            <input type="hidden" name="token" value="<?= htmlspecialchars($resetToken, ENT_QUOTES, "UTF-8") ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["auth_csrf_token"], ENT_QUOTES, "UTF-8") ?>">
            <label for="password">New Password</label>
            <input
                type="password"
                id="password"
                name="password"
                minlength="8"
                pattern="(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}"
                title="Use at least 8 characters, including an uppercase letter, a number, and a special character."
                autocomplete="new-password"
                required
            >
            <label for="confirm_password">Confirm New Password</label>
            <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
            <p class="helper-text">At least 8 characters, with an uppercase letter, a number, and a special character.</p>
            <button type="submit">Change Password</button>
        </form>
    <?php endif; ?>

    <p class="auth-navigation">
        <a href="login.php" class="button button-small button-secondary">Login</a>
    </p>
</main>
<?php require __DIR__ . "/site_footer.php"; ?>
</body>
</html>
