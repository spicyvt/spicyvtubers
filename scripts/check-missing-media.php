<?php

declare(strict_types=1);

/**
 * Reports creators in accounts.json missing avatar/banner images.
 *
 * Checks, per creator:
 * - twitch/youtube ("slug" names the shared image):
 *   avatarsLarge/{slug}.webp and banners/{slug}.webp
 * - each "xHandles" entry: avatarsX/{strtolower(handle)}.webp and
 *   bannersX/{same}.webp
 * - "bskyHandle"[0] (the handle name, if set): avatarsB/{strtolower(name)}.webp
 *   and bannersB/{same}.webp
 *
 * Usage:
 *   php check-missing-media.php              # full report
 *   php check-missing-media.php --avatars    # only report missing avatars
 *   php check-missing-media.php --banners    # only report missing banners
 */

$rootDir = dirname(__DIR__);
$accountsPath = $rootDir . '/accounts.json';
$avatarsLargeDir = $rootDir . '/avatarsLarge';
$bannersDir = $rootDir . '/banners';
$avatarsXDir = $rootDir . '/avatarsX';
$bannersXDir = $rootDir . '/bannersX';
$avatarsBDir = $rootDir . '/avatarsB';
$bannersBDir = $rootDir . '/bannersB';

$onlyAvatars = in_array('--avatars', $argv, true);
$onlyBanners = in_array('--banners', $argv, true);
$checkAvatars = !$onlyBanners;
$checkBanners = !$onlyAvatars;

$json = file_get_contents($accountsPath);
if ($json === false) {
    fwrite(STDERR, "Failed to read {$accountsPath}\n");
    exit(1);
}

$accounts = json_decode($json, true);
if (!is_array($accounts)) {
    fwrite(STDERR, "Failed to parse {$accountsPath}\n");
    exit(1);
}

/** @return string[] list of "missing" descriptions for one file pair */
function checkFiles(string $avatarPath, string $bannerPath, bool $checkAvatars, bool $checkBanners): array
{
    $missing = [];
    if ($checkAvatars && !is_file($avatarPath)) {
        $missing[] = 'avatar';
    }
    if ($checkBanners && !is_file($bannerPath)) {
        $missing[] = 'banner';
    }
    return $missing;
}

$totalCreators = 0;
$creatorsWithIssues = 0;
$missingChannelAvatar = 0;
$missingChannelBanner = 0;
$missingXAvatar = 0;
$missingXBanner = 0;
$missingBskyAvatar = 0;
$missingBskyBanner = 0;

foreach ($accounts as $account) {
    if (!is_array($account)) {
        continue;
    }
    $totalCreators++;

    $channel = trim((string) ($account['channel'] ?? ''));
    $displayName = $channel !== '' ? $channel : trim((string) ($account['fansly'] ?? '(unnamed)'));
    $login = strtolower(trim((string) ($account['slug'] ?? '')));
    $type = trim((string) ($account['twitch'] ?? '')) !== '' ? 'twitch' : 'youtube';

    $lines = [];

    if ($login !== '' && (trim((string) ($account['twitch'] ?? '')) !== '' || trim((string) ($account['youtube'] ?? '')) !== '')) {
        $missing = checkFiles(
            "{$avatarsLargeDir}/{$login}.webp",
            "{$bannersDir}/{$login}.webp",
            $checkAvatars,
            $checkBanners
        );
        if ($missing !== []) {
            $lines[] = "  [{$type}] {$channel}: missing " . implode(', ', $missing);
            if (in_array('avatar', $missing, true)) {
                $missingChannelAvatar++;
            }
            if (in_array('banner', $missing, true)) {
                $missingChannelBanner++;
            }
        }
    }

    foreach ((array) ($account['xHandles'] ?? []) as $xHandle) {
        $xHandle = trim((string) $xHandle);
        if ($xHandle === '') {
            continue;
        }
        $handleLower = strtolower($xHandle);
        $missing = checkFiles(
            "{$avatarsXDir}/{$handleLower}.webp",
            "{$bannersXDir}/{$handleLower}.webp",
            $checkAvatars,
            $checkBanners
        );
        if ($missing !== []) {
            $lines[] = "  [x] @{$xHandle}: missing " . implode(', ', $missing);
            if (in_array('avatar', $missing, true)) {
                $missingXAvatar++;
            }
            if (in_array('banner', $missing, true)) {
                $missingXBanner++;
            }
        }
    }

    $bskyHandle = $account['bskyHandle'] ?? null;
    $bskyName = is_array($bskyHandle) ? trim((string) ($bskyHandle[0] ?? '')) : '';
    if ($bskyName !== '') {
        $nameLower = strtolower($bskyName);
        $missing = checkFiles(
            "{$avatarsBDir}/{$nameLower}.webp",
            "{$bannersBDir}/{$nameLower}.webp",
            $checkAvatars,
            $checkBanners
        );
        if ($missing !== []) {
            $lines[] = "  [bluesky] {$bskyName}: missing " . implode(', ', $missing);
            if (in_array('avatar', $missing, true)) {
                $missingBskyAvatar++;
            }
            if (in_array('banner', $missing, true)) {
                $missingBskyBanner++;
            }
        }
    }

    if ($lines !== []) {
        $creatorsWithIssues++;
        echo "{$displayName}\n";
        echo implode("\n", $lines) . "\n";
    }
}

echo "\n---- Summary ----\n";
echo "Total creators checked: {$totalCreators}\n";
echo "Creators with at least one missing image: {$creatorsWithIssues}\n";
echo "Twitch/YouTube: missing avatar={$missingChannelAvatar}, missing banner={$missingChannelBanner}\n";
echo "X handles:      missing avatar={$missingXAvatar}, missing banner={$missingXBanner}\n";
echo "Bluesky:        missing avatar={$missingBskyAvatar}, missing banner={$missingBskyBanner}\n";
