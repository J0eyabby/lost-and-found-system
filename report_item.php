<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once "db.php";
require_once __DIR__ . "/account_guard.php";
require_once __DIR__ . "/user_notifications.php";
require_once __DIR__ . "/activity_log.php";
require_once __DIR__ . "/smart_assistant.php";
require_once __DIR__ . "/item_date_validation.php";
require_once __DIR__ . "/vendor/autoload.php";
enforceActiveAccount($conn);

// Only logged-in users can report items.
if ((int) ($_SESSION["user_id"] ?? 0) < 1) {
    header("Location: login.php?next=" . rawurlencode("report_item.php"));
    exit;
}

$message = "";
$smartWarnings = [];
$duplicateReportFound = false;
$duplicatePhotoFound = false;

$item_name = "";
$description = "";
$category = "";
$status = "";
$location = "";
$date_lost_found = "";
$owner_hint = "";
$handoverContact = "";
$handoverLatitude = "";
$handoverLongitude = "";
$reportCsrfToken = $_SESSION["report_csrf_token"] ?? "";
if (!is_string($reportCsrfToken) || $reportCsrfToken === "") {
    $reportCsrfToken = bin2hex(random_bytes(32));
    $_SESSION["report_csrf_token"] = $reportCsrfToken;
}
$categoryOptionsResult = $conn->query("SELECT name FROM item_categories WHERE is_active = 1 ORDER BY name");
$categoryOptions = $categoryOptionsResult->fetch_all(MYSQLI_ASSOC);

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && (!is_string($_POST["csrf_token"] ?? "") || !hash_equals($reportCsrfToken, $_POST["csrf_token"]))
) {
    $message = "Your session expired. Refresh the page and try again.";
} elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
    $item_name = trim($_POST["item_name"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $category = trim($_POST["category"] ?? "");
    $status = $_POST["status"] ?? "";
    $location = trim($_POST["location"] ?? "");
    $postedItemDate = $_POST["date_lost_found"] ?? "";
    $date_lost_found = is_string($postedItemDate) ? $postedItemDate : "";
    $owner_hint = trim($_POST["owner_hint"] ?? "");
    $handoverContact = trim($_POST["handover_contact"] ?? "");
    $handoverLatitude = trim($_POST["handover_latitude"] ?? "");
    $handoverLongitude = trim($_POST["handover_longitude"] ?? "");
    $latitudeValue = $handoverLatitude === "" ? null : filter_var($handoverLatitude, FILTER_VALIDATE_FLOAT);
    $longitudeValue = $handoverLongitude === "" ? null : filter_var($handoverLongitude, FILTER_VALIDATE_FLOAT);

    if (
        $item_name === "" ||
    !isValidPastOrPresentItemDate($postedItemDate) ||
        $owner_hint === "" ||
        strlen($owner_hint) > 2000 ||
        !in_array($status, ["lost", "found"], true) ||
        ($status === "found" && ($handoverContact === "" || strlen($handoverContact) > 1000)) ||
        strlen($handoverContact) > 1000 ||
        (($handoverLatitude === "") !== ($handoverLongitude === "")) ||
        ($handoverLatitude !== "" && ($latitudeValue === false || $latitudeValue < -90 || $latitudeValue > 90)) ||
        ($handoverLongitude !== "" && ($longitudeValue === false || $longitudeValue < -180 || $longitudeValue > 180)) ||
        ($category !== "" && !in_array($category, array_column($categoryOptions, "name"), true))
    ) {
        $message = "Enter valid item details, choose an item date no later than today, and provide a private way for the owner to reach you when reporting a found item. Pinned coordinates must be valid latitude and longitude values.";
    } else {
        $handoverContactValue = $status === "found" ? $handoverContact : null;
        $latitudeValue = $status === "found" && $handoverLatitude !== "" ? (string) $latitudeValue : null;
        $longitudeValue = $status === "found" && $handoverLongitude !== "" ? (string) $longitudeValue : null;
        $user_id = $_SESSION["user_id"];
        $imageName = null;
        $uploadedPath = null;
        $uploadError = "";

        // Process the optional photo
        $imageUpload = isset($_FILES["image_camera"]) &&
            $_FILES["image_camera"]["error"] !== UPLOAD_ERR_NO_FILE
            ? $_FILES["image_camera"]
            : ($_FILES["image"] ?? null);
        if ($imageUpload !== null && $imageUpload["error"] !== UPLOAD_ERR_NO_FILE) {

            if ($imageUpload["error"] !== UPLOAD_ERR_OK) {
                error_log("Item photo upload rejected before processing; upload error code: " . (int) $imageUpload["error"]);
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
                    "image/png"  => "png",
                    "image/webp" => "webp"
                ];

                if (
                    !isset($allowedTypes[$mimeType]) ||
                    getimagesize($temporaryFile) === false
                ) {
                    $uploadError = in_array($mimeType, ["image/heic", "image/heif"], true)
                        ? "HEIC and HEIF photos are not supported yet. Please choose JPEG, PNG, or WebP, or change your phone camera format to Most Compatible."
                        : "Please upload a valid JPG, PNG, or WebP image.";
                } else {
                    $photoHash = hash_file("sha256", $temporaryFile);
                    if ($photoHash === false) {
                        $uploadError = "Unable to check the uploaded photo.";
                    } else {
                        $existingImages = $conn->query("SELECT image FROM items WHERE image IS NOT NULL AND image <> ''");
                        if ($existingImages === false) {
                            $uploadError = "Unable to check whether this photo has already been reported.";
                        } else {
                            while ($existingImage = $existingImages->fetch_assoc()) {
                                $existingPath = __DIR__ . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . basename($existingImage["image"]);
                                if (is_file($existingPath) && hash_file("sha256", $existingPath) === $photoHash) {
                                    $duplicatePhotoFound = true;
                                    $smartWarnings[] = "This exact photo is already attached to another report. Review the possible duplicate before submitting.";
                                    break;
                                }
                            }
                            $existingImages->free();
                        }
                    }
                    if ($uploadError === "")
{
$imageName = null;
$uploadedPath = null;

$cloudName = trim((string) getenv("CLOUDINARY_CLOUD_NAME"));
$cloudKey = trim((string) getenv("CLOUDINARY_API_KEY"));
$cloudSecret = trim((string) getenv("CLOUDINARY_API_SECRET"));

$cloudinaryConfigured =
    $cloudName !== "" ||
    $cloudKey !== "" ||
    $cloudSecret !== "";

if ($cloudinaryConfigured) {

    // If cloud storage is configured, require all three credentials.
    if ($cloudName === "" || $cloudKey === "" || $cloudSecret === "") {
        $uploadError = "Cloud photo storage is not fully configured.";
    } else {
        try {
            \Cloudinary\Configuration\Configuration::instance([
                "cloud" => [
                    "cloud_name" => $cloudName,
                    "api_key" => $cloudKey,
                    "api_secret" => $cloudSecret
                ],
                "url" => [
                    "secure" => true
                ]
            ]);

            $uploadResult = (new \Cloudinary\Api\Upload\UploadApi())
                ->upload($temporaryFile, [
                    "folder" => "lost_found/items",
                    "resource_type" => "image"
                ]);

            $cloudUrl = (string) ($uploadResult["secure_url"] ?? "");
            $cloudUrlParts = parse_url($cloudUrl);

            if (
                ($cloudUrlParts["scheme"] ?? "") !== "https" ||
                strtolower($cloudUrlParts["host"] ?? "") !== "res.cloudinary.com"
            ) {
                $uploadError = "Cloud photo storage returned an invalid image URL.";
            } else {
                $imageName = $cloudUrl;
            }

        } catch (\Throwable $e) {
            error_log("Cloudinary item photo upload failed: " . get_class($e));
            $uploadError = "Unable to store the photo online. Please try again.";
        }
    }

} else {
    // Local fallback for development on your laptop.
    $imageName = bin2hex(random_bytes(16)) . "." . $allowedTypes[$mimeType];

    $uploadDirectory = __DIR__ . DIRECTORY_SEPARATOR . "uploads";
    $uploadedPath = $uploadDirectory . DIRECTORY_SEPARATOR . $imageName;

    if (!is_dir($uploadDirectory)) {
        $uploadError = "The uploads folder does not exist.";
        $imageName = null;
        $uploadedPath = null;
    } elseif (!move_uploaded_file($temporaryFile, $uploadedPath)) {
        $uploadError = "Unable to save the photo. Check the uploads folder.";
        $imageName = null;
        $uploadedPath = null;
    }
}
                        }
                    }
                }
            }
        }

        if ($uploadError !== "") {
            $message = $uploadError;
        } else {
            $similarReports = $conn->prepare(
                "SELECT item_id, item_name, description, category, location
                 FROM items WHERE status = ? AND resolved_at IS NULL AND is_hidden = 0"
            );
            $similarReports->bind_param("s", $status);
            $similarReports->execute();
            $similarResult = $similarReports->get_result();
            while ($existingReport = $similarResult->fetch_assoc()) {
                $nameSimilarity = smartAssistantSimilarity($item_name, (string) $existingReport["item_name"]);
                $textSimilarity = smartAssistantSimilarity(
                    $item_name . " " . $description . " " . $location,
                    $existingReport["item_name"] . " " . $existingReport["description"] . " " . $existingReport["location"]
                );
                if ($nameSimilarity >= 0.65 || $textSimilarity >= 0.6) {
                    $duplicateReportFound = true;
                    $smartWarnings[] = "A similar active report may already exist (" . $existingReport["item_name"] . "). Check it before creating a duplicate.";
                    break;
                }
            }
            $similarReports->close();
            foreach (smartAssistantModerationFlags($description) as $moderationFlag) {
                $smartWarnings[] = $moderationFlag . ". Consider removing it from the public description.";
            }

            $stmt = $conn->prepare(
                "INSERT INTO items
                (user_id, item_name, description, category, status, location,
                 date_lost_found, owner_hint, image, private_handover_contact,
                 private_handover_latitude, private_handover_longitude)
                VALUES (?, ?, ?, ?, ?, ?, NULLIF(?, ''), ?, ?,
                        ?, ?, ?)"
            );

            $stmt->bind_param(
                "isssssssssss",
                $user_id,
                $item_name,
                $description,
                $category,
                $status,
                $location,
                $date_lost_found,
                $owner_hint,
                $imageName,
                $handoverContactValue,
                $latitudeValue,
                $longitudeValue
            );

            if ($stmt->execute()) {
                $message = "Item reported successfully!";
                if ($smartWarnings !== []) {
                    $message .= " Assistant review: " . implode(" ", $smartWarnings);
                }
                $newItemId = (int) $conn->insert_id;
                recordActivity($conn, (int) $user_id, "item_reported", "Item ID " . $newItemId);
                if ($duplicateReportFound) {
                    recordActivity($conn, (int) $user_id, "possible_duplicate_report", "Item ID " . $newItemId);
                }
                if ($duplicatePhotoFound) {
                    recordActivity($conn, (int) $user_id, "possible_duplicate_photo", "Item ID " . $newItemId);
                }
                notifyUsersAboutItem($conn, (int) $user_id, $item_name, $category, $location);

                $item_name = "";
                $description = "";
                $category = "";
                $status = "";
                $location = "";
                $date_lost_found = "";
                $owner_hint = "";
                $handoverContact = "";
                $handoverLatitude = "";
                $handoverLongitude = "";
            } else {
                if ($uploadedPath !== null && file_exists($uploadedPath)) {
                    unlink($uploadedPath);
                }

                $message = "Unable to report the item. Please try again.";
            }

            $stmt->close();
        }
    }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report an Item - Lost and Found System</title>
    <link rel="stylesheet" href="css/style.css?v=theme-20261014-12">
    <script src="navigation.js" defer></script>
    <script src="handover-location.js" defer></script>
    <script src="report-photo.js" defer></script>
    
</head>
<body>
<?php require __DIR__ . "/site_header.php"; ?>
    <main class="form-container">
    <h1 class="page-title">Report an Item</h1>
    <h1>Report a Lost or Found Item</h1>

    <?php if ($message !== ""): ?>
        <p><?= htmlspecialchars($message) ?></p>
    <?php endif; ?>

    <form method="POST" action="report_item.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($reportCsrfToken, ENT_QUOTES, "UTF-8") ?>">
       <label for="item_name">Item Name *</label><br>
        <input
            type="text"
            id="item_name"
            name="item_name"
            maxlength="100"
            value="<?= htmlspecialchars($item_name) ?>"
            required
        ><br><br>

<fieldset class="report-photo-picker">
    <legend>Item Photo (optional)</legend>
    <input
        class="report-photo-input"
        type="file"
        id="image_camera"
        name="image_camera"
        accept="image/*"
        capture="environment"
    >
    <label class="button button-small button-secondary" for="image_camera">Take a photo</label>
    <input
        class="report-photo-input"
        type="file"
        id="image"
        name="image"
        accept="image/jpeg,image/png,image/webp"
    >
    <label class="button button-small button-secondary" for="image">Browse photos</label>
    <p class="helper-text" data-photo-name aria-live="polite">Choose a photo from your device or take one with its camera.</p>
    <img class="report-photo-preview" data-photo-preview alt="Selected item photo preview" hidden>
</fieldset>
<br><br>
        <label for="description">Description</label><br>
        <textarea
            id="description"
            name="description"
            rows="4"
            cols="40"
            data-smart-description
        ><?= htmlspecialchars($description) ?></textarea><br><br>
        <div class="smart-assistant-actions">
            <button type="button" class="button button-small button-secondary" data-suggest-description>Draft a basic description</button>
            <button type="button" class="button button-small button-secondary" data-check-moderation>Check public text for private contact details</button>
        </div>
        <p class="helper-text" data-assistant-feedback aria-live="polite"></p>

        <label for="owner_hint">Private ownership clue *</label><br>
        <textarea
            id="owner_hint"
            name="owner_hint"
            rows="3"
            maxlength="2000"
            required
            aria-describedby="owner-hint-help"
        ><?= htmlspecialchars($owner_hint) ?></textarea>
        <p id="owner-hint-help" class="helper-text">Add a detail only the true owner is likely to know, such as a hidden mark or what was inside. This will not be shown publicly; claimants must answer it and only you and administrators can review the answer.</p><br>

        <label for="category">Category</label><br>
        <select id="category" name="category">
            <option value="">Select a category</option>
            <?php foreach ($categoryOptions as $categoryOption): ?>
                <option value="<?= htmlspecialchars($categoryOption["name"], ENT_QUOTES, "UTF-8") ?>" <?= $category === $categoryOption["name"] ? "selected" : "" ?>>
                    <?= htmlspecialchars($categoryOption["name"], ENT_QUOTES, "UTF-8") ?>
                </option>
            <?php endforeach; ?>
        </select><br><br>
        <button type="button" class="button button-small button-secondary" data-suggest-category>Suggest category from item name</button>
        <p class="helper-text" data-category-feedback aria-live="polite"></p>

        <label for="status">Status *</label><br>
        <select id="status" name="status" required>
            <option value="">Select status</option>
            <option value="lost"
                <?= $status === "lost" ? "selected" : "" ?>>
                Lost
            </option>
            <option value="found"
                <?= $status === "found" ? "selected" : "" ?>>
                Found
            </option>
        </select><br><br>

        <section class="handover-fields" data-handover-fields hidden aria-labelledby="handover-title">
            <h2 id="handover-title">Private owner handover details</h2>
            <p class="helper-text">These details are never shown on the public listing. Only the claimant you approve through the administrator review process can see them.</p>
            <label for="handover-contact">How can the owner safely reach you? *</label>
            <textarea id="handover-contact" name="handover_contact" rows="3" maxlength="1000"
                placeholder="For example, a safe way to contact you or how to arrange collection"><?= htmlspecialchars($handoverContact, ENT_QUOTES, "UTF-8") ?></textarea>
            <p class="helper-text">Avoid putting personal contact details in the public description. Share only what you are comfortable giving to a verified owner.</p>

            <fieldset class="handover-pin">
                <legend>Pin a safe meeting location (optional)</legend>
                <p class="helper-text">Use your device's location or enter map coordinates. Your pin is shared only with the approved claimant.</p>
                <button type="button" class="button button-small button-secondary" data-get-location>Use my current location</button>
                <p class="helper-text" data-location-status aria-live="polite"></p>
                <div class="handover-coordinate-grid">
                    <div>
                        <label for="handover-latitude">Latitude</label>
                        <input id="handover-latitude" name="handover_latitude" type="number" min="-90" max="90" step="any"
                            value="<?= htmlspecialchars($handoverLatitude, ENT_QUOTES, "UTF-8") ?>">
                    </div>
                    <div>
                        <label for="handover-longitude">Longitude</label>
                        <input id="handover-longitude" name="handover_longitude" type="number" min="-180" max="180" step="any"
                            value="<?= htmlspecialchars($handoverLongitude, ENT_QUOTES, "UTF-8") ?>">
                    </div>
                </div>
            </fieldset>
        </section>

<label for="location">Location</label><br>

<input
    type="text"
    id="location"
    name="location"
    maxlength="150"
    value="<?= htmlspecialchars($location, ENT_QUOTES, 'UTF-8') ?>"
    placeholder="Enter a location where the item was lost or found"
><br><br>

        <label for="date_lost_found">Date Lost or Found</label><br>
        <input
            type="date"
            id="date_lost_found"
            name="date_lost_found"
            max="<?= date("Y-m-d") ?>"
            value="<?= htmlspecialchars($date_lost_found) ?>"
        ><br><br>

        <button type="submit">Submit Report</button>

    </form>

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
    <?php require __DIR__ . "/site_footer.php"; ?>
</body>
</html>
