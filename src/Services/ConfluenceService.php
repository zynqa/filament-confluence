<?php

declare(strict_types=1);

namespace Zynqa\FilamentConfluence\Services;

use Exception;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Zynqa\FilamentConfluence\Support\AttachmentUrl;
use Zynqa\FilamentConfluence\Support\SpaceAllowlist;

/**
 * The knowledge base's view of Confluence.
 *
 * Only spaces in filament-confluence.allowed_spaces are ever read: spaces outside it are not
 * listed, their pages are not returned, searches cannot reach them and their images are not
 * served. The Confluence account behind the API can see more than the portal should show.
 */
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

    /**
     * A page, or null when it does not exist or is not a page in an allowed space.
     */
    public function getPage(string $pageId): ?array
    {
        if (! ctype_digit($pageId)) {
            return null;
        }

        $format = config('filament-confluence.content_format', 'markdown');
        $page = $this->client->getPage($pageId, $format);

        return is_array($page) && $this->isAllowedPage($page) ? $page : null;
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
                    $spacePages = $this->getPagesInSpace((string) $spaceKey);
                    $pages = array_merge($pages, $spacePages);
                }
            }

            // 2. Get explicitly assigned pages
            if (method_exists($user, 'getConfluencePageAssignments')) {
                foreach ($user->getConfluencePageAssignments() as $assignment) {
                    $pageId = (string) $assignment['page_id'];
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
                        $descendants = $this->getPageDescendants((string) $exclusion['page_id']);
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
        } catch (Exception $e) {
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
        if ($this->getPage($pageId) === null) {
            return [];
        }

        return $this->onlyAllowedPages($this->client->getPageChildren($pageId));
    }

    /**
     * The allowed spaces the Confluence account can see.
     */
    public function getSpaces(): array
    {
        return array_values(array_filter(
            $this->client->getSpaces(),
            fn ($space): bool => is_array($space) && SpaceAllowlist::allows($space['key'] ?? null),
        ));
    }

    public function getPagesInSpace(string $spaceKey): array
    {
        if (! SpaceAllowlist::allows($spaceKey)) {
            Log::warning('Refused to read a Confluence space outside the allowlist', [
                'space_key' => SpaceAllowlist::isValidKey($spaceKey) ? $spaceKey : '(invalid)',
            ]);

            return [];
        }

        return $this->onlyAllowedPages($this->client->getPagesInSpace($spaceKey));
    }

    /**
     * Pages in the allowed spaces whose title or text matches the query.
     *
     * The query is plain text, never CQL: it is quoted as a CQL string, and the space
     * restriction is added here, so a query cannot widen the search.
     */
    public function searchPages(string $query): array
    {
        $query = trim($query);
        $spaceClause = SpaceAllowlist::cqlClause();

        if ($query === '' || $spaceClause === null) {
            return [];
        }

        $term = SpaceAllowlist::cqlString($query);
        $cql = "type = page AND {$spaceClause} AND (title ~ {$term} OR text ~ {$term})";

        return $this->onlyAllowedPages($this->client->searchPages($cql));
    }

    /**
     * An image linked from page content, served only when its page is in an allowed space.
     */
    public function fetchImage(string $url): ?Response
    {
        $attachment = AttachmentUrl::parse($url);

        if ($attachment !== null && $this->getPage($attachment['page_id']) === null) {
            return null;
        }

        return $this->client->fetchImage($url);
    }

    /**
     * Whether a page belongs to an allowed space. A page whose space is unknown is refused.
     *
     * @param  array<string, mixed>  $page
     */
    private function isAllowedPage(array $page): bool
    {
        if (($page['type'] ?? 'page') !== 'page') {
            return false;
        }

        return SpaceAllowlist::allows($this->spaceKeyOf($page));
    }

    /**
     * @param  array<string, mixed>  $page
     */
    private function spaceKeyOf(array $page): ?string
    {
        if (isset($page['spaceKey']) && is_string($page['spaceKey'])) {
            return $page['spaceKey'];
        }

        $webui = $page['_links']['webui'] ?? null;

        if (is_string($webui) && preg_match('#^/spaces/([^/]+)/#', $webui, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @param  array<mixed>  $pages
     * @return list<array<string, mixed>>
     */
    private function onlyAllowedPages(array $pages): array
    {
        return array_values(array_filter(
            $pages,
            fn ($page): bool => is_array($page) && $this->isAllowedPage($page),
        ));
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
