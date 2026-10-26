<?php

declare(strict_types=1);

/**
 * Builds per-creator Fansly leaderboard history files under fansly/ from
 * the "fansly" database table (columns: id, scannedAt, rank, accountId —
 * a leaderboard rank snapshot taken roughly every 15 minutes).
 *
 * For every accounts.json entry, resolves creators/{channelLower}.json and
 * reads its "fansly"."id" (the Fansly accountId set by update.php's Fansly
 * fetch). Entries with no resolved Fansly accountId are skipped entirely —
 * there's nothing to correlate against the leaderboard table for them.
 *
 * For every remaining accountId, queries every row for it (ordered by
 * scannedAt), thins out long flat runs of unchanged rank (see
 * dedupeConsecutiveRanks() — keeps only the first/last point of any run
 * longer than 3, so the stepped graph still draws the same flat segment
 * with far fewer points), groups the rows into 8am-to-8am monthly buckets
 * (see groupRowsByMonth()), drops any month with no scan in its final 7
 * days (see monthHasRecentActivity() — too stale to bother graphing), and
 * writes fansly/{channelLower}.json as a JSON object:
 *   { "stats": { "highestRank": 5, "largestIncrease": 42,
 *   "totalIncreases": 13 }, "reports": [ { "month": "June 2026", "key":
 *   "2026-06", "data": [ { "scannedAt": "2026-06-02 07:52:20", "rank": 12
 *   }, ... ] }, ... ] }
 * "stats" is computed from the account's ENTIRE chronological row
 * history (see computeFanslyStats()), not just the surviving months in
 * "reports". If the accountId has no rows at all, or every month gets
 * dropped as stale, no file is written for that creator (no graph is
 * rendered) — and any previously-written fansly/{channelLower}.json is
 * deleted (see deleteStaleFanslyFile()) so a leftover file can't keep
 * rendering a stale graph.
 * Always overwrites when there's live data (no incremental/resolved-skip
 * logic, unlike update.php).
 *
 * DB credentials are read from .env (DB_HOST/DB_PORT/DB_DATABASE/
 * DB_USERNAME/DB_PASSWORD/DB_CHARSET) via a minimal parser — no dotenv
 * dependency in this repo.
 *
 * Usage:
 *   php fansly.php
 */

// Site root (this script lives in scripts/).
const ROOT_DIR = __DIR__ . '/..';
const ACCOUNTS_PATH = ROOT_DIR . '/accounts.json';
const CREATORS_DIR = ROOT_DIR . '/creators';
const FANSLY_DIR = ROOT_DIR . '/fansly';
const ENV_PATH = ROOT_DIR . '/.env';

/**
 * Minimal .env parser: KEY=VALUE per line, blank lines and lines starting
 * with # are ignored, surrounding quotes on the value are stripped.
 */
function loadEnv(string $path): array
{
    $env = [];
    if (!is_file($path)) {
        return $env;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (strlen($value) >= 2 && (
            ($value[0] === '"' && str_ends_with($value, '"'))
            || ($value[0] === "'" && str_ends_with($value, "'"))
        )) {
            $value = substr($value, 1, -1);
        }

        $env[$key] = $value;
    }

    return $env;
}

function connectDb(array $env): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $env['DB_HOST'] ?? '127.0.0.1',
        $env['DB_PORT'] ?? '3306',
        $env['DB_DATABASE'] ?? '',
        $env['DB_CHARSET'] ?? 'utf8mb4'
    );

    $pdo = new PDO($dsn, $env['DB_USERNAME'] ?? '', $env['DB_PASSWORD'] ?? '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

/**
 * Fetches every leaderboard row for one Fansly accountId, ordered by
 * scannedAt ascending, as a flat list of ['scannedAt' => ..., 'rank' => ...].
 */
function fetchFanslyRows(PDO $pdo, string $accountId): array
{
    $stmt = $pdo->prepare(
        'SELECT scannedAt, `rank` FROM fansly WHERE accountId = :accountId ORDER BY scannedAt ASC'
    );
    $stmt->execute(['accountId' => $accountId]);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = [
            'scannedAt' => $row['scannedAt'],
            'rank' => (int) $row['rank'],
        ];
    }

    return $rows;
}

/**
 * Groups flat scannedAt/rank rows into monthly report blocks, e.g.
 * [ { "month": "June 2026", "key": "2026-06", "data": [...] }, ... ].
 * Month boundaries run 8am on the 1st to 8am on the 1st of the next month
 * (e.g. "June" = 2026-06-01 08:00:00 up to but excluding 2026-07-01
 * 08:00:00), not the calendar month — achieved by shifting each
 * scannedAt back 8 hours before reading its Y-m.
 */
function groupRowsByMonth(array $rows): array
{
    $months = [];
    foreach ($rows as $row) {
        $ts = strtotime($row['scannedAt']) - 8 * 3600;
        $key = date('Y-m', $ts);
        $months[$key][] = $row;
    }
    
    //Month should be in format as fansly - so Jun '26, Jul '26, Aug '26, etc
    $reports = [];
    foreach ($months as $key => $data) {
        $reports[] = [
            'month' => date('M \'y', strtotime($key . '-01 08:00:00')),
            'key' => $key,
            'data' => $data,
        ];
    }

    return $reports;
}

/**
 * Thins out long flat runs of consecutive identical ranks so the graphed
 * data doesn't carry a scan point every ~15 minutes for weeks of no
 * movement. A run of more than 3 identical ranks in a row has everything
 * between its first and last point dropped — the first and last of the
 * run are always kept, so the stepped line graph still draws a flat
 * segment across the exact same span. Runs of 3 or fewer are left
 * untouched (there's nothing worth trimming, and short runs shouldn't
 * ever be collapsed down to just two points).
 */
function dedupeConsecutiveRanks(array $rows): array
{
    $result = [];
    for ($i = 0, $count = count($rows); $i < $count;) {
        $j = $i;
        while ($j + 1 < $count && $rows[$j + 1]['rank'] === $rows[$i]['rank']) {
            $j++;
        }

        if ($j - $i + 1 > 3) {
            $result[] = $rows[$i];
            $result[] = $rows[$j];
        } else {
            for ($k = $i; $k <= $j; $k++) {
                $result[] = $rows[$k];
            }
        }

        $i = $j + 1;
    }

    return $result;
}

/**
 * Computes summary stats across a creator's ENTIRE chronological rank
 * history (all rows, not just the months that survive the staleness
 * filter) — "Highest Rank" is the best (numerically lowest) rank ever
 * reached; "Largest Increase" is the biggest single scan-to-scan
 * improvement (previous rank minus current rank, when positive); "Total
 * Increases" counts how many scan-to-scan steps improved the rank at all,
 * starting the counter at 1 (not 0) per spec.
 */
function computeFanslyStats(array $rows): array
{
    $highestRank = min(array_column($rows, 'rank'));

    $largestIncrease = 0;
    $totalIncreases = 1;
    for ($i = 1, $count = count($rows); $i < $count; $i++) {
        $delta = $rows[$i - 1]['rank'] - $rows[$i]['rank'];
        if ($delta > 0) {
            $totalIncreases++;
            if ($delta > $largestIncrease) {
                $largestIncrease = $delta;
            }
        }
    }

    return [
        'highestRank' => $highestRank,
        'largestIncrease' => $largestIncrease,
        'totalIncreases' => $totalIncreases,
    ];
}

/**
 * A month report only counts as "live" if it has at least one scan within
 * the final 7 days of its own 8am-to-8am bucket (e.g. for a June report,
 * 2026-06-24 08:00 through 2026-07-01 08:00) — a gap longer than that
 * means the account had already stopped being scanned (most likely fell
 * out of the top-500 leaderboard) well before the month ended, so it's
 * too stale to bother graphing.
 */
function monthHasRecentActivity(array $report): bool
{
    $data = $report['data'];
    if ($data === []) {
        return false;
    }

    $lastPoint = $data[count($data) - 1];
    $lastTs = strtotime((string) $lastPoint['scannedAt']);
    $bucketEnd = strtotime($report['key'] . '-01 08:00:00 +1 month');
    $windowStart = $bucketEnd - 7 * 24 * 3600;

    return $lastTs !== false && $lastTs >= $windowStart;
}

function loadCreatorFanslyId(string $creatorsDir, string $login): ?string
{
    $file = $creatorsDir . '/' . $login . '.json';
    if (!is_file($file)) {
        return null;
    }

    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) {
        return null;
    }

    $id = $data['fansly']['id'] ?? null;

    return is_string($id) && $id !== '' ? $id : null;
}

/**
 * Removes a previously-written fansly/{login}.json when this run finds the
 * account has gone entirely stale (no rows, or every month too old) —
 * otherwise a graph could keep rendering from a leftover file even though
 * this run has nothing current to say about the account.
 */
function deleteStaleFanslyFile(string $login): void
{
    $file = FANSLY_DIR . '/' . $login . '.json';
    if (is_file($file)) {
        unlink($file);
    }
}

// ---------------------------------------------------------------------

$env = loadEnv(ENV_PATH);

try {
    $pdo = connectDb($env);
} catch (PDOException $e) {
    fwrite(STDERR, 'Failed to connect to database: ' . $e->getMessage() . "\n");
    exit(1);
}

if (!is_dir(FANSLY_DIR) && !mkdir(FANSLY_DIR, 0755, true) && !is_dir(FANSLY_DIR)) {
    fwrite(STDERR, 'Failed to create fansly/ directory.' . "\n");
    exit(1);
}

$accounts = json_decode((string) file_get_contents(ACCOUNTS_PATH), true);
if (!is_array($accounts)) {
    fwrite(STDERR, 'Failed to read/parse accounts.json.' . "\n");
    exit(1);
}

$written = 0;
$skippedNoFanslyId = 0;

foreach ($accounts as $account) {
    $login = strtolower(trim((string) ($account['slug'] ?? '')));
    if ($login === '') {
        continue;
    }

    $accountId = loadCreatorFanslyId(CREATORS_DIR, $login);
    if ($accountId === null) {
        $skippedNoFanslyId++;
        continue;
    }

    $rows = fetchFanslyRows($pdo, $accountId);
    if (empty($rows)) {
        fwrite(STDERR, "Failed to fetch rows for {$login}.\n");
        deleteStaleFanslyFile($login);
        continue;
    }
    $reports = groupRowsByMonth(dedupeConsecutiveRanks($rows));
    if (!is_array($reports)) {
        fwrite(STDERR, "Failed to group rows for {$login}.\n");
        continue;
    }
    $reports = array_values(array_filter($reports, 'monthHasRecentActivity'));
    if ($reports === []) {
        echo "Skipping fansly/{$login}.json: no month with a scan in its final 7 days.\n";
        deleteStaleFanslyFile($login);
        continue;
    }
    $stats = computeFanslyStats($rows);
    $outFile = FANSLY_DIR . '/' . $login . '.json';
    file_put_contents(
        $outFile,
        json_encode(
            ['stats' => $stats, 'reports' => $reports],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) . "\n"
    );
    $written++;
    echo "Wrote fansly/{$login}.json (" . count($rows) . " rows, " . count($reports) . " month(s))\n";
}

echo "Done. Written: {$written}, skipped (no Fansly accountId): {$skippedNoFanslyId}\n";
