
<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once "db.php";
require_once __DIR__ . "/activity_log.php";
require_once __DIR__ . "/user_notifications.php";

// Check whether the user is logged in
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

// Verify administrator role from the database
$user_id = $_SESSION["user_id"];

$stmt = $conn->prepare(
    "SELECT role FROM users WHERE user_id = ?"
);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($role);
$userExists = $stmt->fetch();
$stmt->close();

if (!$userExists || $role !== "admin") {
    http_response_code(403);
    exit("Access denied. Administrators only.");
}

// Create a security token for form submissions
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$message = "";

// Handle item moderation and deletion.
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";
    $itemId = filter_input(
        INPUT_POST,
        "item_id",
        FILTER_VALIDATE_INT
    );
    $action = $_POST["action"] ?? "delete";
    $reason = trim((string) ($_POST["moderation_reason"] ?? ""));

    if (
        !is_string($token) ||
        !hash_equals($_SESSION["csrf_token"], $token)
    ) {
        $message = "Invalid request. Please refresh the page.";
    } elseif (!$itemId || $itemId < 1) {
        $message = "Invalid item selected.";
    } elseif ($action === "moderate" && strlen($reason) > 500) {
        $message = "The moderation reason must be no longer than 500 characters.";
    } elseif ($action === "moderate" && $reason === "" && (int) ($_POST["is_hidden"] ?? 0) === 1) {
        $message = "Provide a clear reason before hiding a report.";
    } elseif ($action === "moderate") {
        $hidden = (int) ($_POST["is_hidden"] ?? 0) === 1 ? 1 : 0;
        $reasonValue = $hidden === 1 ? $reason : "";
        $update = $conn->prepare(
            "UPDATE items SET is_hidden = ?, moderation_reason = NULLIF(?, '')
             WHERE item_id = ?"
        );
        $update->bind_param("isi", $hidden, $reasonValue, $itemId);
        if ($update->execute() && $update->affected_rows > 0) {
            recordActivity($conn, (int) $user_id, $hidden ? "item_hidden_by_admin" : "item_restored_by_admin", "Item ID " . $itemId . ($reasonValue !== "" ? "; reason: " . $reasonValue : ""));
            $message = $hidden ? "Report hidden from public listings." : "Report restored to public listings.";
            $ownerQuery = $conn->prepare("SELECT user_id, item_name FROM items WHERE item_id = ?");
            $ownerQuery->bind_param("i", $itemId);
            $ownerQuery->execute();
            $owner = $ownerQuery->get_result()->fetch_assoc();
            $ownerQuery->close();
            if ($owner) {
                $notice = $hidden
                    ? "Your report for " . $owner["item_name"] . " was hidden. Reason: " . $reasonValue
                    : "Your report for " . $owner["item_name"] . " was restored to public listings.";
                addUserNotification($conn, (int) $owner["user_id"], "items", $notice);
            }
        } else {
            $message = "Unable to update this report; it may already have that visibility.";
        }
        $update->close();
    } elseif ($action === "delete") {
        // Do not delete an item that has associated claims
        $check = $conn->prepare(
            "SELECT claim_id FROM claims WHERE item_id = ? LIMIT 1"
        );
        $check->bind_param("i", $itemId);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $message = "This item has associated claims. Manage its claims before deleting it.";
        } else {
            $delete = $conn->prepare(
                "DELETE FROM items WHERE item_id = ?"
            );
            $delete->bind_param("i", $itemId);

            if ($delete->execute() && $delete->affected_rows > 0) {
                recordActivity($conn, (int) $user_id, "item_deleted_by_admin", "Item ID " . $itemId);
                $message = "Item deleted successfully.";
            } else {
                $message = "Item not found or could not be deleted.";
            }

            $delete->close();
        }

        $check->close();
    } else {
        $message = "Invalid item-management action.";
    }
}

// Get the selected filter
$filter = $_GET["status"] ?? "all";

if (!in_array($filter, ["all", "lost", "found"], true)) {
    $filter = "all";
}

// Retrieve reported items
if ($filter === "all") {
    $sql = "SELECT items.item_id, items.item_name,
                   items.description, items.category,
                   items.status, items.location,
                   items.date_lost_found, items.created_at,
                   items.last_confirmed_at,
                   CASE WHEN COALESCE(items.last_confirmed_at, items.created_at) <= DATE_SUB(NOW(), INTERVAL 90 DAY) THEN 1 ELSE 0 END AS review_due,
                   items.is_hidden, items.moderation_reason,
                   (SELECT COUNT(*) FROM items AS duplicate
                    WHERE duplicate.status = items.status
                      AND duplicate.resolved_at IS NULL
                      AND duplicate.item_id <> items.item_id
                      AND LOWER(duplicate.item_name) = LOWER(items.item_name)) AS duplicate_count,
                   users.full_name
            FROM items
            INNER JOIN users
                ON items.user_id = users.user_id
            ORDER BY items.created_at DESC";

    $result = $conn->query($sql);
} else {
    $sql = "SELECT items.item_id, items.item_name,
                   items.description, items.category,
                   items.status, items.location,
                   items.date_lost_found, items.created_at,
                   items.last_confirmed_at,
                   CASE WHEN COALESCE(items.last_confirmed_at, items.created_at) <= DATE_SUB(NOW(), INTERVAL 90 DAY) THEN 1 ELSE 0 END AS review_due,
                   items.is_hidden, items.moderation_reason,
                   (SELECT COUNT(*) FROM items AS duplicate
                    WHERE duplicate.status = items.status
                      AND duplicate.resolved_at IS NULL
                      AND duplicate.item_id <> items.item_id
                      AND LOWER(duplicate.item_name) = LOWER(items.item_name)) AS duplicate_count,
                   users.full_name
            FROM items
            INNER JOIN users
                ON items.user_id = users.user_id
            WHERE items.status = ?
            ORDER BY items.created_at DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $filter);
    $stmt->execute();
    $result = $stmt->get_result();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Reported Items</title>

<link rel="stylesheet" href="css/style.css?v=theme-20261014-12">
<script src="navigation.js" defer></script>

</head>
<body class="admin-theme">
<?php require __DIR__ . "/site_header.php"; ?>

    <main class="admin-page">
        <header class="admin-page-header">
            <div>
                <p class="eyebrow">Operations / Inventory</p>
                <h1>Manage Reported Items</h1>
            </div>
            <nav class="admin-nav" aria-label="Administrator navigation">
                <a href="admin.php" class="button button-small">Dashboard</a>
                <a href="settings.php#profile-settings" class="button button-small button-secondary">Settings</a>
                <a href="manage_claims.php" class="button button-small button-secondary">Claims</a>
                <a href="manage_users.php" class="button button-small button-secondary">Users</a>
                <a href="dashboard.php" class="button button-small button-secondary">Dashboard</a>
                <a href="index.php" class="button button-small button-secondary">View Site</a>
                <a href="logout.php" class="button button-small button-ghost">Logout</a>
            </nav>
        </header>

    <?php if ($message !== ""): ?>
        <p>
            <strong><?= htmlspecialchars($message) ?></strong>
        </p>
    <?php endif; ?>

    <section class="admin-panel">
    <h2>Filter Items</h2>

    <nav class="filter-pills" aria-label="Filter items">
        <a class="button button-small <?= $filter === "all" ? "" : "button-secondary" ?>" href="manage_items.php?status=all">All Items</a>
        <a class="button button-small <?= $filter === "lost" ? "" : "button-secondary" ?>" href="manage_items.php?status=lost">Lost Items</a>
        <a class="button button-small <?= $filter === "found" ? "" : "button-secondary" ?>" href="manage_items.php?status=found">Found Items</a>
    </nav>

    <h2>
        <?php
        if ($filter === "lost") {
            echo "Lost Items";
        } elseif ($filter === "found") {
            echo "Found Items";
        } else {
            echo "All Reported Items";
        }
        ?>
    </h2>

    <?php if ($result && $result->num_rows > 0): ?>

        <table border="1" cellpadding="8" cellspacing="0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Item Name</th>
                    <th>Description</th>
                    <th>Category</th>
                    <th>Status</th>
                    <th>Location</th>
                    <th>Date Lost/Found</th>
                    <th>Reported By</th>
                    <th>Date Reported</th>
                    <th>Moderation / duplicate review</th>
                    <th>Manage</th>
                </tr>
            </thead>

            <tbody>
                <?php while ($item = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= (int) $item["item_id"] ?></td>

                        <td>
                            <?= htmlspecialchars($item["item_name"]) ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($item["description"] ?? "") ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($item["category"] ?? "") ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($item["status"]) ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($item["location"] ?? "") ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($item["date_lost_found"] ?? "Not specified") ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($item["full_name"]) ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($item["created_at"]) ?>
                        </td>
                        <td>
                            <?= (int) $item["is_hidden"] === 1 ? "Hidden: " . htmlspecialchars($item["moderation_reason"] ?? "", ENT_QUOTES, "UTF-8") : "Public" ?>
                            <?php if ((int) $item["duplicate_count"] > 0): ?><br><strong>Possible duplicate title</strong><?php endif; ?>
                            <?php if ((int) $item["review_due"] === 1 && (int) $item["is_hidden"] === 0): ?><br><strong>Reporter review due (90+ days)</strong><?php endif; ?>
                        </td>

                        <td>
                            <a class="button button-small button-secondary" href="edit_item.php?item_id=<?= (int) $item["item_id"] ?>">Edit</a>
                            <form method="post" action="manage_items.php?status=<?= htmlspecialchars($filter) ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8") ?>">
                                <input type="hidden" name="item_id" value="<?= (int) $item["item_id"] ?>">
                                <input type="hidden" name="action" value="moderate">
                                <?php if ((int) $item["is_hidden"] === 1): ?>
                                    <input type="hidden" name="is_hidden" value="0">
                                    <button class="button button-small button-secondary" type="submit">Restore listing</button>
                                <?php else: ?>
                                    <input type="hidden" name="is_hidden" value="1">
                                    <label for="moderation-reason-<?= (int) $item["item_id"] ?>">Reason to hide report</label>
                                    <input id="moderation-reason-<?= (int) $item["item_id"] ?>" name="moderation_reason" maxlength="500" required>
                                    <button class="button button-small button-danger" type="submit">Hide listing</button>
                                <?php endif; ?>
                            </form>
                            <form
                                method="POST"
                                action="manage_items.php?status=<?= htmlspecialchars($filter) ?>"
                                onsubmit="return confirm('Are you sure you want to delete this item report?');"
                            >
                                <input type="hidden" name="action" value="delete">
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="item_id"
                                    value="<?= (int) $item["item_id"] ?>"
                                >

                                <button type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>

    <?php else: ?>

        <p>No items found for this selection.</p>

    <?php endif; ?>
    </section>
    </main>
    <?php require __DIR__ . "/site_footer.php"; ?>
</body>
</html>
<?php
if (isset($stmt) && $filter !== "all") {
    $stmt->close();
}
$conn->close();
?>
