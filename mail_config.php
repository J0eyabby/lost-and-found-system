<?php
require_once __DIR__ . "/config.php";

return [
    "host" => $mail_host ?? (getenv("MAIL_HOST") ?: "smtp.gmail.com"),
    "port" => (int) ($mail_port ?? (getenv("MAIL_PORT") ?: 587)),
    "username" => $mail_username ?? (getenv("MAIL_USERNAME") ?: ""),
    "password" => $mail_password ?? (getenv("MAIL_PASSWORD") ?: ""),
    "encryption" => $mail_encryption ?? (getenv("MAIL_ENCRYPTION") ?: "tls"),
];
