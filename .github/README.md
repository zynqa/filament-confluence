# Filament Confluence

A Laravel/Filament package that provides Confluence knowledge base integration. Display Confluence pages directly in your Filament admin panel with user-based access control.

## Features

- ✅ Dual API support: Direct Confluence REST API or MCP integration
- ✅ User-based space assignment
- ✅ Admin-curated page sharing with automatic sub-page inclusion
- ✅ Read-only viewing with markdown support
- ✅ No database storage - uses Sushi for virtual Eloquent models
- ✅ Comprehensive caching strategy
- ✅ Filament v3 compatible

## Requirements

- PHP 8.2+
- Laravel 11+
- Filament 3.2+
- Spatie Laravel Settings 3.0+

## Installation

### Step 1: Install the package

```bash
composer require zynqa/filament-confluence
```

### Step 2: Publish configuration and migrations

```bash
php artisan vendor:publish --tag="filament-confluence-config"
php artisan vendor:publish --tag="filament-confluence-migrations"
```

### Step 3: Run migrations

```bash
php artisan migrate
```

### Step 4: Add trait to User model

```php
use Zynqa\FilamentConfluence\Models\Concerns\HasConfluenceSpaces;

class User extends Authenticatable
{
    use HasConfluenceSpaces;

    protected $fillable = [
        // ... your existing fields
        'confluence_space_keys',
    ];
}
```

### Step 5: Register the plugin

In your `AppPanelProvider.php`:

```php
use Zynqa\FilamentConfluence\FilamentConfluencePlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugins([
            FilamentConfluencePlugin::make(),
        ]);
}
```

## Configuration

### Environment Variables

#### For Direct API Connection:

```env
CONFLUENCE_CONNECTION=direct
CONFLUENCE_URL=https://your-domain.atlassian.net
CONFLUENCE_EMAIL=your-email@example.com
CONFLUENCE_API_TOKEN=your-api-token
CONFLUENCE_AUTH_TYPE=basic
CONFLUENCE_CONTENT_FORMAT=markdown
```

#### For MCP Connection:

```env
CONFLUENCE_CONNECTION=mcp
CONFLUENCE_CLOUD_ID=your-cloud-id
CONFLUENCE_CONTENT_FORMAT=markdown
```

### Assigning Spaces to Users

Update your UserResource to include the Confluence space assignment field:

```php
Forms\Components\Select::make('confluence_space_keys')
    ->label('Confluence Spaces')
    ->multiple()
    ->options(function () {
        $service = app(\Zynqa\FilamentConfluence\Services\ConfluenceService::class);
        $spaces = $service->getSpaces();

        return collect($spaces)->mapWithKeys(fn($s) => [
            $s['key'] => $s['key'] . ' - ' . $s['name']
        ]);
    })
    ->searchable(),
```

### Admin-Shared Pages

Configure shared pages in Settings > Confluence:
- Add page IDs that should be visible to all users
- All sub-pages (descendants) are automatically included

## Usage

Once configured, users will see a "Knowledge Base" menu item in Filament where they can:
- Browse pages from their assigned Confluence spaces
- View admin-shared pages and their descendants
- Read content in markdown format
- Click "Open in Confluence" to edit in Confluence

## Architecture

This package uses:
- **Sushi**: Virtual Eloquent models without database storage
- **Dual API Support**: Choose between direct Confluence API or MCP delegation
- **Per-User Caching**: Each user gets their own cached page set
- **Space-Based Access**: Users see only pages from their assigned spaces

## Security

- Read-only access - all editing happens in Confluence
- User-scoped data - each user only sees their assigned spaces
- Admin-controlled shared pages
- Comprehensive error logging

## License

MIT

## Credits

- Built for FilamentPHP
- Uses Spatie Laravel Settings
- Powered by Sushi by Caleb Porzio
