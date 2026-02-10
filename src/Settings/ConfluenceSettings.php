<?php

declare(strict_types=1);

namespace Zynqa\FilamentConfluence\Settings;

use Spatie\LaravelSettings\Settings;

class ConfluenceSettings extends Settings
{
    // Future: Add global Confluence settings here
    // Example: public bool $enable_page_comments = false;

    public static function group(): string
    {
        return 'confluence';
    }
}
