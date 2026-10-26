<?php

declare(strict_types=1);

/**
 * Bulk channel-data runner for accounts.json.
 *
 * accounts.json entries look like { "slug", "channel", "twitch"?,
 * "youtube"?, "fansly"?, "xHandles", "bskyHandle"? } (see
 * accounts-json-lib.php). "slug" is the identity used for every file name
 * below ({login} == slug) and is auto-filled when missing; "channel" is a
 * manually-set display name and is never modified here. "twitch" is fetched
 * via the GetUserBasic GraphQL query and "youtube" by scraping
 * https://www.youtube.com/@{handle} for its embedded ytInitialData; an
 * account may have both.
 *
 * For every "twitch"/"youtube" handle whose creators/{login}.json doesn't
 * already have that platform's own key set (this, not accounts.json, is the
 * source of truth for whether it's resolved), fetches it and, on success:
 * - Downloads the profile/banner image(s) to a temp file under images/,
 *   converts them to WebP with ImageMagick, and deletes the temp file —
 *   images/ is never used as permanent storage. The profile image is
 *   saved as avatarsLarge/{channel}.webp (a ~300px version) and as
 *   avatars/{channel}.webp (70x70; Twitch only supplies the 300px source
 *   and is resized locally, YouTube's 70px thumbnail is fetched
 *   directly). The banner (if any) is saved as banners/{channel}.webp.
 *   Every one of these is skipped if it already exists on disk, since
 *   this also runs to backport data for existing entries and must never
 *   re-download/overwrite an image that's already there.
 * - Merges into creators/{login}.json: sets "bio" and stores every field
 *   the fetch returned under the platform's own key (e.g. "twitch") —
 *   merging into the file if it already exists rather than clobbering
 *   other platform keys ("kick", "fansly", etc. are left untouched), and
 *   NEVER overwriting a platform key that's already set. This happens on
 *   ANY successful fetch, even with an empty description, so an account
 *   is never re-fetched purely because it has no bio.
 *
 * Since this script is the sole writer of accounts.json now, every save
 * also normalizes casing: all string values are lowercased except
 * "channel" (display name) and URL-ish fields
 * ("socials", the URL half of "bskyHandle"/"other" entries) which are
 * left untouched to avoid breaking case-sensitive links.
 *
 * At the end of every run, data.json (the small public file the browser
 * extension polls to badge new creators, see extension popup.js scripts)
 * has its known fields merged in — current UTC timestamp ("updated"), the
 * total number of entries in accounts.json ("creators"), the
 * spicyLinks/twitterBsky/socials counts (see computeAccountsStats(), a
 * PHP port of script001.js's computeStats()), and the chrome/firefox
 * extension version numbers read straight out of
 * extension/chrome/manifest.json and extension/firefox/manifest.json
 * ("chromeVersion"/"firefoxVersion") — regardless of whether this run
 * changed anything, since it always reflects accounts.json's current true
 * state. writeDataJson() reads whatever is already in data.json first and
 * only overwrites these specific keys, so any other field that ends up in
 * data.json is never lost.
 *
 * Finally, every entry with a non-empty "fansly" handle whose
 * creators/{login}.json doesn't already have a "fansly" key set is
 * queried against Fansly's account endpoint, FANSLY_BATCH_SIZE usernames
 * per request, and — for every username the API actually returns data
 * for — creators/{login}.json's "fansly" field is set to the subset of
 * fields we keep (see shapeFanslyAccountData()) plus our own "fetchedAt"
 * timestamp.
 *
 * Usage:
 *   php update.php                  # incremental (default)
 *   php update.php --force          # re-fetch every eligible twitch/youtube/
 *                                   # fansly/twitter/bluesky account and
 *                                   # re-save its images, even if already
 *                                   # resolved on disk
 *   php update.php --force=NAME     # same as --force, but scoped to only
 *                                   # the account whose "slug" (case-
 *                                   # insensitive) equals NAME — every
 *                                   # other account still runs incrementally
 *
 * Notes:
 * - No app dependencies; uses only built-in cURL/file_get_contents.
 * - Runs sequentially with a 1-second delay between twitch/youtube API
 *   calls, and a FANSLY_SLEEP_SECONDS delay between Fansly batch calls.
 * - accounts.json is re-saved after every update so progress isn't lost
 *   if the run is interrupted.
 *
 * Twitter/X and Bluesky (added 2026-08-08): every account's "xHandles"
 * (an array — a creator can have several X handles) and "bskyHandle"
 * ([name, link], name is index 0 — a creator only ever has one) are also
 * processed, scraping https://x.com/{handle} and https://bsky.app/profile/
 * {name} directly (no API/auth). Unlike twitch/youtube, resolved-state is
 * tracked per handle: creators/{login}.json's "twitter" key is an object
 * keyed by each lowercased handle (since there can be several), while
 * "bluesky" is a single object exactly like "twitch"/"youtube". Avatars
 * are always saved 300x300 (cropped) now, to avatarsX/{handle}.webp and
 * avatarsB/{name}.webp; banners are resized to a height of 500 (aspect
 * preserved) to bannersX/{handle}.webp and bannersB/{name}.webp. The
 * small 70x70 avatar variant was removed everywhere (twitch/youtube
 * included) since nothing on the site displays it anymore.
 *
 * RPlay (added 2026-08-18): every entry with a non-empty "rplay" handle
 * whose creators/{login}.json doesn't already have an "rplay" key set is
 * queried against https://api.rplay.live/account/getuser, one account per
 * request (no batching, unlike Fansly) — using the userOid param when
 * "rplay" is "creatorhome/{id}", or the customUrl param otherwise. On
 * success, creators/{login}.json's "rplay" field is set to id,
 * channelIntro, creatorTags, nickname, signupDate, verified,
 * channelLanguage, isLivestreamer, customUrl, channelImage, and
 * streamStartTime, plus our own "fetchedAt" timestamp.
 */

require __DIR__ . '/accounts-json-lib.php';

// accounts.json handle fields fetched by fetchPlatformData(), in avatar/banner priority order.
const CHANNEL_PLATFORMS = ['twitch', 'youtube'];

const TWITCH_GRAPHQL_URL = 'https://gql.twitch.tv/gql';
const TWITCH_QUERY = <<<'GRAPHQL'
query GetUserBasic($login: String!) {
  user(login: $login) {
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

// Twitch GQL usually requires Client-Id. Provide via env, with a common public fallback.
$clientId = getenv('TWITCH_CLIENT_ID');
if (!is_string($clientId) || $clientId === '') {
    $clientId = 'kimne78kx3ncx6brgo4mv6wki5h1ko';
}

// Fansly's account endpoint (requested via X_FETCH_WORKER_URL's option=fansly) accepts a comma-separated batch of usernames.
const FANSLY_BATCH_SIZE = 20;
const FANSLY_SLEEP_SECONDS = 5;

const RPLAY_API_URL = 'https://api.rplay.live/account/getuser';
// RPlay's getuser endpoint takes one account at a time, unlike Fansly's batch endpoint.
const RPLAY_SLEEP_SECONDS = 1;

// Whether to fetch X/Twitter profiles via the x-fetch-test Cloudflare
// Worker (see workers/x-fetch-test) instead of scraping x.com directly —
// X blocks unauthenticated scraping from GitHub Actions' datacenter IPs,
// so this is on by default. Set to false to force the direct scrape path
// (fetchTwitterPlatformDataDirect()). Requires the X_FETCH_WORKER_URL env
// var (e.g. https://spicyvtubers-x-fetch-test.<subdomain>.workers.dev);
// falls back to the direct scrape automatically if that's unset.
const USE_X_FETCH_WORKER = true;
const X_FETCH_WORKER_URL = 'https://xtest.thighdrate.workers.dev/';

// --force forces every eligible account; --force=NAME scopes forcing to
// only the account whose "slug" equals NAME (every other
// account is still processed incrementally as normal).
$force = in_array('--force', $argv, true);
$forceCreator = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--force=')) {
        $forceCreator = strtolower(trim(substr($arg, strlen('--force='))));
        break;
    }
}

/** Whether $login should be force-refetched given the current $force/$forceCreator flags. */
function isForcedFor(string $login, bool $force, ?string $forceCreator): bool
{
    return $force || ($forceCreator !== null && $login === $forceCreator);
}

/**
 * Loads simple.json (a flat array of creator slugs that must never
 * have avatars/banners downloaded, for copyright-claim protection) into a
 * lowercased-name lookup set. Missing/malformed file just yields no protected
 * creators rather than failing the whole run.
 */
function loadSimpleChannels(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        return [];
    }

    $set = [];
    foreach ($decoded as $name) {
        $name = strtolower(trim((string) $name));
        if ($name !== '') {
            $set[$name] = true;
        }
    }

    return $set;
}

/** Whether $login (already lowercased) is copyright-protected per simple.json. */
function isSimpleProtected(string $login, array $simpleChannels): bool
{
    return isset($simpleChannels[$login]);
}

$rootDir = dirname(__DIR__);
$accountsPath = $rootDir . '/accounts.json';
$dataJsonPath = $rootDir . '/data.json';
$imagesDir = $rootDir . '/images';
$creatorsDir = $rootDir . '/creators';
$avatarsLargeDir = $rootDir . '/avatarsLarge';
$bannersDir = $rootDir . '/banners';
$avatarsXDir = $rootDir . '/avatarsX';
$bannersXDir = $rootDir . '/bannersX';
$avatarsBDir = $rootDir . '/avatarsB';
$bannersBDir = $rootDir . '/bannersB';
$chromeManifestPath = $rootDir . '/extension/chrome/manifest.json';
$firefoxManifestPath = $rootDir . '/extension/firefox/manifest.json';

foreach ([$imagesDir, $creatorsDir, $avatarsLargeDir, $bannersDir, $avatarsXDir, $bannersXDir, $avatarsBDir, $bannersBDir] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "Failed to create directory: {$dir}\n");
        exit(1);
    }
}

if (shell_exec('command -v convert') === null) {
    fwrite(STDERR, "Error: ImageMagick's 'convert' command is not installed.\n");
    exit(1);
}

$json = file_get_contents($accountsPath);
if ($json === false) {
    fwrite(STDERR, "Failed to read accounts.json\n");
    exit(1);
}

$accounts = json_decode($json, true);
if (!is_array($accounts)) {
    fwrite(STDERR, "Failed to parse accounts.json\n");
    exit(1);
}

try {
    $slugsAdded = ensureAccountSlugs($accounts);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
if ($slugsAdded > 0) {
    writeAccountsJson($accountsPath, $accounts);
    echo "Generated slug for {$slugsAdded} account(s).\n";
}

$simpleJsonPath = $rootDir . '/simple.json';
$simpleChannels = loadSimpleChannels($simpleJsonPath);

function fetchTwitchUserBasic(string $login, string $clientId): ?array
{
    $payload = [
        'operationName' => 'GetUserBasic',
        'query' => TWITCH_QUERY,
        'variables' => ['login' => $login],
    ];

    $ch = curl_init(TWITCH_GRAPHQL_URL);
    if ($ch === false) {
        return null;
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
        return null;
    }

    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}

function downloadImage(string $url, string $destination): bool
{
    $ch = curl_init($url);
    if ($ch === false) {
        return false;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36',
    ]);

    $data = curl_exec($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($data === false || $statusCode !== 200) {
        return false;
    }

    return file_put_contents($destination, $data) !== false;
}

/**
 * Downloads $url to a temp file under images/{$tempNameBase}.<ext> (the
 * extension is taken from the URL, falling back to .png). Returns the
 * temp file's path on success, or null on failure. Caller is responsible
 * for deleting the temp file once it's done converting it.
 */
function downloadTempImage(string $imagesDir, string $tempNameBase, ?string $url): ?string
{
    if (!is_string($url) || $url === '') {
        return null;
    }

    $ext = pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION);
    $ext = $ext !== '' ? $ext : 'png';
    $tempPath = $imagesDir . '/' . $tempNameBase . '.' . $ext;

    return downloadImage($url, $tempPath) ? $tempPath : null;
}

/**
 * Converts $sourcePath to WebP at $destPath via ImageMagick. If
 * $squareSize is given, the image is cropped/resized to that exact
 * square size (used for every avatar, now always 300). Otherwise, if
 * $targetHeight is given, the image is resized to that height only,
 * aspect ratio preserved, no cropping (used for X/Bluesky banners).
 */
function convertToWebp(string $sourcePath, string $destPath, ?int $squareSize = null, ?int $targetHeight = null): bool
{
    if ($squareSize !== null) {
        $resizeArgs = sprintf('-resize %1$dx%1$d^ -gravity center -extent %1$dx%1$d', $squareSize);
    } elseif ($targetHeight !== null) {
        $resizeArgs = sprintf('-resize x%d', $targetHeight);
    } else {
        $resizeArgs = '';
    }

    // -background none is required before -extent, otherwise ImageMagick
    // flattens the image onto an opaque white canvas even when no padding
    // is actually needed, silently destroying source transparency.
    $cmd = trim(sprintf('convert %s -background none %s %s', escapeshellarg($sourcePath), $resizeArgs, escapeshellarg($destPath)));
    exec($cmd . ' 2>&1', $output, $exitCode);

    if ($exitCode !== 0) {
        fwrite(STDERR, "  -> ImageMagick conversion failed ({$exitCode}): {$cmd}\n" . implode("\n", $output) . "\n");
        return false;
    }

    return true;
}

/**
 * Produces avatarsLarge/{$login}.webp, always cropped/resized to exactly
 * 300x300, skipping if it already exists on disk unless $force is true
 * (this also runs to backport existing entries and must never
 * re-download/reconvert an avatar that's already there, except when
 * forced). The temp file downloaded into images/ is deleted once
 * converted.
 */
function saveAvatarWebp(string $imagesDir, string $avatarsLargeDir, string $login, ?string $url, bool $force = false): void
{
    if (!is_string($url) || $url === '') {
        return;
    }

    $dest = $avatarsLargeDir . '/' . $login . '.webp';
    if (!$force && is_file($dest)) {
        echo "  -> avatar already exists, skipping.\n";
        return;
    }

    $temp = downloadTempImage($imagesDir, $login . '_avatar_tmp', $url);
    if ($temp === null) {
        echo "  -> Failed to download profile image.\n";
        return;
    }

    if (convertToWebp($temp, $dest, 300)) {
        echo "  -> Saved avatarsLarge/{$login}.webp\n";
    } else {
        echo "  -> Failed to convert profile image.\n";
    }

    @unlink($temp);
}

/**
 * Downloads and converts a creator's banner to banners/{$login}.webp,
 * skipping if it already exists unless $force is true (this also runs to
 * backport existing entries and must never re-download/reconvert a
 * banner that's already there, except when forced). The temp file
 * downloaded into images/ is deleted once converted.
 */
function saveBannerWebp(string $imagesDir, string $bannersDir, string $login, ?string $url, bool $force = false): void
{
    if (!is_string($url) || $url === '') {
        return;
    }

    $dest = $bannersDir . '/' . $login . '.webp';
    if (!$force && is_file($dest)) {
        echo "  -> banner already exists, skipping.\n";
        return;
    }

    $temp = downloadTempImage($imagesDir, $login . '_banner_tmp', $url);
    if ($temp === null) {
        echo "  -> Failed to download banner image.\n";
        return;
    }

    if (convertToWebp($temp, $dest)) {
        echo "  -> Saved banners/{$login}.webp\n";
    } else {
        echo "  -> Failed to convert banner image.\n";
    }

    @unlink($temp);
}

/**
 * Downloads and converts an avatar to $destDir/{$key}.webp, cropped/resized
 * to exactly 300x300 — used for X/Bluesky avatars, keyed by handle/name
 * rather than the creator's own login (a creator can have several X
 * handles, each with its own avatar). Skips if $dest already exists
 * unless $force is true.
 */
function saveKeyedAvatarWebp(string $imagesDir, string $destDir, string $key, ?string $url, bool $force = false): void
{
    if (!is_string($url) || $url === '') {
        return;
    }

    $dest = $destDir . '/' . $key . '.webp';
    if (!$force && is_file($dest)) {
        echo "  -> avatar already exists, skipping.\n";
        return;
    }

    $temp = downloadTempImage($imagesDir, $key . '_avatar_tmp', $url);
    if ($temp === null) {
        echo "  -> Failed to download avatar image.\n";
        return;
    }

    if (convertToWebp($temp, $dest, 300)) {
        echo "  -> Saved {$dest}\n";
    } else {
        echo "  -> Failed to convert avatar image.\n";
    }

    @unlink($temp);
}

/**
 * Downloads and converts a banner to $destDir/{$key}.webp, resized to a
 * height of 500 (aspect ratio preserved, no cropping) — used for X/Bluesky
 * banners, keyed by handle/name. Skips if $dest already exists unless
 * $force is true.
 */
function saveKeyedBannerWebp(string $imagesDir, string $destDir, string $key, ?string $url, bool $force = false): void
{
    if (!is_string($url) || $url === '') {
        return;
    }

    $dest = $destDir . '/' . $key . '.webp';
    if (!$force && is_file($dest)) {
        echo "  -> banner already exists, skipping.\n";
        return;
    }

    $temp = downloadTempImage($imagesDir, $key . '_banner_tmp', $url);
    if ($temp === null) {
        echo "  -> Failed to download banner image.\n";
        return;
    }

    if (convertToWebp($temp, $dest, null, 500)) {
        echo "  -> Saved {$dest}\n";
    } else {
        echo "  -> Failed to convert banner image.\n";
    }

    @unlink($temp);
}

/**
 * Normalizes GetUserBasic's field names to the casing used in storage
 * (profileImageURL/bannerImageURL -> profileImageUrl/bannerImageUrl);
 * every other field is stored exactly as the API returned it.
 */
function normalizeTwitchUserData(array $user): array
{
    $normalized = $user;

    if (array_key_exists('profileImageURL', $normalized)) {
        $normalized['profileImageUrl'] = $normalized['profileImageURL'];
        unset($normalized['profileImageURL']);
    }

    if (array_key_exists('bannerImageURL', $normalized)) {
        $normalized['bannerImageUrl'] = $normalized['bannerImageURL'];
        unset($normalized['bannerImageURL']);
    }

    return $normalized;
}

/**
 * Fetches a Twitch channel and shapes it into the result fetchPlatformData()
 * documents. Returns null on any failure.
 */
function fetchTwitchPlatformData(string $login, string $clientId): ?array
{
    $decoded = fetchTwitchUserBasic($login, $clientId);
    $user = $decoded['data']['user'] ?? null;

    if (!is_array($user)) {
        return null;
    }

    $apiLogin = $user['login'] ?? null;

    return [
        'creatorName' => is_string($apiLogin) && $apiLogin !== '' ? strtolower($apiLogin) : strtolower($login),
        'displayName' => is_string($user['displayName'] ?? null) ? $user['displayName'] : null,
        'description' => is_string($user['description'] ?? null) ? $user['description'] : '',
        'profileImageUrl' => is_string($user['profileImageURL'] ?? null) ? $user['profileImageURL'] : null,
        'bannerImageUrl' => is_string($user['bannerImageURL'] ?? null) ? $user['bannerImageURL'] : null,
        'platformData' => normalizeTwitchUserData($user),
    ];
}

/**
 * Rewrites a yt3.googleusercontent.com avatar/banner URL to request $size
 * pixels, dropping any crop params the URL already had (e.g. .../abc=w1060-
 * fcrop64=1,... becomes .../abc=s{$size}).
 */
function resizeGoogleUserContentUrl(string $url, int $size): string
{
    return preg_replace('/=.*$/', '=h' . $size, $url) ?? $url;
}

/**
 * Scrapes a YouTube channel page (https://www.youtube.com/@{channel}) and
 * shapes it into the result fetchPlatformData() documents. Returns null on
 * any failure (network error, page structure not recognized, no channel id
 * found, etc.).
 */
function fetchYoutubePlatformData(string $channel): ?array
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
        return null;
    }

    // Plain strpos() rather than a /var ytInitialData = (\{.*?\});<\/script>/s
    // regex: some channel pages are large enough (2MB+) that the lazy .*?
    // exhausts PCRE's default backtrack limit and preg_match() silently
    // fails, wrongly treating a valid page as unparseable.
    $marker = 'var ytInitialData = ';
    $jsonStart = strpos($html, $marker);
    if ($jsonStart === false) {
        return null;
    }
    $jsonStart += strlen($marker);

    $jsonEnd = strpos($html, ';</script>', $jsonStart);
    if ($jsonEnd === false) {
        return null;
    }

    $data = json_decode(substr($html, $jsonStart, $jsonEnd - $jsonStart), true);
    if (!is_array($data)) {
        return null;
    }

    $channelMeta = $data['metadata']['channelMetadataRenderer'] ?? null;
    if (!is_array($channelMeta)) {
        return null;
    }

    $channelId = $channelMeta['externalId'] ?? null;
    $avatarUrl = $channelMeta['avatar']['thumbnails'][0]['url'] ?? null;

    if (!is_string($channelId) || $channelId === '' || !is_string($avatarUrl) || $avatarUrl === '') {
        return null;
    }

    $ownerUrl = $channelMeta['ownerUrls'][0] ?? null;
    $handle = is_string($ownerUrl) && preg_match('/@([^\/?]+)/', $ownerUrl, $handleMatch) ? $handleMatch[1] : $channel;

    $headerViewModel = $data['header']['pageHeaderRenderer']['content']['pageHeaderViewModel'] ?? [];
    $bannerSources = $headerViewModel['banner']['imageBannerViewModel']['image']['sources'] ?? [];
    $bannerUrl = is_array($bannerSources) && count($bannerSources) > 0 ? ($bannerSources[0]['url'] ?? null) : null;

    // Best-effort supplementary stats; kept as the raw display strings
    // (e.g. "52.3K subscribers") since YouTube doesn't expose exact counts
    // here, and this is conservative extra data, not a required field.
    $metadataRows = $headerViewModel['metadata']['contentMetadataViewModel']['metadataRows'] ?? [];
    $statsParts = $metadataRows[1]['metadataParts'] ?? [];
    $subscriberCountText = $statsParts[0]['text']['content'] ?? null;
    $videoCountText = $statsParts[1]['text']['content'] ?? null;

    $description = is_string($channelMeta['description'] ?? null) ? $channelMeta['description'] : '';
    $profileImageUrl = resizeGoogleUserContentUrl($avatarUrl, 300);
    $bannerImageUrl = is_string($bannerUrl) && $bannerUrl !== '' ? resizeGoogleUserContentUrl($bannerUrl, 480) : null;

    return [
        'creatorName' => strtolower($handle),
        'displayName' => is_string($channelMeta['title'] ?? null) ? $channelMeta['title'] : null,
        'description' => $description,
        'profileImageUrl' => $profileImageUrl,
        'bannerImageUrl' => $bannerImageUrl,
        'platformData' => [
            'id' => $channelId,
            'handle' => $handle,
            'title' => $channelMeta['title'] ?? null,
            'description' => $description,
            'channelUrl' => $channelMeta['channelUrl'] ?? null,
            'keywords' => $channelMeta['keywords'] ?? null,
            'profileImageUrl' => $profileImageUrl,
            'bannerImageUrl' => $bannerImageUrl,
            'subscriberCountText' => is_string($subscriberCountText) ? $subscriberCountText : null,
            'videoCountText' => is_string($videoCountText) ? $videoCountText : null,
        ],
    ];
}

/**
 * Plain GET request with a browser User-Agent. Returns
 * ['body' => ?string, 'statusCode' => int, 'finalUrl' => string,
 * 'curlError' => string] — body is null on any curl/network/non-200
 * failure, but statusCode/curlError/finalUrl are always populated so
 * callers can log *why* it failed (e.g. distinguishing a block/CAPTCHA
 * page's 403/429 from a network timeout).
 */
function curlGetDetailed(string $url, array $headers = []): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['body' => null, 'statusCode' => 0, 'finalUrl' => $url, 'curlError' => 'curl_init() failed'];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        // '' = accept/auto-decode any encoding curl supports (gzip/deflate/br) —
        // RPlay's getuser endpoint sends gzip-encoded bodies unconditionally,
        // regardless of what Accept-Encoding (if any) the request sent.
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => array_merge([
            'User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36',
            'Accept-Language: en-US,en;q=0.9',
        ], $headers),
    ]);

    $body = curl_exec($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $curlError = curl_error($ch);
    curl_close($ch);

    return [
        'body' => ($body !== false && $statusCode === 200) ? $body : null,
        'statusCode' => $statusCode,
        'finalUrl' => $finalUrl !== '' ? $finalUrl : $url,
        'curlError' => $curlError,
    ];
}

/**
 * Plain GET request with a browser User-Agent, returning the response
 * body, or null on any curl/network/non-200 failure.
 */
function curlGet(string $url, array $headers = []): ?string
{
    return curlGetDetailed($url, $headers)['body'];
}

/**
 * Parses $html into a DOMDocument, forcing UTF-8 (loadHTML() otherwise
 * misreads non-ASCII text without an explicit encoding hint) and
 * suppressing libxml's warnings about the page's non-standard markup.
 */
function parseHtmlDom(string $html): DOMDocument
{
    $dom = new DOMDocument();
    $internalErrors = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($internalErrors);

    return $dom;
}

/** First matching node's value for an XPath query, or null if none match. */
function domFirst(DOMXPath $xpath, string $query, ?DOMNode $context = null): ?string
{
    $nodes = $context !== null ? $xpath->query($query, $context) : $xpath->query($query);

    return ($nodes !== false && $nodes->length > 0) ? $nodes->item(0)->nodeValue : null;
}

/**
 * Picks the highest-resolution URL out of an `imageSrcSet`-style string
 * ("url1 600w, url2 1080w, url3 1500w"), e.g. an X profile banner's
 * preload link, which always lists several sizes and never the largest
 * first.
 */
function largestSrcsetUrl(string $srcset): ?string
{
    $best = null;
    $bestWidth = -1;

    foreach (explode(',', $srcset) as $candidate) {
        if (!preg_match('/^(\S+)\s+(\d+)w$/', trim($candidate), $m)) {
            continue;
        }
        $width = (int) $m[2];
        if ($width > $bestWidth) {
            $bestWidth = $width;
            $best = $m[1];
        }
    }

    return $best;
}

/**
 * Fetches @$handle's X/Twitter profile data. Dispatches to the
 * x-fetch-test Cloudflare Worker (see workers/x-fetch-test) when
 * USE_X_FETCH_WORKER is enabled and X_FETCH_WORKER_URL is configured —
 * X blocks unauthenticated scraping from GitHub Actions' datacenter IPs
 * (see fetchTwitterPlatformDataDirect()'s docblock), and routing through
 * the worker tests/uses Cloudflare's edge IPs instead. Falls back to
 * scraping x.com directly if the worker is disabled or unconfigured.
 */
function fetchTwitterPlatformData(string $handle): ?array
{    
    if (USE_X_FETCH_WORKER && is_string(X_FETCH_WORKER_URL) && X_FETCH_WORKER_URL !== '') {
        return fetchTwitterPlatformDataViaWorker($handle, X_FETCH_WORKER_URL);
    }

    return fetchTwitterPlatformDataDirect($handle);
}

/**
 * Fetches @$handle's profile data via the x-fetch-test worker (a thin
 * proxy that scrapes x.com from Cloudflare's edge and shapes the result
 * identically to fetchTwitterPlatformDataDirect()'s return value).
 * Returns null on any request/parse failure or if the worker itself
 * reports the fetch as blocked.
 */
function fetchTwitterPlatformDataViaWorker(string $handle, string $workerUrl): ?array
{
    $requestUrl = rtrim($workerUrl, '/') . '/' . rawurlencode($handle) . '?option=twitter';
    $fetch = curlGetDetailed($requestUrl);
    if ($fetch['body'] === null) {
        fwrite(STDERR, sprintf(
            "  -> x-fetch-worker request failed for @%s: HTTP %d, curl error: %s\n",
            $handle,
            $fetch['statusCode'],
            $fetch['curlError'] !== '' ? $fetch['curlError'] : '(none)'
        ));
        return null;
    }

    $decoded = json_decode($fetch['body'], true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "  -> x-fetch-worker returned invalid JSON for @{$handle}.\n");
        return null;
    }

    if (!empty($decoded['likelyBlocked']) || !is_array($decoded['platformData'] ?? null)) {
        fwrite(STDERR, sprintf(
            "  -> x-fetch-worker reports @%s blocked/unavailable — upstream status %s, page title: %s\n",
            $handle,
            $decoded['status'] ?? '?',
            $decoded['title'] ?? '(none)'
        ));
        return null;
    }

    return [
        'profileImageUrl' => $decoded['avatarUrl'] ?? null,
        'bannerImageUrl' => $decoded['bannerUrl'] ?? null,
        'platformData' => $decoded['platformData'],
    ];
}

/**
 * Scrapes an X/Twitter profile page (https://x.com/{handle}) for its
 * server-rendered schema.org ProfilePage microdata. Only profile-level
 * data is extracted (avatar, banner, bio, id, follower/following/post
 * counts, account creation date) — the page also embeds this same
 * itemProp="author" block for every tweet/repost shown, including other
 * users', so the first one whose alternateName matches $handle is used
 * (that's the profile owner's own block); post/tweet content itself is
 * never read. Returns null if the page couldn't be fetched or no avatar
 * preload link was found (a sign X served an error/login page instead).
 */
function fetchTwitterPlatformDataDirect(string $handle): ?array
{
    $url = 'https://x.com/' . rawurlencode($handle);
    $fetch = curlGetDetailed($url);
    $html = $fetch['body'];
    if ($html === null) {
        fwrite(STDERR, sprintf(
            "  -> x.com fetch failed for @%s: HTTP %d, curl error: %s, final URL: %s\n",
            $handle,
            $fetch['statusCode'],
            $fetch['curlError'] !== '' ? $fetch['curlError'] : '(none)',
            $fetch['finalUrl']
        ));
        return null;
    }

    $dom = parseHtmlDom($html);
    $xpath = new DOMXPath($dom);

    $avatarUrl = domFirst($xpath, '(//link[@rel="preload"][@as="image"][contains(@href,"profile_images")]/@href)[1]');
    $bannerSrcset = domFirst($xpath, '(//link[@rel="preload"][@as="image"][@imagesrcset])[1]/@imagesrcset');
    $bannerUrl = $bannerSrcset !== null ? largestSrcsetUrl($bannerSrcset) : null;
    $description = domFirst($xpath, '(//meta[@name="description"]/@content)[1]');
    $dateCreated = domFirst($xpath, '(//meta[@itemprop="dateCreated"]/@content)[1]');
    $profileUrl = domFirst($xpath, '(//meta[@itemprop="url"]/@content)[1]');

    $displayName = null;
    $titleText = domFirst($xpath, '(//title)[1]');
    if ($titleText !== null && preg_match('/^(.*)\s\(@[^)]+\)/u', trim($titleText), $m)) {
        $displayName = trim($m[1]);
    }

    $handleLower = strtolower($handle);
    $authorNode = null;
    foreach ($xpath->query('//div[@itemprop="author"]') as $node) {
        $altName = domFirst($xpath, './/meta[@itemprop="alternateName"]/@content', $node);
        if ($altName !== null && strtolower(ltrim($altName, '@')) === $handleLower) {
            $authorNode = $node;
            break;
        }
    }

    $identifier = null;
    $followersCount = null;
    $followingCount = null;
    $postsCount = null;

    if ($authorNode !== null) {
        $identifier = domFirst($xpath, './/meta[@itemprop="identifier"]/@content', $authorNode);
        $displayName = domFirst($xpath, './/meta[@itemprop="name"]/@content', $authorNode) ?? $displayName;
        $avatarUrl = $avatarUrl ?? domFirst($xpath, './/meta[@itemprop="image"]/@content', $authorNode);

        $stats = [];
        foreach ($xpath->query('.//div[@itemprop="agentInteractionStatistic"] | .//div[@itemprop="interactionStatistic"]', $authorNode) as $statNode) {
            $name = domFirst($xpath, './/meta[@itemprop="name"]/@content', $statNode);
            $count = domFirst($xpath, './/meta[@itemprop="userInteractionCount"]/@content', $statNode);
            if ($name !== null && $count !== null) {
                $stats[$name] = (int) $count;
            }
        }
        $postsCount = $stats['Tweets'] ?? null;
        $followingCount = $stats['Following'] ?? null;
        $followersCount = $stats['Follows'] ?? null;
    }

    if (!is_string($avatarUrl) || $avatarUrl === '') {
        // HTTP 200 but no avatar found in the microdata is the signature of
        // X serving a login/rate-limit/challenge wall instead of the real
        // profile (common when scraping from a shared/datacenter IP, e.g.
        // GitHub Actions runners) — surface the page title and length so
        // that's distinguishable from an actually-missing/suspended account.
        $pageTitle = domFirst($xpath, '(//title)[1]');
        fwrite(STDERR, sprintf(
            "  -> x.com returned HTTP 200 for @%s but no avatar/profile data was found (likely blocked/challenged) — page title: %s, body length: %d bytes\n",
            $handle,
            $pageTitle !== null ? trim($pageTitle) : '(none)',
            strlen($html)
        ));
        return null;
    }

    return [
        'profileImageUrl' => $avatarUrl,
        'bannerImageUrl' => $bannerUrl,
        'platformData' => [
            'id' => $identifier,
            'handle' => $handleLower,
            'displayName' => $displayName,
            'description' => $description ?? '',
            'profileImageUrl' => $avatarUrl,
            'bannerImageUrl' => $bannerUrl,
            'followersCount' => $followersCount,
            'followingCount' => $followingCount,
            'postsCount' => $postsCount,
            'accountCreatedAt' => $dateCreated,
            'profileUrl' => $profileUrl ?? $url,
        ],
    ];
}

/**
 * Scrapes a Bluesky profile page (https://bsky.app/profile/{name}) for
 * its embedded `application/ld+json` schema.org ProfilePage script, which
 * (unlike X) cleanly contains everything needed in valid JSON — no HTML
 * parsing required. `hasPart` (the user's own posts) is present in that
 * JSON but deliberately never read, per the profile-only scope here.
 * Returns null if the page couldn't be fetched or the JSON-LD block / its
 * avatar image is missing.
 */
function fetchBlueskyPlatformData(string $name): ?array
{
    $url = 'https://bsky.app/profile/' . rawurlencode($name);
    $html = curlGet($url);
    if ($html === null) {
        return null;
    }

    if (!preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $ldMatch)) {
        return null;
    }
    $ld = json_decode($ldMatch[1], true);
    $mainEntity = is_array($ld['mainEntity'] ?? null) ? $ld['mainEntity'] : null;
    if ($mainEntity === null) {
        return null;
    }

    $avatarUrl = $mainEntity['image'] ?? null;
    if (!is_string($avatarUrl) || $avatarUrl === '') {
        return null;
    }

    $bannerUrl = null;
    if (preg_match('#<meta property="og:image" content="([^"]+)"#', $html, $bannerMatch)) {
        $bannerUrl = html_entity_decode($bannerMatch[1], ENT_QUOTES);
    }

    $followersCount = null;
    foreach ((array) ($mainEntity['interactionStatistic'] ?? []) as $stat) {
        if (($stat['interactionType'] ?? null) === 'https://schema.org/FollowAction') {
            $followersCount = (int) ($stat['userInteractionCount'] ?? 0);
            break;
        }
    }

    $followingCount = null;
    $postsCount = null;
    foreach ((array) ($mainEntity['agentInteractionStatistic'] ?? []) as $stat) {
        $type = $stat['interactionType'] ?? null;
        if ($type === 'https://schema.org/FollowAction') {
            $followingCount = (int) ($stat['userInteractionCount'] ?? 0);
        } elseif ($type === 'https://schema.org/WriteAction') {
            $postsCount = (int) ($stat['userInteractionCount'] ?? 0);
        }
    }

    return [
        'profileImageUrl' => $avatarUrl,
        'bannerImageUrl' => $bannerUrl,
        'platformData' => [
            'did' => $mainEntity['identifier'] ?? null,
            'handle' => strtolower(ltrim((string) ($mainEntity['alternateName'] ?? $name), '@')),
            'displayName' => $mainEntity['name'] ?? null,
            'description' => $mainEntity['description'] ?? '',
            'profileImageUrl' => $avatarUrl,
            'bannerImageUrl' => $bannerUrl,
            'followersCount' => $followersCount,
            'followingCount' => $followingCount,
            'postsCount' => $postsCount,
            'accountCreatedAt' => $ld['dateCreated'] ?? null,
            'profileUrl' => $url,
        ],
    ];
}

/**
 * True if creators/{creatorName}.json already has a non-empty value at
 * the nested path $keyPath (e.g. ['bluesky'] or ['twitter', 'somehandle']).
 */
function creatorHasPlatformSubkeyData(string $creatorsDir, string $creatorName, array $keyPath): bool
{
    $value = loadCreatorData($creatorsDir, $creatorName);
    foreach ($keyPath as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return false;
        }
        $value = $value[$segment];
    }

    return !empty($value);
}

/**
 * Merges $platformData into creators/{creatorName}.json at the nested
 * path $keyPath (creating intermediate arrays as needed), plus our own
 * "fetchedAt" timestamp, unless that path is already set (unless $force).
 * Every other field already on disk is preserved. Returns true on
 * success, or null on failure.
 */
function saveCreatorPlatformSubkeyData(string $creatorsDir, string $creatorName, array $keyPath, array $platformData, bool $force = false): ?bool
{
    if ($creatorName === '' || $keyPath === []) {
        return null;
    }

    $creatorFile = $creatorsDir . '/' . $creatorName . '.json';
    $creatorData = loadCreatorData($creatorsDir, $creatorName);

    $ref = &$creatorData;
    foreach (array_slice($keyPath, 0, -1) as $segment) {
        if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
            $ref[$segment] = [];
        }
        $ref = &$ref[$segment];
    }
    $lastKey = end($keyPath);

    if ($force || empty($ref[$lastKey])) {
        $platformData['fetchedAt'] = gmdate('Y-m-d\TH:i:s\Z');
        $ref[$lastKey] = $platformData;
    }
    unset($ref);

    $written = file_put_contents(
        $creatorFile,
        json_encode($creatorData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );

    return $written === false ? null : true;
}

/**
 * Strips lone (unpaired) UTF-16 surrogate \uXXXX escapes that would
 * otherwise make json_decode() fail the whole response — Fansly has been
 * observed truncating a string (e.g. a wall name) mid-emoji, leaving a
 * high surrogate with no matching low surrogate.
 */
function repairUnicodeSurrogates(string $json): string
{
    $json = preg_replace('/\\\\u[dD][89abAB][0-9a-fA-F]{2}(?!\\\\u[dD][c-fC-F][0-9a-fA-F]{2})/', '', $json) ?? $json;
    $json = preg_replace('/(?<!\\\\u[dD][89abAB][0-9a-fA-F]{2})\\\\u[dD][c-fC-F][0-9a-fA-F]{2}/', '', $json) ?? $json;

    return $json;
}

/**
 * Queries Fansly's account endpoint for up to FANSLY_BATCH_SIZE usernames
 * at once (see fansly-accounts.json for example response shape). Returns
 * the decoded "response" array of account objects, or null on failure.
 */
function fetchFanslyAccountsBatch(array $usernames): ?array
{
    if (count($usernames) === 0) {
        return null;
    }

    $usernamesParam = implode(',', array_map('rawurlencode', $usernames));
    // Proxied through the x-fetch worker, which passes Fansly's response back untouched.
    $url = rtrim(X_FETCH_WORKER_URL, '/') . '/?option=fansly&usernames=' . $usernamesParam;
    // Outpout url to STDERR for debugging purposes
    fwrite(STDERR, "Fetching Fansly batch: {$url}\n");


    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }

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
    curl_close($ch);

    if ($response === false || $statusCode !== 200) {
        return null;
    }

    $decoded = json_decode(repairUnicodeSurrogates($response), true);
    if (!is_array($decoded) || ($decoded['success'] ?? false) !== true || !is_array($decoded['response'] ?? null)) {
        return null;
    }

    return $decoded['response'];
}

/**
 * Shapes a raw Fansly account object into the subset of fields we keep,
 * plus our own "fetchedAt" timestamp; everything else (badges, walls,
 * subscription tiers, media, etc.) is discarded.
 */
function shapeFanslyAccountData(array $account): array
{
    return [
        'id' => $account['id'] ?? null,
        'username' => $account['username'] ?? null,
        'displayName' => $account['displayName'] ?? null,
        'version' => $account['version'] ?? null,
        'followCount' => $account['followCount'] ?? null,
        'statusId' => $account['statusId'] ?? null,
        'accountMediaLikes' => $account['accountMediaLikes'] ?? null,
        'postLikes' => $account['postLikes'] ?? null,
        'about' => $account['about'] ?? null,
        'location' => $account['location'] ?? null,
        'streaming' => $account['streaming'] ?? null,
        'fetchedAt' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
}

/**
 * Merges $fanslyData into creators/{creatorName}.json's "fansly" field
 * (only ever called when that key isn't already set) while every other
 * field on disk is preserved. Returns true on success, or null on
 * failure.
 */
function saveFanslyCreatorData(string $creatorsDir, string $creatorName, array $fanslyData): ?bool
{
    if ($creatorName === '') {
        return null;
    }

    $creatorFile = $creatorsDir . '/' . $creatorName . '.json';
    $creatorData = loadCreatorData($creatorsDir, $creatorName);
    $creatorData['fansly'] = $fanslyData;

    $written = file_put_contents(
        $creatorFile,
        json_encode($creatorData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );

    return $written === false ? null : true;
}

/**
 * Fetches an RPlay account via https://api.rplay.live/account/getuser.
 * accounts.json's "rplay" value is either "creatorhome/{userOid}" (queried
 * via the userOid param) or a bare customUrl (queried via the customUrl
 * param). Returns the decoded response array, or null on failure.
 */
function fetchRplayAccount(string $rplayValue): ?array
{
    if (str_starts_with($rplayValue, 'creatorhome/')) {
        $userOid = substr($rplayValue, strlen('creatorhome/'));
        $url = RPLAY_API_URL . '?userOid=' . rawurlencode($userOid);
    } else {
        $url = RPLAY_API_URL . '?customUrl=' . rawurlencode($rplayValue);
    }    

    $response = curlGet($url, ['Accept: application/json']);
    if ($response === null) {
        return null;
    }

    $decoded = json_decode($response, true);
    

    return is_array($decoded) ? $decoded : null;
}

/**
 * Shapes a raw RPlay account object into the subset of fields we keep,
 * plus our own "fetchedAt" timestamp; everything else (posts, subscription
 * tiers, sns links, etc.) is discarded.
 */
function shapeRplayAccountData(array $account): array
{
    return [
        'id' => $account['_id'] ?? null,
        'channelIntro' => $account['channelIntro'] ?? null,
        'creatorTags' => $account['creatorTags'] ?? null,
        'nickname' => $account['nickname'] ?? null,
        'signupDate' => $account['signupdate'] ?? null,
        'verified' => $account['verified'] ?? null,
        'channelLanguage' => $account['channelLanguage'] ?? null,
        'isLivestreamer' => $account['isLivestreamer'] ?? null,
        'customUrl' => $account['customUrl'] ?? null,
        'channelImage' => $account['channelImage'] ?? null,
        'streamStartTime' => $account['streamStartTime'] ?? null,
        'fetchedAt' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
}

/**
 * Merges $rplayData into creators/{creatorName}.json's "rplay" field
 * (only ever called when that key isn't already set, unless $force) while
 * every other field on disk is preserved. Returns true on success, or
 * null on failure.
 */
function saveRplayCreatorData(string $creatorsDir, string $creatorName, array $rplayData, bool $force = false): ?bool
{
    if ($creatorName === '') {
        return null;
    }

    $creatorFile = $creatorsDir . '/' . $creatorName . '.json';
    $creatorData = loadCreatorData($creatorsDir, $creatorName);

    if (!$force && !empty($creatorData['rplay'])) {
        return true;
    }

    $creatorData['rplay'] = $rplayData;

    $written = file_put_contents(
        $creatorFile,
        json_encode($creatorData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );

    return $written === false ? null : true;
}

/**
 * Dispatches to the fetcher for $type. Every fetcher returns null on
 * failure, or an array shaped like:
 *   creatorName          string  canonical platform handle (informational)
 *   displayName          ?string platform display name (informational)
 *   description          string  stored as creators/{slug}.json's "bio"
 *   profileImageUrl      ?string avatar; converted (cropped) to a 300x300
 *                                avatarsLarge/{slug}.webp
 *   bannerImageUrl       ?string converted to banners/{slug}.webp
 *   platformData         array   every field the fetch returned, stored
 *                                under creators/{slug}.json's "{$type}" key
 *
 * To add a platform: write a fetchXxxPlatformData() returning this shape,
 * add it below, and add its key to CHANNEL_PLATFORMS.
 */
function fetchPlatformData(string $type, string $channel, string $clientId): ?array
{
    return match ($type) {
        'twitch' => fetchTwitchPlatformData($channel, $clientId),
        'youtube' => fetchYoutubePlatformData($channel),
        default => null,
    };
}

/**
 * Caps $text to its first two paragraphs (paragraphs are runs of text
 * separated by one or more blank lines), discarding the rest.
 */
function capToTwoParagraphs(string $text): string
{
    $normalized = str_replace("\r\n", "\n", $text);
    $paragraphs = preg_split('/\n\s*\n/', trim($normalized)) ?: [];

    return implode("\n\n", array_slice($paragraphs, 0, 2));
}

/**
 * Reads creators/{creatorName}.json, returning its decoded contents (or
 * [] if it doesn't exist or fails to parse). Every other field already on
 * disk ("kick", "fansly", other platform keys, etc.) is preserved as-is
 * by callers that start from this data rather than a blank array.
 */
function loadCreatorData(string $creatorsDir, string $creatorName): array
{
    $creatorFile = $creatorsDir . '/' . $creatorName . '.json';

    if (!is_file($creatorFile)) {
        return [];
    }

    $existingJson = file_get_contents($creatorFile);
    $existing = $existingJson !== false ? json_decode($existingJson, true) : null;

    return is_array($existing) ? $existing : [];
}

/**
 * True if creators/{creatorName}.json already has a non-empty
 * "{$platformKey}" field, meaning that platform is already resolved for
 * this creator and should not be re-fetched.
 */
function creatorHasPlatformData(string $creatorsDir, string $creatorName, string $platformKey): bool
{
    return !empty(loadCreatorData($creatorsDir, $creatorName)[$platformKey]);
}

/**
 * Creates (or merges into) creators/{creatorName}.json, setting "bio" to
 * $description (capped to two paragraphs) and the "{$platformKey}" field
 * to $platformData plus our own "fetchedAt" timestamp (unless that key is
 * already set there, which is never overwritten — unless $force is true).
 * Every other field already on disk is preserved. Returns true on
 * success, or null on failure.
 */
function saveCreatorData(string $creatorsDir, string $creatorName, string $description, string $platformKey, array $platformData, bool $force = false): ?bool
{
    if ($creatorName === '') {
        return null;
    }

    $creatorFile = $creatorsDir . '/' . $creatorName . '.json';
    $creatorData = loadCreatorData($creatorsDir, $creatorName);

    $creatorData['bio'] = capToTwoParagraphs($description);

    if ($force || empty($creatorData[$platformKey])) {
        $platformData['fetchedAt'] = gmdate('Y-m-d\TH:i:s\Z');
        $creatorData[$platformKey] = $platformData;
    }

    $written = file_put_contents(
        $creatorFile,
        json_encode($creatorData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );

    if ($written === false) {
        return null;
    }

    return true;
}

/**
 * Reads the "version" field out of a browser extension's manifest.json.
 * Returns null if the file is missing/unparseable or has no version.
 */
function readExtensionVersion(string $manifestPath): ?string
{
    $json = @file_get_contents($manifestPath);
    if ($json === false) {
        return null;
    }

    $manifest = json_decode($json, true);
    $version = is_array($manifest) ? ($manifest['version'] ?? null) : null;

    return is_string($version) && $version !== '' ? $version : null;
}

/**
 * Rewrites data.json — the small public file the browser extension polls
 * (see extension popup.js scripts) and script100.js's get-extension menu
 * reads — with the current UTC timestamp, total creator count, the
 * spicyLinks/twitterBsky/socials counts (see computeAccountsStats()), and
 * the chrome/firefox extension version numbers. Merges these fields into
 * whatever is already in data.json (parsed first, then overwritten key by
 * key) instead of replacing the whole file, so any other field that ends
 * up in data.json is never lost.
 */
function writeDataJson(string $path, int $creatorsCount, array $stats, ?string $chromeVersion, ?string $firefoxVersion): void
{
    $existingJson = is_file($path) ? file_get_contents($path) : false;
    $data = $existingJson !== false ? json_decode($existingJson, true) : null;
    if (!is_array($data)) {
        $data = [];
    }

    $data['updated'] = gmdate('Y-m-d\TH:i:s\Z');
    $data['creators'] = $creatorsCount;
    $data['spicyLinks'] = $stats['spicyLinks'];
    $data['twitterBsky'] = $stats['twitterBsky'];
    $data['socials'] = $stats['socials'];
    if ($chromeVersion !== null) {
        $data['chromeVersion'] = $chromeVersion;
    }
    if ($firefoxVersion !== null) {
        $data['firefoxVersion'] = $firefoxVersion;
    }

    file_put_contents(
        $path,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    );
}

/**
 * Builds the public {id, login, username} list consumed by the Fansly
 * stream-tracker Cloudflare Worker. Includes every account with a Fansly
 * handle regardless of creators/{login}.json resolution state — unlike
 * the fetch loop below, this isn't gated by creatorHasPlatformData().
 * "id" is the Fansly accountId already resolved onto
 * creators/{login}.json's "fansly"."id" (null if not resolved yet).
 */
function buildFanslyUsernameList(array $accounts, string $creatorsDir): array
{
    $list = [];
    foreach ($accounts as $account) {
        $fanslyUsername = trim((string) ($account['fansly'] ?? ''));
        $login = accountSlug($account);
        if ($fanslyUsername === '' || $login === '') {
            continue;
        }
        $fanslyId = loadCreatorData($creatorsDir, $login)['fansly']['id'] ?? null;
        $list[] = ['id' => $fanslyId, 'login' => $login, 'username' => $fanslyUsername];
    }

    return $list;
}

/**
 * Builds the public {login, id, customUrl, startedAt} list for every
 * account with an "rplay" handle, regardless of creators/{login}.json
 * resolution state. "id"/"customUrl"/"startedAt" come from
 * creators/{login}.json's "rplay" key (null if not resolved yet), not
 * from accounts.json directly. "startedAt" is rplay's "streamStartTime",
 * renamed here for internal consistency with the Fansly stream tracker.
 */
function buildRplayUsernameList(array $accounts, string $creatorsDir): array
{
    $list = [];
    foreach ($accounts as $account) {
        $rplayValue = trim((string) ($account['rplay'] ?? ''));
        $login = accountSlug($account);
        if ($rplayValue === '' || $login === '') {
            continue;
        }
        $rplayData = loadCreatorData($creatorsDir, $login)['rplay'] ?? [];
        $list[] = [
            'login' => $login,
            'id' => $rplayData['id'] ?? null,
            'customUrl' => $rplayData['customUrl'] ?? null,
            'startedAt' => $rplayData['streamStartTime'] ?? null,
        ];
    }

    return $list;
}

// Mirrors script001.js's SPICE_PLATFORMS keys.
const SPICE_PLATFORM_KEYS = ['fansly', 'onlyfans', 'rplay', 'joystick', 'patreon'];

/**
 * Reproduces script001.js's computeStats()/getOtherLinks() over every
 * accounts.json entry with a non-empty "slug" (the same set
 * data.json's "creators" count reflects): spicyLinks (SPICE_PLATFORM_KEYS
 * set, plus "other" links), twitterBsky (non-empty xHandles, plus 1 if
 * bskyHandle's link is set), and socials (creators with "socials" set).
 */
function computeAccountsStats(array $accounts): array
{
    $spicyLinks = 0;
    $twitterBsky = 0;
    $socials = 0;

    foreach ($accounts as $account) {
        if (accountSlug($account) === '') {
            continue;
        }

        foreach (SPICE_PLATFORM_KEYS as $key) {
            if (!empty($account[$key])) {
                $spicyLinks++;
            }
        }

        foreach ((array) ($account['other'] ?? []) as $entry) {
            foreach ((array) $entry as $url) {
                if ($url) {
                    $spicyLinks++;
                }
            }
        }

        $twitterBsky += count(array_filter((array) ($account['xHandles'] ?? [])));

        $bskyHandle = $account['bskyHandle'] ?? null;
        if (is_array($bskyHandle) && !empty($bskyHandle[1])) {
            $twitterBsky++;
        }

        if (!empty($account['socials'])) {
            $socials++;
        }
    }

    return ['spicyLinks' => $spicyLinks, 'twitterBsky' => $twitterBsky, 'socials' => $socials];
}

function resolveAvatarFolder(array $account, string $avatarsXDir, string $avatarsBDir, string $avatarsLargeDir): ?string
{
    $xHandles = array_values(array_filter((array) ($account['xHandles'] ?? []), static fn ($h): bool => trim((string) $h) !== ''));
    $firstXHandle = trim((string) ($xHandles[0] ?? ''));
    if ($firstXHandle !== '' && is_file($avatarsXDir . '/' . strtolower($firstXHandle) . '.webp')) {
        return 'avatarsX';
    }

    $bskyHandle = $account['bskyHandle'] ?? null;
    $bskyName = is_array($bskyHandle) ? trim((string) ($bskyHandle[0] ?? '')) : '';
    if ($bskyName !== '' && is_file($avatarsBDir . '/' . strtolower($bskyName) . '.webp')) {
        return 'avatarsB';
    }

    $slug = accountSlug($account);
    if ($slug !== '' && is_file($avatarsLargeDir . '/' . $slug . '.webp')) {
        return 'avatarsLarge';
    }

    return null;
}

function resolveBannerFolder(array $account, string $bannersXDir, string $bannersBDir, string $bannersDir): ?string
{
    $xHandles = array_values(array_filter((array) ($account['xHandles'] ?? []), static fn ($h): bool => trim((string) $h) !== ''));
    $firstXHandle = trim((string) ($xHandles[0] ?? ''));
    if ($firstXHandle !== '' && is_file($bannersXDir . '/' . strtolower($firstXHandle) . '.webp')) {
        return 'bannersX';
    }

    $bskyHandle = $account['bskyHandle'] ?? null;
    $bskyName = is_array($bskyHandle) ? trim((string) ($bskyHandle[0] ?? '')) : '';
    if ($bskyName !== '' && is_file($bannersBDir . '/' . strtolower($bskyName) . '.webp')) {
        return 'bannersB';
    }

    $slug = accountSlug($account);
    if ($slug !== '' && is_file($bannersDir . '/' . $slug . '.webp')) {
        return 'banners';
    }

    return null;
}

/**
 * For every account whose "slug" is copyright-protected per simple.json:
 * deletes any avatar/banner .webp files already on disk (across twitch/
 * youtube + every xHandle + bskyHandle) and forces accounts.json's
 * avatar/banner fields to the literal string "simple" so generate-
 * creator-pages.php/script215.js render the site logo / no banner instead.
 * Returns the number of accounts whose avatar/banner fields were changed.
 */
function enforceSimpleMediaProtection(
    array &$accounts,
    array $simpleChannels,
    string $avatarsLargeDir,
    string $bannersDir,
    string $avatarsXDir,
    string $bannersXDir,
    string $avatarsBDir,
    string $bannersBDir
): int {
    $accountsChanged = 0;

    foreach ($accounts as &$account) {
        if (!is_array($account)) {
            continue;
        }

        $login = accountSlug($account);
        if ($login === '' || !isSimpleProtected($login, $simpleChannels)) {
            continue;
        }

        $mediaFiles = [
            $avatarsLargeDir . '/' . $login . '.webp',
            $bannersDir . '/' . $login . '.webp',
        ];

        foreach ((array) ($account['xHandles'] ?? []) as $xHandle) {
            $xHandle = strtolower(trim((string) $xHandle));
            if ($xHandle === '') {
                continue;
            }
            $mediaFiles[] = $avatarsXDir . '/' . $xHandle . '.webp';
            $mediaFiles[] = $bannersXDir . '/' . $xHandle . '.webp';
        }

        $bskyHandle = $account['bskyHandle'] ?? null;
        $bskyName = strtolower(trim((string) (is_array($bskyHandle) ? ($bskyHandle[0] ?? '') : '')));
        if ($bskyName !== '') {
            $mediaFiles[] = $avatarsBDir . '/' . $bskyName . '.webp';
            $mediaFiles[] = $bannersBDir . '/' . $bskyName . '.webp';
        }

        foreach ($mediaFiles as $mediaFile) {
            if (is_file($mediaFile)) {
                unlink($mediaFile);
                echo "  -> Deleted protected media (simple.json): {$mediaFile}\n";
            }
        }

        $changed = false;
        if (($account['avatar'] ?? '') !== 'simple') {
            $account['avatar'] = 'simple';
            $changed = true;
        }
        if (($account['banner'] ?? '') !== 'simple') {
            $account['banner'] = 'simple';
            $changed = true;
        }
        if ($changed) {
            $accountsChanged++;
        }
    }
    unset($account);

    return $accountsChanged;
}

$total = count($accounts);
$updated = 0;
$skippedComplete = 0;
$skippedNoSlug = 0;
$failed = 0;

if ($force) {
    echo "--force: re-fetching every eligible account, ignoring already-resolved data.\n";
} elseif ($forceCreator !== null) {
    echo "--force={$forceCreator}: re-fetching only that account, ignoring already-resolved data.\n";
}

foreach ($accounts as $index => $account) {
    $login = accountSlug($account);

    if ($login === '') {
        $skippedNoSlug++;
        continue;
    }

    $isForced = isForcedFor($login, $force, $forceCreator);
    $hasTwitch = trim((string) ($account['twitch'] ?? '')) !== '';
    $position = $index + 1;

    foreach (CHANNEL_PLATFORMS as $type) {
        $handle = strtolower(trim((string) ($account[$type] ?? '')));
        if ($handle === '') {
            continue;
        }

        // creators/{login}.json having this platform's key already set marks
        // it resolved, unless forced via --force or --force=NAME.
        if (!$isForced && creatorHasPlatformData($creatorsDir, $login, $type)) {
            $skippedComplete++;
            continue;
        }

        echo "[{$position}/{$total}] Fetching ({$type}): {$handle} -> {$login}\n";

        $result = fetchPlatformData($type, $handle, $clientId);

        if ($result === null) {
            echo "  -> No user data returned, skipping.\n";
            $failed++;
            sleep(1);
            continue;
        }

        // avatarsLarge/banners hold one image per slug; Twitch wins when both are set.
        $ownsMedia = $type === 'twitch' || !$hasTwitch;
        if (!$ownsMedia) {
            echo "  -> Twitch also set; keeping its avatar/banner.\n";
        } elseif (isSimpleProtected($login, $simpleChannels)) {
            echo "  -> Skipping avatar/banner download for {$login} (protected in simple.json).\n";
        } else {
            saveAvatarWebp($imagesDir, $avatarsLargeDir, $login, $result['profileImageUrl'] ?? null, $isForced);
            saveBannerWebp($imagesDir, $bannersDir, $login, $result['bannerImageUrl'] ?? null, $isForced);
        }

        // A successful fetch always writes the platform key (even with an
        // empty bio) so this handle isn't re-fetched just to learn that.
        $bio = is_string($result['description'] ?? null) ? $result['description'] : '';
        $existingBio = (string) (loadCreatorData($creatorsDir, $login)['bio'] ?? '');
        if (!$ownsMedia && $existingBio !== '') {
            $bio = $existingBio;
        }
        $platformData = is_array($result['platformData'] ?? null) ? $result['platformData'] : [];
        $saved = saveCreatorData($creatorsDir, $login, $bio, $type, $platformData, $isForced);

        if ($saved === true) {
            $updated++;
            echo $bio !== ''
                ? "  -> Saved creator data: creators/{$login}.json\n"
                : "  -> No bio returned; saved empty creators/{$login}.json to skip re-fetching.\n";
        } else {
            echo "  -> Failed to save creator data.\n";
        }

        sleep(1);
    }
}

writeAccountsJson($accountsPath, $accounts);

// Template entries (no slug) aren't real creators, so they're excluded from data.json's count.
$creatorsCount = count(array_filter(
    $accounts,
    static fn (array $a): bool => accountSlug($a) !== ''
));
writeDataJson(
    $dataJsonPath,
    $creatorsCount,
    computeAccountsStats($accounts),
    readExtensionVersion($chromeManifestPath),
    readExtensionVersion($firefoxManifestPath)
);

echo "========================================\n";
echo "Done. Updated: {$updated}, skipped (complete): {$skippedComplete}, skipped (no slug): {$skippedNoSlug}, failed: {$failed}\n";

// creators/{login}.json's "twitter" key is keyed by each lowercased handle
// (a creator can have several xHandles), so resolved-state is checked per
// handle rather than once per creator like twitch/youtube/fansly.
$twitterRequests = [];
foreach ($accounts as $account) {
    $login = accountSlug($account);
    if ($login === '') {
        continue;
    }
    $isForced = isForcedFor($login, $force, $forceCreator);
    foreach ((array) ($account['xHandles'] ?? []) as $xHandle) {
        $xHandle = trim((string) $xHandle);
        if ($xHandle === '') {
            continue;
        }
        if (!$isForced && creatorHasPlatformSubkeyData($creatorsDir, $login, ['twitter', strtolower($xHandle)])) {
            continue;
        }
        $twitterRequests[] = ['login' => $login, 'handle' => $xHandle, 'force' => $isForced];
    }
}

$twitterTotal = count($twitterRequests);
$twitterUpdated = 0;
$twitterFailed = 0;

echo "========================================\n";
echo "Fetching Twitter/X data for {$twitterTotal} handles...\n";

foreach ($twitterRequests as $i => $request) {
    $position = $i + 1;
    echo "[{$position}/{$twitterTotal}] Fetching (twitter): {$request['handle']}\n";

    $result = fetchTwitterPlatformData($request['handle']);
    if ($result === null) {
        echo "  -> No user data returned, skipping.\n";
        $twitterFailed++;
        sleep(1);
        continue;
    }

    $handleLower = strtolower($request['handle']);
    if (isSimpleProtected($request['login'], $simpleChannels)) {
        echo "  -> Skipping avatar/banner download for {$handleLower} (protected in simple.json).\n";
    } else {
        saveKeyedAvatarWebp($imagesDir, $avatarsXDir, $handleLower, $result['profileImageUrl'] ?? null, $request['force']);
        saveKeyedBannerWebp($imagesDir, $bannersXDir, $handleLower, $result['bannerImageUrl'] ?? null, $request['force']);
    }

    $platformData = is_array($result['platformData'] ?? null) ? $result['platformData'] : [];
    $saved = saveCreatorPlatformSubkeyData($creatorsDir, $request['login'], ['twitter', $handleLower], $platformData, $request['force']);

    if ($saved === true) {
        $twitterUpdated++;
        echo "  -> Saved creator data: creators/{$request['login']}.json (twitter->{$handleLower})\n";
    } else {
        $twitterFailed++;
        echo "  -> Failed to save creator data.\n";
    }

    sleep(1);
}

echo "========================================\n";
echo "Done. Twitter updated: {$twitterUpdated}, failed: {$twitterFailed}\n";

// bskyHandle is [name, link]; only one per creator, so "bluesky" is a
// single top-level key exactly like "twitch"/"youtube"/"fansly".
$blueskyRequests = [];
foreach ($accounts as $account) {
    $login = accountSlug($account);
    $bskyHandle = $account['bskyHandle'] ?? null;
    $bskyName = is_array($bskyHandle) ? trim((string) ($bskyHandle[0] ?? '')) : '';
    $bskyPath = is_array($bskyHandle) ? trim((string) ($bskyHandle[1] ?? '')) : '';
    if ($login === '' || $bskyName === '') {
        continue;
    }
    $isForced = isForcedFor($login, $force, $forceCreator);
    if (!$isForced && creatorHasPlatformSubkeyData($creatorsDir, $login, ['bluesky'])) {
        continue;
    }
    $blueskyRequests[] = ['login' => $login, 'name' => $bskyName, 'path' => $bskyPath, 'force' => $isForced];
}

$blueskyTotal = count($blueskyRequests);
$blueskyUpdated = 0;
$blueskyFailed = 0;

echo "========================================\n";
echo "Fetching Bluesky data for {$blueskyTotal} accounts...\n";

foreach ($blueskyRequests as $i => $request) {
    $position = $i + 1;
    echo "[{$position}/{$blueskyTotal}] Fetching (bluesky): {$request['name']} ({$request['path']})\n";

    $result = fetchBlueskyPlatformData($request['path']);
    if ($result === null) {
        echo "  -> No user data returned, skipping.\n";
        $blueskyFailed++;
        sleep(1);
        continue;
    }

    $nameLower = strtolower($request['name']);
    if (isSimpleProtected($request['login'], $simpleChannels)) {
        echo "  -> Skipping avatar/banner download for {$nameLower} (protected in simple.json).\n";
    } else {
        saveKeyedAvatarWebp($imagesDir, $avatarsBDir, $nameLower, $result['profileImageUrl'] ?? null, $request['force']);
        saveKeyedBannerWebp($imagesDir, $bannersBDir, $nameLower, $result['bannerImageUrl'] ?? null, $request['force']);
    }

    $platformData = is_array($result['platformData'] ?? null) ? $result['platformData'] : [];
    $saved = saveCreatorPlatformSubkeyData($creatorsDir, $request['login'], ['bluesky'], $platformData, $request['force']);

    if ($saved === true) {
        $blueskyUpdated++;
        echo "  -> Saved creator data: creators/{$request['login']}.json (bluesky)\n";
    } else {
        $blueskyFailed++;
        echo "  -> Failed to save creator data.\n";
    }

    sleep(1);
}

echo "========================================\n";
echo "Done. Bluesky updated: {$blueskyUpdated}, failed: {$blueskyFailed}\n";

// Like twitch/youtube, creators/{login}.json already having a "fansly"
// key marks this entry resolved and skips it, batched FANSLY_BATCH_SIZE
// at a time.
$fanslyRequests = [];
foreach ($accounts as $account) {
    $fanslyUsername = trim((string) ($account['fansly'] ?? ''));
    $login = accountSlug($account);
    if ($fanslyUsername === '' || $login === '') {
        continue;
    }
    if (!isForcedFor($login, $force, $forceCreator) && creatorHasPlatformData($creatorsDir, $login, 'fansly')) {
        continue;
    }
    $fanslyRequests[] = ['login' => $login, 'username' => $fanslyUsername];
}

$fanslyUpdated = 0;
$fanslyFailed = 0;
$fanslyTotal = count($fanslyRequests);

echo "========================================\n";
echo "Fetching Fansly data for {$fanslyTotal} accounts...\n";

foreach (array_chunk($fanslyRequests, FANSLY_BATCH_SIZE) as $batch) {
    $usernames = array_column($batch, 'username');
    echo "Fetching Fansly batch: " . implode(', ', $usernames) . "\n";

    $response = fetchFanslyAccountsBatch($usernames);

    if ($response === null) {
        echo "  -> Fansly batch request failed, skipping.\n";
        $fanslyFailed += count($batch);
        sleep(FANSLY_SLEEP_SECONDS);
        continue;
    }

    $byUsername = [];
    foreach ($response as $fanslyAccount) {
        if (is_array($fanslyAccount) && is_string($fanslyAccount['username'] ?? null)) {
            $byUsername[strtolower($fanslyAccount['username'])] = $fanslyAccount;
        }
    }

    foreach ($batch as $request) {
        $match = $byUsername[strtolower($request['username'])] ?? null;

        if ($match === null) {
            echo "  -> No Fansly data returned for {$request['username']}, skipping.\n";
            $fanslyFailed++;
            continue;
        }

        $fanslyData = shapeFanslyAccountData($match);
        $saved = saveFanslyCreatorData($creatorsDir, $request['login'], $fanslyData);

        if ($saved === true) {
            $fanslyUpdated++;
            echo "  -> Saved Fansly data: creators/{$request['login']}.json\n";
        } else {
            $fanslyFailed++;
            echo "  -> Failed to save Fansly data for {$request['login']}.\n";
        }
    }

    sleep(FANSLY_SLEEP_SECONDS);
}

echo "========================================\n";
echo "Done. Fansly updated: {$fanslyUpdated}, failed: {$fanslyFailed}\n";

// Like fansly, creators/{login}.json already having an "rplay" key marks
// this entry resolved and skips it. Unlike fansly's batched endpoint,
// RPlay's getuser endpoint is queried one account at a time.
$rplayRequests = [];
foreach ($accounts as $account) {
    $rplayValue = trim((string) ($account['rplay'] ?? ''));
    $login = accountSlug($account);
    if ($rplayValue === '' || $login === '') {
        continue;
    }
    $isForced = isForcedFor($login, $force, $forceCreator);
    if (!$isForced && creatorHasPlatformData($creatorsDir, $login, 'rplay')) {
        continue;
    }
    $rplayRequests[] = ['login' => $login, 'value' => $rplayValue, 'force' => $isForced];
}

$rplayUpdated = 0;
$rplayFailed = 0;
$rplayTotal = count($rplayRequests);

echo "========================================\n";
echo "Fetching RPlay data for {$rplayTotal} accounts...\n";

foreach ($rplayRequests as $request) {
    echo "Fetching RPlay: {$request['login']} ({$request['value']})\n";

    $rplayAccount = fetchRplayAccount($request['value']);

    if ($rplayAccount === null) {
        echo "  -> RPlay request failed, skipping.\n";
        $rplayFailed++;
        sleep(RPLAY_SLEEP_SECONDS);
        continue;
    }

    $rplayData = shapeRplayAccountData($rplayAccount);
    $saved = saveRplayCreatorData($creatorsDir, $request['login'], $rplayData, $request['force']);

    if ($saved === true) {
        $rplayUpdated++;
        echo "  -> Saved RPlay data: creators/{$request['login']}.json\n";
    } else {
        $rplayFailed++;
        echo "  -> Failed to save RPlay data for {$request['login']}.\n";
    }

    sleep(RPLAY_SLEEP_SECONDS);
}

echo "========================================\n";
echo "Done. RPlay updated: {$rplayUpdated}, failed: {$rplayFailed}\n";

// Written last so ids fetched in this same run are already resolved onto
// creators/{login}.json (consumed by workers/fansly-stream-tracker).
file_put_contents(
    $rootDir . '/fansly.json',
    json_encode(buildFanslyUsernameList($accounts, $creatorsDir), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);

// Same idea as fansly.json above, written last so ids fetched in this same
// run are already resolved.
file_put_contents(
    $rootDir . '/rplay.json',
    json_encode(buildRplayUsernameList($accounts, $creatorsDir), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);

$folderFieldsUpdated = 0;
foreach ($accounts as &$account) {
    if (!is_array($account)) {
        continue;
    }

    $changed = false;

    if (trim((string) ($account['avatar'] ?? '')) === '') {
        $avatarFolder = resolveAvatarFolder($account, $avatarsXDir, $avatarsBDir, $avatarsLargeDir);
        if ($avatarFolder !== null) {
            $account['avatar'] = $avatarFolder;
            $changed = true;
        }
    }

    if (trim((string) ($account['banner'] ?? '')) === '') {
        $bannerFolder = resolveBannerFolder($account, $bannersXDir, $bannersBDir, $bannersDir);
        if ($bannerFolder !== null) {
            $account['banner'] = $bannerFolder;
            $changed = true;
        }
    }

    if ($changed) {
        $folderFieldsUpdated++;
    }
}
unset($account);

if ($folderFieldsUpdated > 0) {
    writeAccountsJson($accountsPath, $accounts);
}

echo "Avatar/banner folder fields updated on {$folderFieldsUpdated} account(s).\n";

echo "========================================\n";
echo "Enforcing simple.json copyright protection...\n";
$simpleAccountsChanged = enforceSimpleMediaProtection(
    $accounts,
    $simpleChannels,
    $avatarsLargeDir,
    $bannersDir,
    $avatarsXDir,
    $bannersXDir,
    $avatarsBDir,
    $bannersBDir
);
if ($simpleAccountsChanged > 0) {
    writeAccountsJson($accountsPath, $accounts);
}
echo "Done. avatar/banner set to \"simple\" on {$simpleAccountsChanged} account(s).\n";
