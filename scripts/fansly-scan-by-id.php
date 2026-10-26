<?php

declare(strict_types=1);

/**
 * Standalone, very basic Fansly lookup by account ID (not username).
 * Queries Fansly's account endpoint with `?ids=` instead of `?usernames=`,
 * and prints the raw JSON response to the console. Does not touch
 * accounts.json/creators/*.json/status.json.
 *
 * Usage:
 *   php fansly-scan-by-id.php <fansly_account_id>
 */

const FANSLY_API_URL = 'https://apiv3.fansly.com/api/v1/account';

$id = trim((string) ($argv[1] ?? ''));
if ($id === '') {
    fwrite(STDERR, "Usage: php fansly-scan-by-id.php <fansly_account_id>\n");
    exit(1);
}

$url = FANSLY_API_URL . '?ids=' . rawurlencode($id) . '&ngsw-bypass=true';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36',
    ],
]);

$response = curl_exec($ch);
$statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    fwrite(STDERR, "Request failed: {$curlError}\n");
    exit(1);
}

echo "HTTP {$statusCode}\n";

$decoded = json_decode($response, true);
$accounts = is_array($decoded) ? ($decoded['response'] ?? null) : null;

if (!is_array($accounts) || count($accounts) === 0) {
    echo "No account found for id: {$id}\n";
    echo $response . "\n";
    exit(0);
}

unset($accounts[0]['pinnedPosts'], $accounts[0]['subscriptionTiers']);

echo json_encode($accounts[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
