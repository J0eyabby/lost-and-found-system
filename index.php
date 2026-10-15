<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once "db.php";
require_once __DIR__ . "/account_guard.php";
require_once __DIR__ . "/smart_assistant.php";
require_once __DIR__ . "/assets/image_helper.php";
require_once __DIR__ . "/item_date_validation.php";
enforceActiveAccount($conn);

function escapeLikeValue(string $value): string
{
    return str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], $value);
}

// Get the search term and selected status
$search = trim($_GET["search"] ?? "");
$status = $_GET["status"] ?? "all";
$category = trim($_GET["category"] ?? "");
$location = trim($_GET["location"] ?? "");
$dateFrom = trim($_GET["date_from"] ?? "");
$dateError = "";

// Accept only valid filter options
if (!in_array($status, ["all", "lost", "found"], true)) {
    $status = "all";
}

if (!isValidPastOrPresentItemDate($dateFrom)) {
    $dateError = "Choose a valid incident date up to today.";
    $dateFrom = "";
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

// Match each meaningful search word across public item details.
$sql = "SELECT items.item_id, items.item_name,
               items.description, items.category,
               items.status, items.location,
               items.date_lost_found, items.created_at,
               items.image, items.user_id, items.owner_hint, users.full_name
        FROM items
        INNER JOIN users ON items.user_id = users.user_id
        WHERE items.resolved_at IS NULL AND items.is_hidden = 0";
$searchTerms = array_slice(smartAssistantTokens($search), 0, 8);
$queryValues = [];
foreach ($searchTerms as $term) {
    $sql .= " AND (items.item_name LIKE ? OR items.description LIKE ?
                    OR items.category LIKE ? OR items.location LIKE ?)";
    $likeTerm = "%" . escapeLikeValue($term) . "%";
    array_push($queryValues, $likeTerm, $likeTerm, $likeTerm, $likeTerm);
}

if ($status !== "all") {
    $sql .= " AND items.status = ?";
    $queryValues[] = $status;
}

$filtersActive = $search !== "" || $status !== "all" || $category !== ""
    || $location !== "" || $dateFrom !== "";
if ($category !== "") {
    $sql .= " AND items.category = ?";
    $queryValues[] = $category;
}
if ($location !== "") {
    $sql .= " AND items.location LIKE ?";
    $queryValues[] = "%" . escapeLikeValue($location) . "%";
}
if ($dateFrom !== "") {
    $sql .= " AND items.date_lost_found >= ?";
    $queryValues[] = $dateFrom;
}

$sql .= " ORDER BY items.created_at DESC";
$sql .= " LIMIT 24";

$stmt = $conn->prepare($sql);
$result = null;
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
        $result = $stmt->get_result();

        if ($result === false) {
            $loadError = "Unable to load items right now.";
        }
    }
}

// Get the current user's role, if logged in
$currentRole = "";
$profilePhoto = null;

if (isset($_SESSION["user_id"])) {
    $userStmt = $conn->prepare(
        "SELECT role, profile_photo FROM users WHERE user_id = ?"
    );

    if ($userStmt !== false) {
        $userStmt->bind_param("i", $_SESSION["user_id"]);
        if ($userStmt->execute()) {
            $userStmt->bind_result($currentRole, $profilePhoto);
            $userStmt->fetch();
        }
        $userStmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lost and Found System</title>
    <link rel="stylesheet" href="css/style.css?v=theme-20261014-12">
    <script src="navigation.js" defer></script>
    <script src="date-range.js" defer></script>
</head>
<body>
<?php require __DIR__ . "/site_header.php"; ?>

    <main class="home-page">
        <?php if (($_GET["notice"] ?? "") === "item-deleted"): ?>
            <p class="feedback-banner success" role="status">Your item report was deleted.</p>
        <?php endif; ?>

        <section class="home-hero" aria-labelledby="home-hero-title">
            <div class="home-hero-content">
                <p class="home-eyebrow">Community-powered recovery</p>
                <h1 id="home-hero-title">Lost Something? <span>Let's Help You Find It.</span></h1>
                <p class="home-hero-subtitle">Connect with people reporting lost and found belongings, search active listings, and help return important items to their owners.</p>
                <div class="home-hero-actions">
                    <a class="button home-primary-action" href="#find-an-item">Find an Item</a>
                    <?php if (isset($_SESSION["user_id"])): ?>
                        <a class="button button-secondary home-secondary-action" href="report_item.php">Report an Item</a>
                    <?php else: ?>
                        <a class="button button-secondary home-secondary-action" href="login.php?next=report_item.php">Report an Item</a>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <div class="container home-content">
            <section class="search-panel" id="find-an-item">
                <p class="lead">Search by item name, description, category, or location.</p>
                <div class="browse-shortcuts">
                    <a href="lost_items.php" class="button button-small button-secondary">Browse Lost Items</a>
                    <a href="found_items.php" class="button button-small button-secondary">Browse Found Items</a>
                </div>
                <h2>Find an Item</h2>

                <!-- Search and filter form -->
                <form method="GET" action="index.php" class="search-form">
                <div class="field-group">
                    <label class="visually-hidden" for="search">Search by name, description, category, or location</label>
                    <input
                        type="search"
                        id="search"
                        name="search"
                        placeholder="Search items"
                        value="<?= htmlspecialchars($search) ?>"
                    >
                </div>

                <div class="field-group">
                    <label class="visually-hidden" for="status">Filter by status</label>
                    <select id="status" name="status">
                        <option value="all"
                            <?= $status === "all" ? "selected" : "" ?>>
                            All
                        </option>

                        <option value="lost"
                            <?= $status === "lost" ? "selected" : "" ?>>
                            Lost
                        </option>

                        <option value="found"
                            <?= $status === "found" ? "selected" : "" ?>>
                            Found
                        </option>
                    </select>
                </div>

                <div class="field-group">
                    <label class="visually-hidden" for="category">Filter by category</label>
                    <select id="category" name="category">
                        <option value="">All categories</option>
                        <?php foreach ($categoryOptions as $categoryOption): ?>
                            <option value="<?= htmlspecialchars($categoryOption, ENT_QUOTES, "UTF-8") ?>"
                                <?= $category === $categoryOption ? "selected" : "" ?>>
                                <?= htmlspecialchars($categoryOption) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field-group">
                    <label class="visually-hidden" for="location">Filter by location</label>
                    <input type="search" id="location" name="location"
                        placeholder="Location" value="<?= htmlspecialchars($location) ?>">
                </div>

                <div class="field-group date-filter-group">
                    <label for="date-from">Incident date from</label>
                    <input type="date" id="date-from" name="date_from"
                        max="<?= date("Y-m-d") ?>" value="<?= htmlspecialchars($dateFrom) ?>">
                </div>

                <?php if ($dateError !== ""): ?>
                    <p class="form-error full-width" role="alert"><?= htmlspecialchars($dateError, ENT_QUOTES, "UTF-8") ?></p>
                <?php endif; ?>

                <div class="search-actions">
                    <button class="search-submit" type="submit" aria-label="Search items" title="Search">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <circle cx="10.8" cy="10.8" r="6.3"></circle>
                            <path d="m15.5 15.5 5 5"></path>
                        </svg>
                    </button>
                </div>
                <?php if ($filtersActive): ?>
                    <a href="index.php" class="clear-link">Clear filters</a>
                <?php endif; ?>
                </form>
            </section>

             <section id="item-results"> 
            <div class="section-header">
                <h2>Recently Reported Items</h2>
            </div>

    <?php if (!empty($loadError)): ?>
        <p class="empty-message"><?= htmlspecialchars($loadError) ?></p>

    <?php elseif ($result && $result->num_rows > 0): ?>
        <div class="items-grid">
            <?php while ($item = $result->fetch_assoc()): ?>

<?php $itemImageUrl = getItemImageUrl($item["image"] ?? null); ?>

<article class="item-card">
    <?php if ($itemImageUrl !== null): ?>
        <a class="item-thumbnail-link" href="item.php?item_id=<?= (int) $item["item_id"] ?>" aria-label="View <?= htmlspecialchars($item["item_name"], ENT_QUOTES, 'UTF-8') ?>">
            <img
                src="<?= htmlspecialchars($itemImageUrl, ENT_QUOTES, 'UTF-8') ?>"
                alt="<?= htmlspecialchars($item["item_name"], ENT_QUOTES, 'UTF-8') ?>"
                class="item-photo"
            >
        </a>
    <?php else: ?>
        <a class="item-thumbnail-link item-thumbnail-placeholder" href="item.php?item_id=<?= (int) $item["item_id"] ?>" aria-label="View <?= htmlspecialchars($item["item_name"], ENT_QUOTES, 'UTF-8') ?>">
            <span aria-hidden="true">No photo available</span>
        </a>
    <?php endif; ?>
                    <div class="item-body">
                        <h3><a class="item-title-link" href="item.php?item_id=<?= (int) $item["item_id"] ?>"><?= htmlspecialchars($item["item_name"]) ?></a></h3>

                        <span class="status <?= htmlspecialchars($item["status"] === "lost" ? "status-lost" : "status-found") ?>">
                            <?= htmlspecialchars(ucfirst($item["status"])) ?>
                        </span>

                        <p><strong>Description:</strong> <?= htmlspecialchars($item["description"] ?? "Not provided") ?></p>
                        <p><strong>Category:</strong> <?= htmlspecialchars($item["category"] ?? "Not specified") ?></p>
                        <p><strong>Location:</strong> <?= htmlspecialchars($item["location"] ?? "Not specified") ?></p>
                        <p><strong>Date:</strong> <?= htmlspecialchars($item["date_lost_found"] ?? "Not specified") ?></p>
                        <p><strong>Reported by:</strong> <?= htmlspecialchars($item["full_name"]) ?></p>

                        <?php
                        $isOwner = isset($_SESSION["user_id"])
                            && (int) $_SESSION["user_id"] === (int) ($item["user_id"] ?? 0);
                        $canClaimItem = isset($_SESSION["user_id"])
                            && $item["status"] === "found"
                            && !$isOwner;
                        ?>
                        <?php if ($isOwner || $canClaimItem): ?>
                            <div class="item-actions">
                                <?php if ($isOwner): ?>
                                    <a href="edit_item.php?item_id=<?= (int) $item["item_id"] ?>" class="button button-small button-secondary">Edit details</a>
                                <?php elseif ($canClaimItem): ?>
                                    <?php if (trim((string) $item["owner_hint"]) !== ""): ?>
                                        <a href="claim_item.php?item_id=<?= (int) $item["item_id"] ?>" class="button button-small button-claim">Claim This Item</a>
                                    <?php else: ?>
                                        <p class="helper-text">Claims are unavailable until the reporter adds a private ownership clue.</p>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <a href="item.php?item_id=<?= (int) $item["item_id"] ?>" class="button button-small button-secondary">View details</a>
                    </div>
                </article>
            <?php endwhile; ?>
        </div>

    <?php else: ?>
        <div class="empty-state">
            <h3>No items match your search</h3>
            <p>Try removing a filter or search for a different item, location, or category.</p>
            <a class="button button-small button-secondary" href="index.php">Clear filters</a>
        </div>
    <?php endif; ?>
            </section>
        <?php
        $hasReporterConsentColumn = dbHasColumn($conn, "items", "reporter_return_consent");
        $hasClaimantConsentColumn = dbHasColumn($conn, "claims", "claimant_return_consent");
        $returnedItems = null;
        if ($hasReporterConsentColumn && $hasClaimantConsentColumn) {
            $returnedItems = $conn->query(
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
            <?php if ($returnedItems && $returnedItems->num_rows > 0): ?>
                <section class="returned-section">
                <p class="eyebrow">Reunited</p>
                <h2>Successfully Returned</h2>
                <p>Shared with permission from both people involved. No personal or verification details are shown.</p>
                <div class="returned-grid">
                    <?php while ($returned = $returnedItems->fetch_assoc()): ?>
                        <article class="returned-card">
                            <span aria-hidden="true">âœ“</span>
                            <h3>A community item was returned</h3>
                            <p>Reunited with its owner</p>
                            <time datetime="<?= htmlspecialchars($returned["resolved_at"]) ?>"><?= htmlspecialchars(date("M Y", strtotime($returned["resolved_at"]))) ?></time>
                        </article>
                    <?php endwhile; ?>
                </div>
                </section>
            <?php endif; ?>
        </div>
    </main>

    <?php require __DIR__ . "/site_footer.php"; ?>

    <?php
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    $conn->close();
    ?>
    <?php if ($filtersActive): ?>
        <script>
            document.addEventListener("DOMContentLoaded", function () {
                const results = document.getElementById("item-results");

                if (results) {
                    results.scrollIntoView({
                        behavior: "smooth",
                        block: "start"
                    });
                }
            });
        </script>
    <?php endif; ?>
</body>
</html>
