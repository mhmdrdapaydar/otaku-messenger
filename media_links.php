<?php
/** Return true only for HTTPS links whose host is explicitly allow-listed. */
function isAllowedMediaLink(PDO $pdo, string $url): bool
{
    if ($url === '') {
        return true;
    }
    if (strlen($url) > 500 || !filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $parsed = parse_url($url);
    if (!$parsed || strtolower($parsed['scheme'] ?? '') !== 'https'
        || empty($parsed['host']) || isset($parsed['user']) || isset($parsed['pass'])) {
        return false;
    }

    $host = strtolower(rtrim($parsed['host'], '.'));
    $domain = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    $stmt = $pdo->prepare("SELECT 1 FROM allowed_domains WHERE LOWER(TRIM(TRAILING '/' FROM domain)) IN (?, ?) LIMIT 1");
    $stmt->execute([$host, $domain]);
    return (bool)$stmt->fetchColumn();
}
