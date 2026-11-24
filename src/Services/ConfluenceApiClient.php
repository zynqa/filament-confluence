<?php

declare(strict_types=1);

namespace Zynqa\FilamentConfluence\Services;

use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Zynqa\FilamentConfluence\Support\AttachmentUrl;

/**
 * Confluence REST API client.
 *
 * Every call goes to the API base (filament-confluence.api_url), falling back to the site
 * (filament-confluence.confluence_url). A scoped service-account token only works on the
 * API gateway, https://api.atlassian.com/ex/confluence/<cloudId>, so the two can differ.
 * Links shown to people keep pointing at the site.
 *
 * Page, child-page and attachment metadata reads use the v2 REST API (/wiki/api/v2). The
 * v1 content endpoints (/wiki/rest/api/content) are deprecated and reject a token that
 * carries the granular page and attachment scopes, with 401 "scope does not match". Space
 * listing and CQL search stay on v1, which those tokens still accept. Some v2 routes
 * (/spaces?keys=, /direct-children, /ancestors, /folders) also return 401, so space keys
 * are resolved through v1 /space, children through /pages/{id}/children, and ancestors by
 * walking parentId. Attachment files are still downloaded from the v1 download URL, which
 * the token accepts.
 *
 * Results are reshaped into the page shape the rest of the package reads: id, title,
 * status, spaceKey, parentId, ancestorIds, createdAt, version.createdAt,
 * body.{view|storage}.value and _links.webui.
 */
class ConfluenceApiClient
{
    /** Results per request; the v2 API allows up to 250, v1 listings rather less. */
    private const PAGE_SIZE = 100;

    /** Upper bound on paginated requests, so a misbehaving API cannot loop forever. */
    private const MAX_REQUESTS = 50;

    /** Upper bound on walking parentId, so a cycle cannot loop forever. */
    private const MAX_ANCESTORS = 50;

    private const PAGE_EXPAND = 'space,version,ancestors,history';

    private string $apiUrl;

    private string $auth;

    public function __construct()
    {
        $config = config('filament-confluence');
        $this->apiUrl = rtrim((string) ($config['api_url'] ?? '') ?: (string) $config['confluence_url'], '/');

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
                // 'view' is rendered HTML that can be displayed; 'storage' is the raw format.
                $bodyFormat = $format === 'markdown' ? 'view' : 'storage';

                $response = $this->request()->get($this->url('/api/v2/pages/'.rawurlencode($pageId)), [
                    'body-format' => $bodyFormat,
                ]);

                if (! $response->successful()) {
                    Log::error('Failed to fetch Confluence page', [
                        'page_id' => $pageId,
                        'status' => $response->status(),
                    ]);

                    return null;
                }

                $content = $response->json();

                return is_array($content) ? $this->normaliseV2Page($content, includeAncestors: true) : null;
            } catch (Exception $e) {
                Log::error('Exception fetching Confluence page', [
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
                $spaceId = $this->spaceIdForKey($spaceKey);

                if ($spaceId === null) {
                    return [];
                }

                $results = $this->getAllV2('/api/v2/pages', array_merge([
                    'space-id' => $spaceId,
                    'status' => 'current',
                ], $options), ['space_key' => $spaceKey], 'Failed to fetch pages in space');

                return array_map(
                    fn (array $page): array => $this->normaliseV2Page($page, spaceKey: $spaceKey),
                    $results,
                );
            } catch (Exception $e) {
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
                $children = array_map(
                    fn (array $page): array => $this->normaliseV2Page($page, parentId: $pageId),
                    $this->getAllV2(
                        '/api/v2/pages/'.rawurlencode($pageId).'/children',
                        [],
                        ['page_id' => $pageId],
                        'Failed to fetch page children',
                    ),
                );

                $descendants = [];
                foreach ($children as $child) {
                    $descendants[] = $child;
                    $descendants = array_merge($descendants, $this->getPageChildren($child['id']));
                }

                return $descendants;
            } catch (Exception $e) {
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
                $spaces = $this->getAll('/rest/api/space', [], [], 'Failed to fetch Confluence spaces');

                return array_map(function (array $space): array {
                    $id = isset($space['id']) ? (string) $space['id'] : null;
                    $key = is_string($space['key'] ?? null) ? $space['key'] : null;

                    if ($id !== null && $key !== null && $key !== '') {
                        $this->rememberSpace($key, $id);
                    }

                    return [
                        'id' => $id,
                        'key' => $key,
                        'name' => $space['name'] ?? ($space['key'] ?? ''),
                        'type' => $space['type'] ?? null,
                        'status' => $space['status'] ?? null,
                    ];
                }, $spaces);
            } catch (Exception $e) {
                Log::error('Exception fetching Confluence spaces', [
                    'error' => $e->getMessage(),
                ]);

                return [];
            }
        });
    }

    /**
     * Run a CQL search for pages. The CQL is sent as given: build it with
     * ConfluenceService::searchPages(), which limits it to what the user may see.
     */
    public function searchPages(string $cql): array
    {
        try {
            $response = $this->request()->get($this->url('/rest/api/content/search'), [
                'cql' => $cql,
                'limit' => self::PAGE_SIZE,
                'expand' => self::PAGE_EXPAND,
            ]);

            if (! $response->successful()) {
                Log::error('Failed to search Confluence pages', [
                    'status' => $response->status(),
                ]);

                return [];
            }

            return array_map($this->normaliseV1Page(...), array_values(array_filter(
                (array) ($response->json('results') ?? []),
                'is_array',
            )));
        } catch (Exception $e) {
            Log::error('Exception searching Confluence pages', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Fetch an image linked from page content.
     *
     * Attachments are downloaded through the API's attachment download endpoint, the only
     * place the token is accepted. Anything else that is protected is refused. Public static
     * assets on the site, such as emoticons, are fetched without credentials, so the token is
     * never sent anywhere but the API base.
     */
    public function fetchImage(string $url): ?Response
    {
        try {
            $attachment = AttachmentUrl::parse($url);

            if ($attachment === null) {
                return AttachmentUrl::isProtected($url) ? null : Http::timeout(30)->get($url);
            }

            $downloadUrl = $this->resolveAttachmentDownloadUrl($attachment);

            return $downloadUrl === null
                ? null
                : Http::withHeaders(['Authorization' => $this->auth])->timeout(30)->get($downloadUrl);
        } catch (Exception $e) {
            Log::error('Exception fetching Confluence image', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The API download URL for an attachment, or null when the page has no such attachment.
     *
     * @param  array{page_id: string, filename: ?string, attachment_id: ?string}  $attachment
     */
    private function resolveAttachmentDownloadUrl(array $attachment): ?string
    {
        $attachmentId = $attachment['attachment_id']
            ?? $this->getPageAttachmentIds($attachment['page_id'])[(string) $attachment['filename']]
            ?? null;

        if (! is_string($attachmentId) || $attachmentId === '') {
            return null;
        }

        return $this->url('/rest/api/content/'.rawurlencode($attachment['page_id'])
            .'/child/attachment/'.rawurlencode($attachmentId).'/download');
    }

    /**
     * Attachment IDs for a page's attachments, keyed by filename.
     *
     * Cached per page so that one lookup serves every image on an article.
     *
     * @return array<string, string>
     */
    private function getPageAttachmentIds(string $pageId): array
    {
        $cacheKey = "confluence_page_{$pageId}_attachment_ids";
        $cacheTtl = config('filament-confluence.cache.pages', 1800);

        return Cache::remember($cacheKey, $cacheTtl, function () use ($pageId) {
            $ids = [];

            $attachments = $this->getAllV2(
                '/api/v2/pages/'.rawurlencode($pageId).'/attachments',
                [],
                ['page_id' => $pageId],
                'Failed to fetch Confluence page attachments',
            );

            foreach ($attachments as $attachment) {
                $title = $attachment['title'] ?? null;
                $id = $attachment['id'] ?? null;

                if (is_string($title) && $title !== '' && is_scalar($id) && (string) $id !== '') {
                    $ids[$title] = (string) $id;
                }
            }

            return $ids;
        });
    }

    /**
     * Every result of a paginated v1 listing (start/limit).
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $logContext
     * @return list<array<string, mixed>>
     */
    private function getAll(string $path, array $params, array $logContext, string $failureMessage): array
    {
        $results = [];
        $start = 0;

        for ($request = 0; $request < self::MAX_REQUESTS; $request++) {
            $response = $this->request()->get($this->url($path), array_merge($params, [
                'start' => $start,
                'limit' => self::PAGE_SIZE,
            ]));

            if (! $response->successful()) {
                Log::error($failureMessage, array_merge($logContext, ['status' => $response->status()]));

                break;
            }

            $batch = array_values(array_filter((array) ($response->json('results') ?? []), 'is_array'));
            $results = array_merge($results, $batch);

            if ($batch === [] || $response->json('_links.next') === null) {
                break;
            }

            $start += count($batch);
        }

        return $results;
    }

    /**
     * Every result of a paginated v2 listing, following _links.next (a cursor URL).
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $logContext
     * @return list<array<string, mixed>>
     */
    private function getAllV2(string $path, array $params, array $logContext, string $failureMessage): array
    {
        $results = [];
        $url = $this->url($path);
        $query = array_merge($params, ['limit' => self::PAGE_SIZE]);

        for ($request = 0; $request < self::MAX_REQUESTS; $request++) {
            // A later page's URL already carries the cursor. Passing an empty
            // query array would make Guzzle drop that cursor and repeat page 1.
            $response = $query === null
                ? $this->request()->get($url)
                : $this->request()->get($url, $query);

            if (! $response->successful()) {
                Log::error($failureMessage, array_merge($logContext, ['status' => $response->status()]));

                break;
            }

            $batch = array_values(array_filter((array) ($response->json('results') ?? []), 'is_array'));
            $results = array_merge($results, $batch);

            $next = $response->json('_links.next');

            if ($batch === [] || ! is_string($next) || $next === '') {
                break;
            }

            $url = $this->resolve($next);
            $query = null;
        }

        return $results;
    }

    /**
     * The space id for a space key, or null when Confluence has no such space.
     *
     * v2 /spaces?keys= returns 401 for the scoped token, so this uses v1 /space/{key}.
     */
    private function spaceIdForKey(string $spaceKey): ?string
    {
        $cacheKey = 'confluence_space_id_'.$spaceKey;
        $cacheTtl = config('filament-confluence.cache.spaces', 1800);
        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = $this->request()->get($this->url('/rest/api/space/'.rawurlencode($spaceKey)));

            if (! $response->successful()) {
                Log::error('Failed to resolve Confluence space key', [
                    'space_key' => $spaceKey,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $id = $response->json('id');

            if (! is_scalar($id) || (string) $id === '') {
                return null;
            }

            $this->rememberSpace($spaceKey, (string) $id);

            return (string) $id;
        } catch (Exception $e) {
            Log::error('Exception resolving Confluence space key', [
                'space_key' => $spaceKey,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function spaceKeyForId(?string $spaceId): ?string
    {
        if ($spaceId === null || $spaceId === '') {
            return null;
        }

        $cached = Cache::get('confluence_space_key_'.$spaceId);

        return is_string($cached) && $cached !== '' ? $cached : null;
    }

    private function rememberSpace(string $key, string $id): void
    {
        $ttl = config('filament-confluence.cache.spaces', 1800);

        Cache::put('confluence_space_id_'.$key, $id, $ttl);
        Cache::put('confluence_space_key_'.$id, $key, $ttl);
    }

    /**
     * Reshape a v1 content result (search) into the page shape the package reads.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function normaliseV1Page(array $content): array
    {
        $ancestors = is_array($content['ancestors'] ?? null) ? $content['ancestors'] : [];
        $parent = $ancestors === [] ? null : end($ancestors);
        $spaceKey = is_string($content['space']['key'] ?? null) ? $content['space']['key'] : null;
        $spaceId = isset($content['space']['id']) ? (string) $content['space']['id'] : null;

        if ($spaceKey !== null && $spaceId !== null && $spaceId !== '') {
            $this->rememberSpace($spaceKey, $spaceId);
        }

        $page = [
            'id' => (string) ($content['id'] ?? ''),
            'type' => $content['type'] ?? 'page',
            'status' => $content['status'] ?? 'current',
            'title' => $content['title'] ?? null,
            'spaceKey' => $spaceKey,
            'spaceId' => $spaceId,
            'parentId' => is_array($parent) && isset($parent['id']) ? (string) $parent['id'] : null,
            'ancestorIds' => array_values(array_map(
                fn (array $ancestor): string => (string) $ancestor['id'],
                array_filter($ancestors, fn ($ancestor): bool => is_array($ancestor) && isset($ancestor['id'])),
            )),
            'createdAt' => $content['history']['createdDate'] ?? null,
            'version' => [
                'number' => $content['version']['number'] ?? null,
                'createdAt' => $content['version']['when'] ?? null,
                'by' => ['displayName' => $content['version']['by']['displayName'] ?? null],
            ],
            '_links' => ['webui' => $content['_links']['webui'] ?? null],
        ];

        foreach (['view', 'storage'] as $format) {
            if (isset($content['body'][$format]['value'])) {
                $page['body'][$format] = ['value' => (string) $content['body'][$format]['value']];
            }
        }

        return $page;
    }

    /**
     * Reshape a v2 page (or a slim child) into the page shape the package reads.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function normaliseV2Page(array $content, ?string $spaceKey = null, ?string $parentId = null, bool $includeAncestors = false): array
    {
        $id = (string) ($content['id'] ?? '');
        $spaceId = isset($content['spaceId']) ? (string) $content['spaceId'] : null;
        $webui = is_string($content['_links']['webui'] ?? null) ? $content['_links']['webui'] : null;
        $resolvedKey = $spaceKey
            ?? $this->spaceKeyFromWebui($webui)
            ?? $this->spaceKeyForId($spaceId);

        if ($resolvedKey !== null && $spaceId !== null && $spaceId !== '') {
            $this->rememberSpace($resolvedKey, $spaceId);
        }

        $resolvedParent = $parentId ?? (isset($content['parentId']) && is_scalar($content['parentId']) && (string) $content['parentId'] !== ''
            ? (string) $content['parentId']
            : null);

        $page = [
            'id' => $id,
            'type' => $content['type'] ?? 'page',
            'status' => $content['status'] ?? 'current',
            'title' => $content['title'] ?? null,
            'spaceKey' => $resolvedKey,
            'spaceId' => $spaceId,
            'parentId' => $resolvedParent,
            'ancestorIds' => $includeAncestors ? $this->ancestorIds($resolvedParent) : [],
            'createdAt' => $content['createdAt'] ?? null,
            'version' => [
                'number' => $content['version']['number'] ?? null,
                'createdAt' => $content['version']['createdAt'] ?? null,
                'by' => ['displayName' => $content['version']['by']['displayName'] ?? null],
            ],
            '_links' => ['webui' => $webui ?? $this->synthesiseWebui($resolvedKey, $id)],
        ];

        foreach (['view', 'storage'] as $format) {
            if (isset($content['body'][$format]['value'])) {
                $page['body'][$format] = ['value' => (string) $content['body'][$format]['value']];
            }
        }

        return $page;
    }

    /**
     * Ancestor ids from root to parent, by walking parentId. The v2 /ancestors route
     * returns 401 for the scoped token. A parent that cannot be read is still kept, so an
     * exclusion by ancestry holds even when that parent cannot be fetched.
     *
     * @return list<string>
     */
    private function ancestorIds(?string $parentId): array
    {
        $ids = [];
        $current = $parentId;

        for ($step = 0; $step < self::MAX_ANCESTORS && is_string($current) && $current !== ''; $step++) {
            $ids[] = $current;
            $current = $this->parentIdOf($current);
        }

        return array_reverse($ids);
    }

    /**
     * The parent id of a page, or null when it has none or cannot be read.
     */
    private function parentIdOf(string $pageId): ?string
    {
        $cacheKey = "confluence_page_{$pageId}_parent";
        $cacheTtl = config('filament-confluence.cache.pages', 1800);

        $cached = Cache::get($cacheKey);

        if ($cached === 'none') {
            return null;
        }

        if (is_string($cached)) {
            return $cached;
        }

        try {
            $response = $this->request()->get($this->url('/api/v2/pages/'.rawurlencode($pageId)));

            if (! $response->successful()) {
                Cache::put($cacheKey, 'none', $cacheTtl);

                return null;
            }

            $parent = $response->json('parentId');
            $value = is_scalar($parent) && (string) $parent !== '' ? (string) $parent : 'none';
            Cache::put($cacheKey, $value, $cacheTtl);

            $spaceId = $response->json('spaceId');
            $webui = $response->json('_links.webui');
            $key = is_string($webui) ? $this->spaceKeyFromWebui($webui) : null;

            if ($key !== null && is_scalar($spaceId) && (string) $spaceId !== '') {
                $this->rememberSpace($key, (string) $spaceId);
            }

            return $value === 'none' ? null : $value;
        } catch (Exception $e) {
            Log::error('Exception walking Confluence page parent', [
                'page_id' => $pageId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function spaceKeyFromWebui(?string $webui): ?string
    {
        if ($webui === null || preg_match('#^/spaces/([^/]+)/#', $webui, $matches) !== 1) {
            return null;
        }

        return rawurldecode($matches[1]);
    }

    private function synthesiseWebui(?string $spaceKey, string $pageId): ?string
    {
        if ($spaceKey === null || $spaceKey === '' || $pageId === '') {
            return null;
        }

        return '/spaces/'.$spaceKey.'/pages/'.$pageId;
    }

    private function request(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => $this->auth,
            'Accept' => 'application/json',
        ])->timeout(30);
    }

    private function url(string $path): string
    {
        return $this->apiUrl.'/wiki'.$path;
    }

    /**
     * Turn a v2 _links.next value (a relative /wiki/... path or an absolute URL) into a
     * request URL. The cursor is already in the link, so the next request sends no extra query.
     */
    private function resolve(string $pathOrUrl): string
    {
        if (str_starts_with($pathOrUrl, 'https://') || str_starts_with($pathOrUrl, 'http://')) {
            return $pathOrUrl;
        }

        if (str_starts_with($pathOrUrl, '/wiki/')) {
            return $this->apiUrl.$pathOrUrl;
        }

        return $this->url(str_starts_with($pathOrUrl, '/') ? $pathOrUrl : '/'.$pathOrUrl);
    }

    public function clearCache(string $pageId): void
    {
        Cache::forget("confluence_page_{$pageId}_markdown");
        Cache::forget("confluence_page_{$pageId}_adf");
        Cache::forget("confluence_page_{$pageId}_parent");
        Cache::forget("confluence_page_children_{$pageId}");
        Cache::forget("confluence_page_{$pageId}_attachment_ids");
    }

    public function clearSpaceCache(string $spaceKey): void
    {
        Cache::forget("confluence_space_pages_{$spaceKey}");
        Cache::forget('confluence_space_id_'.$spaceKey);
    }
}
