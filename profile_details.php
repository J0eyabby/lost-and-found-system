<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/account_guard.php";
enforceActiveAccount($conn);

$userId = (int) ($_SESSION["user_id"] ?? 0);
if ($userId < 1) {
    header("Location: login.php");
    exit;
}
$query = $conn->prepare(
    "SELECT full_name, email, phone, profile_photo, created_at
     FROM users WHERE user_id = ?"
);
$query->bind_param("i", $userId);
$query->execute();
$query->bind_result($fullName, $email, $phone, $photo, $createdAt);
if (!$query->fetch()) {
    $query->close();
    http_response_code(404);
    exit("Account not found.");
}
$query->close();
$photo = basename((string) $photo);
function profileDetailsEscape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Profile - Lost and Found</title>
    <link rel="stylesheet" href="css/style.css?v=theme-20261014-12">
</head>
<body>
<?php require __DIR__ . "/site_header.php"; ?>
<main class="container profile-details-page">
    <p class="eyebrow">Your account</p>
    <h1 class="page-title">My Profile</h1>
    <section class="profile-details-card">
        <div class="profile-details-heading">
           <?php if (
             is_string($photo) &&
             $photo !== "" &&
             is_file(__DIR__ . "/uploads/profiles/" . basename($photo))
): ?>
                <img class="profile-details-photo" src="uploads/profiles/<?= profileDetailsEscape($photo) ?>" alt="Profile photo">
            <?php else: ?>
                <span class="profile-details-photo profile-menu-avatar" aria-hidden="true"><?= profileDetailsEscape(strtoupper(substr($fullName, 0, 1))) ?></span>
            <?php endif; ?>
            <div>
                <h2><?= profileDetailsEscape($fullName) ?></h2>
                <p>Member since <?= profileDetailsEscape(
    date("F Y", strtotime(is_string($createdAt) ? $createdAt : "now"))) ?></p>
            </div>
        </div>
        <dl class="profile-details-list">
            <div><dt>Username / display name</dt><dd><?= profileDetailsEscape($fullName) ?></dd></div>
            <div><dt>Email address</dt><dd><?= profileDetailsEscape($email) ?></dd></div>
            <div><dt>Phone number</dt><dd><?= profileDetailsEscape($phone ?: "Not provided") ?></dd></div>
        </dl>
        <div class="profile-details-actions">
            <a class="button" href="settings.php#profile-settings">Edit profile details</a>
            <a class="button button-secondary" href="my_items.php">My Reports</a>
            <a class="button button-secondary" href="my_claims.php">My Claims</a>
        </div>
    </section>
</main>
<?php require __DIR__ . "/site_footer.php"; ?>
</body>
</html>
