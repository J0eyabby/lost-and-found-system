<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/account_guard.php";
require_once __DIR__ . "/assets/image_helper.php";
require_once __DIR__ . "/item_date_validation.php";
enforceActiveAccount($conn);

function escapeAllItemsLikeValue(string $value): string
{
    return str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], $value);
}

$userId = (int) ($_SESSION["user_id"] ?? 0);
$search = trim($_GET["search"] ?? "");
$category = trim($_GET["category"] ?? "");
$location = trim($_GET["location"] ?? "");
$dateFrom = trim($_GET["date_from"] ?? "");
$dateTo = trim($_GET["date_to"] ?? "");
$status = trim($_GET["status"] ?? "all");
$dateError = "";

if (!in_array($status, ["all", "lost", "found"], true)) {
    $status = "all";
}

if (!isValidPastOrPresentDateRange($dateFrom, $dateTo)) {
    $dateError = "Choose valid dates up to today, with the From date no later than the To date.";
    $dateFrom = "";
    $dateTo = "";
}

$categoryOptions = [];
$categoryResult = $conn->query("SELECT name FROM item_categories WHERE is_active = 1 ORDER BY name");
if ($categoryResult) {
    while ($categoryRow = $categoryResult->fetch_assoc()) {
        $categoryOptions[] = (string) $categoryRow["name"];
    }
}
if ($category !== "" && !in_array($category, $categoryOptions, true)) {
    $category = "";
}

$sql = "SELECT items.item_id, items.item_name, items.description, items.category,
               items.status, items.location, items.date_lost_found, items.created_at,
               items.image, items.user_id, items.owner_hint, items.resolved_at,
               items.is_hidden, users.full_name
        FROM items
        LEFT JOIN users ON users.user_id = items.user_id
        WHERE items.resolved_at IS NULL AND items.is_hidden = 0";
$queryValues = [];

if ($search !== "") {
    $likeSearch = "%" . escapeAllItemsLikeValue($search) . "%";
    $sql .= " AND (items.item_name LIKE ? OR items.description LIKE ?
                   OR items.category LIKE ? OR items.location LIKE ?)";
    array_push($queryValues, $likeSearch, $likeSearch, $likeSearch, $likeSearch);
}

if ($status !== "all") {
    $sql .= " AND items.status = ?";
    $queryValues[] = $status;
}

if ($category !== "") {
    $sql .= " AND items.category = ?";
    $queryValues[] = $category;
}

if ($location !== "") {
    $sql .= " AND items.location LIKE ?";
    $queryValues[] = "%" . escapeAllItemsLikeValue($location) . "%";
}

if ($dateFrom !== "") {
    $sql .= " AND items.date_lost_found >= ?";
    $queryValues[] = $dateFrom;
}

if ($dateTo !== "") {
    $sql .= " AND items.date_lost_found <= ?";
    $queryValues[] = $dateTo;
}

$sql .= " ORDER BY items.created_at DESC, items.item_id DESC";

$stmt = $conn->prepare($sql);
$items = null;
$loadError = "";
if ($stmt === false) {
    $loadError = "Unable to load items right now.";
} else {
    if ($queryValues !== []) {
        $bindArguments = [str_repeat("s", count($queryValues))];
        foreach ($queryValues as $index => &$queryValue) {
            $bindArguments[] = &$queryValue;
        }
        unset($queryValue);
        $bindResult = call_user_func_array([$stmt, "bind_param"], $bindArguments);
    } else {
        $bindResult = true;
    }

    if ($bindResult === false || !$stmt->execute()) {
        $loadError = "Unable to load items right now.";
    } else {
        $items = $stmt->get_result();
        if ($items === false) {
            $loadError = "Unable to load items right now.";
        }
    }
}

$profilePhoto = null;
if ($userId > 0) {
    $profileStmt = $conn->prepare("SELECT profile_photo FROM users WHERE user_id = ?");
    if ($profileStmt !== false) {
        $profileStmt->bind_param("i", $userId);
        if ($profileStmt->execute()) {
            $profileStmt->bind_result($profilePhoto);
            $profileStmt->fetch();
        }
        $profileStmt->close();
    }
}

$filtersActive = $search !== "" || $category !== "" || $location !== ""
    || $dateFrom !== "" || $dateTo !== "" || $status !== "all";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Items - Lost and Found</title>
    <link rel="stylesheet" href="css/style.css?v=theme-20261014-12">
    <script src="date-range.js" defer></script>
</head>
<body>
<?php require __DIR__ . "/site_header.php"; ?>
<main class="container browse-page">
    <h1 class="browse-title">All Reported Items</h1>

    <form method="GET" class="browse-search browse-filter-form">
        <label for="search">Search by name, description, category, or location</label>
        <input id="search" name="search" type="search" value="<?= htmlspecialchars($search) ?>" placeholder="Describe what you remember">
        <label for="category">Category</label>
        <select id="category" name="category">
            <option value="">All categories</option>
            <?php foreach ($categoryOptions as $categoryOption): ?>
                <option value="<?= htmlspecialchars($categoryOption, ENT_QUOTES, "UTF-8") ?>"
                    <?= $category === $categoryOption ? "selected" : "" ?>>
                    <?= htmlspecialchars($categoryOption) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label for="location">Location</label>
        <input id="location" name="location" type="search" value="<?= htmlspecialchars($location) ?>" placeholder="Location">
        <label for="date-from">Incident date from</label>
        <input id="date-from" name="date_from" type="date" max="<?= date("Y-m-d") ?>" value="<?= htmlspecialchars($dateFrom) ?>">
        <label for="date-to">Incident date to</label>
        <input id="date-to" name="date_to" type="date" max="<?= date("Y-m-d") ?>" value="<?= htmlspecialchars($dateTo) ?>">
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="all" <?= $status === "all" ? "selected" : "" ?>>All statuses</option>
            <option value="lost" <?= $status === "lost" ? "selected" : "" ?>>Lost</option>
            <option value="found" <?= $status === "found" ? "selected" : "" ?>>Found</option>
        </select>
        <button type="submit">Search</button>
        <?php if ($dateError !== ""): ?>
            <p class="form-error" role="alert"><?= htmlspecialchars($dateError, ENT_QUOTES, "UTF-8") ?></p>
        <?php endif; ?>
        <?php if ($filtersActive): ?>
            <a href="all_items.php" class="clear-link">Clear filters</a>
        <?php endif; ?>
    </form>

    <section class="browse-intro">
        <p>Browse every item reported to the community, including older historical reports.</p>
        <div class="browse-shortcuts">
            <a class="button button-small button-secondary" href="lost_items.php">Browse Lost Items</a>
            <a class="button button-small button-secondary" href="found_items.php">Browse Found Items</a>
        </div>
    </section>

    <?php if ($loadError !== ""): ?>
        <p class="empty-message"><?= htmlspecialchars($loadError) ?></p>
    <?php elseif ($items && $items->num_rows > 0): ?>
        <div class="items-grid">
            <?php while ($item = $items->fetch_assoc()): ?>
                <?php
                $imageUrl = getItemImageUrl($item["image"] ?? null);
                $isOwner = $userId > 0 && $userId === (int) $item["user_id"];
                $statusClass = $item["status"] === "lost" ? "status-lost" : "status-found";
                ?>
                <article class="item-card">
                    <?php if ($imageUrl !== null): ?>
                        <a class="item-thumbnail-link" href="item.php?item_id=<?= (int) $item["item_id"] ?>" aria-label="View <?= htmlspecialchars($item["item_name"], ENT_QUOTES, "UTF-8") ?>">
                            <img class="item-photo" src="<?= htmlspecialchars($imageUrl, ENT_QUOTES, "UTF-8") ?>" alt="<?= htmlspecialchars($item["item_name"], ENT_QUOTES, "UTF-8") ?>">
                        </a>
                    <?php else: ?>
                        <a class="item-thumbnail-link item-thumbnail-placeholder" href="item.php?item_id=<?= (int) $item["item_id"] ?>" aria-label="View <?= htmlspecialchars($item["item_name"], ENT_QUOTES, "UTF-8") ?>">
                            <span aria-hidden="true">No photo available</span>
                        </a>
                    <?php endif; ?>
                    <div class="item-body">
                        <h2><a class="item-title-link" href="item.php?item_id=<?= (int) $item["item_id"] ?>"><?= htmlspecialchars($item["item_name"]) ?></a></h2>
                        <span class="status <?= $statusClass ?>"><?= htmlspecialchars(ucfirst($item["status"])) ?></span>
                        <?php if ((int) $item["is_hidden"] === 1): ?>
                            <span class="status">Hidden</span>
                        <?php elseif ($item["resolved_at"] !== null): ?>
                            <span class="status">Resolved</span>
                        <?php endif; ?>
                        <p><strong>Description:</strong> <?= htmlspecialchars($item["description"] ?? "Not provided") ?></p>
                        <p><strong>Category:</strong> <?= htmlspecialchars($item["category"] ?? "Not specified") ?></p>
                        <p><strong>Location:</strong> <?= htmlspecialchars($item["location"] ?? "Not specified") ?></p>
                        <p><strong>Date:</strong> <?= htmlspecialchars($item["date_lost_found"] ?? "Not specified") ?></p>
                        <p><strong>Reported by:</strong> <?= htmlspecialchars($item["full_name"] ?? "Unknown") ?></p>
                        <?php if ($isOwner): ?>
                            <div class="item-actions">
                                <a href="edit_item.php?item_id=<?= (int) $item["item_id"] ?>" class="button button-small button-secondary">Edit details</a>
                            </div>
                        <?php endif; ?>
                        <a href="item.php?item_id=<?= (int) $item["item_id"] ?>" class="button button-small button-secondary">View details</a>
                    </div>
                </article>
            <?php endwhile; ?>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <h3>No items match your filters</h3>
            <p>Try removing a filter or search for a different item, location, or category.</p>
            <a class="button button-small button-secondary" href="all_items.php">Clear filters</a>
        </div>
    <?php endif; ?>
</main>
<?php require __DIR__ . "/site_footer.php"; ?>
</body>
</html>
<?php
if ($stmt !== false) {
    $stmt->close();
}
$conn->close();
?>
