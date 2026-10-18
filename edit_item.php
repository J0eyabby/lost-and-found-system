<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once "db.php";
require_once __DIR__ . "/account_guard.php";
require_once __DIR__ . "/user_notifications.php";
require_once __DIR__ . "/activity_log.php";
require_once __DIR__ . "/item_date_validation.php";
require_once __DIR__ . "/assets/image_helper.php";
require_once __DIR__ . "/vendor/autoload.php";
enforceActiveAccount($conn);

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$user_id = (int) $_SESSION["user_id"];
$item_id = filter_input(INPUT_GET, "item_id", FILTER_VALIDATE_INT);
$message = "";
$messageType = "error";
$success = false;

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

if (!$item_id || $item_id < 1) {
    header("Location: index.php");
    exit;
}

$stmt = $conn->prepare(
    "SELECT item_id, user_id, item_name, description, category, status, location,
            date_lost_found, image, owner_hint, private_handover_contact,
            private_handover_latitude, private_handover_longitude
     FROM items
     WHERE item_id = ?"
);
$stmt->bind_param("i", $item_id);
$stmt->execute();
$result = $stmt->get_result();
$item = $result->fetch_assoc();
$stmt->close();

if (!$item) {
    header("Location: index.php");
    exit;
}

$roleStmt = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
$roleStmt->bind_param("i", $user_id);
$roleStmt->execute();
$roleStmt->bind_result($editorRole);
$roleStmt->fetch();
$roleStmt->close();
$isAdminEditor = $editorRole === "admin";
$itemOwnerId = (int) $item["user_id"];
if ($itemOwnerId !== (int) $user_id && !$isAdminEditor) {
    http_response_code(403);
    exit("You can only edit your own item submissions.");
}

$formItemName = $item["item_name"];
$formDescription = $item["description"] ?? "";
$formCategory = $item["category"] ?? "";
$formStatus = $item["status"];
$formLocation = $item["location"] ?? "";
$formDate = $item["date_lost_found"] ?? "";
$currentImage = (string) ($item["image"] ?? "");
$formOwnerHint = $item["owner_hint"] ?? "";
$formHandoverContact = $item["private_handover_contact"] ?? "";
$formHandoverLatitude = $item["private_handover_latitude"] ?? "";
$formHandoverLongitude = $item["private_handover_longitude"] ?? "";
$categoryOptionsQuery = $conn->prepare(
    "SELECT name FROM item_categories WHERE is_active = 1 OR name = ? ORDER BY name"
);
$categoryOptionsQuery->bind_param("s", $formCategory);
$categoryOptionsQuery->execute();
$categoryOptions = $categoryOptionsQuery->get_result()->fetch_all(MYSQLI_ASSOC);
$categoryOptionsQuery->close();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";
    $action = $_POST["action"] ?? "save";

    if (!is_string($token) || !hash_equals($_SESSION["csrf_token"], $token)) {
        $message = "Your session expired. Please refresh the page and try again.";
    } elseif ($action === "delete") {
        $claimStmt = $conn->prepare(
            "SELECT claim_id FROM claims WHERE item_id = ? LIMIT 1"
        );
        $claimStmt->bind_param("i", $item_id);
        $claimStmt->execute();
        $claimStmt->store_result();
        $hasClaims = $claimStmt->num_rows > 0;
        $claimStmt->close();

        if ($hasClaims) {
            $message = "This item has claims and cannot be deleted yet. Please contact an administrator.";
        } else {
            $deleteStmt = $conn->prepare(
                "DELETE FROM items WHERE item_id = ? AND user_id = ?"
            );
            $deleteStmt->bind_param("ii", $item_id, $itemOwnerId);

            if ($deleteStmt->execute() && $deleteStmt->affected_rows === 1) {
                recordActivity($conn, $user_id, $isAdminEditor ? "item_deleted_by_admin" : "item_deleted", "Item ID " . $item_id);
                $uploadDirectory = __DIR__ . DIRECTORY_SEPARATOR . "uploads";
                if (preg_match('/\A[a-f0-9]{32}\.(jpg|png|webp)\z/i', $currentImage)) {
                    $imagePath = $uploadDirectory . DIRECTORY_SEPARATOR . $currentImage;
                    if (is_file($imagePath)) {
                        unlink($imagePath);
                    }
                }

                header("Location: index.php?notice=item-deleted");
                exit;
            }

            $message = "Unable to delete this item right now.";
            $deleteStmt->close();
        }
    } elseif ($action !== "save") {
        $message = "Invalid request. Please try again.";
    } else {
        $formItemName = trim($_POST["item_name"] ?? "");
        $formDescription = trim($_POST["description"] ?? "");
        $formCategory = trim($_POST["category"] ?? "");
        $formStatus = $_POST["status"] ?? "lost";
        $formLocation = trim($_POST["location"] ?? "");
        $postedItemDate = $_POST["date_lost_found"] ?? "";
        $formDate = is_string($postedItemDate) ? $postedItemDate : "";
        $formOwnerHint = trim($_POST["owner_hint"] ?? "");
        $formHandoverContact = trim($_POST["handover_contact"] ?? "");
        $formHandoverLatitude = trim($_POST["handover_latitude"] ?? "");
        $formHandoverLongitude = trim($_POST["handover_longitude"] ?? "");
        $latitudeValue = $formHandoverLatitude === "" ? null : filter_var($formHandoverLatitude, FILTER_VALIDATE_FLOAT);
        $longitudeValue = $formHandoverLongitude === "" ? null : filter_var($formHandoverLongitude, FILTER_VALIDATE_FLOAT);

        if ($formItemName === "" || !isValidPastOrPresentItemDate($postedItemDate) || $formOwnerHint === "" || strlen($formOwnerHint) > 2000 || !in_array($formStatus, ["lost", "found"], true)
            || ($formStatus === "found" && ($formHandoverContact === "" || strlen($formHandoverContact) > 1000))
            || strlen($formHandoverContact) > 1000
            || (($formHandoverLatitude === "") !== ($formHandoverLongitude === ""))
            || ($formHandoverLatitude !== "" && ($latitudeValue === false || $latitudeValue < -90 || $latitudeValue > 90))
            || ($formHandoverLongitude !== "" && ($longitudeValue === false || $longitudeValue < -180 || $longitudeValue > 180))
            || ($formCategory !== "" && !in_array($formCategory, array_column($categoryOptions, "name"), true))) {
            $message = "Enter valid item details, choose an item date no later than today, and provide a private way for the owner to reach you when reporting a found item. Pinned coordinates must be valid latitude and longitude values.";
            $messageType = "error";
        } else {
            $handoverContactValue = $formStatus === "found" ? $formHandoverContact : null;
            $latitudeValue = $formStatus === "found" && $formHandoverLatitude !== "" ? (string) $latitudeValue : null;
            $longitudeValue = $formStatus === "found" && $formHandoverLongitude !== "" ? (string) $longitudeValue : null;
        $newImageName = $currentImage;
        $uploadedPath = null;
        $uploadError = "";

        $imageUpload = isset($_FILES["image_camera"])
            && $_FILES["image_camera"]["error"] !== UPLOAD_ERR_NO_FILE
            ? $_FILES["image_camera"]
            : ($_FILES["image"] ?? null);
        if ($imageUpload !== null && $imageUpload["error"] !== UPLOAD_ERR_NO_FILE) {
            if ($imageUpload["error"] !== UPLOAD_ERR_OK) {
                error_log("Item replacement upload rejected before processing; upload error code: " . (int) $imageUpload["error"]);
                $uploadError = match ((int) $imageUpload["error"]) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "The photo is too large for this server. Please use a photo smaller than 10 MB.",
                    UPLOAD_ERR_PARTIAL => "The photo upload was interrupted. Please try again.",
                    UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => "The server could not save the uploaded photo. Please try again later.",
                    default => "The photo could not be uploaded. Please try again.",
                };
            } elseif ($imageUpload["size"] > 10 * 1024 * 1024) {
                $uploadError = "The photo must be 10 MB or smaller.";
            } else {
                $temporaryFile = $imageUpload["tmp_name"];
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($temporaryFile);
                $allowedTypes = [
                    "image/jpeg" => "jpg",
                    "image/png" => "png",
                    "image/webp" => "webp",
                ];

                if (!isset($allowedTypes[$mimeType]) || getimagesize($temporaryFile) === false) {
                    $uploadError = in_array($mimeType, ["image/heic", "image/heif"], true)
                        ? "HEIC and HEIF photos are not supported yet. Please choose JPEG, PNG, or WebP, or change your phone camera format to Most Compatible."
                        : "Please upload a valid JPG, PNG, or WebP image.";
                } else {
                    $cloudName = trim((string) getenv("CLOUDINARY_CLOUD_NAME"));
                    $cloudKey = trim((string) getenv("CLOUDINARY_API_KEY"));
                    $cloudSecret = trim((string) getenv("CLOUDINARY_API_SECRET"));
                    $cloudinaryConfigured = $cloudName !== "" || $cloudKey !== "" || $cloudSecret !== "";

                    if ($cloudinaryConfigured && ($cloudName === "" || $cloudKey === "" || $cloudSecret === "")) {
                        $uploadError = "Cloud photo storage is not fully configured.";
                    } elseif ($cloudinaryConfigured) {
                        try {
                            \Cloudinary\Configuration\Configuration::instance([
                                "cloud" => [
                                    "cloud_name" => $cloudName,
                                    "api_key" => $cloudKey,
                                    "api_secret" => $cloudSecret
                                ],
                                "url" => ["secure" => true]
                            ]);
                            $uploadResult = (new \Cloudinary\Api\Upload\UploadApi())->upload($temporaryFile, [
                                "folder" => "lost_found/items",
                                "resource_type" => "image"
                            ]);
                            $cloudUrl = (string) ($uploadResult["secure_url"] ?? "");
                            $cloudUrlParts = parse_url($cloudUrl);
                            if (($cloudUrlParts["scheme"] ?? "") !== "https"
                                || strtolower($cloudUrlParts["host"] ?? "") !== "res.cloudinary.com") {
                                $uploadError = "Cloud photo storage returned an invalid image URL.";
                            } else {
                                $newImageName = $cloudUrl;
                            }
                        } catch (\Throwable $e) {
                            error_log("Cloudinary item photo update failed: " . get_class($e));
                            $uploadError = "Unable to store the photo online. Please try again.";
                        }
                    } else {
                        $newImageName = bin2hex(random_bytes(16)) . "." . $allowedTypes[$mimeType];
                        $targetDir = __DIR__ . DIRECTORY_SEPARATOR . "uploads";
                        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $newImageName;

                        if (!is_dir($targetDir)) {
                            $uploadError = "The uploads folder does not exist.";
                        } elseif (!move_uploaded_file($temporaryFile, $targetPath)) {
                            $uploadError = "Unable to save the photo. Check the uploads folder.";
                        } else {
                            $uploadedPath = $targetPath;
                        }
                    }
                }
            }
        }

        if ($uploadError !== "") {
            $message = $uploadError;
            $messageType = "error";
        } else {
            $updateStmt = $conn->prepare(
                "UPDATE items
                 SET item_name = ?, description = ?, category = ?, status = ?, location = ?, date_lost_found = NULLIF(?, ''), image = ?, owner_hint = ?,
                     private_handover_contact = ?, private_handover_latitude = ?, private_handover_longitude = ?
                 WHERE item_id = ? AND user_id = ?"
            );

            $updateStmt->bind_param(
                "sssssssssssii",
                $formItemName,
                $formDescription,
                $formCategory,
                $formStatus,
                $formLocation,
                $formDate,
                $newImageName,
                $formOwnerHint,
                $handoverContactValue,
                $latitudeValue,
                $longitudeValue,
                $item_id,
                $itemOwnerId
            );

            if ($updateStmt->execute()) {
                $success = true;
                $message = "Item updated successfully.";
                $messageType = "success";
                recordActivity($conn, $user_id, $isAdminEditor ? "item_updated_by_admin" : "item_updated", "Item ID " . $item_id);
                notifyUsersAboutItem($conn, $itemOwnerId, $formItemName, $formCategory, $formLocation);
                if ($uploadedPath !== null && preg_match('/\A[a-f0-9]{32}\.(jpg|png|webp)\z/i', $currentImage)) {
                    $oldImagePath = __DIR__ . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . $currentImage;
                    if (is_file($oldImagePath)) {
                        unlink($oldImagePath);
                    }
                }
                $item["item_name"] = $formItemName;
                $item["description"] = $formDescription;
                $item["category"] = $formCategory;
                $item["status"] = $formStatus;
                $item["location"] = $formLocation;
                $item["date_lost_found"] = $formDate;
                $item["image"] = $newImageName;
                $item["owner_hint"] = $formOwnerHint;
                $currentImage = $newImageName;
                $_SESSION["my_items_notice"] = "Item details edited successfully.";
                header("Location: my_items.php");
                exit;
            } else {
                $message = "Unable to update the item right now.";
                $messageType = "error";
                if ($uploadedPath !== null && is_file($uploadedPath)) {
                    unlink($uploadedPath);
                }
            }

            $updateStmt->close();
        }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Item - Lost and Found System</title>
    <link rel="stylesheet" href="css/style.css?v=theme-20261014-12">
    <script src="navigation.js" defer></script>
    <script src="handover-location.js" defer></script>
    <script src="report-photo.js" defer></script>
</head>
<body>
<?php require __DIR__ . "/site_header.php"; ?>

    <main class="container">
        <h1 class="page-title">Edit Item Details</h1>
        <section class="search-panel">
            <?php if ($message !== ""): ?>
                <p class="feedback-banner <?= htmlspecialchars($messageType) ?>" role="<?= $success ? "status" : "alert" ?>"><?= htmlspecialchars($message) ?></p>
            <?php endif; ?>

            <form method="POST" action="edit_item.php?item_id=<?= (int) $item_id ?>" enctype="multipart/form-data" class="search-form" onsubmit="return confirm('Save these changes to your item report?');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8") ?>">
                <input type="hidden" name="action" value="save">
                <div class="field-group">
                    <label for="item_name">Item name</label>
                    <input type="text" id="item_name" name="item_name" value="<?= htmlspecialchars($formItemName) ?>" required>
                </div>

                <div class="field-group">
                    <label for="category">Category</label>
                    <select id="category" name="category">
                        <option value="">Select a category</option>
                        <?php foreach ($categoryOptions as $categoryOption): ?>
                            <option value="<?= htmlspecialchars($categoryOption["name"], ENT_QUOTES, "UTF-8") ?>" <?= $formCategory === $categoryOption["name"] ? "selected" : "" ?>><?= htmlspecialchars($categoryOption["name"], ENT_QUOTES, "UTF-8") ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field-group">
                    <label for="status">Status</label>
                    <select id="status" name="status" required>
                        <option value="lost" <?= $formStatus === "lost" ? "selected" : "" ?>>Lost</option>
                        <option value="found" <?= $formStatus === "found" ? "selected" : "" ?>>Found</option>
                    </select>
                </div>

                <section class="field-group full-width handover-fields" data-handover-fields hidden aria-labelledby="handover-title">
                    <h2 id="handover-title">Private owner handover details</h2>
                    <p class="helper-text">These details are never shown on the public listing. Only the claimant you approve through administrator review can see them.</p>
                    <label for="handover-contact">How can the owner safely reach you? *</label>
                    <textarea id="handover-contact" name="handover_contact" rows="3" maxlength="1000"
                        placeholder="For example, a safe way to contact you or how to arrange collection"><?= htmlspecialchars($formHandoverContact, ENT_QUOTES, "UTF-8") ?></textarea>
                    <fieldset class="handover-pin">
                        <legend>Pin a safe meeting location (optional)</legend>
                        <p class="helper-text">Use your device's location or enter coordinates. The pin is shared only with the approved claimant.</p>
                        <button type="button" class="button button-small button-secondary" data-get-location>Use my current location</button>
                        <p class="helper-text" data-location-status aria-live="polite"></p>
                        <div class="handover-coordinate-grid">
                            <div>
                                <label for="handover-latitude">Latitude</label>
                                <input id="handover-latitude" name="handover_latitude" type="number" min="-90" max="90" step="any" value="<?= htmlspecialchars((string) $formHandoverLatitude, ENT_QUOTES, "UTF-8") ?>">
                            </div>
                            <div>
                                <label for="handover-longitude">Longitude</label>
                                <input id="handover-longitude" name="handover_longitude" type="number" min="-180" max="180" step="any" value="<?= htmlspecialchars((string) $formHandoverLongitude, ENT_QUOTES, "UTF-8") ?>">
                            </div>
                        </div>
                    </fieldset>
                </section>

                <div class="field-group">
                    <label for="location">Location</label>
                    <input type="text" id="location" name="location" value="<?= htmlspecialchars($formLocation) ?>">
                </div>

                <div class="field-group">
                    <label for="date_lost_found">Date lost or found</label>
                    <input type="date" id="date_lost_found" name="date_lost_found" max="<?= date("Y-m-d") ?>" value="<?= htmlspecialchars($formDate) ?>">
                </div>

                <div class="field-group full-width">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" rows="5"><?= htmlspecialchars($formDescription) ?></textarea>
                </div>

                <div class="field-group full-width">
                    <label for="owner_hint">Private ownership clue</label>
                    <textarea id="owner_hint" name="owner_hint" rows="3" maxlength="2000" required aria-describedby="owner-hint-help"><?= htmlspecialchars($formOwnerHint) ?></textarea>
                    <p id="owner-hint-help" class="helper-text">Only claimants, you, and administrators can access this clue and the claimant’s answer. It is never shown on public listings.</p>
                </div>

                <div class="field-group full-width report-photo-picker">
                    <fieldset>
                        <legend>Replace photo</legend>
                        <input class="report-photo-input" type="file" id="image_camera" name="image_camera" accept="image/*" capture="environment">
                        <label class="button button-small button-secondary" for="image_camera">Take a photo</label>
                        <input class="report-photo-input" type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp">
                        <label class="button button-small button-secondary" for="image">Browse photos</label>
                        <p class="helper-text" data-photo-name aria-live="polite">Choose a replacement photo or take one with your camera.</p>
                        <img class="report-photo-preview" data-photo-preview alt="Replacement photo preview" hidden>
                    </fieldset>
                    <?php if (getItemImageUrl($currentImage) !== null): ?>
                        <p class="helper-text">Current photo:</p>
                        <img class="report-photo-preview" src="<?= htmlspecialchars((string) getItemImageUrl($currentImage), ENT_QUOTES, "UTF-8") ?>" alt="Current item photo">
                    <?php endif; ?>
                </div>

                <div class="search-actions full-width">
                    <button type="submit">Save Changes</button>
                    <a href="index.php" class="clear-link">Cancel</a>
                </div>
            </form>

            <div class="danger-zone">
                <h2>Delete this report</h2>
                <p>Deleting this report is permanent. If someone has already submitted a claim, contact an administrator before deleting it.</p>
                <form method="POST" action="edit_item.php?item_id=<?= (int) $item_id ?>" onsubmit="return confirm('Permanently delete this item report? This cannot be undone.');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8") ?>">
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="button-danger">Delete My Item</button>
                </form>
            </div>
        </section>
    </main>

    <script>
        document.addEventListener("DOMContentLoaded", () => {
            const status = document.getElementById("status");
            const handoverFields = document.querySelector("[data-handover-fields]");
            const contact = document.getElementById("handover-contact");
            const updateHandoverVisibility = () => {
                const isFound = status.value === "found";
                handoverFields.hidden = !isFound;
                contact.required = isFound;
            };
            status.addEventListener("change", updateHandoverVisibility);
            updateHandoverVisibility();
        });
    </script>
    <?php $conn->close(); ?>
    <?php require __DIR__ . "/site_footer.php"; ?>
</body>
</html>
