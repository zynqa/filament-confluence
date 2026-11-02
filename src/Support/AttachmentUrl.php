<?php

declare(strict_types=1);

namespace Zynqa\FilamentConfluence\Support;

/**
 * Read the page an attachment URL from Confluence page content belongs to.
 *
 * Page bodies link images in two ways: the legacy download path,
 * /wiki/download/{attachments|thumbnails}/{pageId}/{filename}, and the REST download
 * endpoint, /wiki/rest/api/content/{pageId}/child/attachment/{attachmentId}/download.
 */
final class AttachmentUrl
{
    private const LEGACY_PATTERN = '#^(?:/wiki)?/download/(?:attachments|thumbnails)/(\d+)/([^/]+)$#';

    private const REST_PATTERN = '#^(?:/wiki)?/rest/api/content/(\d+)/child/attachment/(att\d+)/download$#';

    /**
     * @return array{page_id: string, filename: ?string, attachment_id: ?string}|null
     */
    public static function parse(string $url): ?array
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path)) {
            return null;
        }

        if (preg_match(self::LEGACY_PATTERN, $path, $matches) === 1) {
            return ['page_id' => $matches[1], 'filename' => rawurldecode($matches[2]), 'attachment_id' => null];
        }

        if (preg_match(self::REST_PATTERN, $path, $matches) === 1) {
            return ['page_id' => $matches[1], 'filename' => null, 'attachment_id' => $matches[2]];
        }

        return null;
    }

    /**
     * Whether a URL points at authenticated Confluence content (an attachment or the REST API)
     * rather than a public static asset such as an emoticon.
     */
    public static function isProtected(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return (bool) preg_match('#^(?:/wiki)?/(?:download|rest|api)/#', $path);
    }
}
