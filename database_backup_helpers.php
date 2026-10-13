<?php

function writeDatabaseSqlDump(mysqli $connection, callable $write): int
{
    $tablesResult = $connection->query("SHOW TABLES");
    if ($tablesResult === false) {
        throw new RuntimeException("Unable to list database tables: " . $connection->error);
    }
    $tables = [];
    while ($tableRow = $tablesResult->fetch_row()) {
        $tables[] = (string) $tableRow[0];
    }
    $tablesResult->free();

    $write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
    foreach ($tables as $table) {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $table) !== 1) {
            throw new RuntimeException("Unexpected database table name.");
        }
        $safeTable = "`" . $table . "`";
        $createResult = $connection->query("SHOW CREATE TABLE " . $safeTable);
        if ($createResult === false) {
            throw new RuntimeException("Unable to read table definition for " . $table . ": " . $connection->error);
        }
        $definition = $createResult->fetch_assoc();
        $createResult->free();
        $createSql = (string) ($definition["Create Table"] ?? "");
        if ($createSql === "") {
            throw new RuntimeException("Missing table definition for " . $table . ".");
        }
        $write("DROP TABLE IF EXISTS " . $safeTable . ";\n" . $createSql . ";\n");

        $rows = $connection->query("SELECT * FROM " . $safeTable, MYSQLI_USE_RESULT);
        if ($rows === false) {
            throw new RuntimeException("Unable to export table data for " . $table . ": " . $connection->error);
        }
        $fields = $rows->fetch_fields();
        while ($row = $rows->fetch_row()) {
            $values = [];
            foreach ($row as $value) {
                $values[] = $value === null ? "NULL" : "'" . $connection->real_escape_string((string) $value) . "'";
            }
            $fieldList = implode(", ", array_map(
                static fn(object $field): string => "`" . str_replace("`", "``", $field->name) . "`",
                $fields
            ));
            $write("INSERT INTO " . $safeTable . " (" . $fieldList . ") VALUES (" . implode(", ", $values) . ");\n");
        }
        $rows->free();
        $write("\n");
    }
    $write("SET FOREIGN_KEY_CHECKS=1;\n");
    return count($tables);
}
