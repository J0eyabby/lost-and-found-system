<?php

function smartAssistantNormalize(string $text): string
{
    $text = function_exists("mb_strtolower") ? mb_strtolower($text, "UTF-8") : strtolower($text);
    $text = preg_replace('/[^\p{L}\p{N}]+/u', " ", $text);
    return trim((string) preg_replace('/\s+/u', " ", (string) $text));
}

function smartAssistantTokens(string $text): array
{
    $stopWords = [
        "a", "an", "and", "are", "at", "by", "for", "from", "in", "is", "it",
        "lost", "my", "of", "on", "or", "the", "to", "was", "with", "found",
        "ya", "na", "kwa", "katika", "hii", "hicho", "nilipoteza", "nimepata",
    ];
    $tokens = explode(" ", smartAssistantNormalize($text));
    return array_values(array_unique(array_filter(
        $tokens,
        static fn(string $token): bool => strlen($token) > 1 && !in_array($token, $stopWords, true)
    )));
}

function smartAssistantSimilarity(string $first, string $second): float
{
    $firstTokens = smartAssistantTokens($first);
    $secondTokens = smartAssistantTokens($second);
    if ($firstTokens === [] || $secondTokens === []) {
        return 0.0;
    }
    $intersection = count(array_intersect($firstTokens, $secondTokens));
    $union = count(array_unique(array_merge($firstTokens, $secondTokens)));
    return $union > 0 ? $intersection / $union : 0.0;
}

function smartAssistantMatch(array $source, array $candidate, string $language = "en"): array
{
    $titleSimilarity = smartAssistantSimilarity(
        (string) ($source["item_name"] ?? ""),
        (string) ($candidate["item_name"] ?? "")
    );
    $detailsSimilarity = smartAssistantSimilarity(
        trim((string) ($source["item_name"] ?? "") . " " . (string) ($source["description"] ?? "")),
        trim((string) ($candidate["item_name"] ?? "") . " " . (string) ($candidate["description"] ?? ""))
    );
    $score = max($titleSimilarity * 0.62 + $detailsSimilarity * 0.38, $detailsSimilarity * 0.72);
    $reasons = [];
    $sameCategory = trim((string) ($source["category"] ?? "")) !== ""
        && smartAssistantNormalize((string) $source["category"]) === smartAssistantNormalize((string) ($candidate["category"] ?? ""));
    if ($sameCategory) {
        $score += 0.18;
        $reasons[] = $language === "sw" ? "Zimeripotiwa katika kategoria sawa." : "Both reports use the same category.";
    }
    $sourceLocation = smartAssistantNormalize((string) ($source["location"] ?? ""));
    $candidateLocation = smartAssistantNormalize((string) ($candidate["location"] ?? ""));
    if ($sourceLocation !== "" && $candidateLocation !== ""
        && smartAssistantSimilarity($sourceLocation, $candidateLocation) >= 0.25) {
        $score += 0.14;
        $reasons[] = $language === "sw" ? "Maeneo yaliyotajwa yanafanana." : "The reported locations are similar.";
    }
    $sourceDate = strtotime((string) ($source["date_lost_found"] ?? ""));
    $candidateDate = strtotime((string) ($candidate["date_lost_found"] ?? ""));
    if ($sourceDate !== false && $candidateDate !== false
        && abs($sourceDate - $candidateDate) <= 14 * 86400) {
        $score += 0.06;
        $reasons[] = $language === "sw" ? "Tarehe za ripoti ziko karibu." : "The report dates are close.";
    }
    if ($titleSimilarity >= 0.2 || $detailsSimilarity >= 0.2) {
        $reasons[] = $language === "sw" ? "Majina au maelezo yana maneno yanayofanana." : "The item names or descriptions share keywords.";
    }
    if ($reasons === []) {
        $reasons[] = $language === "sw"
            ? "Maelezo machache tu yanafanana; kagua mwenyewe."
            : "Only limited details overlap; review manually.";
    }
    return [
        "score" => (int) round(min(0.99, $score) * 100),
        "reasons" => $reasons,
    ];
}

function smartAssistantSuggestCategory(string $text, array $categories): string
{
    $normalized = smartAssistantNormalize($text);
    $suggestions = [
        "Electronics" => ["phone", "mobile", "laptop", "computer", "tablet", "headphone", "charger", "simu", "rununu", "kompyuta"],
        "Documents" => ["id", "passport", "document", "card", "license", "kitambulisho", "pasipoti", "kadi"],
        "Clothing" => ["shirt", "jacket", "coat", "shoe", "sweater", "nguo", "shati", "kiatu"],
        "Accessories" => ["bag", "wallet", "keys", "watch", "glasses", "mkoba", "pochi", "funguo", "saa"],
        "Books" => ["book", "notebook", "textbook", "kitabu", "daftari"],
    ];
    foreach ($categories as $category) {
        $name = (string) ($category["name"] ?? $category);
        foreach ($suggestions as $categoryName => $keywords) {
            if (smartAssistantNormalize($name) === smartAssistantNormalize($categoryName)) {
                foreach ($keywords as $keyword) {
                    if (preg_match('/(?:^|\s)' . preg_quote($keyword, '/') . '(?:$|\s)/u', $normalized) === 1) {
                        return $name;
                    }
                }
            }
        }
    }
    return "";
}

function smartAssistantModerationFlags(string $text): array
{
    $flags = [];
    if (preg_match('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', $text) === 1) {
        $flags[] = "Possible email address in public text";
    }
    if (preg_match('/(?:\+?\d[\d\s().-]{7,}\d)/', $text) === 1) {
        $flags[] = "Possible phone number in public text";
    }
    if (preg_match('/\b(password|pin|cvv|passcode)\b/i', $text) === 1) {
        $flags[] = "Possible credential or payment-secret disclosure";
    }
    return $flags;
}

function smartAssistantDescription(string $itemName, string $status, string $location, string $date): string
{
    $kind = $status === "found" ? "found" : "lost";
    $description = "A " . $kind . " " . trim($itemName);
    $details = [];
    if (trim($location) !== "") {
        $details[] = "reported near " . trim($location);
    }
    if (trim($date) !== "") {
        $details[] = "on " . trim($date);
    }
    if ($details !== []) {
        $description .= ", " . implode(" ", $details);
    }
    return $description . ". Add distinguishing details you can verify; do not include private ownership clues here.";
}
