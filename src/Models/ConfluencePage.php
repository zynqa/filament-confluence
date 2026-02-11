<?php

declare(strict_types=1);

namespace Zynqa\FilamentConfluence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Sushi\Sushi;
use Zynqa\FilamentConfluence\Services\ConfluenceService;

class ConfluencePage extends Model
{
    use Sushi;

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the rows for the Sushi model
     * This fetches pages from Confluence for the current user
     */
    public function getRows(): array
    {
        $user = auth()->user();

        if (! $user) {
            return [];
        }

        // Check if user has ANY Confluence access (spaces or pages)
        if (method_exists($user, 'hasConfluenceAccess') && ! $user->hasConfluenceAccess()) {
            return [];
        }

        try {
            $service = app(ConfluenceService::class);
            $pages = $service->getPagesForUser($user);

            // Filter out archived pages and only show current ones
            return collect($pages)
                ->filter(fn ($page) => ($page['status'] ?? 'current') === 'current')
                ->map(fn ($page) => $this->formatPageRow($page))
                ->values()
                ->all();
        } catch (\Exception $e) {
            Log::error('Error fetching Confluence pages for Sushi model', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Format a Confluence page API response into a row for Sushi
     */
    protected function formatPageRow(array $page): array
    {
        // Extract space key from URL if not provided directly
        $spaceKey = $page['spaceKey'] ?? null;

        if (! $spaceKey && isset($page['_links']['webui'])) {
            // Extract space key from URL like "/spaces/DPTM/pages/..."
            if (preg_match('/\/spaces\/([^\/]+)\//', $page['_links']['webui'], $matches)) {
                $spaceKey = $matches[1];
            }
        }

        return [
            'id' => $page['id'],
            'page_id' => $page['id'],
            'parent_id' => $page['parentId'] ?? null,
            'space_key' => $spaceKey,
            'title' => $page['title'] ?? 'Untitled',
            'content' => $this->extractContent($page),
            'status' => $page['status'] ?? 'current',
            'created_at' => $page['createdAt'] ?? now(),
            'updated_at' => $page['version']['createdAt'] ?? $page['updatedAt'] ?? now(),
            'author_name' => $page['version']['by']['displayName']
                ?? $page['authorDisplayName']
                ?? 'Unknown',
            'url' => $this->extractUrl($page),
        ];
    }

    /**
     * Extract content from page response
     * Handles both view (HTML) and storage formats
     */
    protected function extractContent(array $page): string
    {
        // Try different possible locations for content
        $content = $page['body']['view']['value']
            ?? $page['body']['storage']['value']
            ?? $page['body']['atlas_doc_format']['value']
            ?? $page['content']
            ?? '';

        return $content;
    }

    /**
     * Extract web URL from page response
     */
    protected function extractUrl(array $page): ?string
    {
        return $page['_links']['webui']
            ?? $page['_links']['base'].$page['_links']['webui']
            ?? $page['url']
            ?? null;
    }

    /**
     * Disable Sushi caching to prevent cross-user cache pollution
     *
     * ISSUE: Sushi uses a single SQLite file for ALL users, which causes users
     * to see cached pages from other users with different Confluence space access.
     * The sushiCacheReferenceName() method doesn't create separate storage - it only
     * replaces the entire cache, which doesn't always work correctly.
     *
     * SOLUTION: Disable caching to always fetch fresh data from the API.
     * The ConfluenceService already implements proper per-user Laravel caching,
     * so this doesn't significantly impact performance.
     *
     * For better performance in the future, consider implementing database-backed
     * storage instead of Sushi, or use Laravel Cache with proper per-user keys.
     */
    protected function sushiShouldCache(): bool
    {
        return false; // Disable caching to prevent cross-user data leakage
    }

    /**
     * Cache reference name per user with assignments hash
     * This ensures each user gets their own cached set of pages
     * and cache is automatically invalidated when assignments change
     */
    protected function sushiCacheReferenceName(): string
    {
        $user = auth()->user();
        $userId = $user?->id ?? 'guest';

        // Include hash of assignments and exclusions to bust cache when they change
        $assignmentsHash = md5(json_encode([
            method_exists($user, 'getConfluenceSpaceKeys')
                ? $user->getConfluenceSpaceKeys()
                : [],
            method_exists($user, 'getConfluencePageAssignments')
                ? $user->getConfluencePageAssignments()
                : [],
            method_exists($user, 'getConfluenceExcludedPages')
                ? $user->getConfluenceExcludedPages()
                : [],
        ]));

        return "confluence_pages_user_{$userId}_{$assignmentsHash}";
    }

    /**
     * How long to cache the Sushi data (in seconds)
     * Default: 5 minutes
     */
    protected function sushiCacheDuration(): int
    {
        return 300; // 5 minutes
    }

    /**
     * Define the schema for Sushi model
     */
    protected function sushiSchema(): array
    {
        return [
            'id' => 'string',
            'page_id' => 'string',
            'parent_id' => 'string',
            'space_key' => 'string',
            'title' => 'string',
            'content' => 'text',
            'status' => 'string',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'author_name' => 'string',
            'url' => 'string',
        ];
    }

    /**
     * Children relationship for tree structure
     */
    public function children()
    {
        return $this->hasMany(self::class, 'parent_id', 'page_id');
    }

    /**
     * Parent relationship for tree structure
     */
    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id', 'page_id');
    }

    /**
     * Get the name of the children relationship for tree functionality
     */
    public function getChildrenRelationshipName(): string
    {
        return 'children';
    }

    /**
     * Get the name of the parent key column for tree functionality
     */
    public function getParentKeyName(): string
    {
        return 'parent_id';
    }
}
