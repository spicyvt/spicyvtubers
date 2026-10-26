<?php

declare(strict_types=1);

/**
 * Standalone platform-existence checker for accounts.json entries.
 *
 * Unlike update.php (which only persists a platform's data once fully
 * resolved, so any failed/blocked fetch is silently retried on every
 * future run), this script permanently records EVERY check's outcome —
 * whether the profile exists, or doesn't (which covers both a genuine
 * "not found" and a request that got no usable response/failed) — to
 * status.json. That file is loaded first, and any account/platform pair
 * already present in it is never re-checked, so re-running this script
 * only ever processes newly-added accounts/handles.
 *
 * Checks six platforms, independently of one another (one full pass per
 * platform over every account, not one account fully checked at a time):
 * - twitch/youtube: accounts.json's "twitch" and "youtube" handles
 * - twitter: every "xHandles" entry (checked per-handle, like update.php)
 * - bluesky: "bskyHandle" (checked once per account, like update.php)
 * - fansly: "fansly" username, batched FANSLY_BATCH_SIZE at a time
 * - rplay: "rplay" value (userOid or customUrl form)
 *
 * status.json is saved after every single check (or, for Fansly, after
 * every batch) so progress is never lost if the run is interrupted.
 *
 * status.json shape:
 *   {
 *     "{login}": {
 *       "twitch":  { "exists": bool, "checkedAt": "<ISO8601>" },
 *       "youtube": { "exists": bool, "checkedAt": "<ISO8601>" },
 *       "bluesky": { "exists": bool, "checkedAt": "<ISO8601>" },
 *       "fansly":  { "exists": bool, "checkedAt": "<ISO8601>" },
 *       "rplay":   { "exists": bool, "checkedAt": "<ISO8601>" },
 *       "twitter": { "{handleLower}": { "exists": bool, "checkedAt": "..." }, ... }
 *     },
 *     ...
 *   }
 * "{login}" is always accounts.json's "slug" — same
 * identifier update.php uses for creators/{login}.json.
 *
 * Usage:
 *   php check-account-status.php              # fetch accounts.json from
 *                                              # https://spicyvtubers.com/accounts.json
 *   php check-account-status.php --local       # use the local accounts.json instead
 *   php check-account-status.php --accounts=PATH_OR_URL  # explicit source
 *   php check-account-status.php --twitch --fansly       # only those platforms
 *   php check-account-status.php --platforms=twitch,fansly  # same, comma-separated
 *                                              # (default: all six platforms)
 *
 * No app dependencies; uses only built-in cURL/file_get_contents, same
 * as update.php.
 */

const DEFAULT_ACCOUNTS_URL = 'https://spicyvtubers.com/accounts.json';
// Site root (this script lives in scripts/).
const ROOT_DIR = __DIR__ . '/..';
const STATUS_PATH = ROOT_DIR . '/status.json';
const ALL_PLATFORMS = ['twitch', 'youtube', 'twitter', 'bluesky', 'fansly', 'rplay'];

const TWITCH_GRAPHQL_URL = 'https://gql.twitch.tv/gql';
const TWITCH_QUERY = <<<'GRAPHQL'
query GetUserBasic($login: String!) {
  user(login: $login) {
          id
          login
  }
}
GRAPHQL;

const FANSLY_API_URL = 'https://apiv3.fansly.com/api/v1/account';
const FANSLY_BATCH_SIZE = 20;
const FANSLY_SLEEP_SECONDS = 5;

const RPLAY_API_URL = 'https://api.rplay.live/account/getuser';
const RPLAY_SLEEP_SECONDS = 1;

// Delay between individual (non-batched) requests: twitch/youtube/twitter/bluesky/rplay.
const REQUEST_SLEEP_SECONDS = 1;

// Same X-fetch-worker dispatch update.php uses — X blocks unauthenticated
// scraping from datacenter IPs, so route through the worker by default.
// Falls back to the direct scrape if unset/disabled.
const USE_X_FETCH_WORKER = true;
const X_FETCH_WORKER_URL = 'https://xtest.thighdrate.workers.dev/';

/** Plain GET with a browser User-Agent; body null on any failure/non-200. */
function curlGetDetailed(string $url, array $headers = []): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['body' => null, 'statusCode' => 0, 'curlError' => 'curl_init() failed'];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => array_merge([
            'User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36',
            'Accept-Language: en-US,en;q=0.9',
        ], $headers),
    ]);

    $body = curl_exec($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    return [
        'body' => ($body !== false && $statusCode === 200) ? $body : null,
        'statusCode' => $statusCode,
        'curlError' => $curlError,
    ];
}

function curlGet(string $url, array $headers = []): ?string
{
    return curlGetDetailed($url, $headers)['body'];
}

function parseHtmlDom(string $html): DOMDocument
{
    $dom = new DOMDocument();
    $internalErrors = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($internalErrors);

    return $dom;
}

function domFirst(DOMXPath $xpath, string $query, ?DOMNode $context = null): ?string
{
    $nodes = $context !== null ? $xpath->query($query, $context) : $xpath->query($query);

    return ($nodes !== false && $nodes->length > 0) ? $nodes->item(0)->nodeValue : null;
}

/**
 * Strips lone (unpaired) UTF-16 surrogate \uXXXX escapes that would
 * otherwise make json_decode() fail the whole response (see update.php).
 */
function repairUnicodeSurrogates(string $json): string
{
    $json = preg_replace('/\\\\u[dD][89abAB][0-9a-fA-F]{2}(?!\\\\u[dD][c-fC-F][0-9a-fA-F]{2})/', '', $json) ?? $json;
    $json = preg_replace('/(?<!\\\\u[dD][89abAB][0-9a-fA-F]{2})\\\\u[dD][c-fC-F][0-9a-fA-F]{2}/', '', $json) ?? $json;

    return $json;
}

/** True if Twitch's GetUserBasic query returns a real user for $login. */
function checkTwitchExists(string $login, string $clientId): bool
{
    $payload = [
        'operationName' => 'GetUserBasic',
        'query' => TWITCH_QUERY,
        'variables' => ['login' => $login],
    ];

    // curlGetDetailed() only does GET; Twitch's GQL endpoint needs POST, so
    // the request is built by hand here instead.
    $ch = curl_init(TWITCH_GRAPHQL_URL);
    if ($ch === false) {
        return false;
    }
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
    curl_close($ch);

    if ($response === false || $statusCode !== 200) {
        return false;
    }

    $decoded = json_decode($response, true);
    $user = is_array($decoded) ? ($decoded['data']['user'] ?? null) : null;

    return is_array($user) && !empty($user['id'] ?? null);
}

/** True if a YouTube channel page for @$channel resolves to a real channel. */
function checkYoutubeExists(string $channel): bool
{
    $url = 'https://www.youtube.com/@' . rawurlencode($channel);

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36\r\nAccept-Language: en-US,en;q=0.9\r\n",
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);

    $html = @file_get_contents($url, false, $context);
    if (!is_string($html) || $html === '') {
        return false;
    }

    $marker = 'var ytInitialData = ';
    $jsonStart = strpos($html, $marker);
    if ($jsonStart === false) {
        return false;
    }
    $jsonStart += strlen($marker);

    $jsonEnd = strpos($html, ';</script>', $jsonStart);
    if ($jsonEnd === false) {
        return false;
    }

    $data = json_decode(substr($html, $jsonStart, $jsonEnd - $jsonStart), true);
    if (!is_array($data)) {
        return false;
    }

    $channelMeta = $data['metadata']['channelMetadataRenderer'] ?? null;
    if (!is_array($channelMeta)) {
        return false;
    }

    $channelId = $channelMeta['externalId'] ?? null;
    $avatarUrl = $channelMeta['avatar']['thumbnails'][0]['url'] ?? null;

    return is_string($channelId) && $channelId !== '' && is_string($avatarUrl) && $avatarUrl !== '';
}

/** Dispatches to the x-fetch-test worker (if configured) or a direct x.com scrape. */
function checkTwitterExists(string $handle): bool
{
    if (USE_X_FETCH_WORKER && X_FETCH_WORKER_URL !== '') {
        return checkTwitterExistsViaWorker($handle, X_FETCH_WORKER_URL);
    }

    return checkTwitterExistsDirect($handle);
}

function checkTwitterExistsViaWorker(string $handle, string $workerUrl): bool
{
    $requestUrl = rtrim($workerUrl, '/') . '/' . rawurlencode($handle);
    $fetch = curlGetDetailed($requestUrl);
    if ($fetch['body'] === null) {
        return false;
    }

    $decoded = json_decode($fetch['body'], true);
    if (!is_array($decoded)) {
        return false;
    }

    return empty($decoded['likelyBlocked'])
        && is_array($decoded['platformData'] ?? null)
        && !empty($decoded['avatarUrl'] ?? null);
}

/** Same profile-owner-block matching logic as update.php's fetchTwitterPlatformDataDirect(). */
function checkTwitterExistsDirect(string $handle): bool
{
    $url = 'https://x.com/' . rawurlencode($handle);
    $html = curlGet($url);
    if ($html === null) {
        return false;
    }

    $dom = parseHtmlDom($html);
    $xpath = new DOMXPath($dom);

    $avatarUrl = domFirst($xpath, '(//link[@rel="preload"][@as="image"][contains(@href,"profile_images")]/@href)[1]');

    if ($avatarUrl === null) {
        $handleLower = strtolower($handle);
        foreach ($xpath->query('//div[@itemprop="author"]') as $node) {
            $altName = domFirst($xpath, './/meta[@itemprop="alternateName"]/@content', $node);
            if ($altName !== null && strtolower(ltrim($altName, '@')) === $handleLower) {
                $avatarUrl = domFirst($xpath, './/meta[@itemprop="image"]/@content', $node);
                break;
            }
        }
    }

    return is_string($avatarUrl) && $avatarUrl !== '';
}

/** Same JSON-LD ProfilePage check as update.php's fetchBlueskyPlatformData(). */
function checkBlueskyExists(string $name): bool
{
    $url = 'https://bsky.app/profile/' . rawurlencode($name);
    $html = curlGet($url);
    if ($html === null) {
        return false;
    }

    if (!preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $ldMatch)) {
        return false;
    }

    $ld = json_decode($ldMatch[1], true);
    $mainEntity = is_array($ld['mainEntity'] ?? null) ? $ld['mainEntity'] : null;
    if ($mainEntity === null) {
        return false;
    }

    $avatarUrl = $mainEntity['image'] ?? null;

    return is_string($avatarUrl) && $avatarUrl !== '';
}

/** Queries Fansly's account endpoint for up to FANSLY_BATCH_SIZE usernames at once. */
function fetchFanslyAccountsBatch(array $usernames): ?array
{
    if (count($usernames) === 0) {
        return null;
    }

    $usernamesParam = implode(',', array_map('rawurlencode', $usernames));
    $url = FANSLY_API_URL . '?usernames=' . $usernamesParam . '&ngsw-bypass=true';

    $fetch = curlGetDetailed($url, ['Accept: application/json']);
    if ($fetch['body'] === null) {
        return null;
    }

    $decoded = json_decode(repairUnicodeSurrogates($fetch['body']), true);
    if (!is_array($decoded) || ($decoded['success'] ?? false) !== true || !is_array($decoded['response'] ?? null)) {
        return null;
    }

    return $decoded['response'];
}

/** True if RPlay's getuser endpoint returns a real account for $rplayValue. */
function checkRplayExists(string $rplayValue): bool
{
    if (str_starts_with($rplayValue, 'creatorhome/')) {
        $userOid = substr($rplayValue, strlen('creatorhome/'));
        $url = RPLAY_API_URL . '?userOid=' . rawurlencode($userOid);
    } else {
        $url = RPLAY_API_URL . '?customUrl=' . rawurlencode($rplayValue);
    }

    $response = curlGet($url, ['Accept: application/json']);
    if ($response === null) {
        return false;
    }

    $decoded = json_decode($response, true);

    return is_array($decoded) && !empty($decoded['_id'] ?? null);
}

/** Loads accounts data from a URL (via cURL) or a local file path. */
function loadAccountsFrom(string $source): ?array
{
    $json = preg_match('#^https?://#i', $source) ? curlGet($source) : (is_file($source) ? file_get_contents($source) : false);
    if (!is_string($json) || $json === '') {
        return null;
    }

    $decoded = json_decode($json, true);

    return is_array($decoded) ? $decoded : null;
}

function loadStatus(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : [];
}

function saveStatus(string $path, array $status): void
{
    ksort($status);
    file_put_contents(
        $path,
        json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );
}

/** Records a check's outcome (top-level platform key, e.g. "twitch"/"bluesky"/"fansly"/"rplay"). */
function statusSetPlatform(array &$status, string $login, string $platform, bool $exists): void
{
    $status[$login][$platform] = ['exists' => $exists, 'checkedAt' => gmdate('Y-m-d\TH:i:s\Z')];
}

/** Records a per-handle check's outcome (currently only "twitter"). */
function statusSetSubkey(array &$status, string $login, string $platform, string $subkey, bool $exists): void
{
    $status[$login][$platform][$subkey] = ['exists' => $exists, 'checkedAt' => gmdate('Y-m-d\TH:i:s\Z')];
}

function statusHasPlatform(array $status, string $login, string $platform): bool
{
    return isset($status[$login][$platform]);
}

function statusHasSubkey(array $status, string $login, string $platform, string $subkey): bool
{
    return isset($status[$login][$platform][$subkey]);
}

/**
 * Counts, across the WHOLE status.json (not just this run's checks), how
 * many entries have "exists": false per top-level platform key — for
 * "twitter" this counts individual handles, not accounts.
 */
function countNotFoundPerPlatform(array $status): array
{
    $counts = array_fill_keys(ALL_PLATFORMS, 0);

    foreach ($status as $platforms) {
        foreach ($platforms as $platform => $value) {
            if (!in_array($platform, ALL_PLATFORMS, true)) {
                continue;
            }
            if ($platform === 'twitter') {
                foreach ($value as $handleEntry) {
                    if (($handleEntry['exists'] ?? true) === false) {
                        $counts['twitter']++;
                    }
                }
            } elseif (($value['exists'] ?? true) === false) {
                $counts[$platform]++;
            }
        }
    }

    return $counts;
}

// ---------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------

$useLocal = in_array('--local', $argv, true);
$accountsSource = DEFAULT_ACCOUNTS_URL;
if ($useLocal) {
    $accountsSource = ROOT_DIR . '/accounts.json';
}
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--accounts=')) {
        $accountsSource = substr($arg, strlen('--accounts='));
        break;
    }
}

// --twitch/--youtube/--twitter/--bluesky/--fansly/--rplay and/or
// --platforms=a,b,c restrict which platforms this run processes; with
// neither given, every platform is processed (unchanged default behavior).
$selectedPlatforms = [];
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--platforms=')) {
        foreach (explode(',', substr($arg, strlen('--platforms='))) as $platform) {
            $platform = strtolower(trim($platform));
            if (in_array($platform, ALL_PLATFORMS, true)) {
                $selectedPlatforms[] = $platform;
            }
        }
    }
}
foreach (ALL_PLATFORMS as $platform) {
    if (in_array('--' . $platform, $argv, true)) {
        $selectedPlatforms[] = $platform;
    }
}
$selectedPlatforms = $selectedPlatforms !== [] ? array_values(array_unique($selectedPlatforms)) : ALL_PLATFORMS;

$clientId = getenv('TWITCH_CLIENT_ID');
if (!is_string($clientId) || $clientId === '') {
    $clientId = 'kimne78kx3ncx6brgo4mv6wki5h1ko';
}

echo "Loading accounts from: {$accountsSource}\n";
$accounts = loadAccountsFrom($accountsSource);
if ($accounts === null) {
    fwrite(STDERR, "Failed to load/parse accounts data from {$accountsSource}\n");
    exit(1);
}
echo 'Loaded ' . count($accounts) . " accounts.\n";
echo 'Platforms: ' . implode(', ', $selectedPlatforms) . "\n";

$status = loadStatus(STATUS_PATH);

// ---- Twitch / YouTube ----
$channelRequests = [];
foreach ($accounts as $account) {
    if (!is_array($account)) {
        continue;
    }
    $login = strtolower(trim((string) ($account['slug'] ?? '')));
    if ($login === '') {
        continue;
    }
    foreach (['twitch', 'youtube'] as $type) {
        $handle = strtolower(trim((string) ($account[$type] ?? '')));
        if ($handle === '' || !in_array($type, $selectedPlatforms, true) || statusHasPlatform($status, $login, $type)) {
            continue;
        }
        $channelRequests[] = ['login' => $login, 'handle' => $handle, 'type' => $type];
    }
}

$channelTotal = count($channelRequests);
echo "========================================\n";
echo "Checking {$channelTotal} twitch/youtube accounts...\n";

foreach ($channelRequests as $i => $req) {
    $position = $i + 1;
    $exists = $req['type'] === 'twitch'
        ? checkTwitchExists($req['handle'], $clientId)
        : checkYoutubeExists($req['handle']);

    echo "[{$position}/{$channelTotal}] ({$req['type']}) {$req['handle']}: " . ($exists ? 'exists' : 'not found') . "\n";
    statusSetPlatform($status, $req['login'], $req['type'], $exists);
    saveStatus(STATUS_PATH, $status);
    sleep(REQUEST_SLEEP_SECONDS);
}

// ---- Twitter/X (per handle) ----
$twitterRequests = [];
foreach ((in_array('twitter', $selectedPlatforms, true) ? $accounts : []) as $account) {
    if (!is_array($account)) {
        continue;
    }
    $login = strtolower(trim((string) ($account['slug'] ?? '')));
    if ($login === '') {
        continue;
    }
    foreach ((array) ($account['xHandles'] ?? []) as $xHandle) {
        $xHandle = trim((string) $xHandle);
        if ($xHandle === '') {
            continue;
        }
        $handleLower = strtolower($xHandle);
        if (statusHasSubkey($status, $login, 'twitter', $handleLower)) {
            continue;
        }
        $twitterRequests[] = ['login' => $login, 'handle' => $xHandle];
    }
}

$twitterTotal = count($twitterRequests);
echo "========================================\n";
echo "Checking {$twitterTotal} twitter/X handles...\n";

foreach ($twitterRequests as $i => $req) {
    $position = $i + 1;
    $exists = checkTwitterExists($req['handle']);
    $handleLower = strtolower($req['handle']);

    echo "[{$position}/{$twitterTotal}] (twitter) @{$req['handle']}: " . ($exists ? 'exists' : 'not found') . "\n";
    statusSetSubkey($status, $req['login'], 'twitter', $handleLower, $exists);
    saveStatus(STATUS_PATH, $status);
    sleep(REQUEST_SLEEP_SECONDS);
}

// ---- Bluesky ----
$blueskyRequests = [];
foreach ((in_array('bluesky', $selectedPlatforms, true) ? $accounts : []) as $account) {
    if (!is_array($account)) {
        continue;
    }
    $login = strtolower(trim((string) ($account['slug'] ?? '')));
    if ($login === '') {
        continue;
    }
    $bskyHandle = $account['bskyHandle'] ?? null;
    $bskyName = is_array($bskyHandle) ? trim((string) ($bskyHandle[0] ?? '')) : '';
    $bskyPath = is_array($bskyHandle) ? trim((string) ($bskyHandle[1] ?? '')) : '';
    if ($bskyName === '') {
        continue;
    }
    if (statusHasPlatform($status, $login, 'bluesky')) {
        continue;
    }
    $blueskyRequests[] = ['login' => $login, 'path' => $bskyPath !== '' ? $bskyPath : $bskyName];
}

$blueskyTotal = count($blueskyRequests);
echo "========================================\n";
echo "Checking {$blueskyTotal} bluesky accounts...\n";

foreach ($blueskyRequests as $i => $req) {
    $position = $i + 1;
    $exists = checkBlueskyExists($req['path']);

    echo "[{$position}/{$blueskyTotal}] (bluesky) {$req['path']}: " . ($exists ? 'exists' : 'not found') . "\n";
    statusSetPlatform($status, $req['login'], 'bluesky', $exists);
    saveStatus(STATUS_PATH, $status);
    sleep(REQUEST_SLEEP_SECONDS);
}

// ---- Fansly (batched) ----
$fanslyRequests = [];
foreach ((in_array('fansly', $selectedPlatforms, true) ? $accounts : []) as $account) {
    if (!is_array($account)) {
        continue;
    }
    $fanslyUsername = trim((string) ($account['fansly'] ?? ''));
    $login = strtolower(trim((string) ($account['slug'] ?? '')));
    if ($fanslyUsername === '' || $login === '') {
        continue;
    }
    if (statusHasPlatform($status, $login, 'fansly')) {
        continue;
    }
    $fanslyRequests[] = ['login' => $login, 'username' => $fanslyUsername];
}

$fanslyTotal = count($fanslyRequests);
echo "========================================\n";
echo "Checking {$fanslyTotal} fansly accounts (batched " . FANSLY_BATCH_SIZE . " per request)...\n";

foreach (array_chunk($fanslyRequests, FANSLY_BATCH_SIZE) as $batch) {
    $usernames = array_column($batch, 'username');
    echo 'Fetching Fansly batch: ' . implode(', ', $usernames) . "\n";

    $response = fetchFanslyAccountsBatch($usernames);

    $foundUsernames = [];
    if ($response !== null) {
        foreach ($response as $fanslyAccount) {
            if (is_array($fanslyAccount) && is_string($fanslyAccount['username'] ?? null)) {
                $foundUsernames[strtolower($fanslyAccount['username'])] = true;
            }
        }
    }

    foreach ($batch as $req) {
        $exists = isset($foundUsernames[strtolower($req['username'])]);
        echo "  ({$req['username']}): " . ($exists ? 'exists' : 'not found') . "\n";
        statusSetPlatform($status, $req['login'], 'fansly', $exists);
    }

    saveStatus(STATUS_PATH, $status);
    sleep(FANSLY_SLEEP_SECONDS);
}

// ---- RPlay ----
$rplayRequests = [];
foreach ((in_array('rplay', $selectedPlatforms, true) ? $accounts : []) as $account) {
    if (!is_array($account)) {
        continue;
    }
    $rplayValue = trim((string) ($account['rplay'] ?? ''));
    $login = strtolower(trim((string) ($account['slug'] ?? '')));
    if ($rplayValue === '' || $login === '') {
        continue;
    }
    if (statusHasPlatform($status, $login, 'rplay')) {
        continue;
    }
    $rplayRequests[] = ['login' => $login, 'value' => $rplayValue];
}

$rplayTotal = count($rplayRequests);
echo "========================================\n";
echo "Checking {$rplayTotal} rplay accounts...\n";

foreach ($rplayRequests as $i => $req) {
    $position = $i + 1;
    $exists = checkRplayExists($req['value']);

    echo "[{$position}/{$rplayTotal}] (rplay) {$req['value']}: " . ($exists ? 'exists' : 'not found') . "\n";
    statusSetPlatform($status, $req['login'], 'rplay', $exists);
    saveStatus(STATUS_PATH, $status);
    sleep(RPLAY_SLEEP_SECONDS);
}

echo "========================================\n";
echo "Done. Checked: twitch/youtube={$channelTotal}, twitter={$twitterTotal}, bluesky={$blueskyTotal}, fansly={$fanslyTotal}, rplay={$rplayTotal}\n";
echo "Status saved to: " . STATUS_PATH . "\n";

$notFoundCounts = countNotFoundPerPlatform($status);
echo "========================================\n";
echo "Not found (exists=false), across all of status.json:\n";
foreach ($notFoundCounts as $platform => $count) {
    echo "  {$platform}: {$count}\n";
}
