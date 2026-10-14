<?php
require_once __DIR__ . "/config.php";

$conn = new mysqli(
    $host,
    $username,
    $password,
    $database,
    $port
);

if ($conn->connect_error) {
    die("Database connection failed.");
}

$conn->set_charset("utf8mb4");

function dbHasColumn(mysqli $connection, string $table, string $column): bool
{
    $table = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $column = preg_replace('/[^A-Za-z0-9_]/', '', $column);

    if ($table === "" || $column === "") {
        return false;
    }

    $result = $connection->query("SHOW COLUMNS FROM `" . $table . "` LIKE '" . $column . "'");
    if ($result === false) {
        return false;
    }

    return $result->num_rows > 0;
}
?>
