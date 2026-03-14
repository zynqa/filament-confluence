<?php

declare(strict_types=1);

namespace Zynqa\FilamentConfluence\Http\Controllers;

use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Zynqa\FilamentConfluence\Services\ConfluenceService;

class ConfluenceImageProxyController extends Controller
{
    public function __invoke(Request $request, string $source, ConfluenceService $confluenceService): Response
    {
        $decodedSource = $this->decodeSource($source);

        abort_unless($decodedSource && $this->isAllowedSource($decodedSource), 404);

        $upstreamResponse = $confluenceService->fetchImage($decodedSource);

        abort_unless($upstreamResponse instanceof HttpResponse && $upstreamResponse->successful(), 404);

        $contentType = (string) $upstreamResponse->header('Content-Type');

        abort_unless(Str::startsWith(Str::lower($contentType), 'image/'), 404);

        $headers = array_filter([
            'Content-Type' => $contentType,
            'Content-Length' => $upstreamResponse->header('Content-Length'),
            'Cache-Control' => config('filament-confluence.image_proxy.cache_control', 'private, max-age=300'),
            'ETag' => $upstreamResponse->header('ETag'),
            'Last-Modified' => $upstreamResponse->header('Last-Modified'),
        ]);

        return response($upstreamResponse->body(), $upstreamResponse->status(), $headers);
    }

    private function decodeSource(string $source): ?string
    {
        $decoded = base64_decode(strtr($source, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    private function isAllowedSource(string $source): bool
    {
        $configuredBaseUrl = (string) config('filament-confluence.confluence_url');
        $configuredParts = parse_url($configuredBaseUrl);
        $sourceParts = parse_url($source);

        if (! is_array($configuredParts) || ! is_array($sourceParts)) {
            return false;
        }

        $configuredHost = Str::lower((string) ($configuredParts['host'] ?? ''));
        $sourceHost = Str::lower((string) ($sourceParts['host'] ?? ''));
        $sourceScheme = Str::lower((string) ($sourceParts['scheme'] ?? ''));
        $sourcePath = (string) ($sourceParts['path'] ?? '');

        if ($configuredHost === '' || $sourceHost !== $configuredHost) {
            return false;
        }

        if (! in_array($sourceScheme, ['http', 'https'], true)) {
            return false;
        }

        return Str::startsWith($sourcePath, ['/wiki/', '/download/', '/rest/']);
    }
}
