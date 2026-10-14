<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    $cookie = session_get_cookie_params();
    $lifetime = 30 * 24 * 60 * 60;
    $forwardedProtocol = strtolower((string) ($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? ""));
    $cloudflareVisitor = json_decode((string) ($_SERVER["HTTP_CF_VISITOR"] ?? ""), true);
    $isSecureRequest = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off")
        || $forwardedProtocol === "https"
        || (is_array($cloudflareVisitor) && ($cloudflareVisitor["scheme"] ?? "") === "https");
    $isProduction = strtolower((string) getenv("APP_ENV")) === "production"
        || strtolower((string) getenv("RENDER")) === "true";

    if ($isProduction && !$isSecureRequest) {
        $requestUri = (string) ($_SERVER["REQUEST_URI"] ?? "/");
        header("Location: https://" . ($_SERVER["HTTP_HOST"] ?? "") . $requestUri, true, 301);
        exit;
    }

    ini_set("session.gc_maxlifetime", (string) $lifetime);
    ini_set("session.use_strict_mode", "1");
    $sessionCookieOptions = [
        "lifetime" => $lifetime,
        "path" => $cookie["path"],
        "domain" => $cookie["domain"],
        "secure" => $isProduction || $isSecureRequest,
        "httponly" => true,
        "samesite" => "Lax",
    ];
    if ($isProduction && $isSecureRequest) {
        header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
    }
    session_set_cookie_params($sessionCookieOptions);
    session_start();
    if (isset($_COOKIE[session_name()]) && hash_equals((string) $_COOKIE[session_name()], session_id())) {
        setcookie(session_name(), session_id(), [
            "expires" => time() + $lifetime,
            "path" => $cookie["path"],
            "domain" => $cookie["domain"],
            "secure" => $isProduction || $isSecureRequest,
            "httponly" => true,
            "samesite" => "Lax",
        ]);
    }
}

function expireAppSessionCookie(): void
{
    $cookie = session_get_cookie_params();
    setcookie(session_name(), "", [
        "expires" => time() - 3600,
        "path" => $cookie["path"],
        "domain" => $cookie["domain"],
        "secure" => $cookie["secure"],
        "httponly" => $cookie["httponly"],
        "samesite" => $cookie["samesite"] ?? "Lax",
    ]);
}
