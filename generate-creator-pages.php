<?php

declare(strict_types=1);

/**
 * Full site page generator: emits index.html AND a static, fully-baked SEO
 * page + OG image for every creator in accounts.json (c/{channelLower}/
 * index.html + og-image.webp). Both page types share the same head/header/
 * footer components below (renderHeadMeta/renderSiteHeader/renderSiteFooter)
 * so they can't drift apart the way a hand-edited index.html and a
 * generated creator template otherwise would.
 *
 * The current stylesheet/script filenames are held in the STYLESHEET/SCRIPT
 * constants below and baked directly into every generated page's <link>/
 * <script> tags — there's no runtime manifest.json indirection since this
 * script already regenerates every page whenever those filenames change
 * (bump the constants, then `--force`).
 *
 * Incremental by default for creator pages: this script keeps its own
 * snapshot of every creator's accounts.json entry in
 * generated-accounts.json. A creator is only (re)generated if it's missing
 * from the snapshot or its current accounts.json entry differs from the
 * snapshot — otherwise it's skipped entirely (no HTML/image writes). The
 * snapshot entry is updated immediately after each creator's output is
 * successfully written, so an interrupted run never leaves a creator
 * falsely marked up to date. index.html and sitemap.xml are always
 * rewritten in full every run (cheap, and index.html's own content doesn't
 * depend on any single creator's data).
 *
 * Usage:
 *   php generate-creator-pages.php               # incremental (default)
 *   php generate-creator-pages.php --force        # regenerate every
 *                                                  # creator's HTML + OG
 *                                                  # image regardless of
 *                                                  # the snapshot
 *   php generate-creator-pages.php --force-html   # same, but reuses each
 *                                                  # creator's existing
 *                                                  # og-image.webp instead of
 *                                                  # rebuilding it (only
 *                                                  # built when missing) —
 *                                                  # for template-only
 *                                                  # changes, much faster
 *                                                  # than a full --force
 * 
 *   php generate-creator-pages.php --limit=N       # only process the first N creators (for testing)
 *   php generate-creator-pages.php --clean-c        # delete everything inside c/ (but keep
 *                                                  # the c/ folder itself) and reset the
 *                                                  # generated-accounts.json snapshot so the
 *                                                  # next run regenerates every creator from
 *                                                  # scratch; exits immediately, does not
 *                                                  # regenerate anything itself
 *                                                 
 */

const BASE_URL = 'https://spicyvtubers.com/';
const ACCOUNTS_PATH = __DIR__ . '/accounts.json';
const CREATORS_DIR = __DIR__ . '/creators';
const IMAGES_DIR = __DIR__ . '/images';
const AVATARS_DIR = __DIR__ . '/avatarsLarge';
const BANNERS_DIR = __DIR__ . '/banners';
const AVATARS_X_DIR = __DIR__ . '/avatarsX';
const AVATARS_B_DIR = __DIR__ . '/avatarsB';
const BANNERS_X_DIR = __DIR__ . '/bannersX';
const BANNERS_B_DIR = __DIR__ . '/bannersB';
const OUTPUT_DIR = __DIR__ . '/c';
const INDEX_PATH = __DIR__ . '/index.html';
const INSIGHTS_DIR = __DIR__ . '/insights';
const INSIGHTS_PATH = INSIGHTS_DIR . '/index.html';
const SNAPSHOT_PATH = __DIR__ . '/generated-accounts.json';
const SITEMAP_PATH = __DIR__ . '/sitemap.xml';
const OG_LOGO_PATH = __DIR__ . '/spicy_vtubers_logo.png';
const OG_FONT = 'DejaVu-Sans-Bold';
const OG_BG = '#0f0a12';
// Keep OG image build code available, but disable generation by default.
const ENABLE_CREATOR_OG_IMAGE_GENERATION = false;
const FANSLY_DIR = __DIR__ . '/fansly';
// Toggle the Fansly leaderboard-rank graph section on creator pages on/off.
const ENABLE_FANSLY_LEADERBOARD_GRAPH = true;
// Toggle the Fansly Stream History section on creator pages on/off.
// Disabled by default — flip to true to bake it back in.
const ENABLE_FANSLY_STREAM_HISTORY = true;
// Single source of truth for the current stylesheet/script filenames —
// bump these and re-run --force to bake the new filenames into every page.
const STYLESHEET = 'style211.css';
const SCRIPT = 'script211.js';
// Pages reference the "min"-prefixed copies; generateMinifiedAssets() (run
// once at the start of main()) builds these from STYLESHEET/SCRIPT above.
const MIN_STYLESHEET = 'min' . STYLESHEET;
const MIN_SCRIPT = 'min' . SCRIPT;

// ---------------------------------- Data helpers ----------------------------------

function spicePlatforms(): array
{
    return [
        ['key' => 'fansly', 'label' => 'Fansly', 'baseUrl' => 'https://fansly.com/', 'refKey' => 'fanslyRef', 'icon' => '<img src="fansly.svg" alt="" aria-hidden="true">'],
        ['key' => 'onlyfans', 'label' => 'OnlyFans', 'baseUrl' => 'https://onlyfans.com/', 'icon' => '<img src="onlyfans.svg" alt="" aria-hidden="true">'],
        ['key' => 'rplay', 'label' => 'Rplay', 'baseUrl' => 'https://rplay.live/c/', 'rootBaseUrl' => 'https://rplay.live/', 'icon' => '<img src="rplay.svg" alt="" aria-hidden="true">'],
        ['key' => 'joystick', 'label' => 'joystick.tv', 'baseUrl' => 'https://joystick.tv/u/', 'icon' => '<img src="joystick.svg" alt="" aria-hidden="true">'],
        ['key' => 'patreon', 'label' => 'Patreon', 'baseUrl' => 'https://www.patreon.com/', 'icon' => '<svg viewBox="0 0 436 476" aria-hidden="true"><path fill="currentColor" d="M436 143c-.084-60.778-47.57-110.591-103.285-128.565C263.528-7.884 172.279-4.649 106.214 26.424 26.142 64.089.988 146.596.051 228.883c-.77 67.653 6.004 245.841 106.83 247.11 74.917.948 86.072-95.279 120.737-141.623 24.662-32.972 56.417-42.285 95.507-51.929C390.309 265.865 436.097 213.011 436 143Z"/></svg>'],
    ];
}

// Mirrors script100.js's renderPlatformFilter() output exactly. The platform
// list never changes at runtime, so it's baked into index.html at generation
// time instead of being (re)built by JS on every page load.
function buildPlatformFilterHtml(): string
{
    $html = '<button type="button" class="platform-filter-btn is-active" data-platform="all" aria-pressed="true" aria-label="All"><span>All</span></button>';
    foreach (spicePlatforms() as $platform) {
        $html .= '<button type="button" class="platform-filter-btn" data-platform="' . htmlspecialchars($platform['key']) .
            '" aria-pressed="false" aria-label="' . htmlspecialchars($platform['label']) . '">' .
            $platform['icon'] . '<span>' . htmlspecialchars($platform['label']) . '</span></button>';
    }
    return $html;
}

function channelPlatforms(): array
{
    return [
        'twitch' => ['baseUrl' => 'https://twitch.com/', 'label' => 'Twitch'],
        'youtube' => ['baseUrl' => 'https://youtube.com/@', 'label' => 'YouTube'],
    ];
}

function readJson(string $path)
{
    $json = file_get_contents($path);
    if ($json === false) {
        return null;
    }
    return json_decode($json, true);
}

function writeJson(string $path, $data): bool
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }
    return file_put_contents($path, $json) !== false;
}

// Init-stage step (called once at the top of main()): rebuilds MIN_STYLESHEET/
// MIN_SCRIPT from STYLESHEET/SCRIPT by stripping comments, so every generated
// page's asset tags (see stylesheetTag()/scriptBootstrap()) resolve to real files.
function generateMinifiedAssets(): void
{
    $cssPath = __DIR__ . '/' . STYLESHEET;
    $jsPath = __DIR__ . '/' . SCRIPT;

    $css = file_get_contents($cssPath);
    if ($css === false) {
        fwrite(STDERR, "Failed to read " . STYLESHEET . " for minification\n");
        exit(1);
    }
    if (file_put_contents(__DIR__ . '/' . MIN_STYLESHEET, stripCssComments($css)) === false) {
        fwrite(STDERR, "Failed to write " . MIN_STYLESHEET . "\n");
        exit(1);
    }

    $js = file_get_contents($jsPath);
    if ($js === false) {
        fwrite(STDERR, "Failed to read " . SCRIPT . " for minification\n");
        exit(1);
    }
    if (file_put_contents(__DIR__ . '/' . MIN_SCRIPT, stripJsComments($js)) === false) {
        fwrite(STDERR, "Failed to write " . MIN_SCRIPT . "\n");
        exit(1);
    }
}

// Strips /* ... */ comments from CSS, respecting single/double-quoted strings
// (e.g. content: "/* not a comment */") so their contents are never touched.
function stripCssComments(string $css): string
{
    $out = '';
    $len = strlen($css);
    $inString = null; // null, or the quote char currently open
    for ($i = 0; $i < $len; $i++) {
        $ch = $css[$i];

        if ($inString !== null) {
            $out .= $ch;
            if ($ch === '\\' && $i + 1 < $len) {
                $out .= $css[++$i];
            } elseif ($ch === $inString) {
                $inString = null;
            }
            continue;
        }

        if (($ch === '"' || $ch === "'")) {
            $inString = $ch;
            $out .= $ch;
            continue;
        }

        if ($ch === '/' && $i + 1 < $len && $css[$i + 1] === '*') {
            $end = strpos($css, '*/', $i + 2);
            $i = ($end === false) ? $len : $end + 1;
            continue;
        }

        $out .= $ch;
    }
    return $out;
}

// Strips // line comments and /* */ block comments from JS, respecting
// single/double-quoted strings and template literals (including nested
// ${...} expressions inside a template) so their contents are never touched.
// NOTE: does not attempt real regex-literal detection (no /pattern/ regex
// literals exist in SCRIPT as of writing) — only relevant if one is added.
function stripJsComments(string $js): string
{
    $out = '';
    $len = strlen($js);
    // Stack of open contexts: 'code' | 'sq' | 'dq' | 'tpl' | int (brace depth
    // of a ${...} expression inside a template literal, tracked via a
    // parallel stack entry so we know when to drop back into 'tpl').
    $stack = ['code'];
    $i = 0;
    while ($i < $len) {
        $ch = $js[$i];
        $mode = end($stack);

        if ($mode === 'sq' || $mode === 'dq') {
            $out .= $ch;
            if ($ch === '\\' && $i + 1 < $len) {
                $out .= $js[$i + 1];
                $i += 2;
                continue;
            }
            if (($mode === 'sq' && $ch === "'") || ($mode === 'dq' && $ch === '"')) {
                array_pop($stack);
            }
            $i++;
            continue;
        }

        if ($mode === 'tpl') {
            if ($ch === '\\' && $i + 1 < $len) {
                $out .= $ch . $js[$i + 1];
                $i += 2;
                continue;
            }
            if ($ch === '`') {
                array_pop($stack);
                $out .= $ch;
                $i++;
                continue;
            }
            if ($ch === '$' && $i + 1 < $len && $js[$i + 1] === '{') {
                $stack[] = 0; // brace depth for this ${...} expression
                $out .= '${';
                $i += 2;
                continue;
            }
            $out .= $ch;
            $i++;
            continue;
        }

        if (is_int($mode)) {
            // Inside a ${...} template expression — behaves like code, but
            // track brace depth so a matching `}` drops back into 'tpl'.
            if ($ch === '{') {
                $stack[count($stack) - 1] = $mode + 1;
                $out .= $ch;
                $i++;
                continue;
            }
            if ($ch === '}') {
                if ($mode === 0) {
                    array_pop($stack);
                    $out .= $ch;
                    $i++;
                    continue;
                }
                $stack[count($stack) - 1] = $mode - 1;
                $out .= $ch;
                $i++;
                continue;
            }
            // fall through to shared "code-like" handling below
        }

        // 'code' mode (or inside a ${...} expression, handled above for braces)
        if ($ch === "'" ) {
            $stack[] = 'sq';
            $out .= $ch;
            $i++;
            continue;
        }
        if ($ch === '"') {
            $stack[] = 'dq';
            $out .= $ch;
            $i++;
            continue;
        }
        if ($ch === '`') {
            $stack[] = 'tpl';
            $out .= $ch;
            $i++;
            continue;
        }
        if ($ch === '/' && $i + 1 < $len && $js[$i + 1] === '/') {
            $end = strpos($js, "\n", $i + 2);
            $i = ($end === false) ? $len : $end; // keep the newline itself
            continue;
        }
        if ($ch === '/' && $i + 1 < $len && $js[$i + 1] === '*') {
            $end = strpos($js, '*/', $i + 2);
            $i = ($end === false) ? $len : $end + 2;
            continue;
        }

        $out .= $ch;
        $i++;
    }
    return $out;
}

function loadBio(string $slug): ?string
{
    $path = CREATORS_DIR . '/' . $slug . '.json';
    if (!is_file($path)) {
        return null;
    }
    $data = readJson($path);
    return is_array($data) && isset($data['bio']) && is_string($data['bio']) && trim($data['bio']) !== ''
        ? $data['bio']
        : null;
}

// ---------------------------------- HTML builders ----------------------------------

function profileLink(string $baseUrl, ?string ...$segments): string
{
    $segments = array_values(array_filter($segments, fn($s) => $s !== null && $s !== ''));
    $encoded = array_map('rawurlencode', $segments);
    return $baseUrl . implode('/', $encoded);
}

function pillLink(string $class, string $href, string $title, string $iconKey, string $label): string
{
    $iconAttr = $iconKey !== '' ? ' data-icon="' . htmlspecialchars($iconKey) . '"' : '';
    return '<a class="' . htmlspecialchars($class) . '"' . $iconAttr .
        ' href="' . htmlspecialchars($href) . '" target="_blank" rel="noopener noreferrer" title="' . htmlspecialchars($title) . '">' .
        htmlspecialchars($label) . '</a>';
}

function getOtherLinks(array $creator): array
{
    $links = [];
    foreach (($creator['other'] ?? []) as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        foreach ($entry as $label => $url) {
            if ($url) {
                $links[] = ['label' => (string) $label, 'url' => (string) $url];
            }
        }
    }
    return $links;
}

// Mirrors script100.js's socialsIcon constant exactly, so server-rendered
// creator pages and any future client-rendered rows stay visually identical.
function socialsIconSvg(): string
{
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3.9 12c0-1.71 1.39-3.1 3.1-3.1h4V7H7c-2.76 0-5 2.24-5 5s2.24 5 5 5h4v-1.9H7c-1.71 0-3.1-1.39-3.1-3.1zM8 13h8v-2H8v2zm9-6h-4v1.9h4c1.71 0 3.1 1.39 3.1 3.1s-1.39 3.1-3.1 3.1h-4V17h4c2.76 0 5-2.24 5-5s-2.24-5-5-5z"/></svg>';
}

// Mirrors script100.js's externalLinkIcon constant.
function externalLinkIconSvg(): string
{
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3h7v7h-2V6.41l-9.29 9.3-1.42-1.42 9.3-9.29H14V3zM5 5h6v2H7v10h10v-4h2v6H5V5z"/></svg>';
}

function twitchIconSvg(): string
{
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#9146FF" d="M3 2h18v12l-4 4h-4l-3 3H7v-3H3V2zm16 10V4H5v12h3v3l3-3h5l3-3z"/><path fill="#FFFFFF" d="M10 7h2v5h-2V7zm4 0h2v5h-2V7z"/></svg>';
}

function youtubeIconSvg(): string
{
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#FF0000" d="M23 12s0-3.16-.4-4.69a3.15 3.15 0 0 0-2.21-2.22C18.86 4.7 12 4.7 12 4.7s-6.86 0-8.39.39A3.15 3.15 0 0 0 1.4 7.31C1 8.84 1 12 1 12s0 3.16.4 4.69a3.15 3.15 0 0 0 2.21 2.22c1.53.39 8.39.39 8.39.39s6.86 0 8.39-.39a3.15 3.15 0 0 0 2.21-2.22C23 15.16 23 12 23 12z"/><path fill="#fff" d="m10 15.5 6-3.5-6-3.5v7z"/></svg>';
}

function channelPlatformIconSvg(?array $channelInfo): string
{
    $label = $channelInfo['label'] ?? null;
    return $label === 'YouTube' ? youtubeIconSvg() : twitchIconSvg();
}

function getChannelInfo(array $creator): ?array
{
    if (empty($creator['channel'])) {
        return null;
    }
    $platforms = channelPlatforms();
    $platform = $platforms[$creator['type'] ?? 'twitch'] ?? $platforms['twitch'];
    return ['href' => profileLink($platform['baseUrl'], $creator['channel']), 'label' => $platform['label']];
}

function buildSpicePills(array $creator): string
{
    $html = '';
    foreach (spicePlatforms() as $platform) {
        $value = $creator[$platform['key']] ?? null;
        if (!$value) {
            continue;
        }
        $isPath = isset($platform['rootBaseUrl']) && str_contains($value, '/');
        if ($isPath) {
            $href = profileLink($platform['rootBaseUrl'], ...explode('/', $value));
            $text = $creator['channelLower'];
        } else {
            $ref = $platform['refKey'] ?? null;
            $refVal = $ref ? ($creator[$ref] ?? 'spicy') : null;
            $href = $refVal !== null ? profileLink($platform['baseUrl'], $value, $refVal) : profileLink($platform['baseUrl'], $value);
            $text = mb_strtolower($value);
        }
        $html .= pillLink('spice-pill', $href, $platform['label'] . ': ' . $text, $platform['key'], $text) . ' ';
    }
    foreach (getOtherLinks($creator) as $link) {
        $html .= pillLink('spice-pill other-pill', $link['url'], $link['url'], '', $link['label']) . ' ';
    }
    return $html;
}

function buildSocialPills(array $creator): string
{
    $html = '';
    foreach (($creator['xHandles'] ?? []) as $handle) {
        if (!$handle) {
            continue;
        }
        $html .= pillLink('x-pill', profileLink('https://x.com/', $handle), 'Twitter: ' . $handle, 'x', mb_strtolower($handle)) . ' ';
    }
    $bsky = $creator['bskyHandle'] ?? null;
    if (is_array($bsky) && !empty($bsky[1])) {
        $label = mb_strtolower($bsky[0] ?? $bsky[1]);
        $html .= pillLink('x-pill', profileLink('https://bsky.app/profile/', $bsky[1]), 'Bluesky: ' . $label, 'bsky', $label) . ' ';
    }
    return $html;
}

function buildSameAs(array $creator, ?array $channelInfo): array
{
    $sameAs = [];
    if ($channelInfo) {
        $sameAs[] = $channelInfo['href'];
    }
    foreach (($creator['xHandles'] ?? []) as $handle) {
        if ($handle) {
            $sameAs[] = profileLink('https://x.com/', $handle);
        }
    }
    $bsky = $creator['bskyHandle'] ?? null;
    if (is_array($bsky) && !empty($bsky[1])) {
        $sameAs[] = profileLink('https://bsky.app/profile/', $bsky[1]);
    }
    if (!empty($creator['socials'])) {
        $sameAs[] = $creator['socials'];
    }
    return array_values(array_unique($sameAs));
}

// Possessive form for generated copy: names ending in s/S get a bare
// apostrophe (Chris'), everything else gets 's (bao's).
function possessive(string $name): string
{
    return $name . (mb_strtolower(mb_substr($name, -1)) === 's' ? "'" : "'s");
}

// Joins $items into a natural English list: "A", "A & B", or "A, B & C".
function naturalJoin(array $items): string
{
    if (count($items) <= 1) {
        return $items[0] ?? '';
    }
    $last = array_pop($items);
    return implode(', ', $items) . ' & ' . $last;
}

// Always a fixed template naming every platform the creator actually has —
// deliberately NOT the bio. A short/inconsistent per-creator bio made Google
// ignore this tag and stitch its own snippet from page text instead (pill
// labels running together, footer boilerplate bleeding in); a consistent,
// on-topic description is far more likely to actually get used as the
// search snippet.
function buildDescription(array $creator): string
{
    $platforms = [];

    $channelInfo = getChannelInfo($creator);
    if ($channelInfo) {
        $platforms[] = $channelInfo['label'];
    }
    foreach (spicePlatforms() as $platform) {
        if (!empty($creator[$platform['key']])) {
            $platforms[] = $platform['label'];
        }
    }
    if (array_filter((array) ($creator['xHandles'] ?? []))) {
        $platforms[] = 'X (Twitter)';
    }
    $bsky = $creator['bskyHandle'] ?? null;
    if (is_array($bsky) && !empty($bsky[1])) {
        $platforms[] = 'Bluesky';
    }

    if ($platforms === []) {
        return $creator['channel'] . "'s profile on the Spicy VTubers index.";
    }

    return possessive($creator['channel']) . ' Spicy VTubers profile - ' . naturalJoin($platforms) . '.';
}

function avatarFolderForCreator(array $creator): string
{
    $folder = strtolower(trim((string) ($creator['avatar'] ?? '')));

    return match ($folder) {
        'avatarsx' => 'avatarsX',
        'avatarsb' => 'avatarsB',
        'avatarslarge' => 'avatarsLarge',
        default => 'avatarsLarge',
    };
}

function bannerFolderForCreator(array $creator): string
{
    $folder = strtolower(trim((string) ($creator['banner'] ?? '')));

    return match ($folder) {
        'bannersx' => 'bannersX',
        'bannersb' => 'bannersB',
        'banners' => 'banners',
        default => 'banners',
    };
}

function avatarDirForFolder(string $folder): string
{
    return match ($folder) {
        'avatarsX' => AVATARS_X_DIR,
        'avatarsB' => AVATARS_B_DIR,
        default => AVATARS_DIR,
    };
}

function bannerDirForFolder(string $folder): string
{
    return match ($folder) {
        'bannersX' => BANNERS_X_DIR,
        'bannersB' => BANNERS_B_DIR,
        default => BANNERS_DIR,
    };
}

function mediaBaseNameForFolder(array $creator, string $folder): string
{
    $fallback = (string) ($creator['channelLower'] ?? '');

    if ($folder === 'avatarsX' || $folder === 'bannersX') {
        $xHandles = array_values(array_filter((array) ($creator['xHandles'] ?? []), static fn ($h): bool => trim((string) $h) !== ''));
        $firstXHandle = trim((string) ($xHandles[0] ?? ''));
        return $firstXHandle !== '' ? mb_strtolower($firstXHandle) : $fallback;
    }

    if ($folder === 'avatarsB' || $folder === 'bannersB') {
        $bskyHandle = $creator['bskyHandle'] ?? null;
        $bskyName = is_array($bskyHandle) ? trim((string) ($bskyHandle[0] ?? '')) : '';
        return $bskyName !== '' ? mb_strtolower($bskyName) : $fallback;
    }

    return $fallback;
}

function avatarPathForCreator(array $creator): string
{
    $avatarFolder = avatarFolderForCreator($creator);
    $avatarBaseName = mediaBaseNameForFolder($creator, $avatarFolder);
    return avatarDirForFolder($avatarFolder) . '/' . $avatarBaseName . '.webp';
}

function bannerPathForCreator(array $creator): string
{
    $bannerFolder = bannerFolderForCreator($creator);
    $bannerBaseName = mediaBaseNameForFolder($creator, $bannerFolder);
    return bannerDirForFolder($bannerFolder) . '/' . $bannerBaseName . '.webp';
}

function avatarUrlRelativeForCreator(array $creator): string
{
    $avatarFolder = avatarFolderForCreator($creator);
    $avatarBaseName = mediaBaseNameForFolder($creator, $avatarFolder);
    return '/' . $avatarFolder . '/' . rawurlencode($avatarBaseName) . '.webp';
}

function avatarUrlAbsoluteForCreator(array $creator): string
{
    $avatarFolder = avatarFolderForCreator($creator);
    $avatarBaseName = mediaBaseNameForFolder($creator, $avatarFolder);
    return BASE_URL . $avatarFolder . '/' . rawurlencode($avatarBaseName) . '.webp';
}

function bannerUrlRelativeForCreator(array $creator): string
{
    $bannerFolder = bannerFolderForCreator($creator);
    $bannerBaseName = mediaBaseNameForFolder($creator, $bannerFolder);
    return '/' . $bannerFolder . '/' . rawurlencode($bannerBaseName) . '.webp';
}

// ---------------------------------- Shared page components ----------------------------------
// Both index.html and every c/{slug}/index.html are built from these same
// pieces so their head/header/footer can't drift apart from each other.

function renderHeadMeta(string $title, string $description, string $canonical, string $ogType, string $ogImage, string $twitterImage): string
{
    return <<<HTML
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$title}</title>
<meta name="description" content="{$description}">
<link rel="canonical" href="{$canonical}">

<!-- Open Graph -->
<meta property="og:type" content="{$ogType}">
<meta property="og:site_name" content="Spicy VTubers">
<meta property="og:title" content="{$title}">
<meta property="og:description" content="{$description}">
<meta property="og:url" content="{$canonical}">
<meta property="og:image" content="{$ogImage}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">

<!-- Twitter Card -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:site" content="@Spicy_VTubers">
<meta name="twitter:title" content="{$title}">
<meta name="twitter:description" content="{$description}">
<meta name="twitter:image" content="{$twitterImage}">

<link rel="icon" type="image/x-icon" href="/favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
HTML;
}

// Plain <link>/<script> tags using the MIN_STYLESHEET/MIN_SCRIPT constants
// above — no runtime manifest/fetch indirection needed since this generator
// already rewrites every page whenever those filenames change.
function stylesheetTag(): string
{
    $stylesheet = MIN_STYLESHEET;
    return "<link rel=\"stylesheet\" href=\"/{$stylesheet}\">";
}

function scriptBootstrap(string $initFn): string
{
    $script = MIN_SCRIPT;
    return <<<HTML
<script src="/{$script}"></script>
<script>window.{$initFn}();</script>
HTML;
}

function renderSiteHeader(string $scope): string
{
    $headerActionsExtra = in_array($scope, ['creator', 'insights'], true)
        ? '<a class="header-index-btn" href="/" title="Back to full index"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h7v7H4V4zm9 0h7v7h-7V4zM4 13h7v7H4v-7zm9 0h7v7h-7v-7z"/></svg><span>Index</span></a>'
        : '<button type="button" class="sort-toggle-btn" id="sort-toggle" aria-pressed="false" title="Toggle between A-Z order and the newest creators added"><svg class="sort-toggle-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M6 12h12M10 18h4"/></svg><span>Newest</span></button>';

    $insightsLink = $scope === 'insights'
        ? ''
        : '<a class="header-index-btn" href="/insights/" title="Weekly Fansly stream insights"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20V10h4v10H4zm6 0V4h4v16h-4zm6 0v-7h4v7h-4z"/></svg><span>Insights</span></a>';

    return <<<HTML
<header class="site-header">
  <div class="header-inner">
    <div class="header-titles">
      <a class="brand" href="/">
        <img class="brand-mark" src="/spicyvtubers.webp" alt="Spicy VTubers logo" width="40" height="40">
        <span class="brand-name">Spicy <span class="brand-accent">VTubers</span></span>
      </a>
      <p class="tagline">Index of VTubers and their Spicy Accounts</p>
    </div>

    <div class="header-controls" id="header-controls">
      <div class="get-extension-wrap" id="get-extension-wrap">
        <button type="button" class="get-extension-btn" id="get-extension-btn" aria-haspopup="true" aria-expanded="false">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 11H19V7c0-1.1-.9-2-2-2h-4V3.5C13 2.12 11.88 1 10.5 1S8 2.12 8 3.5V5H4c-1.1 0-1.99.9-1.99 2v3.8H3.5c1.49 0 2.7 1.21 2.7 2.7s-1.21 2.7-2.7 2.7H2V20c0 1.1.9 2 2 2h3.8v-1.5c0-1.49 1.21-2.7 2.7-2.7s2.7 1.21 2.7 2.7V22H17c1.1 0 2-.9 2-2v-4h1.5c1.38 0 2.5-1.12 2.5-2.5S21.88 11 20.5 11z"/></svg>
          <span>Extension</span>
          <svg class="get-extension-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10l5 5 5-5z"/></svg>
        </button>
        <div class="get-extension-menu" id="get-extension-menu" hidden>
          <a class="get-extension-option" id="get-extension-chrome" data-browser="chrome" href="#" aria-disabled="true">
            <span>Chrome</span>
            <span class="get-extension-version"></span>
          </a>        
          <a class="get-extension-option" id="get-extension-firefox" data-browser="firefox" href="#" aria-disabled="true">
            <span>Firefox</span>
            <span class="get-extension-version"></span>
          </a>        
        </div>
      </div>

      <div class="header-actions" id="header-actions">
        {$insightsLink}
        {$headerActionsExtra}
      </div>
    </div>
  </div>
</header>
HTML;
}

function renderSiteFooter(string $scope): string
{
    $backToIndex = in_array($scope, ['creator', 'insights'], true)
        ? '<p class="back-to-index"><a href="/">← All Creators</a></p>'
        : '';

    return <<<HTML
<footer class="site-footer" id="site-footer">
  {$backToIndex}
  <button type="button" class="criteria-toggle-btn" id="criteria-toggle-btn" aria-expanded="false" aria-controls="criteria-content">
    <span>Index criteria</span>
    <svg class="criteria-toggle-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10l5 5 5-5z"/></svg>
  </button>
  <p class="criteria-content" id="criteria-content" style="line-height: 1.45;" hidden></p>
  <template id="criteria-content-template">
Twitch or YouTube channel and an X (Twitter) or Bluesky account<br>
Streamed as a VTuber on at least one platform (SFW or NSFW)<br>
Created or plan to create spicy audio, livestreams or IRL content on<br>
Fansly, OnlyFans, Rplay, Joystick or Patreon
</template>
  <p class="site-stats" id="site-stats"></p>
  <p>Not affiliated with any platform<br> Contact: <a href="https://x.com/spicy_vtubers">@Spicy_VTubers</a></p>
</footer>
HTML;
}

// ---------------------------------- Fansly leaderboard graph ----------------------------------

/**
 * Reads fansly/{slug}.json (see fansly.php — a {"stats": {...}, "reports":
 * [...]} object) and returns ['stats' => array|null, 'reports' => array],
 * keeping only report months that actually have data points. Returns
 * ['stats' => null, 'reports' => []] if the file is missing, unreadable,
 * or has no non-empty months.
 */
function loadFanslyData(string $slug): array
{
    $empty = ['stats' => null, 'reports' => []];

    $path = FANSLY_DIR . '/' . $slug . '.json';
    if (!is_file($path)) {
        return $empty;
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded) || !is_array($decoded['reports'] ?? null)) {
        return $empty;
    }

    $reports = array_values(array_filter(
        $decoded['reports'],
        static fn ($report) => is_array($report) && !empty($report['data'])
    ));

    return [
        'stats' => is_array($decoded['stats'] ?? null) ? $decoded['stats'] : null,
        'reports' => $reports,
    ];
}

/**
 * Renders one month's rank-over-time report as an inline SVG line chart —
 * y-axis is 1 (top) to a dynamic bottom bound: the worst/highest rank
 * reached that month, clamped upward to the nearest of 10/50/100/250/500;
 * x-axis is the scan-time range of that month's data points, labelled
 * with localised month+day ticks.
 */
function buildFanslyGraphSvg(array $report): string
{
    $width = 760;
    $height = 340;
    $padLeft = 44;
    $padRight = 16;
    $padTop = 16;
    $padBottom = 34;
    $plotWidth = $width - $padLeft - $padRight;
    $plotHeight = $height - $padTop - $padBottom;

    $points = [];
    foreach ($report['data'] as $point) {
        $ts = strtotime((string) $point['scannedAt']);
        if ($ts === false) {
            continue;
        }
        $points[] = ['ts' => $ts, 'rank' => (int) $point['rank']];
    }
    if ($points === []) {
        return '';
    }

    $minTs = $points[0]['ts'];
    $maxTs = $points[count($points) - 1]['ts'];
    $tsSpan = max($maxTs - $minTs, 1);

    // Bottom bound snaps up to the nearest bucket (each bucket's tick step
    // is bucket/5, so 1 + 5 evenly-spaced ticks always land cleanly).
    $worstRank = max(array_column($points, 'rank'));
    $buckets = [10 => 2, 50 => 10, 100 => 20, 250 => 50, 500 => 100];
    $bottom = 500;
    $step = 100;
    foreach ($buckets as $bucket => $bucketStep) {
        if ($worstRank <= $bucket) {
            $bottom = $bucket;
            $step = $bucketStep;
            break;
        }
    }

    $x = static fn (int $ts): float => $padLeft + ($ts - $minTs) / $tsSpan * $plotWidth;
    $y = static fn (int $rank): float => $padTop + (max(1, min($bottom, $rank)) - 1) / ($bottom - 1) * $plotHeight;

    $polylinePoints = implode(' ', array_map(
        static fn ($p) => round($x($p['ts']), 1) . ',' . round($y($p['rank']), 1),
        $points
    ));

    // Y-axis: labels only, 1 plus 5 evenly-spaced multiples of $step.
    $yTicksSvg = '';
    $yTickValues = [1];
    for ($i = 1; $i <= 5; $i++) {
        $yTickValues[] = $i * $step;
    }
    foreach ($yTickValues as $rank) {
        $ty = round($y($rank), 1);
        $yTicksSvg .= '<text class="fansly-axis-text" x="' . ($padLeft - 8) . '" y="' . $ty . '" text-anchor="end" dominant-baseline="middle">' . $rank . '</text>';
    }

    // X-axis: evenly spaced date+day labels across the data's time span.
    $xTicksSvg = '';
    $tickCount = 6;
    for ($i = 0; $i < $tickCount; $i++) {
        $tickTs = (int) round($minTs + $i * $tsSpan / ($tickCount - 1));
        $tx = round($x($tickTs), 1);
        $label = htmlspecialchars(date('M j', $tickTs));
        $xTicksSvg .= '<text class="fansly-axis-text" x="' . $tx . '" y="' . ($height - $padBottom + 18) . '" text-anchor="middle">' . $label . '</text>';
    }

    return <<<SVG
<svg viewBox="0 0 {$width} {$height}" role="img" aria-label="Fansly leaderboard rank over time">
{$yTicksSvg}
{$xTicksSvg}
<polyline class="fansly-line" points="{$polylinePoints}"></polyline>
</svg>
SVG;
}

/**
 * Formats an integer with its ordinal suffix, e.g. 1 -> "1st", 26 -> "26th".
 */
function ordinalSuffix(int $n): string
{
    if ($n % 100 >= 11 && $n % 100 <= 13) {
        return $n . 'th';
    }

    return $n . match ($n % 10) {
        1 => 'st',
        2 => 'nd',
        3 => 'rd',
        default => 'th',
    };
}

/**
 * The pill's "final position" suffix for one month, e.g. "26th" or ">500".
 * Uses the last data point's rank UNLESS no scan was recorded in the
 * month's final 8-hour window (its 8am-to-8am bucket end minus 8h — for a
 * June report that's 2026-07-01 00:00 to 08:00, i.e. "anything on July") —
 * a gap there means the account most likely dropped out of the top-500
 * leaderboard (and so out of the scans) before the month ended, so the
 * last known rank is stale and ">500" is shown instead.
 */
function fanslyFinalPositionLabel(array $report): string
{
    $data = $report['data'];
    if ($data === []) {
        return '>500';
    }

    $lastPoint = $data[count($data) - 1];
    $lastTs = strtotime((string) $lastPoint['scannedAt']);
    $bucketEnd = strtotime($report['key'] . '-01 08:00:00 +1 month');
    $windowStart = $bucketEnd - 8 * 3600;

    if ($lastTs === false || $lastTs < $windowStart) {
        return '>500';
    }

    return ordinalSuffix((int) $lastPoint['rank']);
}

/**
 * Renders the "below the graph" single-line stats strip (Highest Rank /
 * Largest Increase / Total Increases), wrapping like the month pills.
 * Returns '' if $stats is null/missing (e.g. an old fansly/{slug}.json
 * written before stats existed).
 */
function buildFanslyStatsHtml(?array $stats): string
{
    if ($stats === null) {
        return '';
    }

    $highestRank = htmlspecialchars(ordinalSuffix((int) ($stats['highestRank'] ?? 0)));
    $largestIncrease = (int) ($stats['largestIncrease'] ?? 0);
    $totalIncreases = (int) ($stats['totalIncreases'] ?? 0);

    return <<<HTML
<div class="fansly-stats">
          <span class="fansly-stat"><span class="fansly-stat-label">Highest Rank:</span> {$highestRank}</span>
          <span class="fansly-stat"><span class="fansly-stat-label">Largest Gain:</span> {$largestIncrease}</span>
          <span class="fansly-stat"><span class="fansly-stat-label">Total Gains:</span> {$totalIncreases}</span>
        </div>
HTML;
}

/**
 * Builds just the Leaderboard tab's inner content: one pill per month
 * (swaps the visible graph, see script's initFanslyGraphs()), one
 * pre-rendered SVG panel per month, and the stats strip. Takes an
 * already-loaded loadFanslyData() result (caller has already checked
 * 'reports' is non-empty).
 */
function buildFanslyLeaderboardPanel(array $fanslyData): string
{
    $pillsHtml = '';
    $panelsHtml = '';
    foreach ($fanslyData['reports'] as $i => $report) {
        $monthKey = htmlspecialchars((string) $report['key']);
        $monthLabel = htmlspecialchars((string) $report['month'] . ' · ' . fanslyFinalPositionLabel($report));
        $activeClass = $i === 0 ? ' is-active' : '';
        $pillsHtml .= '<button type="button" class="fansly-month-btn' . $activeClass . '" data-month="' . $monthKey . '">' . $monthLabel . '</button>';
        $panelsHtml .= '<div class="fansly-graph-panel' . $activeClass . '" data-month="' . $monthKey . '">' . buildFanslyGraphSvg($report) . '</div>';
    }
    $statsHtml = buildFanslyStatsHtml($fanslyData['stats']);

    return <<<HTML
<div class="fansly-month-pills">{$pillsHtml}</div>
        <div class="fansly-graphs">{$panelsHtml}</div>
        {$statsHtml}
HTML;
}

/**
 * True when the creator has a Fansly account at all (accounts.json's
 * "fansly" field, same one buildSpicePills() checks) — broader than
 * requiring a stream to have ever been seen, so the Streams tab is baked
 * in immediately rather than waiting for stale data to catch up once a
 * creator actually starts streaming.
 */
function creatorHasFansly(array $creator): bool
{
    return !empty($creator['fansly']);
}

/**
 * Builds just the Streams tab's inner content: a placeholder container —
 * the actual rows are fetched/rendered client-side (see
 * initFanslyStreamHistory() in SCRIPT) from
 * https://cdn.spicyvtubers.com/fansly/streams/{slug}.json, written by the
 * workers/fansly-stream-tracker Cloudflare Worker.
 */
function buildFanslyStreamHistoryPanel(string $slug): string
{
    $slugAttr = htmlspecialchars($slug);

    return '<div id="fansly-stream-history" data-slug="' . $slugAttr . '"></div>';
}

/**
 * Builds the combined Fansly tabbed card (Fansly icon + "Streams"/
 * "Leaderboard" pill tabs, Streams first, more tabs can be appended to
 * $tabs later) — only tabs with actual content are included. Returns ''
 * (renders nothing) if the creator qualifies for neither tab.
 */
function buildFanslyTabsSection(array $creator): string
{
    $slug = $creator['channelLower'];
    $tabs = [];

    if (ENABLE_FANSLY_STREAM_HISTORY && creatorHasFansly($creator)) {
        $tabs[] = ['key' => 'streams', 'label' => 'Streams', 'html' => buildFanslyStreamHistoryPanel($slug)];
    }

    if (ENABLE_FANSLY_LEADERBOARD_GRAPH) {
        $fanslyData = loadFanslyData($slug);
        if ($fanslyData['reports'] !== []) {
            $tabs[] = ['key' => 'leaderboard', 'label' => 'Leaderboard', 'html' => buildFanslyLeaderboardPanel($fanslyData)];
        }
    }

    if ($tabs === []) {
        return '';
    }

    $pillsHtml = '';
    $panelsHtml = '';
    foreach ($tabs as $i => $tab) {
        $activeClass = $i === 0 ? ' is-active' : '';
        $pillsHtml .= '<button type="button" class="fansly-tab-btn' . $activeClass . '" data-tab="' . $tab['key'] . '"><img src="/fansly.svg" alt="" aria-hidden="true">' . htmlspecialchars($tab['label']) . '</button>';
        $panelsHtml .= '<div class="fansly-tab-panel' . $activeClass . '" data-tab="' . $tab['key'] . '">' . $tab['html'] . '</div>';
    }

    return <<<HTML
<section class="creator-fansly-tabs">
    <div class="creator-card fansly-tabs-card">
      <div class="fansly-tabs-inner">
        <div class="fansly-main-tabs">{$pillsHtml}</div>
        <div class="fansly-tab-panels">{$panelsHtml}</div>
      </div>
    </div>
  </section>
HTML;
}

function renderCreatorHtml(array $creator, ?string $bio): string
{
    $channel = $creator['channel'];
    $channelEsc = htmlspecialchars($channel);
    $slug = $creator['channelLower'];
    $canonical = BASE_URL . 'c/' . rawurlencode($slug) . '/';
    $title = $channelEsc . ' - Spicy VTubers';
    $description = htmlspecialchars(buildDescription($creator));
    // Body-visible references are root-relative (portable across domains); only
    // meta/JSON-LD URLs below need to stay fully-qualified per the OG/schema.org spec.
    $avatarUrlRelative = avatarUrlRelativeForCreator($creator);
    $avatarUrlAbsolute = avatarUrlAbsoluteForCreator($creator);
    $ogImageUrl = $avatarUrlAbsolute;
    // Desktop-only decorative background on the avatar/name row; omitted
    // entirely (no attribute) when the creator has no banner on disk. Passed
    // as a custom property (not the background-image property itself) so the
    // mobile media query below — a plain stylesheet rule — can still turn it
    // off; an inline background-image would always win over that.
    $bannerPath = bannerPathForCreator($creator);
    $hasBanner = is_file($bannerPath);
    $profileHeadClass = 'creator-profile-head' . ($hasBanner ? ' has-banner' : '');
    $bannerUrlRelative = bannerUrlRelativeForCreator($creator);
    $bannerImgHtml = $hasBanner
        ? '<img class="creator-banner-img" src="' . htmlspecialchars($bannerUrlRelative) . '" alt="" decoding="async">'
        : '';

    $channelInfo = getChannelInfo($creator);
    $spicePills = buildSpicePills($creator);
    $socialPills = buildSocialPills($creator);
    $sameAs = buildSameAs($creator, $channelInfo);

    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'Person',
        'name' => $channel,
        'url' => $canonical,
        'image' => $avatarUrlAbsolute,
    ];
    if ($sameAs) {
        $jsonLd['sameAs'] = $sameAs;
    }
    $jsonLdJson = json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $bioHtml = $bio !== null ? '<div class="bio-content creator-inline-bio">' . htmlspecialchars($bio) . '</div>' : '';
    $channelLinkHtml = $channelInfo
        ? '<a class="creator-quick-link creator-channel-link" href="' . htmlspecialchars($channelInfo['href']) . '" target="_blank" rel="noopener noreferrer">' . channelPlatformIconSvg($channelInfo) . '<span class="creator-quick-link-label">' . htmlspecialchars($channelInfo['label']) . '</span></a>'
        : '';
    $socialsLinkHtml = !empty($creator['socials'])
        ? '<a class="creator-quick-link creator-socials-link" href="' . htmlspecialchars($creator['socials']) . '" target="_blank" rel="noopener noreferrer">' . socialsIconSvg() . '<span class="creator-quick-link-label">Socials</span></a>'
        : '';
    $head = renderHeadMeta($title, $description, $canonical, 'profile', $ogImageUrl, $ogImageUrl);
    $bootstrapHead = stylesheetTag();
    $bootstrapBody = scriptBootstrap('initCreatorPage');
    $header = renderSiteHeader('creator');
    $footer = renderSiteFooter('creator');
    $quickLinksHtml = ($socialsLinkHtml !== '' || $channelLinkHtml !== '')
        ? '<div class="creator-profile-actions">' . $socialsLinkHtml . $channelLinkHtml . '</div>'
        : '';
    $profileSideHtml = $quickLinksHtml !== ''
        ? '<div class="creator-profile-side">' . $quickLinksHtml . '</div>'
        : '';
    $fanslyTabsSectionHtml = buildFanslyTabsSection($creator);
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
{$head}
{$bootstrapHead}
<script type="application/ld+json">{$jsonLdJson}</script>
</head>
<body>

{$header}

<main class="page creator-page">
  <section class="creator-profile">
    <div class="creator-card">
      <div class="creator-profile-card">
                <div class="{$profileHeadClass}">{$bannerImgHtml}</div>
                <div class="creator-profile-identity">
                    <div class="creator-profile-main">
                        <span class="avatar avatar-xl">
                            <img class="avatar-img is-loaded" src="{$avatarUrlRelative}" alt="" decoding="async">
                        </span>
                        <h1 class="channel-name">{$channelEsc}</h1>
                    </div>
                    {$profileSideHtml}
        </div>
        <div class="spice-handles">{$spicePills}{$socialPills}</div>
                {$bioHtml}
      </div>
    </div>
  </section>
  {$fanslyTabsSectionHtml}
  <div id="creator-json-container"></div>
</main>


{$footer}

{$bootstrapBody}
</body>
</html>
HTML;
}

function generateIndexHtml(): string
{
    $title = 'Spicy VTubers';
    $description = 'Index of VTubers and their Spicy Accounts on Fansly, OnlyFans, Rplay, Joystick.tv and Patreon';
    $head = renderHeadMeta($title, $description, BASE_URL, 'website', BASE_URL . 'og-image.png', BASE_URL . 'og-spicy.png');
    $bootstrapHead = stylesheetTag();
    $bootstrapBody = scriptBootstrap('initIndex');
    $header = renderSiteHeader('index');
    $footer = renderSiteFooter('index');
    $platformFilterHtml = buildPlatformFilterHtml();

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
{$head}
{$bootstrapHead}
</head>
<body>

{$header}

<main class="page">

  <section class="toolbar">
    <div class="search-wrap">
      <svg class="search-icon" viewBox="0 0 24 24" aria-hidden="true">
        <path d="M15.5 14h-.79l-.28-.27a6.5 6.5 0 1 0-.7.7l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0A4.5 4.5 0 1 1 14 9.5 4.5 4.5 0 0 1 9.5 14z"/>
      </svg>
      <input type="search" id="search-input" placeholder="Search by Channel, Spicy Links, or Socials…" aria-label="Search creators" spellcheck="false">
      <button type="button" id="search-clear-btn" class="search-clear-btn" aria-label="Clear search" hidden>
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M18.3 5.71 12 12.01 5.7 5.71 4.29 7.12l6.3 6.3-6.3 6.29 1.41 1.41 6.3-6.29 6.3 6.29 1.41-1.41-6.29-6.29 6.29-6.3z"/>
        </svg>
      </button>
    </div>
    <div class="toolbar-row">
      <div class="platform-filter" id="platform-filter" role="group" aria-label="Filter by platform">{$platformFilterHtml}</div>
      <p class="result-count" id="result-count" aria-live="polite">Loading creators…</p>
    </div>
  </section>

  <section class="table-section" id="table-section" hidden>
    <table class="creator-table" id="creator-table">
      <colgroup>
        <col class="col-channel">
        <col class="col-spice">
      </colgroup>
      <thead id="creator-thead">
        <tr>
          <th>
            <span>Channel</span>
          </th>
          <th class="col-spice" title="Fansly, OnlyFans, Rplay, joystick.tv, Twitter, Bluesky">
            <span>Links</span>
          </th>
        </tr>
      </thead>
      <tbody id="creator-tbody">
        <!-- Injected rows -->
      </tbody>
    </table>
    <p class="empty-state" id="empty-state" hidden>No creators match your search.</p>
  </section>
  <!-- Load more -->
  <div class="load-more-wrap" id="load-more-wrap" hidden>
    <button type="button" class="load-more-btn" id="load-more-btn">Load More</button>
  </div>
</main>

{$footer}

{$bootstrapBody}
</body>
</html>
HTML;
}

function generateInsightsHtml(): string
{
    $title = 'Weekly Fansly Insights - Spicy VTubers';
    $description = 'Weekly aggregated Fansly stream activity across indexed VTubers: when they usually stream, how many are active at a given time, and how viewer activity shifts through the week.';
    $canonical = BASE_URL . 'insights/';
    $head = renderHeadMeta($title, $description, $canonical, 'website', BASE_URL . 'og-image.png', BASE_URL . 'og-spicy.png');
    $bootstrapHead = stylesheetTag();
    $bootstrapBody = scriptBootstrap('initInsights');
    $header = renderSiteHeader('insights');
    $footer = renderSiteFooter('insights');

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
{$head}
{$bootstrapHead}
</head>
<body>

{$header}

<main class="page insights-page">
  <div class="insights-layout">
    <section class="insights-info-card">
      <h1 class="insights-title">Weekly Fansly Insights</h1>
      <p class="insights-intro">Aggregated stream activity across every indexed VTuber's Fansly account.</p>
      <div id="insights-summary" data-loading>Loading weekly insights…</div>
      <div id="insights-tabs" class="insights-main-tabs"></div>
    </section>
    <section class="insights-graph-card">
      <div id="insights-root" data-loading>Loading weekly insights…</div>
    </section>
  </div>
</main>

{$footer}

{$bootstrapBody}
</body>
</html>
HTML;
}

// ---------------------------------- OG image (ImageMagick) ----------------------------------

function runCmd(string $cmd): bool
{
    exec($cmd . ' 2>&1', $output, $exitCode);
    if ($exitCode !== 0) {
        fwrite(STDERR, "Command failed ({$exitCode}): {$cmd}\n" . implode("\n", $output) . "\n");
        return false;
    }
    return true;
}

function measureTextWidth(string $font, int $pointsize, string $text): int
{
    $cmd = sprintf(
        'convert -font %s -pointsize %d %s -format "%%w" info:',
        escapeshellarg($font),
        $pointsize,
        escapeshellarg('label:' . $text)
    );
    return (int) trim((string) shell_exec($cmd));
}

function findAvatarSource(array $creator): ?string
{
    $avatarPath = avatarPathForCreator($creator);
    return is_file($avatarPath) ? $avatarPath : null;
}

function cleanupDir(string $dir): void
{
    foreach (glob("{$dir}/*") ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
}

// Recursively removes everything inside $dir, leaving $dir itself in place.
function deleteDirContents(string $dir): void
{
    $items = is_dir($dir) ? scandir($dir) : false;
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path) && !is_link($path)) {
            deleteDirContents($path);
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
}

function buildOgImage(array $creator, string $outPath): bool
{
    $tmp = sys_get_temp_dir() . '/spicy-og-' . bin2hex(random_bytes(6));
    if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
        return false;
    }

    $ok = true;
    $step = "{$tmp}/00-canvas.png";
    $ok = $ok && runCmd(sprintf('convert -size 1200x630 xc:%s %s', escapeshellarg(OG_BG), escapeshellarg($step)));

    // Full "Spicy VTubers" wordmark logo, vertically centered within the
    // fixed header band above where the banner starts (previously a fixed
    // logoY left it hugging the banner with a big gap above it instead).
    $logoBottom = 165;
    if ($ok && is_file(OG_LOGO_PATH)) {
        $logoResized = "{$tmp}/logo.png";
        $ok = $ok && runCmd(sprintf('convert %s -resize x110 %s', escapeshellarg(OG_LOGO_PATH), escapeshellarg($logoResized)));
        $logoW = $ok ? (int) trim((string) shell_exec('identify -format "%w" ' . escapeshellarg($logoResized))) : 0;
        $logoH = $ok ? (int) trim((string) shell_exec('identify -format "%h" ' . escapeshellarg($logoResized))) : 0;
        $logoY = intdiv($logoBottom - $logoH, 2);
        if ($ok) {
            $logoX = intdiv(1200 - $logoW, 2);
            $next = "{$tmp}/01.png";
            $ok = runCmd(sprintf('convert %s %s -geometry +%d+%d -composite %s', escapeshellarg($step), escapeshellarg($logoResized), $logoX, $logoY, escapeshellarg($next)));
            if ($ok) {
                $step = $next;
                // $logoBottom intentionally left as the fixed header height
                // (not $logoY + $logoH) so the banner/avatar below still
                // start at a consistent position regardless of the logo's
                // own (now-centered) height.
            }
        }
    }

    // Creator's banner (same source as the /c/{slug}/ page's desktop banner)
    // filling everything below the logo, dimmed with a top-to-bottom dark
    // gradient (mirrors the page's CSS overlay) so the avatar/name drawn on
    // top of it stay legible regardless of the banner's own colors.
    $bannerBoxH = 630 - $logoBottom;
    $bannerPath = bannerPathForCreator($creator);
    if ($ok && is_file($bannerPath)) {
        $bannerFit = "{$tmp}/banner-fit.png";
        $ok = runCmd(sprintf(
            'convert %s -resize %dx%d^ -gravity center -extent %dx%d %s',
            escapeshellarg($bannerPath), 1200, $bannerBoxH, 1200, $bannerBoxH, escapeshellarg($bannerFit)
        ));

        $bannerOverlay = "{$tmp}/banner-overlay.png";
        $ok = $ok && runCmd(sprintf(
            'convert -size %dx%d gradient:%s-%s %s',
            1200, $bannerBoxH, escapeshellarg('rgba(15,10,18,0.35)'), escapeshellarg('rgba(15,10,18,0.9)'), escapeshellarg($bannerOverlay)
        ));

        $bannerDimmed = "{$tmp}/banner-dimmed.png";
        $ok = $ok && runCmd(sprintf('convert %s %s -composite %s', escapeshellarg($bannerFit), escapeshellarg($bannerOverlay), escapeshellarg($bannerDimmed)));

        if ($ok) {
            $next = "{$tmp}/01b.png";
            $ok = runCmd(sprintf('convert %s %s -geometry +0+%d -composite %s', escapeshellarg($step), escapeshellarg($bannerDimmed), $logoBottom, escapeshellarg($next)));
            if ($ok) {
                $step = $next;
            }
        }
    }

    // Avatar (circle-masked, with a plain black ring behind it so it stands
    // out from the banner) + creator name, centered lower in the canvas now
    // that there's no icon row at the bottom competing for space.
    $avatarBottom = $logoBottom + 295;
    $avatarSrc = $ok ? findAvatarSource($creator) : null;
    if ($ok && $avatarSrc) {
        $size = 240;
        $radius = intdiv($size, 2);
        $ringWidth = 5;
        $ringRadius = $radius + $ringWidth;

        $square = "{$tmp}/avatar-square.png";
        $ok = runCmd(sprintf('convert %s -resize %dx%d^ -gravity center -extent %dx%d %s', escapeshellarg($avatarSrc), $size, $size, $size, $size, escapeshellarg($square)));

        // Circle inset 3px from the canvas edge so its anti-aliased edge isn't
        // clipped at the top/bottom/left/right tangent points (where it would
        // otherwise touch the canvas boundary exactly and render flat).
        $mask = "{$tmp}/avatar-mask.png";
        $ok = $ok && runCmd(sprintf('convert -size %dx%d xc:none -fill white -draw %s %s', $size, $size, escapeshellarg("circle {$radius},{$radius} {$radius},3"), escapeshellarg($mask)));

        $circle = "{$tmp}/avatar-circle.png";
        $ok = $ok && runCmd(sprintf('convert %s %s -alpha off -compose CopyOpacity -composite %s', escapeshellarg($square), escapeshellarg($mask), escapeshellarg($circle)));

        $avatarY = $logoBottom + 55;
        $avatarX = intdiv(1200 - $size, 2);
        if ($ok) {
            // Drawn straight onto the full canvas (plenty of margin from any
            // edge), so no inset-for-anti-aliasing trick is needed here.
            $ringCenterX = $avatarX + $radius;
            $ringCenterY = $avatarY + $radius;
            $next = "{$tmp}/04a.png";
            $ok = runCmd(sprintf(
                'convert %s -fill black -draw %s %s',
                escapeshellarg($step),
                escapeshellarg("circle {$ringCenterX},{$ringCenterY} {$ringCenterX}," . ($ringCenterY - $ringRadius)),
                escapeshellarg($next)
            ));
            if ($ok) {
                $step = $next;
            }
        }

        if ($ok) {
            $next = "{$tmp}/04b.png";
            $ok = runCmd(sprintf('convert %s %s -geometry +%d+%d -composite %s', escapeshellarg($step), escapeshellarg($circle), $avatarX, $avatarY, escapeshellarg($next)));
            if ($ok) {
                $step = $next;
                $avatarBottom = $avatarY + $size;
            }
        }
    }

    if ($ok) {
        $namePointsize = 58;
        $nameText = $creator['channel'];
        $nameWidth = measureTextWidth(OG_FONT, $namePointsize, $nameText);
        while ($nameWidth > 1000 && $namePointsize > 28) {
            $namePointsize -= 4;
            $nameWidth = measureTextWidth(OG_FONT, $namePointsize, $nameText);
        }
        $nameY = $avatarBottom + 40;

        // Dark shadow/outline behind the name (mirrors the creator page's CSS
        // text-shadow: 4 diagonal offset copies softened with a blur) so it
        // stays legible over the banner instead of a colored glow.
        $shadow = "{$tmp}/name-shadow.png";
        $strokeArgs = '';
        foreach ([[-2, -2], [2, -2], [-2, 2], [2, 2]] as [$dx, $dy]) {
            $strokeArgs .= sprintf(' -annotate %+d%+d %s', $dx, $nameY + $dy, escapeshellarg($nameText));
        }
        $ok = runCmd(sprintf(
            'convert -size 1200x630 xc:none -font %s -pointsize %d -fill black -gravity North%s -blur 0x3 %s',
            escapeshellarg(OG_FONT), $namePointsize, $strokeArgs, escapeshellarg($shadow)
        ));
        if ($ok) {
            $next = "{$tmp}/05a.png";
            $ok = runCmd(sprintf('convert %s %s -composite %s', escapeshellarg($step), escapeshellarg($shadow), escapeshellarg($next)));
            if ($ok) {
                $step = $next;
            }
        }

        if ($ok) {
            $next = "{$tmp}/05b.png";
            $ok = runCmd(sprintf(
                'convert %s -font %s -pointsize %d -fill white -gravity North -annotate +0+%d %s %s',
                escapeshellarg($step), escapeshellarg(OG_FONT), $namePointsize, $nameY, escapeshellarg($nameText), escapeshellarg($next)
            ));
            if ($ok) {
                $step = $next;
            }
        }
    }

    if ($ok) {
        $ok = runCmd(sprintf('convert %s %s', escapeshellarg($step), escapeshellarg($outPath)));
    }

    cleanupDir($tmp);
    return $ok;
}

// ---------------------------------- Sitemap ----------------------------------

function writeSitemap(array $slugs): void
{
    $today = gmdate('Y-m-d');
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>');

    $home = $xml->addChild('url');
    $home->addChild('loc', BASE_URL);
    $home->addChild('lastmod', $today);

    $insights = $xml->addChild('url');
    $insights->addChild('loc', BASE_URL . 'insights/');
    $insights->addChild('lastmod', $today);

    foreach ($slugs as $slug) {
        $u = $xml->addChild('url');
        $u->addChild('loc', BASE_URL . 'c/' . rawurlencode($slug) . '/');
        $u->addChild('lastmod', $today);
    }

    $xml->asXML(SITEMAP_PATH);
}

// ---------------------------------- Main ----------------------------------

function main(array $argv): void
{
    if (in_array('--clean-c', $argv, true)) {
        deleteDirContents(OUTPUT_DIR);
        if (is_file(SNAPSHOT_PATH)) {
            @unlink(SNAPSHOT_PATH);
        }        
        echo "Cleared all contents of " . OUTPUT_DIR . " (c/ folder itself kept).\n";
        return;
    }

    generateMinifiedAssets();

    $limit = null;
    foreach ($argv as $arg) {
        if (preg_match('/^--limit=(\d+)$/', $arg, $matches)) {
            $limit = (int) $matches[1];
            break;
        }
    }
    $forceHtml = in_array('--force-html', $argv, true);
    $force = $forceHtml || in_array('--force', $argv, true);


    $accounts = readJson(ACCOUNTS_PATH);
    if (!is_array($accounts)) {
        fwrite(STDERR, "Failed to read accounts.json\n");
        exit(1);
    }
    if ($limit !== null) {
        $accounts = array_slice($accounts, 0, $limit);
    }

    $snapshot = is_file(SNAPSHOT_PATH) ? readJson(SNAPSHOT_PATH) : [];
    if (!is_array($snapshot)) {
        $snapshot = [];
    }

    if (!is_dir(OUTPUT_DIR) && !mkdir(OUTPUT_DIR, 0775, true) && !is_dir(OUTPUT_DIR)) {
        fwrite(STDERR, "Failed to create output directory: " . OUTPUT_DIR . "\n");
        exit(1);
    }

    $slugs = [];
    $regenerated = 0;
    $skipped = 0;
    $failed = 0;

    foreach ($accounts as $entry) {
        if (!is_array($entry) || !isset($entry['channel']) || trim((string) $entry['channel']) === '') {
            continue;
        }

        $slug = mb_strtolower($entry['channel']);
        $slugs[] = $slug;

        $needsRegen = $force || !isset($snapshot[$slug]['entry']) || $snapshot[$slug]['entry'] != $entry;
        if (!$needsRegen) {
            $skipped++;
            continue;
        }

        $creator = $entry;
        $creator['channelLower'] = $slug;
        $creator['xHandles'] = array_values(array_filter($entry['xHandles'] ?? []));

        $bio = loadBio($slug);
        $outDir = OUTPUT_DIR . '/' . $slug;

        if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
            fwrite(STDERR, "Failed to create output directory for {$slug}\n");
            $failed++;
            continue;
        }

        $html = renderCreatorHtml($creator, $bio);
        if (file_put_contents("{$outDir}/index.html", $html) === false) {
            fwrite(STDERR, "Failed to write index.html for {$slug}\n");
            $failed++;
            continue;
        }

        if (ENABLE_CREATOR_OG_IMAGE_GENERATION) {
            $ogImagePath = "{$outDir}/og-image.webp";
            // --force-html only redoes the HTML; leave an existing OG image alone
            // (still build one if it's missing, e.g. a brand-new creator).
            $skipOgImage = $forceHtml && is_file($ogImagePath);
            if (!$skipOgImage && !buildOgImage($creator, $ogImagePath)) {
                fwrite(STDERR, "Failed to build og-image.webp for {$slug}\n");
                $failed++;
                continue;
            }
        }

        $snapshot[$slug] = ['entry' => $entry, 'generatedAt' => gmdate('c')];
        writeJson(SNAPSHOT_PATH, $snapshot);

        fwrite(STDOUT, "Regenerated: {$slug}\n");
        $regenerated++;
    }

    if (file_put_contents(INDEX_PATH, generateIndexHtml()) === false) {
        fwrite(STDERR, "Failed to write index.html\n");
    }

    if (!is_dir(INSIGHTS_DIR) && !mkdir(INSIGHTS_DIR, 0775, true) && !is_dir(INSIGHTS_DIR)) {
        fwrite(STDERR, "Failed to create output directory: " . INSIGHTS_DIR . "\n");
    } elseif (file_put_contents(INSIGHTS_PATH, generateInsightsHtml()) === false) {
        fwrite(STDERR, "Failed to write insights/index.html\n");
    }

    writeSitemap($slugs);

    fwrite(STDOUT, "Done. Regenerated: {$regenerated}, skipped: {$skipped}, failed: {$failed}, total: " . count($slugs) . " (index.html + sitemap.xml always rewritten)\n");
}

main($argv);
