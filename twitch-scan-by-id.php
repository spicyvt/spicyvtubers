<?php

declare(strict_types=1);

/**
 * Standalone, very basic Twitch lookup by user ID (not login name).
 * Queries Twitch's GQL GetUserBasic-style query with `user(id: $id)`
 * instead of `user(login: $login)`, and prints the raw JSON response to
 * the console. Does not touch accounts.json/creators/*.json/status.json.
 *
 * Usage:
 *   php twitch-scan-by-id.php <twitch_user_id>
 */

const TWITCH_GRAPHQL_URL = 'https://gql.twitch.tv/gql';
const TWITCH_QUERY_BY_ID = <<<'GRAPHQL'
query GetUserBasicById($id: ID!) {
  user(id: $id) {
          id
          login
          displayName
          createdAt
          updatedAt
          description
          profileImageURL(width: 300)
          bannerImageURL
          primaryColorHex
          followers { totalCount }
          follows { totalCount }
          roles { isPartner isAffiliate }
          lastBroadcast { startedAt }
  }
}
GRAPHQL;

$id = trim((string) ($argv[1] ?? ''));
if ($id === '') {
    fwrite(STDERR, "Usage: php twitch-scan-by-id.php <twitch_user_id>\n");
    exit(1);
}

$clientId = getenv('TWITCH_CLIENT_ID');
if (!is_string($clientId) || $clientId === '') {
    $clientId = 'kimne78kx3ncx6brgo4mv6wki5h1ko';
}

$payload = [
    'operationName' => 'GetUserBasicById',
    'query' => TWITCH_QUERY_BY_ID,
    'variables' => ['id' => $id],
];

$ch = curl_init(TWITCH_GRAPHQL_URL);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Client-Id: ' . $clientId,
        'Content-Type: application/json',
        'Accept: application/json',
        'User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
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
$user = is_array($decoded) ? ($decoded['data']['user'] ?? null) : null;

if ($user === null) {
    echo "No user found for id: {$id}\n";
    echo $response . "\n";
    exit(0);
}

echo json_encode($user, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
