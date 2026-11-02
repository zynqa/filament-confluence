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
 * The client uses the v1 REST API (/wiki/rest/api). A token with classic scopes is refused
 * by the v2 API (/wiki/api/v2) with 401 "scope does not match". Results are reshaped into
 * the page shape the rest of the package reads: id, title, status, spaceKey, parentId,
 * createdAt, version.createdAt, body.{view|storage}.value and _links.webui.
 */
class ConfluenceApiClient
{
    /** Results per request; the v1 API caps expanded content listings well below 250. */
    private const PAGE_SIZE = 100;

    /** Upper bound on paginated requests, so a misbehaving API cannot loop forever. */
    private const MAX_REQUESTS = 50;

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

                $response = $this->request()->get($this->url('/rest/api/content/'.rawurlencode($pageId)), [
                    'expand' => self::PAGE_EXPAND.',body.'.$bodyFormat,
                ]);

                if (! $response->successful()) {
                    Log::error('Failed to fetch Confluence page', [
                        'page_id' => $pageId,
                        'status' => $response->status(),
                    ]);

                    return null;
                }

                $content = $response->json();

                return is_array($content) ? $this->normalisePage($content) : null;
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
                $results = $this->getAll('/rest/api/content', array_merge([
                    'spaceKey' => $spaceKey,
                    'type' => 'page',
                    'status' => 'current',
                    'expand' => self::PAGE_EXPAND,
                ], $options), ['space_key' => $spaceKey], 'Failed to fetch pages in space');

                return array_map($this->normalisePage(...), $results);
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
                $children = array_map($this->normalisePage(...), $this->getAll(
                    '/rest/api/content/'.rawurlencode($pageId).'/child/page',
                    ['expand' => self::PAGE_EXPAND],
                    ['page_id' => $pageId],
                    'Failed to fetch page children',
                ));

                // Recursively get descendants
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

                return array_map(fn (array $space): array => [
                    'id' => isset($space['id']) ? (string) $space['id'] : null,
                    'key' => $space['key'] ?? null,
                    'name' => $space['name'] ?? ($space['key'] ?? ''),
                    'type' => $space['type'] ?? null,
                    'status' => $space['status'] ?? null,
                ], $spaces);
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

            return array_map($this->normalisePage(...), array_values(array_filter(
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

            $attachments = $this->getAll(
                '/rest/api/content/'.rawurlencode($pageId).'/child/attachment',
                [],
                ['page_id' => $pageId],
                'Failed to fetch Confluence page attachments',
            );

            foreach ($attachments as $attachment) {
                $title = $attachment['title'] ?? null;
                $id = $attachment['id'] ?? null;

                if (is_string($title) && $title !== '' && is_string($id) && $id !== '') {
                    $ids[$title] = $id;
                }
            }

            return $ids;
        });
    }

    /**
     * Every result of a paginated v1 listing.
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
     * Reshape v1 content into the page shape the package reads.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function normalisePage(array $content): array
    {
        $ancestors = is_array($content['ancestors'] ?? null) ? $content['ancestors'] : [];
        $parent = $ancestors === [] ? null : end($ancestors);

        $page = [
            'id' => (string) ($content['id'] ?? ''),
            'type' => $content['type'] ?? 'page',
            'status' => $content['status'] ?? 'current',
            'title' => $content['title'] ?? null,
            'spaceKey' => $content['space']['key'] ?? null,
            'spaceId' => isset($content['space']['id']) ? (string) $content['space']['id'] : null,
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

    public function clearCache(string $pageId): void
    {
        Cache::forget("confluence_page_{$pageId}_markdown");
        Cache::forget("confluence_page_{$pageId}_adf");
        Cache::forget("confluence_page_children_{$pageId}");
        Cache::forget("confluence_page_{$pageId}_attachment_ids");
    }

    public function clearSpaceCache(string $spaceKey): void
    {
        Cache::forget("confluence_space_pages_{$spaceKey}");
    }
}
