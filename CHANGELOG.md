# Changelog

All notable changes to the Redirect Manager plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
