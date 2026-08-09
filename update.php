<?php

declare(strict_types=1);

/**
 * Bulk channel-data runner for accounts.json.
 *
 * accounts.json entries look like { "fansly", "channel", "type"?,
 * "xHandles", "bskyHandle"? }. An absent/empty "type" defaults to "twitch".
 * Only entries whose "type" is in SUPPORTED_TYPES (currently "twitch" via
 * the GetUserBasic GraphQL query, and "youtube" by scraping the channel's
 * page, https://www.youtube.com/@{channel}, for its embedded ytInitialData)
 * are processed; other types (e.g. "kick") are left untouched until their
 * fetcher is implemented (see fetchPlatformData()).
 *
 * For every eligible entry with a non-empty "channel" whose
 * creators/{login}.json doesn't already have that platform's own key set
 * (e.g. "twitch"/"youtube" — this, not accounts.json, is the source of
 * truth for whether an entry is resolved), fetches the channel via its
 * platform's fetcher and, on success:
 * - Updates "channel" to the platform's display name (correct casing) —
 *   but ONLY for Twitch. YouTube's "channel" is the @handle used to build
 *   the profile URL and image filenames, and YouTube's displayName is the
 *   channel's title (not the handle), so it's never written back; the
 *   YouTube "channel" value always stays exactly what it was.
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
 * "channel" (keeps platform-provided display casing) and URL-ish fields
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
 *   php update.php            # incremental (default)
 *   php update.php --force    # re-fetch every eligible twitch/youtube/fansly
 *                             # account and re-save its images, even if
 *                             # already resolved on disk
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
 */

// Platform types this script knows how to fetch; add 'kick' here once its
// fetchKickPlatformData() function exists (see below).
const SUPPORTED_TYPES = ['twitch', 'youtube'];

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

const FANSLY_API_URL = 'https://apiv3.fansly.com/api/v1/account';
// Fansly's account endpoint accepts a comma-separated batch of usernames.
const FANSLY_BATCH_SIZE = 1;
const FANSLY_SLEEP_SECONDS = 5;

$force = in_array('--force', $argv, true);

$accountsPath = __DIR__ . '/accounts.json';
$dataJsonPath = __DIR__ . '/data.json';
$imagesDir = __DIR__ . '/images';
$creatorsDir = __DIR__ . '/creators';
$avatarsLargeDir = __DIR__ . '/avatarsLarge';
$bannersDir = __DIR__ . '/banners';
$avatarsXDir = __DIR__ . '/avatarsX';
$bannersXDir = __DIR__ . '/bannersX';
$avatarsBDir = __DIR__ . '/avatarsB';
$bannersBDir = __DIR__ . '/bannersB';
$chromeManifestPath = __DIR__ . '/extension/chrome/manifest.json';
$firefoxManifestPath = __DIR__ . '/extension/firefox/manifest.json';

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

    $cmd = trim(sprintf('convert %s %s %s', escapeshellarg($sourcePath), $resizeArgs, escapeshellarg($destPath)));
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
 * Plain GET request with a browser User-Agent, returning the response
 * body, or null on any curl/network/non-200 failure.
 */
function curlGet(string $url, array $headers = []): ?string
{
    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => array_merge([
            'User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36',
            'Accept-Language: en-US,en;q=0.9',
        ], $headers),
    ]);

    $body = curl_exec($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ($body !== false && $statusCode === 200) ? $body : null;
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
function fetchTwitterPlatformData(string $handle): ?array
{
    $url = 'https://x.com/' . rawurlencode($handle);
    $html = curlGet($url);
    if ($html === null) {
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
    $url = FANSLY_API_URL . '?usernames=' . $usernamesParam . '&ngsw-bypass=true';
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
 * Dispatches to the fetcher for $type. Every fetcher returns null on
 * failure, or an array shaped like:
 *   creatorName          string  canonical handle; names creators/{name}.json
 *   displayName          ?string updates accounts.json's "channel"
 *   description          string  stored as creators/{name}.json's "bio"
 *   profileImageUrl      ?string avatar; converted (cropped) to a 300x300
 *                                avatarsLarge/{channel}.webp
 *   bannerImageUrl       ?string converted to banners/{channel}.webp
 *   platformData         array   every field the fetch returned, stored
 *                                under creators/{name}.json's "{$type}" key
 *
 * To add a platform: write a fetchXxxPlatformData() returning this shape,
 * add it below, and add its type string to SUPPORTED_TYPES.
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

function writeAccountsJson(string $path, array $accounts): void
{
    // Preserve every key present on each account (fansly, rplay, onlyfans,
    // joystick, channel, type, xHandles, other, ...) in their original
    // order, rather than hard-coding a fixed set of fields — otherwise any
    // extra platform key gets silently dropped on every rewrite.
    $lines = array_map(static function (array $a): string {
        $fieldLines = [];
        foreach ($a as $key => $value) {
            if ($key === 'xHandles') {
                $xHandles = implode(', ', array_map(
                    static fn (string $h): string => json_encode(strtolower($h), JSON_UNESCAPED_SLASHES),
                    is_array($value) ? $value : []
                ));
                $fieldLines[] = "    \"xHandles\": [{$xHandles}]";
            } elseif ($key === 'bskyHandle') {
                // bskyHandle is a 2-element [name, link] pair, not a list of
                // handles like xHandles — still written as an inline array.
                // Only the handle name (index 0) is lowercased; the link
                // (index 1) is a URL and left untouched.
                $bskyHandle = is_array($value) ? array_values($value) : [];
                $bskyHandle = array_map(
                    static fn (string $h, int $i): string => json_encode($i === 0 ? strtolower($h) : $h, JSON_UNESCAPED_SLASHES),
                    $bskyHandle,
                    array_keys($bskyHandle)
                );
                $fieldLines[] = "    \"bskyHandle\": [" . implode(', ', $bskyHandle) . "]";
            } elseif ($key === 'other') {
                // "other" is an array of single/multi-key objects, e.g.
                // [{ "Patreon": "https://patreon.com/..." }], used for extra
                // links displayed after the core spicy platform pills. The
                // label (key) is lowercased; the URL (value) is left as-is.
                $entries = array_map(
                    static function (array $entry): string {
                        $entryLines = [];
                        foreach ($entry as $entryKey => $entryValue) {
                            $entryLines[] = sprintf(
                                '        %s: %s',
                                json_encode(strtolower((string) $entryKey), JSON_UNESCAPED_SLASHES),
                                json_encode((string) $entryValue, JSON_UNESCAPED_SLASHES)
                            );
                        }
                        return "      {\n" . implode(",\n", $entryLines) . "\n      }";
                    },
                    is_array($value) ? $value : []
                );
                $fieldLines[] = "    \"other\": [\n" . implode(",\n", $entries) . "\n    ]";
            } elseif ($key === 'channel' || $key === 'socials') {
                // "channel" keeps its API-provided display casing; "socials"
                // is a URL and left untouched.
                $fieldLines[] = sprintf(
                    '    %s: %s',
                    json_encode((string) $key, JSON_UNESCAPED_SLASHES),
                    json_encode((string) $value, JSON_UNESCAPED_SLASHES)
                );
            } elseif (is_bool($value)) {
                // e.g. "data": true — booleans have no casing to normalize.
                $fieldLines[] = sprintf(
                    '    %s: %s',
                    json_encode((string) $key, JSON_UNESCAPED_SLASHES),
                    $value ? 'true' : 'false'
                );
            } else {
                $fieldLines[] = sprintf(
                    '    %s: %s',
                    json_encode((string) $key, JSON_UNESCAPED_SLASHES),
                    json_encode(strtolower((string) $value), JSON_UNESCAPED_SLASHES)
                );
            }
        }

        return "  {\n" . implode(",\n", $fieldLines) . "\n  }";
    }, $accounts);

    file_put_contents($path, "[\n" . implode(",\n", $lines) . "\n]\n");
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

// Mirrors script001.js's SPICE_PLATFORMS keys.
const SPICE_PLATFORM_KEYS = ['fansly', 'onlyfans', 'rplay', 'joystick', 'patreon'];

/**
 * Reproduces script001.js's computeStats()/getOtherLinks() over every
 * accounts.json entry with a non-empty "channel" (the same set
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
        if (trim((string) ($account['channel'] ?? '')) === '') {
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

    $channel = trim((string) ($account['channel'] ?? ''));
    if ($channel !== '' && is_file($avatarsLargeDir . '/' . strtolower($channel) . '.webp')) {
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

    $channel = trim((string) ($account['channel'] ?? ''));
    if ($channel !== '' && is_file($bannersDir . '/' . strtolower($channel) . '.webp')) {
        return 'banners';
    }

    return null;
}

$total = count($accounts);
$updated = 0;
$skippedComplete = 0;
$skippedNoChannel = 0;
$skippedOtherType = 0;
$failed = 0;

if ($force) {
    echo "--force: re-fetching every eligible account, ignoring already-resolved data.\n";
}

foreach ($accounts as $index => &$account) {
    // Absent/empty "type" defaults to Twitch.
    $type = trim((string) ($account['type'] ?? '')) ?: 'twitch';
    if (!in_array($type, SUPPORTED_TYPES, true)) {
        $skippedOtherType++;
        continue;
    }

    $channel = trim((string) ($account['channel'] ?? ''));

    if ($channel === '') {
        $skippedNoChannel++;
        continue;
    }

    $login = strtolower($channel);

    // creators/{login}.json having this platform's key already set is
    // what marks this entry resolved, not accounts.json — unless --force
    // was passed, in which case every eligible entry is re-fetched.
    if (!$force && creatorHasPlatformData($creatorsDir, $login, $type)) {
        $skippedComplete++;
        continue;
    }

    $position = $index + 1;

    echo "[{$position}/{$total}] Fetching ({$type}): {$login}\n";

    $result = fetchPlatformData($type, $login, $clientId);

    if ($result === null) {
        echo "  -> No user data returned, skipping.\n";
        $failed++;
        sleep(1);
        continue;
    }

    saveAvatarWebp($imagesDir, $avatarsLargeDir, $login, $result['profileImageUrl'] ?? null, $force);
    saveBannerWebp($imagesDir, $bannersDir, $login, $result['bannerImageUrl'] ?? null, $force);

    $changed = false;

    // YouTube's displayName is the channel's title, not its @handle, so
    // it must never overwrite "channel" there (that would break both the
    // youtube.com/@{channel} URL and the images/{channel}.<ext> filename
    // used on every future run).
    $displayName = $result['displayName'] ?? null;
    if ($type === 'twitch' && is_string($displayName) && $displayName !== '') {
        $account['channel'] = $displayName;
        $changed = true;
    }

    // A successful fetch is enough to write a creator entry, even when the
    // description is empty — writing the platform key (even to {}) still
    // marks it resolved so this account is never re-fetched on future
    // runs just to learn the description is blank.
    $creatorName = is_string($result['creatorName'] ?? null) && $result['creatorName'] !== '' ? $result['creatorName'] : $login;
    $bio = is_string($result['description'] ?? null) ? $result['description'] : '';
    $platformData = is_array($result['platformData'] ?? null) ? $result['platformData'] : [];
    $saved = saveCreatorData($creatorsDir, $creatorName, $bio, $type, $platformData, $force);

    if ($saved === true) {
        $updated++;
        $changed = true;
        echo $bio !== ''
            ? "  -> Saved creator data: creators/{$creatorName}.json\n"
            : "  -> No bio returned; saved empty creators/{$creatorName}.json to skip re-fetching.\n";
    } else {
        echo "  -> Failed to save creator data.\n";
    }

    if ($changed) {
        writeAccountsJson($accountsPath, $accounts);
    }

    sleep(1);
}
unset($account);

writeAccountsJson($accountsPath, $accounts);

// Empty placeholder entries (no "channel" set — see $skippedNoChannel
// above) are template rows, not real creators, so they're excluded from
// the count reported in data.json.
$creatorsCount = count(array_filter(
    $accounts,
    static fn (array $a): bool => trim((string) ($a['channel'] ?? '')) !== ''
));
writeDataJson(
    $dataJsonPath,
    $creatorsCount,
    computeAccountsStats($accounts),
    readExtensionVersion($chromeManifestPath),
    readExtensionVersion($firefoxManifestPath)
);

echo "========================================\n";
echo "Done. Updated: {$updated}, skipped (complete): {$skippedComplete}, skipped (no channel): {$skippedNoChannel}, skipped (other type): {$skippedOtherType}, failed: {$failed}\n";

// creators/{login}.json's "twitter" key is keyed by each lowercased handle
// (a creator can have several xHandles), so resolved-state is checked per
// handle rather than once per creator like twitch/youtube/fansly.
$twitterRequests = [];
foreach ($accounts as $account) {
    $channel = trim((string) ($account['channel'] ?? ''));
    if ($channel === '') {
        continue;
    }
    $login = strtolower($channel);
    foreach ((array) ($account['xHandles'] ?? []) as $xHandle) {
        $xHandle = trim((string) $xHandle);
        if ($xHandle === '') {
            continue;
        }
        if (!$force && creatorHasPlatformSubkeyData($creatorsDir, $login, ['twitter', strtolower($xHandle)])) {
            continue;
        }
        $twitterRequests[] = ['login' => $login, 'handle' => $xHandle];
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
    saveKeyedAvatarWebp($imagesDir, $avatarsXDir, $handleLower, $result['profileImageUrl'] ?? null, $force);
    saveKeyedBannerWebp($imagesDir, $bannersXDir, $handleLower, $result['bannerImageUrl'] ?? null, $force);

    $platformData = is_array($result['platformData'] ?? null) ? $result['platformData'] : [];
    $saved = saveCreatorPlatformSubkeyData($creatorsDir, $request['login'], ['twitter', $handleLower], $platformData, $force);

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
    $channel = trim((string) ($account['channel'] ?? ''));
    $bskyHandle = $account['bskyHandle'] ?? null;
    $bskyName = is_array($bskyHandle) ? trim((string) ($bskyHandle[0] ?? '')) : '';
    $bskyPath = is_array($bskyHandle) ? trim((string) ($bskyHandle[1] ?? '')) : '';
    if ($channel === '' || $bskyName === '') {
        continue;
    }
    $login = strtolower($channel);
    if (!$force && creatorHasPlatformSubkeyData($creatorsDir, $login, ['bluesky'])) {
        continue;
    }
    $blueskyRequests[] = ['login' => $login, 'name' => $bskyName, 'path' => $bskyPath];
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
    saveKeyedAvatarWebp($imagesDir, $avatarsBDir, $nameLower, $result['profileImageUrl'] ?? null, $force);
    saveKeyedBannerWebp($imagesDir, $bannersBDir, $nameLower, $result['bannerImageUrl'] ?? null, $force);

    $platformData = is_array($result['platformData'] ?? null) ? $result['platformData'] : [];
    $saved = saveCreatorPlatformSubkeyData($creatorsDir, $request['login'], ['bluesky'], $platformData, $force);

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
    $channel = trim((string) ($account['channel'] ?? ''));
    if ($fanslyUsername === '' || $channel === '') {
        continue;
    }
    $login = strtolower($channel);
    if (!$force && creatorHasPlatformData($creatorsDir, $login, 'fansly')) {
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
