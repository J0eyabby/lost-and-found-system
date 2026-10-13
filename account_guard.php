<?php

function enforceActiveAccount(mysqli $conn): void
{
    $userId = (int) ($_SESSION["user_id"] ?? 0);
    if ($userId < 1) {
        return;
    }
    $stmt = $conn->prepare(
        "SELECT COALESCE(user_account_status.is_active, 1),
                user_account_status.suspension_reason
         FROM users
         LEFT JOIN user_account_status ON user_account_status.user_id = users.user_id
         WHERE users.user_id = ?"
    );
    if ($stmt === false) {
        error_log("Unable to prepare account status check: " . $conn->error);
        http_response_code(500);
        exit("Unable to verify the account.");
    }
    $stmt->bind_param("i", $userId);
    if (!$stmt->execute()) {
        error_log("Unable to check account status: " . $stmt->error);
        $stmt->close();
        http_response_code(500);
        exit("Unable to verify the account.");
    }
    $stmt->bind_result($isActive, $suspensionReason);
    $exists = $stmt->fetch();
    $stmt->close();
    if ($exists && (int) $isActive === 1) {
        return;
    }
    $_SESSION["account_suspension_reason"] = (string) ($suspensionReason ?? "");
    foreach (array_keys($_SESSION) as $key) {
        if ($key !== "account_suspension_reason") {
            unset($_SESSION[$key]);
        }
    }
    session_regenerate_id(true);
    header("Location: login.php?account=suspended");
    exit;
}
