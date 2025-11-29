# WP-CLI Commands

SR Dev Tools provides comprehensive WP-CLI commands for all major functionality, allowing you to automate content synchronization, database management, plugin operations, and module generation from the command line.

## Available Commands

### 1. Export Content (`wp srdt export`)

Export all posts, options, and menus to JSON files.

#### Syntax
```bash
wp srdt export [--batch-size=<number>]
```

#### Options
- `--batch-size=<number>` - Number of posts to process per batch. Use 0 to disable batching. Default: 50

#### Examples

**Basic export with default batch size:**
```bash
wp srdt export
```

**Export with custom batch size:**
```bash
wp srdt export --batch-size=100
```

**Export all at once (no batching):**
```bash
wp srdt export --batch-size=0
```

#### Output Example
```
Starting batch export with batch size: 50
Processed batch: 50 posts | Total: 50/395 | Remaining: 345
Processed batch: 50 posts | Total: 100/395 | Remaining: 295
...
Success: Batch export completed! Processed 395 posts across post types: post, page, product
```

#### What Gets Exported
- All posts from selected post types
- WordPress options (excluding sensitive data)
- Navigation menus
- Post metadata
- Hierarchical relationships (for pages)

---

### 2. Import Content (`wp srdt import`)

Import all JSON files from the sync directory into the database.

#### Syntax
```bash
wp srdt import [--batch-size=<number>]
```

#### Options
- `--batch-size=<number>` - Number of files to process per batch. Use 0 to disable batching. Default: 50

#### Examples

**Basic import with default batch size:**
```bash
wp srdt import
```

**Import with custom batch size:**
```bash
wp srdt import --batch-size=25
```

**Import all at once (no batching):**
```bash
wp srdt import --batch-size=0
```

#### Output Example
```
Warning: This will overwrite existing data. Make sure you have a backup.
Starting batch import with batch size: 50
Processed batch: 50 files | Total: 50/395 | Remaining: 345
...
Success: Batch import completed! Processed 395 files.
```

#### Important Notes
- ⚠️ **This will overwrite existing content** - Always backup first
- Imports options and menus before posts
- Uses slugs/paths to match existing content
- Updates existing posts rather than creating duplicates

---

### 3. Generate Module Pages (`wp srdt generate-modules`)

Generate module pages based on ACF field names starting with "Partial".

#### Syntax
```bash
wp srdt generate-modules
```

#### What It Does
1. Scans the current theme's `acf-json` directory
2. Finds all ACF fields with titles starting with "Partial"
3. Creates a "Modules" parent page (if it doesn't exist)
4. Creates child pages under "Modules" for each Partial field
5. Skips pages that already exist

#### Example
```bash
wp srdt generate-modules
```

#### Output Example
```
Scanning ACF field groups for Partial fields...
✓ "Modules" parent page already exists
Found 5 Partial field(s) in ACF JSON files
✓ Created 3 new page(s):
  - Partial Hero
  - Partial CTA
  - Partial Gallery
⊘ Skipped 2 existing page(s):
  - Partial Header
  - Partial Footer
Success: Module pages generation completed! Created: 3, Skipped: 2
```

#### Use Cases
- Automatically create module pages for ACF flexible content layouts
- Maintain consistency between ACF field groups and WordPress pages
- Quickly scaffold module documentation pages

---

### 4. Dump Database (`wp srdt dump-db`)

Create a compressed database backup in the theme's sync/database directory.

#### Syntax
```bash
wp srdt dump-db
```

#### Example
```bash
wp srdt dump-db
```

#### What It Does
- Creates a SQL dump of the entire database
- Compresses it into a tar.gz archive
- Saves to `[theme]/sync/database/database-YYYYMMDD-HHMMSS.tar.gz`
- Uses WP-CLI, mysqldump, or pure PHP fallback

#### Output Location
```
wp-content/themes/[active-theme]/sync/database/database-20240115-143022.tar.gz
```

---

### 5. Import Database (`wp srdt import-db`)

Import the most recent database backup from the theme's sync/database directory.

#### Syntax
```bash
wp srdt import-db
```

#### Example
```bash
wp srdt import-db
```

#### What It Does
- Finds the most recent database backup (tar.gz or sql)
- Extracts and imports the SQL file
- Automatically restores site URL and home settings
- Preserves current site configuration

#### Important Notes
- ⚠️ **This will overwrite your entire database** - Always backup first
- Site URL and Home URL are automatically restored to current values
- Uses the most recent backup file by modification time

---

### 6. Backup Plugins (`wp srdt backup-plugins`)

Create tar.gz archives of all plugins in wp-content/plugins.

#### Syntax
```bash
wp srdt backup-plugins
```

#### Example
```bash
wp srdt backup-plugins
```

#### What It Does
- Scans wp-content/plugins directory
- Creates individual tar.gz archives for each plugin
- Saves to `[theme]/sync/plugins/[plugin-name].tar.gz`
- Excludes .git, .DS_Store, *.tmp, and *.log files

#### Output Example
```
Success: Created 15 plugin tar.gz backup(s) in /path/to/theme/sync/plugins/
```

---

### 7. Install Plugins (`wp srdt install-plugins`)

Install all plugins from tar.gz archives in the theme's sync/plugins directory.

#### Syntax
```bash
wp srdt install-plugins
```

#### Example
```bash
wp srdt install-plugins
```

#### What It Does
- Scans `[theme]/sync/plugins/` for tar.gz files
- Extracts each archive to wp-content/plugins
- Skips plugins that already exist
- Provides detailed success/failure/skipped counts

#### Output Example
```
Success: Plugin installation complete. Success: 5, Skipped: 2, Failed: 0
```

#### Important Notes
- Does not activate plugins automatically
- Skips existing plugins to prevent overwriting
- Requires write permissions on wp-content/plugins

---

## Batch Processing

Commands that support batch processing (`export` and `import`) include:

### Benefits
- **Memory Efficiency**: Processes large datasets without exhausting memory
- **Progress Tracking**: Real-time feedback on progress
- **Server-Friendly**: Built-in delays prevent overwhelming the server
- **Scalable**: Successfully tested with 395+ posts

### Batch Size Recommendations

| Site Size | Recommended Batch Size |
|-----------|----------------------|
| Small (< 1,000 posts) | `--batch-size=100` or `--batch-size=0` |
| Medium (1,000-10,000 posts) | `--batch-size=50` (default) |
| Large (> 10,000 posts) | `--batch-size=25` |
| Very Large | `--batch-size=10` with monitoring |

---

## Automation Examples

### Daily Content Backup
```bash
#!/bin/bash
# Daily automated content backup script

cd /path/to/wordpress
wp srdt export
cd wp-content/themes/[theme]/sync
git add -A
git commit -m "Automated content backup $(date +%Y-%m-%d)"
git push origin main
```

### Deployment Script
```bash
#!/bin/bash
# Deploy content from staging to production

# On staging
wp srdt export
git add sync/
git commit -m "Content export for deployment"
git push

# On production
git pull
wp srdt import
```

### Complete Site Sync
```bash
#!/bin/bash
# Full site synchronization

echo "Exporting content..."
wp srdt export

echo "Backing up database..."
wp srdt dump-db

echo "Backing up plugins..."
wp srdt backup-plugins

echo "Generating module pages..."
wp srdt generate-modules

echo "Sync complete!"
```

### CI/CD Integration

**GitHub Actions Example:**
```yaml
name: Content Sync

on:
  schedule:
    - cron: '0 2 * * *'  # Daily at 2 AM

jobs:
  sync:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      
      - name: Export Content
        run: |
          wp srdt export
          
      - name: Commit Changes
        run: |
          git config user.name "GitHub Actions"
          git config user.email "actions@github.com"
          git add sync/
          git commit -m "Auto-export: ${{ github.sha }}" || exit 0
          git push
```

---

## Error Handling

### Common Issues

**Permission Denied:**
```bash
# Fix directory permissions
chmod 755 wp-content/themes/[theme]/sync/
chown www-data:www-data wp-content/themes/[theme]/sync/
```

**Command Not Found:**
```bash
# Verify WP-CLI installation
wp --info

# Check plugin activation
wp plugin list | grep sr-dev-tools
```

**Memory Issues:**
```bash
# Use smaller batch size
wp srdt export --batch-size=25

# Or increase PHP memory limit
php -d memory_limit=512M $(which wp) srdt export
```

---

## Security Considerations

### Capability Requirements
All commands require the user to have the `SR` capability. This is typically only available to administrators.

### Production Environment
Commands are blocked in production environments when `WP_ENV` is set to `production`.

### Sensitive Data
The export command automatically excludes sensitive options like:
- API keys and secrets
- Database credentials
- Authentication salts
- User passwords

---

## Command Reference Summary

| Command | Purpose | Batch Support | Destructive |
|---------|---------|---------------|-------------|
| `wp srdt export` | Export content to JSON | ✅ Yes | ❌ No |
| `wp srdt import` | Import JSON to database | ✅ Yes | ⚠️ Yes |
| `wp srdt generate-modules` | Create module pages | ❌ No | ❌ No |
| `wp srdt dump-db` | Backup database | ❌ No | ❌ No |
| `wp srdt import-db` | Restore database | ❌ No | ⚠️ Yes |
| `wp srdt backup-plugins` | Archive plugins | ❌ No | ❌ No |
| `wp srdt install-plugins` | Install plugin archives | ❌ No | ❌ No |

---

## Getting Help

### View Available Commands
```bash
wp srdt
```

### Get Command Help
```bash
wp help srdt export
wp help srdt import
wp help srdt generate-modules
```

### Check Plugin Status
```bash
wp plugin status sr-dev-tools
```

---

## Related Documentation

- [Plugin Installation Feature](./plugin-installation-feature.md)
- [Module Generation](./module-generation.md)
- [Database Management](./database-management.md)
- [Main README](../README.md)
