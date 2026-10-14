<?php

require_once __DIR__ . "/session_bootstrap.php";
require_once "db.php";
require_once "activity_log.php";

$message = "";
$notice = $_SESSION["auth_notice"] ?? "";
unset($_SESSION["auth_notice"]);
$suspendedNotice = ($_GET["account"] ?? "") === "suspended";
$suspensionReason = (string) ($_SESSION["account_suspension_reason"] ?? "");
unset($_SESSION["account_suspension_reason"]);
$requestedNext = $_POST["next"] ?? $_GET["next"] ?? "";
$safeNext = is_string($requestedNext)
    && (
        $requestedNext === "report_item.php"
        || preg_match('/^(claim_item|item)\.php\?item_id=[1-9][0-9]*$/D', $requestedNext) === 1
    )
    ? $requestedNext
    : "index.php";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    $stmt = $conn->prepare(
        "SELECT users.user_id, users.full_name, users.password,
                COALESCE(user_account_status.is_active, 1),
                user_account_status.suspension_reason
         FROM users
         LEFT JOIN user_account_status ON user_account_status.user_id = users.user_id
         WHERE users.email = ?"
    );

    $stmt->bind_param("s", $email);
    $stmt->execute();

    $result = $stmt->get_result();

    $accountMessage = "";
    if ($user = $result->fetch_row()) {
        if (password_verify($password, $user[2])) {
            if ((int) $user[3] !== 1) {
                $reason = trim((string) ($user[4] ?? ""));
                $accountMessage = "This account is suspended."
                    . ($reason !== "" ? " Reason: " . $reason : "")
                    . " Please contact support if you need clarification.";
            } else {
                session_regenerate_id(true);

                $_SESSION["user_id"] = $user[0];
                $_SESSION["full_name"] = $user[1];

                recordActivity($conn, (int) $user[0], "user_login", "Successful sign-in");
                header("Location: " . $safeNext);
                exit;
            }
        }
    }

    $message = $accountMessage !== "" ? $accountMessage : "Invalid email address or password.";
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Lost and Found System</title>

<link rel="stylesheet" href="css/style.css?v=20261014">
<script src="navigation.js" defer></script>

</head>
<body class="auth-page">
<?php require __DIR__ . "/site_header.php"; ?>
<main class="auth-container">
    <h1>Login</h1>
    <p>Sign in to your account.</p>
    <p class="helper-text">Youâ€™ll stay signed in on this device unless you log out.</p>

    <?php if ($notice !== ""): ?>
        <p class="feedback-banner success" role="status"><?= htmlspecialchars($notice) ?></p>
    <?php endif; ?>
    <?php if ($suspendedNotice): ?>
        <p class="feedback-banner error" role="alert">
            Your account is suspended or unavailable.
            <?= $suspensionReason !== "" ? "Reason: " . htmlspecialchars($suspensionReason, ENT_QUOTES, "UTF-8") : "" ?>
            Please contact support if you need clarification.
        </p>
    <?php endif; ?>

    <?php if ($message !== ""): ?>
        <p class="feedback-banner error" role="alert"><?= htmlspecialchars($message) ?></p>
    <?php endif; ?>

    <form method="POST" action="login.php">
        <input type="hidden" name="next" value="<?= htmlspecialchars(is_string($requestedNext) ? $requestedNext : "", ENT_QUOTES, "UTF-8") ?>">
        <label for="email">Email Address</label><br>
        <input
            type="email"
            id="email"
            name="email"
            required
        ><br><br>

        <label for="password">Password</label><br>
        <input
            type="password"
            id="password"
            name="password"
            required
        ><br><br>

        <button type="submit">Login</button>
    </form>
    <p><a href="forgot_password.php">Forgot password?</a></p>
    <p><a href="register.php">Create an account</a></p>
</main>
<?php require __DIR__ . "/site_footer.php"; ?>
</body>
</html>
