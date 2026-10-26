<?php

declare(strict_types=1);

/**
 * Shared accounts.json helpers (required by update.php, reorder-xhandles.php,
 * migrate-accounts-slug.php).
 *
 * Account identity is the "slug" field (lowercase [a-z0-9_-]): it names
 * creators/{slug}.json, avatarsLarge/banners/{slug}.webp, /c/{slug}/ and the
 * "login" in fansly.json/rplay.json. "channel" is a free-text display name;
 * "twitch"/"youtube" are the platform handles.
 */

/** The account's identity slug, or '' if unset. */
function accountSlug(array $account): string
{
    return strtolower(trim((string) ($account['slug'] ?? '')));
}

function sanitizeSlug(string $value): string
{
    return preg_replace('/[^a-z0-9_-]+/', '', strtolower(trim($value))) ?? '';
}

/** Candidate slug for an account without one: twitch > youtube > fansly > sanitised channel. */
function deriveAccountSlug(array $account): string
{
    foreach (['twitch', 'youtube', 'fansly', 'channel'] as $field) {
        $slug = sanitizeSlug((string) ($account[$field] ?? ''));
        if ($slug !== '') {
            return $slug;
        }
    }

    return '';
}

/**
 * Fills in "slug" for every account missing one (inserted as the first key).
 * Throws on a duplicate slug, so callers never write a colliding file.
 * Returns the number of slugs added.
 */
function ensureAccountSlugs(array &$accounts): int
{
    $seen = [];
    foreach ($accounts as $account) {
        $slug = is_array($account) ? accountSlug($account) : '';
        if ($slug === '') {
            continue;
        }
        if (isset($seen[$slug])) {
            throw new RuntimeException("Duplicate slug in accounts.json: {$slug}");
        }
        $seen[$slug] = true;
    }

    $added = 0;
    foreach ($accounts as &$account) {
        if (!is_array($account) || accountSlug($account) !== '') {
            continue;
        }
        $slug = deriveAccountSlug($account);
        if ($slug === '') {
            continue;
        }
        if (isset($seen[$slug])) {
            $label = (string) ($account['channel'] ?? $slug);
            throw new RuntimeException("Generated slug \"{$slug}\" for \"{$label}\" collides with an existing account; set its slug manually.");
        }
        $seen[$slug] = true;
        unset($account['slug']);
        $account = ['slug' => $slug] + $account;
        $added++;
    }
    unset($account);

    return $added;
}

/**
 * Writes accounts.json in the repo's hand-formatted style: every key kept in
 * its original order; xHandles/bskyHandle as inline arrays; "other" multi-line;
 * "channel"/"socials" keep their casing; every other scalar is lowercased.
 */
function writeAccountsJson(string $path, array $accounts): void
{
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
                // [name, link]: only the name is lowercased, the link is a URL.
                $bskyHandle = is_array($value) ? array_values($value) : [];
                $bskyHandle = array_map(
                    static fn (string $h, int $i): string => json_encode($i === 0 ? strtolower($h) : $h, JSON_UNESCAPED_SLASHES),
                    $bskyHandle,
                    array_keys($bskyHandle)
                );
                $fieldLines[] = "    \"bskyHandle\": [" . implode(', ', $bskyHandle) . "]";
            } elseif ($key === 'other') {
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
            } elseif ($key === 'channel') {
                $fieldLines[] = sprintf(
                    '    %s: %s',
                    json_encode((string) $key, JSON_UNESCAPED_SLASHES),
                    json_encode((string) $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
            } elseif ($key === 'socials') {
                $fieldLines[] = sprintf(
                    '    %s: %s',
                    json_encode((string) $key, JSON_UNESCAPED_SLASHES),
                    json_encode((string) $value, JSON_UNESCAPED_SLASHES)
                );
            } elseif (is_bool($value)) {
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
