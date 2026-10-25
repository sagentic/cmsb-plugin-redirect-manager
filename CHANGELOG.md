# Changelog

All notable changes to the Redirect Manager plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.01] - 2026-10-24

### Fixed
- Daily maintenance never ran: it was registered on a `_cron_daily` hook that CMS Builder never fires. It is now a scheduled task (`addCronJob`, daily at 4:15am) registered before the command-line check, so `webadmin/cron.php` dispatches it
- Warning emails now send through the CMS mail settings (`sendMessage()`) instead of PHP `mail()`
- Daily maintenance returns a summary line for the Background Tasks log
- Daily maintenance creates the redirects table first if it doesn't exist yet (it was only created on admin login)

## [1.00] - 2026-01-09

### Added
- Initial release
- Create, edit, and delete URL redirects through admin UI
- Support for 301, 302, 307, and 308 redirect types
- Wildcard pattern matching with capture group support
- Chain detection to identify redirect chains (A → B → C)
- Loop detection to prevent infinite redirect loops
- Hit tracking with optional retention period
- CSV import/export functionality
- URL testing tool to verify redirect behavior
- Dashboard with statistics and health status
- Drag-and-drop reordering for redirect priority
- WCAG 2.1 AA accessibility compliance
- PHP dispatcher for 404 handling
- Daily maintenance cron job for log cleanup
- Email notifications for detected issues
