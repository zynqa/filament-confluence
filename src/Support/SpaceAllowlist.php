<?php

declare(strict_types=1);

namespace Zynqa\FilamentConfluence\Support;

/**
 * The Confluence spaces the knowledge base may read.
 *
 * The API account can usually see far more of Confluence than the portal should show, so
 * every space, page, search and image is checked against this list before it is used. The
 * list comes from filament-confluence.allowed_spaces. An empty list allows nothing.
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
        $configured = config('filament-confluence.allowed_spaces', []);

        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        if (! is_array($configured)) {
            return [];
        }

        $keys = [];

        foreach ($configured as $key) {
            $key = is_scalar($key) ? trim((string) $key) : '';

            if (self::isValidKey($key)) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    public static function isValidKey(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) === 1;
    }

    public static function allows(mixed $key): bool
    {
        return is_string($key) && in_array($key, self::keys(), true);
    }

    /**
     * The CQL clause that limits a query to the allowed spaces, or null when none are allowed.
     */
    public static function cqlClause(): ?string
    {
        $keys = self::keys();

        if ($keys === []) {
            return null;
        }

        return 'space IN ('.implode(', ', array_map(self::cqlString(...), $keys)).')';
    }

    /**
     * Quote a value as a CQL string literal, so it can never end the string or add a clause.
     */
    public static function cqlString(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
