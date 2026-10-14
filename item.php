<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/account_guard.php";
require_once __DIR__ . "/assets/image_helper.php";
enforceActiveAccount($conn);

$itemId = filter_input(INPUT_GET, "item_id", FILTER_VALIDATE_INT);
if (!$itemId || $itemId < 1) {
    http_response_code(400);
    exit("Select a valid item.");
}
$query = $conn->prepare(
    "SELECT items.item_id, items.user_id, items.item_name, items.description,
            items.category, items.status, items.location, items.date_lost_found,
            items.image, items.owner_hint, users.full_name
     FROM items
     INNER JOIN users ON users.user_id = items.user_id
     WHERE items.item_id = ? AND items.resolved_at IS NULL AND items.is_hidden = 0"
);
$query->bind_param("i", $itemId);
$query->execute();
$item = $query->get_result()->fetch_assoc();
$query->close();
if (!$item) {
    http_response_code(404);
    exit("This item is no longer available.");
}
$userId = (int) ($_SESSION["user_id"] ?? 0);
$isOwner = $userId > 0 && $userId === (int) $item["user_id"];
$itemUrl = "item.php?item_id=" . (int) $item["item_id"];
function itemPageEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= itemPageEscape($item["item_name"]) ?> - Lost and Found</title>
    <link rel="stylesheet" href="css/style.css?v=theme-20261014-10">
    <script src="share-item.js" defer></script>
    <script src="item-photo-zoom.js" defer></script>
</head>
<body>
<?php require __DIR__ . "/site_header.php"; ?>
<main class="container item-detail-page">
    <article class="item-detail-card">
        <?php $imageUrl = getItemImageUrl($item["image"] ?? null); ?>
        <?php if ($imageUrl !== null): ?>
            <button class="item-detail-photo-button" type="button" data-zoom-photo="<?= itemPageEscape($imageUrl) ?>" data-zoom-alt="<?= itemPageEscape($item["item_name"]) ?>">
                <img class="item-detail-photo" src="<?= itemPageEscape($imageUrl) ?>" alt="<?= itemPageEscape($item["item_name"]) ?>">
                <span>Tap to zoom photo</span>
            </button>
        <?php else: ?>
            <div class="item-detail-photo item-thumbnail-placeholder" role="img" aria-label="No photo available">
                <span>No photo available</span>
            </div>
        <?php endif; ?>
        <div class="item-detail-content">
            <p class="eyebrow">Shared community listing</p>
            <h1><?= itemPageEscape($item["item_name"]) ?></h1>
            <span class="status <?= $item["status"] === "lost" ? "status-lost" : "status-found" ?>"><?= itemPageEscape(ucfirst($item["status"])) ?></span>
            <p><strong>Description:</strong> <?= itemPageEscape($item["description"] ?: "Not provided") ?></p>
            <p><strong>Category:</strong> <?= itemPageEscape($item["category"] ?: "Not specified") ?></p>
            <p><strong>Location:</strong> <?= itemPageEscape($item["location"] ?: "Not specified") ?></p>
            <p><strong>Date:</strong> <?= itemPageEscape($item["date_lost_found"] ?: "Not specified") ?></p>
            <p><strong>Reported by:</strong> <?= itemPageEscape($item["full_name"]) ?></p>
            <div class="item-detail-actions">
                <?php if ($item["status"] === "found" && !$isOwner && trim((string) $item["owner_hint"]) !== ""): ?>
                    <?php if ($userId > 0): ?>
                        <a class="button button-claim" href="claim_item.php?item_id=<?= (int) $item["item_id"] ?>">Claim this item</a>
                    <?php else: ?>
                        <a class="button button-claim" href="login.php?next=<?= rawurlencode("claim_item.php?item_id=" . (int) $item["item_id"]) ?>">Log in to claim</a>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($item["status"] === "found" && !$isOwner && trim((string) $item["owner_hint"]) === ""): ?>
                    <p class="helper-text">Claims are temporarily unavailable while the reporter adds a private ownership clue.</p>
                <?php endif; ?>
                <?php if ($isOwner): ?><a class="button button-secondary" href="edit_item.php?item_id=<?= (int) $item["item_id"] ?>">Edit your listing</a><?php endif; ?>
                <button type="button" class="button button-secondary" data-share-item data-share-url="<?= itemPageEscape($itemUrl) ?>" data-share-title="<?= itemPageEscape($item["item_name"]) ?>">Share item</button>
                <a class="button button-ghost" href="<?= itemPageEscape($itemUrl) ?>">Open item link</a>
            </div>
            <p class="share-feedback helper-text" aria-live="polite"></p>
        </div>
    </article>
    <?php if ($imageUrl !== null): ?>
        <dialog class="photo-zoom-dialog" data-photo-zoom-dialog aria-label="Item photo viewer">
            <div class="photo-zoom-toolbar">
                <button type="button" class="button button-small button-secondary" data-zoom-out aria-label="Zoom out">âˆ’</button>
                <button type="button" class="button button-small button-secondary" data-zoom-reset>Reset zoom</button>
                <button type="button" class="button button-small button-secondary" data-zoom-in aria-label="Zoom in">+</button>
                <button type="button" class="button button-small button-ghost" data-zoom-close>Close</button>
            </div>
            <div class="photo-zoom-scroll">
                <img data-zoom-image alt="">
            </div>
            <p class="helper-text" data-zoom-level aria-live="polite">100%</p>
        </dialog>
    <?php endif; ?>
</main>
<?php require __DIR__ . "/site_footer.php"; ?>
</body>
</html>
