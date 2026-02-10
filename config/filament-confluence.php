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
    'confluence_url' => env('CONFLUENCE_URL', 'https://your-domain.atlassian.net'),
    'email' => env('CONFLUENCE_EMAIL'),
    'api_token' => env('CONFLUENCE_API_TOKEN'),
    'auth_type' => env('CONFLUENCE_AUTH_TYPE', 'basic'), // basic or bearer

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
    | Cache Configuration
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'pages' => env('CONFLUENCE_CACHE_PAGES', 1800), // 30 minutes
        'spaces' => env('CONFLUENCE_CACHE_SPACES', 1800), // 30 minutes
    ],
];
