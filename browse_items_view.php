<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/account_guard.php";
require_once __DIR__ . "/smart_assistant.php";
require_once __DIR__ . "/assets/image_helper.php";
enforceActiveAccount($conn);

$userId = (int) ($_SESSION["user_id"] ?? 0);
if ($userId > 0 && empty($_SESSION["lifecycle_csrf_token"])) {
    $_SESSION["lifecycle_csrf_token"] = bin2hex(random_bytes(32));
}
$search = trim($_GET["search"] ?? "");
$category = trim($_GET["category"] ?? "");
$location = trim($_GET["location"] ?? "");
$dateFrom = trim($_GET["date_from"] ?? "");
$dateTo = trim($_GET["date_to"] ?? "");

foreach (["dateFrom", "dateTo"] as $dateKey) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $$dateKey)) {
        $$dateKey = "";
    }
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

$filtersActive = $search !== "" || $category !== "" || $location !== ""
    || $dateFrom !== "" || $dateTo !== "";
$query = $conn->prepare(
    "SELECT items.item_id, items.item_name, items.description, items.category,
            items.status, items.location, items.date_lost_found, items.image,
            items.user_id, items.owner_hint, users.full_name
     FROM items
     INNER JOIN users ON users.user_id = items.user_id
     WHERE items.status = ? AND items.resolved_at IS NULL AND items.is_hidden = 0
       AND (items.item_name LIKE ? OR items.description LIKE ?
            OR items.category LIKE ? OR items.location LIKE ?)
       AND (? = '' OR items.category = ?)
       AND (? = '' OR items.location LIKE ?)
       AND (? = '' OR items.date_lost_found >= ?)
       AND (? = '' OR items.date_lost_found <= ?)
     ORDER BY items.created_at DESC
     LIMIT 24"
);
$statusLike = "%" . str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], $search) . "%";
$locationLike = "%" . str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], $location) . "%";
$query->bind_param(
    "sssssssssssss",
    $browseStatus,
    $statusLike,
    $statusLike,
    $statusLike,
    $statusLike,
    $category,
    $category,
    $location,
    $locationLike,
    $dateFrom,
    $dateFrom,
    $dateTo,
    $dateTo
);
$query->execute();
$items = $query->get_result();

$profilePhoto = null;
if ($userId > 0) {
    $profileStmt = $conn->prepare("SELECT profile_photo FROM users WHERE user_id = ?");
    $profileStmt->bind_param("i", $userId);
    $profileStmt->execute();
    $profileStmt->bind_result($profilePhoto);
    $profileStmt->fetch();
    $profileStmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($browseTitle) ?> - Lost and Found</title>
    <link rel="stylesheet" href="css/style.css?v=20261014">
</head>
<body>
<?php require __DIR__ . "/site_header.php"; ?>
<main class="container browse-page">
    <h1 class="browse-title"><?= htmlspecialchars($browseTitle) ?></h1>
    <section class="browse-intro">
        <p><?= htmlspecialchars($browseIntro) ?></p>
        <?php if ($userId > 0): ?>
            <a class="button button-small" href="my_items.php">My Reports</a>
        <?php else: ?>
            <a class="button button-small" href="login.php">Sign in to report or claim</a>
        <?php endif; ?>
    </section>
    <form method="GET" class="browse-search browse-filter-form">
        <label for="search">Search <?= strtolower($browseTitle) ?> by name, description, category, or location</label>
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
        <label for="date-from">From date</label>
        <input id="date-from" name="date_from" type="date" max="<?= date("Y-m-d") ?>" value="<?= htmlspecialchars($dateFrom) ?>">
        <label for="date-to">To date</label>
        <input id="date-to" name="date_to" type="date" max="<?= date("Y-m-d") ?>" value="<?= htmlspecialchars($dateTo) ?>">
        <button type="submit">Search</button>
        <?php if ($filtersActive): ?>
            <a href="<?= htmlspecialchars($browseStatus === "lost" ? "lost_items.php" : "found_items.php") ?>" class="clear-link">Clear filters</a>
        <?php endif; ?>
    </form>

    <?php if ($items->num_rows > 0): ?>
        <div class="items-grid">
            <?php while ($item = $items->fetch_assoc()): ?>
                <?php
                $imageUrl = getItemImageUrl($item["image"] ?? null);
                $isOwner = $userId > 0 && $userId === (int) $item["user_id"];
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
                        <span class="status <?= $browseStatus === "lost" ? "status-lost" : "status-found" ?>"><?= htmlspecialchars(ucfirst($browseStatus)) ?></span>
                        <p><strong>Description:</strong> <?= htmlspecialchars($item["description"] ?? "Not provided") ?></p>
                        <p><strong>Category:</strong> <?= htmlspecialchars($item["category"] ?? "Not specified") ?></p>
                        <p><strong>Location:</strong> <?= htmlspecialchars($item["location"] ?? "Not specified") ?></p>
                        <p><strong>Date:</strong> <?= htmlspecialchars($item["date_lost_found"] ?? "Not specified") ?></p>
                        <p><strong>Reported by:</strong> <?= htmlspecialchars($item["full_name"]) ?></p>
                        <?php if ($isOwner): ?>
                            <div class="item-actions">
                                <a href="edit_item.php?item_id=<?= (int) $item["item_id"] ?>" class="button button-small button-secondary">Edit details</a>
                                <?php if ($browseStatus === "lost"): ?>
                                    <form method="POST" action="my_items.php">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["lifecycle_csrf_token"] ?? "", ENT_QUOTES, "UTF-8") ?>">
                                        <input type="hidden" name="action" value="mark_found">
                                        <input type="hidden" name="item_id" value="<?= (int) $item["item_id"] ?>">
                                        <button class="button button-small button-success" type="submit" onclick="return confirm('Mark this lost item as found and remove it from active listings?');">I found it</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($browseStatus === "found" && $userId > 0): ?>
                            <?php if (trim((string) $item["owner_hint"]) === ""): ?>
                                <p class="helper-text">Claims are temporarily unavailable while the reporter adds a private ownership clue.</p>
                            <?php else: ?>
                                <a href="claim_item.php?item_id=<?= (int) $item["item_id"] ?>" class="button button-small button-claim">Claim This Item</a>
                            <?php endif; ?>
                        <?php endif; ?>
                        <a href="item.php?item_id=<?= (int) $item["item_id"] ?>" class="button button-small button-secondary">View details</a>
                    </div>
                </article>
            <?php endwhile; ?>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <h3>No active <?= strtolower($browseTitle) ?> match your filters</h3>
            <p>Try a broader search or clear the filters to see more reports.</p>
            <a class="button button-small button-secondary" href="<?= htmlspecialchars($browseStatus === "lost" ? "lost_items.php" : "found_items.php") ?>">Clear filters</a>
        </div>
    <?php endif; ?>

    <?php
    $hasReporterConsentColumn = dbHasColumn($conn, "items", "reporter_return_consent");
    $hasClaimantConsentColumn = dbHasColumn($conn, "claims", "claimant_return_consent");
    $returned = null;
    if ($hasReporterConsentColumn && $hasClaimantConsentColumn) {
        $returned = $conn->query(
            "SELECT items.resolved_at
             FROM items
             INNER JOIN claims ON claims.item_id = items.item_id
             WHERE items.resolved_at IS NOT NULL
               AND claims.claim_status = 'approved'
               AND items.reporter_return_consent = 1
               AND claims.claimant_return_consent = 1
               AND items.reporter_return_confirmed_at IS NOT NULL
               AND claims.claimant_return_confirmed_at IS NOT NULL
             ORDER BY items.resolved_at DESC
             LIMIT 12"
        );
    }
    ?>
    <?php if ($returned && $returned->num_rows > 0): ?>
        <section class="returned-section">
            <p class="eyebrow">Reunited</p>
            <h2>Successfully Returned</h2>
            <p>Shared with permission from both people involved. No personal or verification details are shown.</p>
            <div class="returned-grid">
                <?php while ($return = $returned->fetch_assoc()): ?>
                    <article class="returned-card">
                        <span aria-hidden="true">âœ“</span>
                        <h3>A community item was returned</h3>
                        <p>Reunited with its owner</p>
                        <time datetime="<?= htmlspecialchars($return["resolved_at"]) ?>"><?= htmlspecialchars(date("M Y", strtotime($return["resolved_at"]))) ?></time>
                    </article>
                <?php endwhile; ?>
            </div>
        </section>
    <?php endif; ?>
</main>
<?php require __DIR__ . "/site_footer.php"; ?>
</body>
</html>
<?php
$query->close();
$conn->close();
?>
