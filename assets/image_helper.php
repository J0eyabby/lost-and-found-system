<?php

/**
 * Return a safe image URL for a local upload or Cloudinary image.
 */
function getItemImageUrl(?string $image): ?string
{
    $image = trim((string) $image);

    if ($image === "") {
        return null;
    }

    // Allow only HTTPS URLs from Cloudinary.
    if (filter_var($image, FILTER_VALIDATE_URL)) {
        $url = parse_url($image);

        if (
            ($url["scheme"] ?? "") === "https" &&
            in_array(
                strtolower($url["host"] ?? ""),
                ["res.cloudinary.com"],
                true
            )
        ) {
            return $image;
        }

        return null;
    }

    // Existing local images must be simple filenames.
    if (
        basename($image) !== $image ||
        !preg_match('/\A[a-f0-9]{32}\.(jpg|png|webp)\z/i', $image)
    ) {
        return null;
    }

    return "uploads/" . rawurlencode($image);
}
