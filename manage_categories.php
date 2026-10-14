<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/activity_log.php";

$userId = (int) ($_SESSION["user_id"] ?? 0);
$role = "";
if ($userId > 0) {
    $adminQuery = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
    $adminQuery->bind_param("i", $userId);
    $adminQuery->execute();
    $adminQuery->bind_result($role);
    $adminQuery->fetch();
    $adminQuery->close();
}
if ($role !== "admin") {
    http_response_code(403);
    exit("Access denied. Administrators only.");
}
if (empty($_SESSION["category_csrf_token"])) {
    $_SESSION["category_csrf_token"] = bin2hex(random_bytes(32));
}
$message = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";
    $action = $_POST["action"] ?? "";
    if (!is_string($token) || !hash_equals($_SESSION["category_csrf_token"], $token)) {
        $message = "Your session expired. Refresh and try again.";
    } elseif ($action === "add") {
        $name = trim($_POST["name"] ?? "");
        if ($name === "" || strlen($name) > 80) {
            $message = "Enter a category name of up to 80 characters.";
        } else {
            $save = $conn->prepare("INSERT INTO item_categories (name) VALUES (?)");
            $save->bind_param("s", $name);
            if ($save->execute()) {
                recordActivity($conn, $userId, "category_created", $name);
                $message = "Category added.";
            } else {
                $message = $save->errno === 1062 ? "That category already exists." : "Unable to add the category.";
            }
            $save->close();
        }
    } elseif ($action === "toggle") {
        $categoryId = filter_input(INPUT_POST, "category_id", FILTER_VALIDATE_INT);
        $active = (int) ($_POST["is_active"] ?? 0) === 1 ? 1 : 0;
        if (!$categoryId || $categoryId < 1) {
            $message = "Select a valid category.";
        } else {
            $update = $conn->prepare("UPDATE item_categories SET is_active = ? WHERE category_id = ?");
            $update->bind_param("ii", $active, $categoryId);
            if ($update->execute() && $update->affected_rows === 1) {
                recordActivity($conn, $userId, $active ? "category_activated" : "category_deactivated", "Category ID " . $categoryId);
                $message = "Category status updated.";
            } else {
                $message = "Unable to update the category.";
            }
            $update->close();
        }
    } else {
        $message = "Invalid category action.";
    }
}
$categories = $conn->query("SELECT category_id, name, is_active, created_at FROM item_categories ORDER BY name");
function categoryEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Category Management</title><link rel="stylesheet" href="css/style.css?v=theme-20261014-10"></head>
<body><?php require __DIR__ . "/site_header.php"; ?>
<main class="container account-page">
    <h1 class="page-title">Category Management</h1>
    <?php if ($message !== ""): ?><p class="feedback-banner" role="status"><?= categoryEscape($message) ?></p><?php endif; ?>
    <section class="settings-card">
        <h2>Add a category</h2>
        <form method="post" class="stacked-form">
            <input type="hidden" name="csrf_token" value="<?= categoryEscape($_SESSION["category_csrf_token"]) ?>">
            <input type="hidden" name="action" value="add">
            <label for="category-name">Category name</label>
            <input id="category-name" name="name" maxlength="80" required>
            <button type="submit">Add Category</button>
        </form>
    </section>
    <section class="account-list">
        <?php while ($category = $categories->fetch_assoc()): ?>
            <article class="account-card">
                <h2><?= categoryEscape($category["name"]) ?></h2>
                <p>Status: <?= (int) $category["is_active"] ? "Active" : "Inactive" ?></p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= categoryEscape($_SESSION["category_csrf_token"]) ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="category_id" value="<?= (int) $category["category_id"] ?>">
                    <input type="hidden" name="is_active" value="<?= (int) !$category["is_active"] ?>">
                    <button class="button button-small button-secondary" type="submit"><?= (int) $category["is_active"] ? "Deactivate" : "Activate" ?></button>
                </form>
            </article>
        <?php endwhile; ?>
    </section>
</main>
<?php require __DIR__ . "/site_footer.php"; ?>
</body>
</html>
