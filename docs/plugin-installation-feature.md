# Plugin Installation Feature

## Overview
Version 1.6.0 introduces the ability to install WordPress plugins directly from tar.gz backup archives stored in the theme's `sync/plugins/` directory.

## Features

### 1. Individual Plugin Installation
- Install a single plugin from its tar.gz backup file
- Accessible via "Install" button next to each plugin in the admin interface
- Confirmation dialog before installation

### 2. Bulk Plugin Installation
- Install all plugin backups at once with a single click
- "Install All Plugins" button at the top of the plugin list
- Provides summary of successful, skipped, and failed installations

### 3. WP-CLI Support
```bash
wp srdt install-plugins
```

## Technical Implementation

### Core Methods in `includes/class-sync-posts.php`

#### `install_plugin_from_archive($tar_path)`
Installs a single plugin from a tar.gz archive.

**Returns:** Array with `success` (bool) and `message` (string)

#### `install_all_plugins($args, $assoc_args)`
Installs all plugins from the sync/plugins directory.

**Returns:** Array with counts for `success`, `failed`, `skipped`, and `messages` array

## Security Features

1. **Capability Checks** - Requires 'SR' user capability
2. **Nonce Verification** - All form submissions verified
3. **File Path Validation** - Prevents directory traversal
4. **Production Protection** - Blocked in production environments
5. **Existing Plugin Protection** - Skips if plugin already exists

## Usage

### Admin Interface
1. Navigate to SR Dev Tools admin page
2. Click "Install All Plugins" or individual "Install" buttons
3. Confirm installation in dialog
4. View success/error messages

### WP-CLI
```bash
wp srdt install-plugins
```

## Requirements
- WordPress 5.0+
- PHP 7.4+
- tar command available
- Write permissions on wp-content/plugins
- User with 'SR' capability
