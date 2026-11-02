<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | API Connection Strategy
    |--------------------------------------------------------------------------
    | Options: 'direct' or 'mcp'
    | - direct: Use Confluence REST API directly
    | - mcp: Delegate to MCP server (requires MCP setup)
    */
    'connection' => env('CONFLUENCE_CONNECTION', 'direct'),

    /*
    |--------------------------------------------------------------------------
    | Confluence API Configuration (for direct connection)
    |--------------------------------------------------------------------------
    */
    // The site: what links and page images point at.
    'confluence_url' => env('CONFLUENCE_URL', 'https://your-domain.atlassian.net'),
    // Where REST calls go; defaults to the site. A scoped service-account token only works on
    // the API gateway, https://api.atlassian.com/ex/confluence/<cloudId>.
    'api_url' => env('CONFLUENCE_API_URL'),
    'email' => env('CONFLUENCE_EMAIL'),
    'api_token' => env('CONFLUENCE_API_TOKEN'),
    'auth_type' => env('CONFLUENCE_AUTH_TYPE', 'basic'), // basic or bearer

    /*
    |--------------------------------------------------------------------------
    | Allowed Spaces
    |--------------------------------------------------------------------------
    | Comma-separated space keys the knowledge base may read, e.g. "DOCS,OPS".
    | Every space, page, search and image outside them is refused. Empty allows
    | nothing, so the API account's wider Confluence access never leaks through.
    */
    'allowed_spaces' => env('CONFLUENCE_ALLOWED_SPACES', ''),

    /*
    |--------------------------------------------------------------------------
    | MCP Configuration (for MCP connection)
    |--------------------------------------------------------------------------
    */
    'cloud_id' => env('CONFLUENCE_CLOUD_ID'),

    /*
    |--------------------------------------------------------------------------
    | Content Format
    |--------------------------------------------------------------------------
    | Options: 'markdown' or 'adf'
    */
    'content_format' => env('CONFLUENCE_CONTENT_FORMAT', 'markdown'),

    /*
    |--------------------------------------------------------------------------
    | Image Proxy
    |--------------------------------------------------------------------------
    | Protected Confluence image URLs are rewritten to this app route so the
    | browser never requests Confluence assets directly.
    */
    'image_proxy' => [
        'middleware' => ['web', 'auth'],
        'cache_control' => env('CONFLUENCE_IMAGE_CACHE_CONTROL', 'private, max-age=300'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'pages' => env('CONFLUENCE_CACHE_PAGES', 1800), // 30 minutes
        'spaces' => env('CONFLUENCE_CACHE_SPACES', 1800), // 30 minutes
    ],
];
