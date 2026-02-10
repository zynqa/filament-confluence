<?php

declare(strict_types=1);

namespace Zynqa\FilamentConfluence\Services;

use Illuminate\Support\Facades\Log;

class ConfluenceService
{
    private ConfluenceApiClient|ConfluenceMcpClient $client;

    public function __construct()
    {
        $useMcp = config('filament-confluence.connection') === 'mcp'
            && app()->bound('mcp');

        $this->client = $useMcp
            ? new ConfluenceMcpClient
            : new ConfluenceApiClient;

        Log::info('Confluence service initialized', [
            'client_type' => $useMcp ? 'MCP' : 'Direct API',
        ]);
    }

    public function getPage(string $pageId): ?array
    {
        $format = config('filament-confluence.content_format', 'markdown');

        return $this->client->getPage($pageId, $format);
    }

    public function getPagesForUser($user): array
    {
        if (! $user) {
            return [];
        }

        // Check if user has ANY Confluence access (spaces or pages)
        if (! method_exists($user, 'hasConfluenceAccess') || ! $user->hasConfluenceAccess()) {
            return [];
        }

        $pages = [];

        try {
            // 1. Get pages from user's assigned spaces
            if (method_exists($user, 'getConfluenceSpaceKeys')) {
                foreach ($user->getConfluenceSpaceKeys() as $spaceKey) {
                    $spacePages = $this->client->getPagesInSpace($spaceKey);
                    $pages = array_merge($pages, $spacePages);
                }
            }

            // 2. Get explicitly assigned pages
            if (method_exists($user, 'getConfluencePageAssignments')) {
                foreach ($user->getConfluencePageAssignments() as $assignment) {
                    $pageId = $assignment['page_id'];
                    $includeDescendants = $assignment['include_descendants'];

                    // Fetch the page itself
                    $page = $this->getPage($pageId);
                    if ($page) {
                        $pages[] = $page;

                        // Fetch descendants if requested
                        if ($includeDescendants) {
                            $descendants = $this->getPageDescendants($pageId);
                            $pages = array_merge($pages, $descendants);
                        }
                    }
                }
            }

            // 3. Deduplicate by page ID
            $pages = collect($pages)->unique('id')->values();

            // 4. Filter out excluded pages
            if (method_exists($user, 'getConfluenceExcludedPages')) {
                $excludedPageIds = [];

                foreach ($user->getConfluenceExcludedPages() as $exclusion) {
                    // Convert to string for consistent comparison
                    $excludedPageIds[] = (string) $exclusion['page_id'];

                    // If exclude_descendants is true, get all descendant IDs
                    if ($exclusion['exclude_descendants'] ?? false) {
                        $descendants = $this->getPageDescendants($exclusion['page_id']);
                        foreach ($descendants as $descendant) {
                            $excludedPageIds[] = (string) ($descendant['id'] ?? '');
                        }
                    }
                }

                // Remove excluded pages (convert page IDs to strings for comparison)
                if (! empty($excludedPageIds)) {
                    $pages = $pages->reject(fn ($page) => in_array((string) ($page['id'] ?? ''), $excludedPageIds, true));
                }
            }

            return $pages->values()->all();
        } catch (\Exception $e) {
            Log::error('Error getting pages for user', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [];
        }
    }

    public function getPageDescendants(string $pageId): array
    {
        return $this->client->getPageChildren($pageId);
    }

    public function getSpaces(): array
    {
        return $this->client->getSpaces();
    }

    public function getPagesInSpace(string $spaceKey): array
    {
        return $this->client->getPagesInSpace($spaceKey);
    }

    public function searchPages(string $cql): array
    {
        return $this->client->searchPages($cql);
    }

    public function clearPageCache(string $pageId): void
    {
        $this->client->clearCache($pageId);
    }

    public function clearAllCache(): void
    {
        // This would need to be implemented to clear all Confluence caches
        // For now, we'll just log it
        Log::info('Clearing all Confluence caches');
    }
}
