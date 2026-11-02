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
 * The Confluence account behind the API can read every space; what a user may see is decided
 * here, per user: the pages of their assigned spaces, their assigned pages (with descendants
 * when asked), minus their excluded pages (with descendants when asked). The page list, page
 * views, search and the image proxy all use that same list (see canUserSeePage()). Super
 * admins may see any page.
 *
 * filament-confluence.allowed_spaces is an optional extra limit on top of that: when it is set,
 * no space outside it is read for anyone. When it is empty, every space is allowed.
 */
class ConfluenceService
{
    private ConfluenceApiClient|ConfluenceMcpClient $client;

    /** @var array<string, list<string>> The page IDs each user may see, for this request. */
    private array $visiblePageIds = [];

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
     *
     * This does not check the user: use canUserSeePage() before showing it to anyone.
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

    /**
     * Every page the user may see: their spaces' pages and their assigned pages (and
     * descendants when asked), minus excluded pages (and descendants when asked).
     */
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
                $excludedTreeRoots = [];

                foreach ($user->getConfluenceExcludedPages() as $exclusion) {
                    $excludedId = (string) $exclusion['page_id'];
                    $excludedPageIds[] = $excludedId;

                    if (($exclusion['exclude_descendants'] ?? false) && ctype_digit($excludedId)) {
                        $excludedTreeRoots[] = $excludedId;

                        // Not gated on the excluded page itself loading: an exclusion
                        // must hold even when that page cannot be fetched right now.
                        foreach ($this->client->getPageChildren($excludedId) as $descendant) {
                            $excludedPageIds[] = (string) ($descendant['id'] ?? '');
                        }
                    }
                }

                if ($excludedPageIds !== []) {
                    $pages = $pages->reject(
                        fn ($page): bool => in_array((string) ($page['id'] ?? ''), $excludedPageIds, true)
                            || array_intersect($this->ancestorIdsOf($page), $excludedTreeRoots) !== [],
                    );
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

    /**
     * Whether the user may see a page: the one check behind page views, search and images.
     *
     * Super admins may see any page in an allowed space. Everyone else may see exactly the
     * pages getPagesForUser() lists for them.
     */
    public function canUserSeePage($user, string $pageId): bool
    {
        if (! $user || ! ctype_digit($pageId)) {
            return false;
        }

        if (self::isSuperAdmin($user)) {
            return $this->getPage($pageId) !== null;
        }

        return in_array($pageId, $this->visiblePageIdsFor($user), true);
    }

    public static function isSuperAdmin($user): bool
    {
        return is_object($user) && method_exists($user, 'hasRole') && $user->hasRole('super_admin');
    }

    public function getPageDescendants(string $pageId): array
    {
        if ($this->getPage($pageId) === null) {
            return [];
        }

        return $this->onlyAllowedPages($this->client->getPageChildren($pageId));
    }

    /**
     * The spaces the Confluence account can see, within the allowlist when one is set.
     * For admin screens that assign spaces; it does not check any user.
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
        if (! SpaceAllowlist::isValidKey($spaceKey) || ! SpaceAllowlist::allows($spaceKey)) {
            Log::warning('Refused to read a Confluence space outside the allowlist', [
                'space_key' => SpaceAllowlist::isValidKey($spaceKey) ? $spaceKey : '(invalid)',
            ]);

            return [];
        }

        return $this->onlyAllowedPages($this->client->getPagesInSpace($spaceKey));
    }

    /**
     * Pages the user may see whose title or text matches the query.
     *
     * The query is plain text, never CQL: it is quoted as a CQL string, and the space
     * restriction is added here, so a query cannot widen the search. Results are then
     * limited to the pages the user may see, so exclusions apply to search too.
     */
    public function searchPages(string $query, $user): array
    {
        $query = trim($query);

        if ($query === '' || ! $user) {
            return [];
        }

        $superAdmin = self::isSuperAdmin($user);

        if ($superAdmin) {
            $spaceClause = SpaceAllowlist::isRestricted()
                ? SpaceAllowlist::cqlSpaceClause(SpaceAllowlist::keys())
                : '';
        } else {
            $visiblePages = $this->getPagesForUser($user);
            $spaceClause = SpaceAllowlist::cqlSpaceClause(array_map($this->spaceKeyOf(...), $visiblePages));
        }

        if ($spaceClause === null) {
            return [];
        }

        $term = SpaceAllowlist::cqlString($query);
        $cql = 'type = page'.($spaceClause === '' ? '' : " AND {$spaceClause}")." AND (title ~ {$term} OR text ~ {$term})";

        $results = $this->onlyAllowedPages($this->client->searchPages($cql));

        if ($superAdmin) {
            return $results;
        }

        $visibleIds = $this->visiblePageIdsFor($user);

        return array_values(array_filter(
            $results,
            fn (array $page): bool => in_array((string) ($page['id'] ?? ''), $visibleIds, true),
        ));
    }

    /**
     * An image linked from page content. An attachment is served only when the user may
     * see its page; other protected Confluence URLs are refused by the client.
     */
    public function fetchImage(string $url, $user): ?Response
    {
        if (! $user) {
            return null;
        }

        $attachment = AttachmentUrl::parse($url);

        if ($attachment !== null && ! $this->canUserSeePage($user, $attachment['page_id'])) {
            return null;
        }

        return $this->client->fetchImage($url);
    }

    /**
     * Whether a page belongs to an allowed space. With an allowlist set, a page whose space
     * is unknown is refused.
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
     * @param  array<string, mixed>  $page
     * @return list<string>
     */
    private function ancestorIdsOf(array $page): array
    {
        $ids = is_array($page['ancestorIds'] ?? null) ? $page['ancestorIds'] : [];

        if (isset($page['parentId']) && is_scalar($page['parentId'])) {
            $ids[] = $page['parentId'];
        }

        return array_values(array_map('strval', array_filter($ids, 'is_scalar')));
    }

    /**
     * @return list<string>
     */
    private function visiblePageIdsFor($user): array
    {
        $key = md5((string) json_encode([
            $user->id ?? null,
            method_exists($user, 'getConfluenceSpaceKeys') ? $user->getConfluenceSpaceKeys() : [],
            method_exists($user, 'getConfluencePageAssignments') ? $user->getConfluencePageAssignments() : [],
            method_exists($user, 'getConfluenceExcludedPages') ? $user->getConfluenceExcludedPages() : [],
        ]));

        return $this->visiblePageIds[$key] ??= array_values(array_map(
            fn (array $page): string => (string) ($page['id'] ?? ''),
            $this->getPagesForUser($user),
        ));
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
        $this->visiblePageIds = [];
        $this->client->clearCache($pageId);
    }

    public function clearAllCache(): void
    {
        // This would need to be implemented to clear all Confluence caches
        // For now, we'll just log it
        Log::info('Clearing all Confluence caches');
    }
}
