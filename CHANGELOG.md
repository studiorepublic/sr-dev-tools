# CHANGELOG

## 1.4.0

- Added: functionality to export plugins as zip files and to dump/import database
- Changed: Renamed plugin namespace from dbvc/dvbc to srdt across the codebase (constants, class names, functions, and text domain)
- Changed: Default sync path now resolves to the active theme's sync/ directory instead of the plugin folder when no custom path is set
- Improved: Updated hooks to use SRDT_Sync_Posts and ensured exports run on relevant WordPress events under the new namespace
- Internal: Updated plugin headers and localization domain to srdt

## 1.3.0

- Changed: Import now uses post slugs (and paths for hierarchical post types) as unique identifiers instead of IDs
- Added: Export includes post_path and parent_path/parent_slug to preserve hierarchy on import
- Improved: Import respects parent page when updating/creating pages by resolving parent via slug/path

## 1.2.0

- **Added**: Option to create module pages
- 
## 1.1.0

- **Added**: Full Site Editing (FSE) integration with theme data export/import functionality in `includes/class-sync-posts.php`
- **Added**: Safe FSE hook registration system to prevent WordPress admin conflicts in `includes/hooks.php`
- **Added**: Comprehensive error handling and safety checks for theme JSON operations in `includes/class-sync-posts.php`
- **Added**: Security validation functions for file paths and JSON data sanitization in `includes/functions.php`
- **Added**: FSE options to the select field in the admin settings in `admin/admin-page.php`
- **Updated**: Text strings for localization in `languages/srdt.pot`

## 1.0.0

- Initial release