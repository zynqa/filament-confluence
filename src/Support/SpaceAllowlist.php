<?php

declare(strict_types=1);

namespace Zynqa\FilamentConfluence\Support;

/**
 * An optional, global limit on the Confluence spaces the knowledge base may read.
 *
 * The main control is per user: each user only sees the spaces and pages assigned to them,
 * minus their excluded pages (see ConfluenceService::canUserSeePage()). This list is an extra
 * safety net on top of that. It comes from filament-confluence.allowed_spaces:
 *
 * - unset or empty: every space the API account can see is allowed;
 * - set: only those spaces are read, for every user, super admins included. A value with no
 *   valid space key in it allows nothing, so a typo never opens everything.
 */
final class SpaceAllowlist
{
    /** A Confluence space key: letters, digits and underscores, with a leading ~ for a personal space. */
    private const KEY_PATTERN = '/^~?[A-Za-z0-9_]+$/';

    /**
     * The allowed space keys. Configured values that are not valid space keys are dropped.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        $keys = [];

        foreach (self::configuredEntries() as $key) {
            if (self::isValidKey($key)) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Whether a global allowlist is configured at all.
     */
    public static function isRestricted(): bool
    {
        return self::configuredEntries() !== [];
    }

    public static function isValidKey(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) === 1;
    }

    /**
     * Whether a space may be read. Without an allowlist every space may.
     */
    public static function allows(mixed $key): bool
    {
        if (! self::isRestricted()) {
            return true;
        }

        return is_string($key) && in_array($key, self::keys(), true);
    }

    /**
     * Quote a value as a CQL string literal, so it can never end the string or add a clause.
     */
    public static function cqlString(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    /**
     * The CQL clause limiting a query to the given space keys, or null when none is valid.
     *
     * @param  array<mixed>  $keys
     */
    public static function cqlSpaceClause(array $keys): ?string
    {
        $valid = array_values(array_unique(array_filter(
            $keys,
            fn ($key): bool => is_string($key) && self::isValidKey($key),
        )));

        if ($valid === []) {
            return null;
        }

        return 'space IN ('.implode(', ', array_map(self::cqlString(...), $valid)).')';
    }

    /**
     * The configured entries, trimmed, with blanks dropped.
     *
     * @return list<string>
     */
    private static function configuredEntries(): array
    {
        $configured = config('filament-confluence.allowed_spaces', []);

        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        if (! is_array($configured)) {
            return [];
        }

        $entries = [];

        foreach ($configured as $entry) {
            $entry = is_scalar($entry) ? trim((string) $entry) : '';

            if ($entry !== '') {
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}
