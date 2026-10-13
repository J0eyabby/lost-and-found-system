<?php

function recordActivity(mysqli $conn, ?int $actorUserId, string $action, string $details = ""): bool
{
    $stmt = $conn->prepare(
        "INSERT INTO system_activity_logs (actor_user_id, action, details)
         VALUES (NULLIF(?, 0), ?, ?)"
    );
    if ($stmt === false) {
        error_log("Unable to prepare activity log: " . $conn->error);
        return false;
    }
    $actorId = $actorUserId ?? 0;
    $stmt->bind_param("iss", $actorId, $action, $details);
    $saved = $stmt->execute();
    if (!$saved) {
        error_log("Unable to record activity: " . $stmt->error);
    }
    $stmt->close();
    return $saved;
}
