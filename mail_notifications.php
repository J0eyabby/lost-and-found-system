<?php
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . "/vendor/autoload.php";

function sendCustomerNotification(string $email, string $subject, string $body): bool
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
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->send();
        return true;
    } catch (Throwable $error) {
        error_log("Customer notification email failed: " . $error->getMessage());
        return false;
    }
}
