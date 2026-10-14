<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once "db.php";
require_once "activity_log.php";

$message = "";
$messageType = "";

$full_name = "";
$email = "";
$phone = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $full_name = trim($_POST["full_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($full_name === "" || $email === "" || $password === "") {
        $message = "Please fill in all required fields.";
        $messageType = "error";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
        $messageType = "error";
    } elseif (
        strlen($password) < 8 ||
        !preg_match("/[A-Z]/", $password) ||
        !preg_match("/[0-9]/", $password) ||
        !preg_match("/[^A-Za-z0-9]/", $password)
    ) {
        $message = "Password must be at least 8 characters and include an uppercase letter, a number, and a special character.";
        $messageType = "error";
    } else {
        $check = $conn->prepare(
            "SELECT user_id FROM users WHERE email = ?"
        );
        $check->bind_param("s", $email);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $message = "This email address is already registered.";
            $messageType = "error";
        } else {
            $hashedPassword = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            $stmt = $conn->prepare(
                "INSERT INTO users
                (full_name, email, phone, password)
                VALUES (?, ?, ?, ?)"
            );

            $stmt->bind_param(
                "ssss",
                $full_name,
                $email,
                $phone,
                $hashedPassword
            );

            if ($stmt->execute()) {
                recordActivity($conn, (int) $conn->insert_id, "user_registered", "New account registered");
                $_SESSION["auth_notice"] = "Registration successful. Please log in.";
                header("Location: login.php");
                exit;
            } else {
                $message = "Registration failed. Please try again.";
                $messageType = "error";
            }

            $stmt->close();
        }

        $check->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Lost and Found System</title>

<link rel="stylesheet" href="css/style.css?v=20261014">
<script src="navigation.js" defer></script>

</head>
<body class="auth-page">
<?php require __DIR__ . "/site_header.php"; ?>
<main class="auth-container">
    <h1>Create an Account</h1>
    <p>Register to use the Lost and Found System.</p>

    <?php if ($message !== ""): ?>
        <p>
            <strong>
                <?= htmlspecialchars($message) ?>
            </strong>
        </p>
    <?php endif; ?>

    <form method="POST" action="register.php">
        <label for="full_name">Full Name *</label><br>
        <input
            type="text"
            id="full_name"
            name="full_name"
            maxlength="100"
            value="<?= htmlspecialchars($full_name) ?>"
            required
        ><br><br>

        <label for="email">Email Address *</label><br>
        <input
            type="email"
            id="email"
            name="email"
            maxlength="100"
            value="<?= htmlspecialchars($email) ?>"
            required
        ><br><br>

        <label for="phone">Phone Number</label><br>
        <input
            type="tel"
            id="phone"
            name="phone"
            maxlength="20"
            value="<?= htmlspecialchars($phone) ?>"
        ><br><br>

        <label for="password">Password *</label><br>
        <input
            type="password"
            id="password"
            name="password"
            minlength="8"
            pattern="(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}"
            title="Use at least 8 characters, including an uppercase letter, a number, and a special character."
            required
        ><br><br>
        <p class="helper-text">At least 8 characters, with an uppercase letter, a number, and a special character.</p>

        <button type="submit">Register</button>
    </form>
    </main>
    <?php require __DIR__ . "/site_footer.php"; ?>
    </body>
</html>
