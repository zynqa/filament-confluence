<?php

declare(strict_types=1);

namespace Zynqa\FilamentConfluence\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ConfluenceApiClient
{
    private string $baseUrl;

    private string $auth;

    public function __construct()
    {
        $config = config('filament-confluence');
        $this->baseUrl = rtrim($config['confluence_url'], '/');

        // Support both auth methods
        if ($config['auth_type'] === 'bearer') {
            $this->auth = 'Bearer '.$config['api_token'];
        } else {
            $email = $config['email'];
            $token = $config['api_token'];
            $this->auth = 'Basic '.base64_encode("{$email}:{$token}");
        }
    }

    public function getPage(string $pageId, string $format = 'markdown'): ?array
    {
        $cacheKey = "confluence_page_{$pageId}_{$format}";
        $cacheTtl = config('filament-confluence.cache.pages', 1800);

        return Cache::remember($cacheKey, $cacheTtl, function () use ($pageId, $format) {
            try {
                // Use 'view' format which returns HTML that can be displayed
                $bodyFormat = $format === 'markdown'
                    ? 'view'
                    : 'storage';

                $response = Http::withHeaders(['Authorization' => $this->auth])
                    ->timeout(30)
                    ->get("{$this->baseUrl}/wiki/api/v2/pages/{$pageId}", [
                        'body-format' => $bodyFormat,
                    ]);

                if (! $response->successful()) {
                    Log::error('Failed to fetch Confluence page', [
                        'page_id' => $pageId,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return null;
                }

                return $response->json();
            } catch (\Exception $e) {
                Log::error('Exception fetching Confluence page', [
                    'page_id' => $pageId,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        });
    }

    /**
     * Get space ID from space key
     * Required because v2 API uses space IDs instead of space keys
     */
    private function getSpaceIdFromKey(string $spaceKey): ?string
    {
        $cacheKey = "confluence_space_id_{$spaceKey}";
        $cacheTtl = config('filament-confluence.cache.spaces', 1800);

        return Cache::remember($cacheKey, $cacheTtl, function () use ($spaceKey) {
            try {
                $spaces = $this->getSpaces();
                $space = collect($spaces)->firstWhere('key', $spaceKey);

                if (! $space) {
                    Log::warning('Space not found for key', [
                        'space_key' => $spaceKey,
                    ]);

                    return null;
                }

                return $space['id'] ?? null;
            } catch (\Exception $e) {
                Log::error('Exception getting space ID from key', [
                    'space_key' => $spaceKey,
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
                // Get space ID from space key - required for v2 API
                $spaceId = $this->getSpaceIdFromKey($spaceKey);

                if (! $spaceId) {
                    Log::error('Cannot fetch pages: space ID not found', [
                        'space_key' => $spaceKey,
                    ]);

                    return [];
                }

                $allPages = [];
                $cursor = null;

                do {
                    // Use correct v2 API endpoint with space ID
                    $params = array_merge([
                        'status' => 'current',
                        'limit' => 250, // Max allowed
                    ], $options);

                    if ($cursor) {
                        $params['cursor'] = $cursor;
                    }

                    $response = Http::withHeaders(['Authorization' => $this->auth])
                        ->timeout(30)
                        ->get("{$this->baseUrl}/wiki/api/v2/spaces/{$spaceId}/pages", $params);

                    if (! $response->successful()) {
                        Log::error('Failed to fetch pages in space', [
                            'space_key' => $spaceKey,
                            'space_id' => $spaceId,
                            'status' => $response->status(),
                            'body' => $response->body(),
                        ]);

                        break;
                    }

                    $data = $response->json();
                    $pages = $data['results'] ?? [];

                    $allPages = array_merge($allPages, $pages);

                    // Check for pagination
                    $cursor = $data['_links']['next'] ?? null;
                } while ($cursor);

                return $allPages;
            } catch (\Exception $e) {
                Log::error('Exception fetching space pages', [
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
                $response = Http::withHeaders(['Authorization' => $this->auth])
                    ->timeout(30)
                    ->get("{$this->baseUrl}/wiki/api/v2/pages/{$pageId}/children", [
                        'limit' => 250,
                    ]);

                if (! $response->successful()) {
                    Log::error('Failed to fetch page children', [
                        'page_id' => $pageId,
                        'status' => $response->status(),
                    ]);

                    return [];
                }

                $data = $response->json();
                $children = $data['results'] ?? [];

                // Recursively get descendants
                $descendants = [];
                foreach ($children as $child) {
                    $descendants[] = $child;
                    $descendants = array_merge(
                        $descendants,
                        $this->getPageChildren($child['id'])
                    );
                }

                return $descendants;
            } catch (\Exception $e) {
                Log::error('Exception fetching page children', [
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
                $response = Http::withHeaders(['Authorization' => $this->auth])
                    ->timeout(30)
                    ->get("{$this->baseUrl}/wiki/api/v2/spaces", [
                        'limit' => 250,
                    ]);

                if (! $response->successful()) {
                    Log::error('Failed to fetch Confluence spaces', [
                        'status' => $response->status(),
                    ]);

                    return [];
                }

                $data = $response->json();

                return $data['results'] ?? [];
            } catch (\Exception $e) {
                Log::error('Exception fetching Confluence spaces', [
                    'error' => $e->getMessage(),
                ]);

                return [];
            }
        });
    }

    public function searchPages(string $cql): array
    {
        try {
            $response = Http::withHeaders(['Authorization' => $this->auth])
                ->timeout(30)
                ->get("{$this->baseUrl}/wiki/rest/api/search", [
                    'cql' => $cql,
                    'limit' => 100,
                ]);

            if (! $response->successful()) {
                Log::error('Failed to search Confluence pages', [
                    'cql' => $cql,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $data = $response->json();

            return $data['results'] ?? [];
        } catch (\Exception $e) {
            Log::error('Exception searching Confluence pages', [
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

    public function clearSpaceCache(string $spaceKey): void
    {
        Cache::forget("confluence_space_pages_{$spaceKey}");
        Cache::forget("confluence_space_id_{$spaceKey}");
    }
}
