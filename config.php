<?php

/**
 * Runtime configuration for local Apache and Render.
 *
 * A local config.local.php file can override these values without being
 * committed. Render supplies the same values through environment variables.
 */
$host = getenv("DB_HOST") ?: "127.0.0.1";
$username = getenv("DB_USERNAME") ?: "root";
$password = getenv("DB_PASSWORD") ?: "";
$database = getenv("DB_DATABASE") ?: "lost_found";
$port = (int) (getenv("DB_PORT") ?: 3306);
$mail_host = getenv("MAIL_HOST") ?: "smtp.gmail.com";
$mail_port = (int) (getenv("MAIL_PORT") ?: 587);
$mail_username = getenv("MAIL_USERNAME") ?: "";
$mail_password = getenv("MAIL_PASSWORD") ?: "";
$mail_encryption = getenv("MAIL_ENCRYPTION") ?: "tls";

$localConfigPath = __DIR__ . "/config.local.php";
if (is_file($localConfigPath)) {
    require $localConfigPath;
}
