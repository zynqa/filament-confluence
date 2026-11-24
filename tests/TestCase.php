<?php

declare(strict_types=1);

namespace Zynqa\FilamentConfluence\Tests;

use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'filament-confluence.connection' => 'direct',
            'filament-confluence.confluence_url' => 'https://example.atlassian.net',
            'filament-confluence.api_url' => 'https://api.atlassian.com/ex/confluence/test-cloud',
            'filament-confluence.email' => 'portal@example.com',
            'filament-confluence.api_token' => 'test-token',
            'filament-confluence.auth_type' => 'basic',
            'filament-confluence.allowed_spaces' => '',
            'filament-confluence.content_format' => 'markdown',
            'filament-confluence.cache.pages' => 1800,
            'filament-confluence.cache.spaces' => 1800,
        ]);
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('app.key', 'base64:2fl+Ktvkfl+Fuz4Qp/A75G2RTiWVA/Zo4j96tYwkokw=');
    }
}
