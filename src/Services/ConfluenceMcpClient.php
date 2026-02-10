<?php

declare(strict_types=1);

namespace Zynqa\FilamentConfluence\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ConfluenceMcpClient
{
    private string $cloudId;

    public function __construct()
    {
        $this->cloudId = config('filament-confluence.cloud_id');
    }

    public function getPage(string $pageId, string $format = 'markdown'): ?array
    {
        $cacheKey = "confluence_page_{$pageId}_{$format}";
        $cacheTtl = config('filament-confluence.cache.pages', 1800);

        return Cache::remember($cacheKey, $cacheTtl, function () use ($pageId, $format) {
            try {
                if (! app()->bound('mcp')) {
                    Log::warning('MCP service not bound, cannot fetch page');

                    return null;
                }

                $result = app('mcp')->call('mcp__atlassian-tescomobile__getConfluencePage', [
                    'cloudId' => $this->cloudId,
                    'pageId' => $pageId,
                    'contentFormat' => $format,
                ]);

                return $result;
            } catch (\Exception $e) {
                Log::error('Exception fetching Confluence page via MCP', [
                    'page_id' => $pageId,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        });
    }

    public function getPagesInSpace(string $spaceKey, array $options = []): array
    {
        $cacheKey = "confluence_space_pages_{$spaceKey}";
        $cacheTtl = config('filament-confluence.cache.spaces', 1800);

        return Cache::remember($cacheKey, $cacheTtl, function () use ($spaceKey, $options) {
            try {
                if (! app()->bound('mcp')) {
                    Log::warning('MCP service not bound, cannot fetch space pages');

                    return [];
                }

                // First get the space to retrieve the spaceId
                $spaces = $this->getSpaces();
                $space = collect($spaces)->firstWhere('key', $spaceKey);

                if (! $space) {
                    Log::warning('Space not found', ['space_key' => $spaceKey]);

                    return [];
                }

                $result = app('mcp')->call('mcp__atlassian-tescomobile__getPagesInConfluenceSpace', [
                    'cloudId' => $this->cloudId,
                    'spaceId' => (string) $space['id'],
                    'status' => $options['status'] ?? 'current',
                    'limit' => $options['limit'] ?? 100,
                ]);

                return $result['results'] ?? [];
            } catch (\Exception $e) {
                Log::error('Exception fetching space pages via MCP', [
                    'space_key' => $spaceKey,
                    'error' => $e->getMessage(),
                ]);

                return [];
            }
        });
    }

    public function getPageChildren(string $pageId): array
    {
        $cacheKey = "confluence_page_children_{$pageId}";
        $cacheTtl = config('filament-confluence.cache.pages', 1800);

        return Cache::remember($cacheKey, $cacheTtl, function () use ($pageId) {
            try {
                if (! app()->bound('mcp')) {
                    Log::warning('MCP service not bound, cannot fetch page children');

                    return [];
                }

                $result = app('mcp')->call('mcp__atlassian-tescomobile__getConfluencePageDescendants', [
                    'cloudId' => $this->cloudId,
                    'pageId' => $pageId,
                ]);

                return $result['results'] ?? [];
            } catch (\Exception $e) {
                Log::error('Exception fetching page children via MCP', [
                    'page_id' => $pageId,
                    'error' => $e->getMessage(),
                ]);

                return [];
            }
        });
    }

    public function getSpaces(): array
    {
        $cacheKey = 'confluence_spaces';
        $cacheTtl = config('filament-confluence.cache.spaces', 1800);

        return Cache::remember($cacheKey, $cacheTtl, function () {
            try {
                if (! app()->bound('mcp')) {
                    Log::warning('MCP service not bound, cannot fetch spaces');

                    return [];
                }

                $result = app('mcp')->call('mcp__atlassian-tescomobile__getConfluenceSpaces', [
                    'cloudId' => $this->cloudId,
                    'limit' => 250,
                ]);

                return $result['results'] ?? [];
            } catch (\Exception $e) {
                Log::error('Exception fetching Confluence spaces via MCP', [
                    'error' => $e->getMessage(),
                ]);

                return [];
            }
        });
    }

    public function searchPages(string $cql): array
    {
        try {
            if (! app()->bound('mcp')) {
                Log::warning('MCP service not bound, cannot search pages');

                return [];
            }

            $result = app('mcp')->call('mcp__atlassian-tescomobile__searchConfluenceUsingCql', [
                'cloudId' => $this->cloudId,
                'cql' => $cql,
                'limit' => 100,
            ]);

            return $result['results'] ?? [];
        } catch (\Exception $e) {
            Log::error('Exception searching Confluence pages via MCP', [
                'cql' => $cql,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function clearCache(string $pageId): void
    {
        Cache::forget("confluence_page_{$pageId}_markdown");
        Cache::forget("confluence_page_{$pageId}_adf");
        Cache::forget("confluence_page_children_{$pageId}");
    }
}
