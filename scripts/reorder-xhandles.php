<?php

declare(strict_types=1);

/**
 * Reorders each account's "xHandles" (when there's more than one) by
 * X follower count, most-followed first, using the per-handle
 * "followersCount" already scraped into creators/{slug}.json's
 * "twitter" object (see update.php's Twitter/X fetcher).
 *
 * An account is skipped (left untouched) if:
 * - it has no "slug" field (no way to resolve its creators/*.json), or
 * - its creators/{slug}.json doesn't exist yet, or
 * - any of its xHandles has no "twitter" data / no "followersCount" yet
 *   (rather than guessing an order with partial data).
 *
 * Usage: php reorder-xhandles.php
 */

require __DIR__ . '/accounts-json-lib.php';

$accountsPath = dirname(__DIR__) . '/accounts.json';
$creatorsDir = dirname(__DIR__) . '/creators';

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

$changed = 0;
$skippedNoChannel = 0;
$skippedNoCreatorFile = 0;
$skippedMissingData = 0;

foreach ($accounts as &$account) {
    if (!is_array($account)) {
        continue;
    }

    $xHandles = array_values(array_filter((array) ($account['xHandles'] ?? []), static fn ($h): bool => trim((string) $h) !== ''));
    if (count($xHandles) < 2) {
        continue;
    }

    $displayName = trim((string) ($account['channel'] ?? $account['fansly'] ?? '(unnamed)'));
    $login = accountSlug($account);
    if ($login === '') {
        echo "{$displayName}: skipped (no \"slug\" field, can't resolve creators file)\n";
        $skippedNoChannel++;
        continue;
    }

    $creatorFile = "{$creatorsDir}/{$login}.json";
    if (!is_file($creatorFile)) {
        echo "{$displayName}: skipped (creators/{$login}.json not found)\n";
        $skippedNoCreatorFile++;
        continue;
    }

    $creatorJson = file_get_contents($creatorFile);
    $creatorData = $creatorJson !== false ? json_decode($creatorJson, true) : null;
    $twitter = is_array($creatorData) ? ($creatorData['twitter'] ?? []) : [];

    $followers = [];
    $missing = [];
    foreach ($xHandles as $handle) {
        $handleLower = strtolower((string) $handle);
        $count = $twitter[$handleLower]['followersCount'] ?? null;
        if (!is_int($count)) {
            $missing[] = $handle;
            continue;
        }
        $followers[$handle] = $count;
    }

    if ($missing !== []) {
        echo "{$displayName}: skipped (no follower data for: " . implode(', ', $missing) . ")\n";
        $skippedMissingData++;
        continue;
    }

    $reordered = $xHandles;
    usort($reordered, static fn ($a, $b): int => $followers[$b] <=> $followers[$a]);

    if ($reordered !== $xHandles) {
        $summary = implode(', ', array_map(
            static fn ($h): string => "{$h}({$followers[$h]})",
            $reordered
        ));
        echo "{$displayName}: reordered -> {$summary}\n";
        $account['xHandles'] = $reordered;
        $changed++;
    }
}
unset($account);

if ($changed > 0) {
    writeAccountsJson($accountsPath, $accounts);
}

echo "\n---- Summary ----\n";
echo "Reordered: {$changed}\n";
echo "Skipped (no slug field): {$skippedNoChannel}\n";
echo "Skipped (no creators file): {$skippedNoCreatorFile}\n";
echo "Skipped (missing follower data for a handle): {$skippedMissingData}\n";
