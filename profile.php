<?php
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/vendor/autoload.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/account_guard.php";
enforceActiveAccount($conn);

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

if (empty($_SESSION["profile_csrf_token"])) {
    $_SESSION["profile_csrf_token"] = bin2hex(random_bytes(32));
}

function profilePasswordIsValid(string $password): bool
{
    return strlen($password) >= 8
        && preg_match("/[A-Z]/", $password) === 1
        && preg_match("/[0-9]/", $password) === 1
        && preg_match("/[^A-Za-z0-9]/", $password) === 1;
}

function sendEmailChangeLink(string $email, string $url): bool
{
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
        $mail->Subject = "Verify your new email address";
        $mail->Body = "Confirm your new email address for the Lost and Found System using this link within one hour:\n\n"
            . $url
            . "\n\nIf you did not request this change, you can ignore this email.";
        $mail->send();
        return true;
    } catch (Exception $error) {
        error_log("Email change verification failed: " . $error->getMessage());
        return false;
    }
}

$userId = (int) $_SESSION["user_id"];
$message = "";
$messageType = "error";
$userStmt = $conn->prepare(
    "SELECT full_name, email, phone, password, profile_photo
     FROM users
     WHERE user_id = ?"
);
$userStmt->bind_param("i", $userId);
$userStmt->execute();
$userStmt->bind_result($fullName, $email, $phone, $passwordHash, $profilePhoto);

if (!$userStmt->fetch()) {
    $userStmt->close();
    http_response_code(404);
    exit("Account not found.");
}
$userStmt->close();

$pendingEmail = "";
$pendingStmt = $conn->prepare(
    "SELECT pending_email FROM email_change_requests
     WHERE user_id = ? AND expires_at > UTC_TIMESTAMP()"
);
$pendingStmt->bind_param("i", $userId);
$pendingStmt->execute();
$pendingStmt->bind_result($pendingEmailResult);
if ($pendingStmt->fetch()) {
    $pendingEmail = $pendingEmailResult;
}
$pendingStmt->close();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";
    $action = $_POST["action"] ?? "";

    if (!is_string($csrfToken) || !hash_equals($_SESSION["profile_csrf_token"], $csrfToken)) {
        $message = "Your session expired. Refresh the page and try again.";
    } elseif ($action === "update_profile") {
        $newFullName = trim($_POST["full_name"] ?? "");
        $newPhone = trim($_POST["phone"] ?? "");
        $removePhoto = ($_POST["remove_photo"] ?? "") === "1";
        $newPhoto = $profilePhoto;
        $newPhotoPath = null;
        $uploadError = "";

        if ($newFullName === "" || strlen($newFullName) > 100 || strlen($newPhone) > 20) {
            $message = "Enter a name of up to 100 characters and a phone number of up to 20 characters.";
        } elseif (isset($_FILES["profile_photo"]) && $_FILES["profile_photo"]["error"] !== UPLOAD_ERR_NO_FILE) {
            $photo = $_FILES["profile_photo"];
            if ($photo["error"] !== UPLOAD_ERR_OK) {
                $uploadError = "The profile photo could not be uploaded.";
            } elseif ($photo["size"] > 5 * 1024 * 1024) {
                $uploadError = "The profile photo must be 5 MB or smaller.";
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($photo["tmp_name"]);
                $allowedTypes = [
                    "image/jpeg" => "jpg",
                    "image/png" => "png",
                    "image/webp" => "webp",
                ];

                if (!isset($allowedTypes[$mimeType]) || getimagesize($photo["tmp_name"]) === false) {
                    $uploadError = "Please upload a valid JPG, PNG, or WebP image.";
                } else {
                    $profileDirectory = __DIR__ . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "profiles";
                    if (!is_dir($profileDirectory) && !mkdir($profileDirectory, 0755, true) && !is_dir($profileDirectory)) {
                        $uploadError = "Unable to create the profile photo folder.";
                    } else {
                        $newPhoto = bin2hex(random_bytes(16)) . "." . $allowedTypes[$mimeType];
                        $newPhotoPath = $profileDirectory . DIRECTORY_SEPARATOR . $newPhoto;
                        if (!move_uploaded_file($photo["tmp_name"], $newPhotoPath)) {
                            $uploadError = "Unable to save the profile photo.";
                            $newPhoto = $profilePhoto;
                            $newPhotoPath = null;
                        }
                    }
                }
            }
        } elseif ($removePhoto) {
            $newPhoto = null;
        }

        if ($uploadError !== "") {
            $message = $uploadError;
        } elseif ($message === "") {
            $updateStmt = $conn->prepare(
                "UPDATE users SET full_name = ?, phone = ?, profile_photo = ? WHERE user_id = ?"
            );
            $updateStmt->bind_param("sssi", $newFullName, $newPhone, $newPhoto, $userId);

            if ($updateStmt->execute()) {
                if (($newPhotoPath !== null || $removePhoto) && !empty($profilePhoto)) {
                    $oldPhotoPath = __DIR__ . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "profiles" . DIRECTORY_SEPARATOR . basename($profilePhoto);
                    if (is_file($oldPhotoPath)) {
                        unlink($oldPhotoPath);
                    }
                }
                $_SESSION["full_name"] = $newFullName;
                $fullName = $newFullName;
                $phone = $newPhone;
                $profilePhoto = $newPhoto;
                $message = "Profile details updated.";
                $messageType = "success";
            } else {
                if ($newPhotoPath !== null && is_file($newPhotoPath)) {
                    unlink($newPhotoPath);
                }
                $message = "Unable to update your profile right now.";
            }
            $updateStmt->close();
        }
    } elseif ($action === "change_password") {
        $currentPassword = $_POST["current_password"] ?? "";
        $newPassword = $_POST["new_password"] ?? "";
        $confirmPassword = $_POST["confirm_password"] ?? "";

        if (!password_verify($currentPassword, $passwordHash)) {
            $message = "Your current password is incorrect.";
        } elseif (!profilePasswordIsValid($newPassword)) {
            $message = "Password must be at least 8 characters and include an uppercase letter, a number, and a special character.";
        } elseif ($newPassword !== $confirmPassword) {
            $message = "The new passwords do not match.";
        } else {
            $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $passwordStmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
            $passwordStmt->bind_param("si", $newPasswordHash, $userId);
            if ($passwordStmt->execute()) {
                $passwordHash = $newPasswordHash;
                $message = "Your password has been changed.";
                $messageType = "success";
            } else {
                $message = "Unable to change your password right now.";
            }
            $passwordStmt->close();
        }
    } elseif ($action === "request_email_change") {
        $newEmail = trim($_POST["new_email"] ?? "");
        $currentPassword = $_POST["email_current_password"] ?? "";

        if (!password_verify($currentPassword, $passwordHash)) {
            $message = "Your current password is incorrect.";
        } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL) || strlen($newEmail) > 100) {
            $message = "Enter a valid email address of up to 100 characters.";
        } elseif (strcasecmp($newEmail, $email) === 0) {
            $message = "That is already your current email address.";
        } else {
            $existsStmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id <> ? LIMIT 1");
            $existsStmt->bind_param("si", $newEmail, $userId);
            $existsStmt->execute();
            $existsStmt->store_result();

            if ($existsStmt->num_rows > 0) {
                $message = "That email address is already in use.";
            }
            $existsStmt->close();

            if ($message === "") {
                $pendingCheck = $conn->prepare(
                    "SELECT user_id FROM email_change_requests
                     WHERE pending_email = ? AND user_id <> ? LIMIT 1"
                );
                $pendingCheck->bind_param("si", $newEmail, $userId);
                $pendingCheck->execute();
                $pendingCheck->store_result();
                if ($pendingCheck->num_rows > 0) {
                    $message = "That email address is already pending verification for another account.";
                }
                $pendingCheck->close();
            }

            if ($message === "") {
                $emailToken = bin2hex(random_bytes(32));
                $emailTokenHash = hash("sha256", $emailToken);
                $expiresAt = gmdate("Y-m-d H:i:s", time() + 3600);
                $requestStmt = $conn->prepare(
                    "INSERT INTO email_change_requests (user_id, pending_email, token_hash, expires_at)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE pending_email = VALUES(pending_email), token_hash = VALUES(token_hash), expires_at = VALUES(expires_at)"
                );
                $requestStmt->bind_param("isss", $userId, $newEmail, $emailTokenHash, $expiresAt);

                if ($requestStmt->execute()) {
                    $configuredBaseUrl = getenv("APP_BASE_URL");
                    $baseUrl = $configuredBaseUrl !== false && $configuredBaseUrl !== ""
                        ? rtrim($configuredBaseUrl, "/")
                        : "http://localhost/lost_found";
                    $verifyUrl = $baseUrl . "/verify_email_change.php?token=" . urlencode($emailToken);

                    if (sendEmailChangeLink($newEmail, $verifyUrl)) {
                        $pendingEmail = $newEmail;
                        $message = "A verification link was sent to your new email address. It expires in one hour.";
                        $messageType = "success";
                    } else {
                        $deleteRequest = $conn->prepare(
                            "DELETE FROM email_change_requests WHERE user_id = ? AND token_hash = ?"
                        );
                        $deleteRequest->bind_param("is", $userId, $emailTokenHash);
                        $deleteRequest->execute();
                        $deleteRequest->close();
                        $message = "Unable to send the verification email. Please try again later.";
                    }
                } else {
                    $message = "Unable to start the email change. Check that the address is not already pending verification.";
                }
                $requestStmt->close();
            }
        }
    } else {
        $message = "Invalid profile request.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Lost and Found System</title>
    <link rel="stylesheet" href="css/style.css?v=theme-20261014-12">
    <script src="navigation.js" defer></script>
</head>
<body>
<?php require __DIR__ . "/site_header.php"; ?>

<main class="container profile-page">
    <h1 class="page-title">Profile Settings</h1>
    <?php if ($message !== ""): ?>
        <p class="feedback-banner <?= htmlspecialchars($messageType) ?>" role="<?= $messageType === "success" ? "status" : "alert" ?>"><?= htmlspecialchars($message) ?></p>
    <?php endif; ?>

    <section class="search-panel profile-card">
        <div class="profile-heading">
            <?php if (!empty($profilePhoto)): ?>
                <img src="uploads/profiles/<?= htmlspecialchars(basename($profilePhoto), ENT_QUOTES, "UTF-8") ?>" alt="Profile photo" class="profile-avatar profile-avatar-large">
            <?php else: ?>
                <span class="profile-avatar profile-avatar-large profile-avatar-placeholder" aria-hidden="true"><?= htmlspecialchars(strtoupper(substr($fullName, 0, 1))) ?></span>
            <?php endif; ?>
            <div>
                <p class="eyebrow">Personal information</p>
                <h2><?= htmlspecialchars($fullName) ?></h2>
                <p class="helper-text">Manage the details connected to your account.</p>
            </div>
        </div>

        <form method="POST" action="profile.php" enctype="multipart/form-data" class="profile-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["profile_csrf_token"], ENT_QUOTES, "UTF-8") ?>">
            <input type="hidden" name="action" value="update_profile">
            <div class="field-group">
                <label for="full_name">Username / display name</label>
                <input type="text" id="full_name" name="full_name" maxlength="100" value="<?= htmlspecialchars($fullName) ?>" required>
            </div>
            <div class="field-group">
                <label for="email_display">Current email</label>
                <input type="email" id="email_display" value="<?= htmlspecialchars($email) ?>" readonly>
                <p class="helper-text">Change and verify your address below.</p>
            </div>
            <div class="field-group">
                <label for="phone">Phone number</label>
                <input type="tel" id="phone" name="phone" maxlength="20" value="<?= htmlspecialchars($phone ?? "") ?>">
            </div>
            <div class="field-group">
                <label for="profile_photo">Profile photo</label>
                <input type="file" id="profile_photo" name="profile_photo" accept="image/jpeg,image/png,image/webp">
                <p class="helper-text">JPG, PNG, or WebP, up to 5 MB.</p>
                <?php if (!empty($profilePhoto)): ?>
                    <label class="checkbox-label"><input type="checkbox" name="remove_photo" value="1"> Remove current photo</label>
                <?php endif; ?>
            </div>
            <div class="search-actions full-width">
                <button type="submit">Save Profile</button>
            </div>
        </form>
    </section>

    <div class="profile-sections">
        <section class="search-panel" id="email-address">
            <p class="eyebrow">Email address</p>
            <h2>Change email</h2>
            <?php if ($pendingEmail !== ""): ?>
                <p class="feedback-banner success" role="status">Waiting for verification at <?= htmlspecialchars($pendingEmail) ?>.</p>
            <?php endif; ?>
            <form method="POST" action="profile.php" class="stacked-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["profile_csrf_token"], ENT_QUOTES, "UTF-8") ?>">
                <input type="hidden" name="action" value="request_email_change">
                <label for="new_email">New email address</label>
                <input type="email" id="new_email" name="new_email" maxlength="100" required>
                <label for="email_current_password">Current password</label>
                <input type="password" id="email_current_password" name="email_current_password" autocomplete="current-password" required>
                <p class="helper-text">Your current email remains active until you verify the new one.</p>
                <button type="submit">Send Verification Link</button>
            </form>
        </section>

        <section class="search-panel" id="account-security">
            <p class="eyebrow">Security</p>
            <h2>Change password</h2>
            <form method="POST" action="profile.php" class="stacked-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["profile_csrf_token"], ENT_QUOTES, "UTF-8") ?>">
                <input type="hidden" name="action" value="change_password">
                <label for="current_password">Current password</label>
                <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
                <label for="new_password">New password</label>
                <input type="password" id="new_password" name="new_password" minlength="8" pattern="(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}" title="At least 8 characters with an uppercase letter, number, and special character." autocomplete="new-password" required>
                <label for="confirm_password">Confirm new password</label>
                <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
                <p class="helper-text">At least 8 characters, with an uppercase letter, a number, and a special character.</p>
                <button type="submit">Update Password</button>
            </form>
        </section>
    </div>
</main>
<?php $conn->close(); ?>
<?php require __DIR__ . "/site_footer.php"; ?>
</body>
</html>
