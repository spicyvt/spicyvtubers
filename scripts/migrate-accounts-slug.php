<?php

declare(strict_types=1);

/**
 * One-time accounts.json migration to the slug/twitch/youtube schema.
 *
 * Per entry: adds "slug" (= lowercased "channel", so every existing URL,
 * creators/{slug}.json, avatar file and fansly.json/rplay.json login stays the
 * same) as the first key, removes "type", and inserts "twitch" or "youtube"
 * (the old channel handle) right after "channel". "channel" itself is kept and
 * from now on is only the display name.
 *
 * Idempotent: entries that already have a slug and no "type" are left alone.
 * Nothing is written if any check fails. A timestamped .bak copy is made first.
 *
 * Usage:
 *   php migrate-accounts-slug.php [--file=PATH] [--dry-run]
 */

require __DIR__ . '/accounts-json-lib.php';

$path = dirname(__DIR__) . '/accounts.json';
$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (str_starts_with($arg, '--file=')) {
        $path = substr($arg, strlen('--file='));
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(2);
    }
}

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\nNothing was written.\n");
    exit(1);
}

$raw = @file_get_contents($path);
if ($raw === false) {
    fail("cannot read {$path}");
}
$accounts = json_decode($raw, true);
if (!is_array($accounts) || !array_is_list($accounts)) {
    fail("{$path} is not a JSON array");
}

function isTemplateEntry(array $entry): bool
{
    foreach ($entry as $value) {
        if (is_array($value) ? array_filter($value, static fn ($v): bool => trim((string) $v) !== '') : trim((string) $value) !== '') {
            return false;
        }
    }
    return true;
}

$migrated = [];
$stats = ['migrated' => 0, 'already' => 0, 'twitch' => 0, 'youtube' => 0, 'template' => 0];
$examples = [];

foreach ($accounts as $i => $entry) {
    if (!is_array($entry)) {
        fail("entry #{$i} is not an object");
    }

    if (array_key_exists('slug', $entry) && !array_key_exists('type', $entry)) {
        $migrated[] = $entry;
        $stats['already']++;
        continue;
    }

    $template = isTemplateEntry($entry);
    $channel = trim((string) ($entry['channel'] ?? ''));
    if ($channel === '' && !$template) {
        fail("entry #{$i} has no channel (fansly: " . ($entry['fansly'] ?? '-') . ')');
    }

    $type = strtolower(trim((string) ($entry['type'] ?? ''))) ?: 'twitch';
    if (!in_array($type, ['twitch', 'youtube'], true)) {
        fail("entry #{$i} ({$channel}) has unsupported type \"{$type}\"");
    }

    $slug = strtolower($channel);
    if ($slug !== '' && sanitizeSlug($slug) !== $slug) {
        fail("entry #{$i} channel \"{$channel}\" is not a valid slug (allowed: a-z 0-9 _ -)");
    }

    $out = ['slug' => $slug];
    foreach ($entry as $key => $value) {
        if ($key === 'slug' || $key === 'type' || $key === 'twitch' || $key === 'youtube') {
            continue;
        }
        $out[$key] = $value;
        if ($key === 'channel') {
            $out[$type] = $slug;
        }
    }
    if (!array_key_exists($type, $out)) {
        $out[$type] = $slug;
    }

    $migrated[] = $out;
    if ($template) {
        $stats['template']++;
    } else {
        $stats['migrated']++;
        $stats[$type]++;
        if (count($examples) < 3 || ($type === 'youtube' && $stats['youtube'] === 1)) {
            $examples[] = $out;
        }
    }
}

$seen = [];
foreach ($migrated as $i => $entry) {
    $slug = accountSlug($entry);
    if ($slug === '') {
        continue;
    }
    if (isset($seen[$slug])) {
        fail("duplicate slug \"{$slug}\" (entries #{$seen[$slug]} and #{$i})");
    }
    $seen[$slug] = $i;
}

echo "File:              {$path}\n";
echo "Entries:           " . count($accounts) . "\n";
echo "Migrated:          {$stats['migrated']} (twitch {$stats['twitch']}, youtube {$stats['youtube']})\n";
echo "Already migrated:  {$stats['already']}\n";
echo "Template entries:  {$stats['template']}\n";

if ($stats['migrated'] === 0 && $stats['template'] === 0) {
    echo "Nothing to do.\n";
    exit(0);
}

if ($dryRun) {
    echo "\n--dry-run: example migrated entries:\n";
    foreach ($examples as $example) {
        echo json_encode($example, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
    echo "\nNo changes written.\n";
    exit(0);
}

$backup = $path . '.bak-' . date('Ymd-His');
if (!copy($path, $backup)) {
    fail("cannot create backup {$backup}");
}
echo "Backup:            {$backup}\n";

writeAccountsJson($path, $migrated);

$check = json_decode((string) file_get_contents($path), true);
if (!is_array($check) || count($check) !== count($accounts)) {
    fwrite(STDERR, "ERROR: written file failed verification; restore from {$backup}\n");
    exit(1);
}
foreach ($accounts as $i => $original) {
    $expected = strtolower(trim((string) ($original['channel'] ?? '')));
    if (!array_key_exists('slug', $original) && accountSlug($check[$i]) !== $expected) {
        fwrite(STDERR, "ERROR: entry #{$i} slug mismatch after write; restore from {$backup}\n");
        exit(1);
    }
}

echo "Done. accounts.json migrated and verified.\n";
