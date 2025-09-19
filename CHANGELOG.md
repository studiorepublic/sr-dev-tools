# CHANGELOG

## 1.5.0

- **Added**: Tabular layout for database dumps and plugin backups with proper WordPress admin styling
- **Added**: "Delete All Plugin Backups" functionality with confirmation dialog
- **Improved**: Consistent button styling using WordPress admin classes throughout the interface
- **Improved**: Simplified table layout by removing date created columns for cleaner presentation
- **Enhanced**: Better visual organization with proper table headers (File Name, Size, Actions)
- **Enhanced**: Bulk action buttons for plugin backups (Download All, Delete All)

## 1.4.4

- **Updated**: Version bump for new release

## 1.4.3

- **Updated**: Version bump for new release

## 1.4.2

- **Changed**: Plugin backups now use tar.gz format instead of zip for better compatibility
- **Improved**: Replaced ZipArchive dependency with tar command for more reliable cross-platform support
- **Added**: Additional file exclusions for plugin backups (*.tmp, *.log files)
- **Updated**: Admin interface text to reflect tar.gz format
- **Updated**: Plugin description and documentation to mention tar.gz archives
- Enhanced: Database import functionality now restores current theme options (stylesheet and template) after import
- Added: Theme restoration logic with fallback mechanisms and comprehensive logging
- Improved: Database import process now preserves both site URLs and active theme configuration

## 1.4.1

- Better tar.gz file handling for plugin backups

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