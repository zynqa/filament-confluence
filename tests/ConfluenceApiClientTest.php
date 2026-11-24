<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Zynqa\FilamentConfluence\Services\ConfluenceApiClient;

const CF_SITE = 'https://example.atlassian.net';
const CF_GATEWAY = 'https://api.atlassian.com/ex/confluence/test-cloud';

function client(): ConfluenceApiClient
{
    Cache::flush();

    return new ConfluenceApiClient;
}

/**
 * @return array<string, mixed>
 */
function v2Page(string $id, string $spaceKey = 'DOCS', ?string $parentId = null, string $title = 'Page'): array
{
    $page = [
        'id' => $id,
        'status' => 'current',
        'title' => $title,
        'spaceId' => '98306',
        'parentId' => $parentId,
        'createdAt' => '2026-01-01T10:00:00.000Z',
        'version' => ['number' => 3, 'createdAt' => '2026-10-01T10:00:00.000Z'],
        '_links' => ['webui' => "/spaces/{$spaceKey}/pages/{$id}/{$title}"],
    ];

    return $page;
}

/**
 * @return array<string, string>
 */
function queryOf(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

describe('pages in a space', function (): void {
    it('resolves the space key with v1 and lists pages with v2, never the site', function (): void {
        Http::fake([
            CF_GATEWAY.'/wiki/rest/api/space/DOCS' => Http::response(['id' => 98306, 'key' => 'DOCS', 'name' => 'Documentation']),
            CF_GATEWAY.'/wiki/api/v2/pages*' => Http::response([
                'results' => [v2Page('101', 'DOCS', null, 'Getting-Started')],
                '_links' => ['base' => CF_SITE.'/wiki'],
            ]),
        ]);

        $pages = client()->getPagesInSpace('DOCS');

        expect($pages)->toHaveCount(1)
            ->and($pages[0]['id'])->toBe('101')
            ->and($pages[0]['spaceKey'])->toBe('DOCS')
            ->and($pages[0]['_links']['webui'])->toBe('/spaces/DOCS/pages/101/Getting-Started');

        Http::assertSent(fn (Request $request): bool => $request->url() === CF_GATEWAY.'/wiki/rest/api/space/DOCS'
            && $request->hasHeader('Authorization'));
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), CF_GATEWAY.'/wiki/api/v2/pages?')
            && queryOf($request)['space-id'] === '98306'
            && queryOf($request)['status'] === 'current');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/rest/api/content')
            || str_starts_with($request->url(), CF_SITE));
    });

    it('follows v2 cursor pagination until there is no next link', function (): void {
        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_ends_with(parse_url($url, PHP_URL_PATH) ?: '', '/space/DOCS')) {
                return Http::response(['id' => 98306, 'key' => 'DOCS']);
            }

            if (str_contains($url, 'cursor=page-2')) {
                return Http::response([
                    'results' => [v2Page('102', 'DOCS')],
                    '_links' => [],
                ]);
            }

            if (str_contains($url, '/api/v2/pages')) {
                return Http::response([
                    'results' => [v2Page('101', 'DOCS')],
                    '_links' => ['next' => '/wiki/api/v2/pages?space-id=98306&status=current&cursor=page-2'],
                ]);
            }

            return Http::response(['message' => 'unexpected '.$url], 500);
        });

        $pages = client()->getPagesInSpace('DOCS');

        expect(array_column($pages, 'id'))->toBe(['101', '102']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'cursor=page-2'));
    });

    it('returns no pages when the space key cannot be resolved', function (): void {
        Http::fake([
            CF_GATEWAY.'/wiki/rest/api/space/MISSING' => Http::response(['message' => 'Not found'], 404),
        ]);

        expect(client()->getPagesInSpace('MISSING'))->toBe([]);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v2/'));
    });
});

describe('a single page', function (): void {
    it('reads a page from the v2 endpoint with the view body', function (): void {
        Http::fake([
            CF_GATEWAY.'/wiki/api/v2/pages/101*' => Http::response(v2Page('101', 'DOCS') + [
                'body' => ['view' => ['representation' => 'view', 'value' => '<p>Hello</p>']],
            ]),
        ]);

        $page = client()->getPage('101');

        expect($page['body']['view']['value'])->toBe('<p>Hello</p>')
            ->and($page['spaceKey'])->toBe('DOCS')
            ->and($page['parentId'])->toBeNull()
            ->and($page['ancestorIds'])->toBe([]);

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), CF_GATEWAY.'/wiki/api/v2/pages/101?')
            && queryOf($request)['body-format'] === 'view');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/rest/api/content/'));
    });

    it('walks parentId for ancestors and keeps a parent that cannot be read', function (): void {
        Http::fake([
            CF_GATEWAY.'/wiki/api/v2/pages/900' => Http::response([], 500),
            CF_GATEWAY.'/wiki/api/v2/pages/101*' => Http::response(v2Page('101', 'DOCS', '900') + [
                'body' => ['view' => ['value' => '<p>Child</p>']],
            ]),
        ]);

        $page = client()->getPage('101');

        expect($page['parentId'])->toBe('900')
            ->and($page['ancestorIds'])->toBe(['900']);
    });

    it('lists ancestors from the root down to the parent', function (): void {
        Http::fake([
            CF_GATEWAY.'/wiki/api/v2/pages/50' => Http::response(v2Page('50', 'DOCS', null, 'Root')),
            CF_GATEWAY.'/wiki/api/v2/pages/100' => Http::response(v2Page('100', 'DOCS', '50', 'Mid')),
            CF_GATEWAY.'/wiki/api/v2/pages/101*' => Http::response(v2Page('101', 'DOCS', '100', 'Leaf') + [
                'body' => ['view' => ['value' => '<p>Leaf</p>']],
            ]),
        ]);

        $page = client()->getPage('101');

        expect($page['ancestorIds'])->toBe(['50', '100'])
            ->and($page['parentId'])->toBe('100');
    });
});

describe('children', function (): void {
    it('lists direct children through /pages/{id}/children and recurses', function (): void {
        Http::fake([
            CF_GATEWAY.'/wiki/api/v2/pages/200/children*' => Http::response([
                'results' => [
                    ['id' => '201', 'status' => 'current', 'title' => 'Child', 'spaceId' => '98306', 'childPosition' => 0],
                ],
                '_links' => [],
            ]),
            CF_GATEWAY.'/wiki/api/v2/pages/201/children*' => Http::response([
                'results' => [
                    ['id' => '202', 'status' => 'current', 'title' => 'Grandchild', 'spaceId' => '98306', 'childPosition' => 0],
                ],
                '_links' => [],
            ]),
            CF_GATEWAY.'/wiki/api/v2/pages/202/children*' => Http::response(['results' => [], '_links' => []]),
        ]);

        // Seed the space key so slim children can carry it.
        Cache::flush();
        Cache::put('confluence_space_key_98306', 'DOCS', 1800);

        $children = (new ConfluenceApiClient)->getPageChildren('200');

        expect(array_column($children, 'id'))->toBe(['201', '202'])
            ->and($children[0]['parentId'])->toBe('200')
            ->and($children[1]['parentId'])->toBe('201')
            ->and($children[0]['spaceKey'])->toBe('DOCS');

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/direct-children')
            || str_contains($request->url(), '/child/page'));
    });
});

describe('spaces and search', function (): void {
    it('still lists spaces through v1', function (): void {
        Http::fake([
            CF_GATEWAY.'/wiki/rest/api/space*' => Http::response([
                'results' => [
                    ['id' => 1, 'key' => 'DOCS', 'name' => 'Documentation', 'type' => 'global', 'status' => 'current'],
                ],
                '_links' => [],
            ]),
        ]);

        $spaces = client()->getSpaces();

        expect($spaces)->toHaveCount(1)
            ->and($spaces[0]['key'])->toBe('DOCS')
            ->and($spaces[0]['id'])->toBe('1');
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/wiki/rest/api/space'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v2/spaces'));
    });

    it('still searches through v1 CQL', function (): void {
        Http::fake([
            CF_GATEWAY.'/wiki/rest/api/content/search*' => Http::response([
                'results' => [[
                    'id' => '101',
                    'type' => 'page',
                    'status' => 'current',
                    'title' => 'Guide',
                    'space' => ['id' => 98306, 'key' => 'DOCS'],
                    'version' => ['number' => 1, 'when' => '2026-10-01T10:00:00.000Z', 'by' => ['displayName' => 'Someone']],
                    'history' => ['createdDate' => '2026-01-01T10:00:00.000Z'],
                    '_links' => ['webui' => '/spaces/DOCS/pages/101/Guide'],
                ]],
                '_links' => [],
            ]),
        ]);

        $pages = client()->searchPages('type = page AND space = "DOCS"');

        expect($pages)->toHaveCount(1)
            ->and($pages[0]['id'])->toBe('101')
            ->and($pages[0]['spaceKey'])->toBe('DOCS');
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/rest/api/content/search'));
    });
});

describe('attachments', function (): void {
    it('lists attachments with v2 and downloads them with the v1 download URL', function (): void {
        $siteUrl = CF_SITE.'/wiki/download/attachments/101/photo.png';

        Http::fake([
            CF_GATEWAY.'/wiki/rest/api/content/101/child/attachment/att9/download' => Http::response('image-bytes', 200, ['Content-Type' => 'image/png']),
            CF_GATEWAY.'/wiki/api/v2/pages/101/attachments*' => Http::response([
                'results' => [['id' => 'att9', 'title' => 'photo.png', 'mediaType' => 'image/png']],
                '_links' => [],
            ]),
        ]);

        $response = client()->fetchImage($siteUrl);

        expect($response)->not->toBeNull()
            ->and($response->body())->toBe('image-bytes');

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), CF_GATEWAY.'/wiki/api/v2/pages/101/attachments'));
        Http::assertSent(fn (Request $request): bool => $request->url() === CF_GATEWAY.'/wiki/rest/api/content/101/child/attachment/att9/download'
            && $request->hasHeader('Authorization'));
        Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), CF_SITE)
            || str_contains($request->url(), '/child/attachment?'));
    });
});
