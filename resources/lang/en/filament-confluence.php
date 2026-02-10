<?php

declare(strict_types=1);

return [
    'navigation' => [
        'label' => 'Knowledge Base',
        'group' => 'Content',
    ],

    'resource' => [
        'title' => [
            'index' => 'Confluence Pages',
            'view' => 'View Page',
        ],
        'labels' => [
            'title' => 'Title',
            'space' => 'Space',
            'author' => 'Author',
            'updated_at' => 'Last Updated',
            'created_at' => 'Created',
            'status' => 'Status',
            'content' => 'Content',
        ],
        'actions' => [
            'open_confluence' => 'Open in Confluence',
            'refresh' => 'Refresh from Confluence',
            'copy_url' => 'Copy URL',
        ],
        'messages' => [
            'refreshed' => 'Page refreshed from Confluence',
            'url_copied' => 'URL copied to clipboard',
        ],
    ],

    'settings' => [
        'title' => 'Confluence Settings',
        'navigation_label' => 'Confluence',
        'sections' => [
            'shared_pages' => [
                'title' => 'Shared Pages Configuration',
                'description' => 'Configure which Confluence pages are shared with all users. All sub-pages (descendants) will be automatically included.',
            ],
            'connection' => [
                'title' => 'Connection Information',
                'description' => 'Current Confluence connection details',
            ],
            'cache' => [
                'title' => 'Cache Management',
                'description' => 'Clear Confluence caches to force fresh data from the API',
            ],
        ],
        'fields' => [
            'shared_page_ids' => [
                'label' => 'Shared Page IDs',
                'helper' => 'Enter Confluence page IDs (e.g., 123456789). Get the page ID from the Confluence URL.',
                'hint' => 'All descendants of these pages will be automatically visible to users',
            ],
            'connection_type' => [
                'label' => 'Connection Type',
            ],
            'confluence_url' => [
                'label' => 'Confluence URL',
            ],
            'cloud_id' => [
                'label' => 'Cloud ID',
            ],
            'content_format' => [
                'label' => 'Content Format',
            ],
        ],
        'actions' => [
            'clear_cache' => 'Clear All Confluence Caches',
        ],
        'messages' => [
            'saved' => 'Settings saved successfully',
            'cache_cleared' => 'Caches cleared successfully',
        ],
    ],

    'statuses' => [
        'current' => 'Current',
        'archived' => 'Archived',
    ],

    'errors' => [
        'no_access' => 'You do not have access to any Confluence spaces.',
        'page_not_found' => 'Page not found',
        'connection_failed' => 'Failed to connect to Confluence',
    ],
];
