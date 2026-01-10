# Redirect Manager for CMS Builder

> **Note:** This plugin only works with CMS Builder, available for download at https://www.interactivetools.com/download/

Manage 301/302 URL redirects through the admin UI instead of manually editing .htaccess files.

## Requirements

-   CMS Builder 3.50 or higher
-   PHP 8.1 or higher

## Installation

1. Upload the `redirectManager` folder to `/cmsb/plugins/`
2. Log into CMS Builder admin
3. Go to Admin > Plugins and enable "Redirect Manager"
4. Configure the PHP dispatcher (see below)

## Dispatcher Setup

The plugin uses a PHP dispatcher to handle redirects. Add this to your `.htaccess` file:

```apache
ErrorDocument 404 /cmsb/plugins/redirectManager/dispatcher.php
```

Or include it in your existing 404 handler:

```php
include_once '/path/to/cmsb/plugins/redirectManager/dispatcher.php';
```

## Features

### Redirect Management

-   Create, edit, and delete URL redirects
-   Support for 301 (Permanent), 302 (Temporary), 307, and 308 redirect types
-   Drag-and-drop reordering for redirect priority
-   Bulk enable/disable redirects

### Pattern Matching

-   Simple exact URL matching
-   Wildcard patterns using `*` and `?`
-   Capture groups with `$1`, `$2`, etc. placeholders

### Health Monitoring

-   Automatic chain detection (A → B → C)
-   Loop detection to prevent infinite redirects
-   Dashboard with redirect statistics

### Analytics

-   Hit tracking for each redirect
-   Last hit date recording
-   Configurable data retention period

### Import/Export

-   CSV import for bulk redirect creation
-   CSV export for backup and migration
-   Format: sourceUrl, destinationUrl, redirectType, isPattern, isActive, notes

## Usage

### Simple Redirect

-   Source URL: `/old-page`
-   Destination URL: `/new-page`
-   Type: 301 (Permanent)

### Pattern Redirect

-   Source URL: `/blog/*`
-   Destination URL: `/articles/$1`
-   Enable "Pattern" checkbox
-   Example: `/blog/my-post` → `/articles/my-post`

### Testing

Use the URL Tester on the Dashboard to verify redirect behavior before going live.

## Version History

See [CHANGELOG.md](CHANGELOG.md) for detailed version history.

## Author

Sagentic Web Design
https://www.sagentic.com

## License

MIT License - See [LICENSE](LICENSE) file for details.
