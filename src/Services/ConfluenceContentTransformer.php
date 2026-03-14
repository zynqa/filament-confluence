<?php

declare(strict_types=1);

namespace Zynqa\FilamentConfluence\Services;

use DOMDocument;
use DOMElement;
use Illuminate\Support\Str;

class ConfluenceContentTransformer
{
    public function transform(string $content): string
    {
        if (trim($content) === '' || ! str_contains($content, '<img')) {
            return $content;
        }

        $previousUseInternalErrors = libxml_use_internal_errors(true);

        $document = new DOMDocument('1.0', 'UTF-8');
        $wrappedContent = sprintf('<?xml encoding="utf-8" ?><div id="confluence-content-root">%s</div>', $content);

        if (! $document->loadHTML($wrappedContent, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseInternalErrors);

            return $content;
        }

        $root = $document->getElementById('confluence-content-root');

        if (! $root instanceof DOMElement) {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseInternalErrors);

            return $content;
        }

        $imageIndex = 0;

        foreach ($root->getElementsByTagName('img') as $image) {
            $originalSource = html_entity_decode($image->getAttribute('src'));
            $preferredSource = $this->resolvePreferredImageSource($image, $originalSource);
            $proxiedSource = $this->buildProxyUrl($preferredSource);

            if ($proxiedSource !== null) {
                $image->setAttribute('src', $proxiedSource);
            }

            if ($image->hasAttribute('srcset') && ! $this->shouldDropSrcset($image, $preferredSource)) {
                $rewrittenSrcset = $this->rewriteSrcset($image->getAttribute('srcset'));

                if ($rewrittenSrcset !== null) {
                    $image->setAttribute('srcset', $rewrittenSrcset);
                }
            } elseif ($image->hasAttribute('srcset')) {
                $image->removeAttribute('srcset');
            }

            if (! $image->hasAttribute('decoding')) {
                $image->setAttribute('decoding', 'async');
            }

            if (! $image->hasAttribute('loading')) {
                $image->setAttribute('loading', $imageIndex === 0 ? 'eager' : 'lazy');
            }

            $imageIndex++;
        }

        $html = '';

        foreach ($root->childNodes as $childNode) {
            $html .= $document->saveHTML($childNode);
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previousUseInternalErrors);

        return $html;
    }

    public function buildProxyUrl(string $source): ?string
    {
        if ($source === '' || Str::startsWith(Str::lower($source), ['data:', 'javascript:', 'cid:'])) {
            return null;
        }

        if ($this->isAlreadyProxied($source)) {
            return $source;
        }

        $absoluteSource = $this->resolveSource($source);

        if ($absoluteSource === null || ! $this->isConfluenceUrl($absoluteSource)) {
            return null;
        }

        return route('filament-confluence.images.show', [
            'source' => rtrim(strtr(base64_encode($absoluteSource), '+/', '-_'), '='),
        ], false);
    }

    private function resolveSource(string $source): ?string
    {
        $configuredBaseUrl = rtrim((string) config('filament-confluence.confluence_url'), '/');

        if ($configuredBaseUrl === '') {
            return null;
        }

        if (Str::startsWith($source, '//')) {
            $scheme = parse_url($configuredBaseUrl, PHP_URL_SCHEME) ?: 'https';

            return "{$scheme}:{$source}";
        }

        if (Str::startsWith($source, '/')) {
            return $configuredBaseUrl.$source;
        }

        if (filter_var($source, FILTER_VALIDATE_URL)) {
            return $source;
        }

        return null;
    }

    private function isConfluenceUrl(string $source): bool
    {
        $configuredHost = Str::lower((string) parse_url((string) config('filament-confluence.confluence_url'), PHP_URL_HOST));
        $sourceHost = Str::lower((string) parse_url($source, PHP_URL_HOST));

        return $configuredHost !== '' && $configuredHost === $sourceHost;
    }

    private function isAlreadyProxied(string $source): bool
    {
        $path = parse_url($source, PHP_URL_PATH);

        if (! is_string($path)) {
            return false;
        }

        return Str::startsWith($path, '/confluence/images/');
    }

    private function rewriteSrcset(string $srcset): ?string
    {
        $candidates = array_filter(array_map('trim', explode(',', $srcset)));

        if ($candidates === []) {
            return null;
        }

        $rewrittenCandidates = [];

        foreach ($candidates as $candidate) {
            $parts = preg_split('/\s+/', $candidate, 2);

            if (! is_array($parts) || $parts === []) {
                continue;
            }

            $url = html_entity_decode($parts[0]);
            $descriptor = $parts[1] ?? null;
            $proxiedUrl = $this->buildProxyUrl($url) ?? $url;

            $rewrittenCandidates[] = trim($proxiedUrl.' '.($descriptor ?? ''));
        }

        return $rewrittenCandidates === [] ? null : implode(', ', $rewrittenCandidates);
    }

    private function resolvePreferredImageSource(DOMElement $image, string $defaultSource): string
    {
        $dataImageSource = html_entity_decode($image->getAttribute('data-image-src'));

        if ($dataImageSource !== '' && $this->isThumbnailUrl($defaultSource)) {
            return $dataImageSource;
        }

        return $defaultSource;
    }

    private function shouldDropSrcset(DOMElement $image, string $preferredSource): bool
    {
        if (! $image->hasAttribute('srcset')) {
            return false;
        }

        return $image->hasAttribute('data-image-src')
            && html_entity_decode($image->getAttribute('data-image-src')) === $preferredSource;
    }

    private function isThumbnailUrl(string $source): bool
    {
        return str_contains($source, '/wiki/download/thumbnails/');
    }
}
