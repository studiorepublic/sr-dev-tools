<?php

/**
 * Get the sync path for exports
 * 
 * @package   DB Version Control
 * @author    Robert DeVore <me@robertdevore.com>
 * @since     1.0.0
 * @return string
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Render the export settings page
 * 
 * @since  1.0.0
 * @return void
 */
function srdt_render_export_page() {
	// Check user capabilities
	if ( ! current_user_can( 'SR' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'srdt' ) );
	}

	// Check if we're in production environment
	$wp_env = defined( 'WP_ENV' ) ? WP_ENV : ( getenv( 'WP_ENV' ) ?: 'production' );
	if ( 'production' === $wp_env ) {
		// Remove sync folder if it exists
		$sync_path = srdt_get_sync_path();
		if ( is_dir( $sync_path ) ) {
			srdt_remove_directory_recursive( $sync_path );
		}
		
		// Show error message and exit
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SR Dev Tools', 'srdt' ); ?></h1>
			<div class="notice notice-error">
				<p><strong><?php esc_html_e( 'Production Environment Detected', 'srdt' ); ?></strong></p>
				<p><?php esc_html_e( 'SR Dev Tools is not allowed to be used in production environments for security reasons. The plugin has been disabled and any existing sync folders have been removed.', 'srdt' ); ?></p>
				<p><?php esc_html_e( 'Please deactivate this plugin on production sites.', 'srdt' ); ?></p>
			</div>
		</div>
		<?php
		return;
	}

	$custom_path         = get_option( 'srdt_sync_path', '' );
	$selected_post_types = get_option( 'srdt_post_types', [] );

	// Handle custom sync path form.
	if ( isset( $_POST['srdt_sync_path_save'] ) && wp_verify_nonce( $_POST['srdt_sync_path_nonce'], 'srdt_sync_path_action' ) ) {
		// Additional capability check
		if ( ! current_user_can( 'SR' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'srdt' ) );
		}

		$new_path = sanitize_text_field( wp_unslash( $_POST['srdt_sync_path'] ) );
		
		// Validate path to prevent directory traversal
		$new_path = srdt_validate_sync_path( $new_path );
		if ( false === $new_path ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Invalid sync path provided. Path cannot contain ../ or other unsafe characters.', 'srdt' ) . '</p></div>';
		} else {
			update_option( 'srdt_sync_path', $new_path );
			$custom_path = $new_path;

			// Create the directory immediately to test the path.
			$resolved_path = srdt_get_sync_path();
			if ( wp_mkdir_p( $resolved_path ) ) {
				echo '<div class="notice notice-success"><p>' . sprintf( esc_html__( 'Sync folder updated and created at: %s', 'srdt' ), '<code>' . esc_html( $resolved_path ) . '</code>' ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error"><p>' . sprintf( esc_html__( 'Sync folder setting saved, but could not create directory at: %s. Please check permissions.', 'srdt' ), '<code>' . esc_html( $resolved_path ) . '</code>' ) . '</p></div>';
			}
		}
	}

	// Handle post types selection form.
	if ( isset( $_POST['srdt_post_types_save'] ) && wp_verify_nonce( $_POST['srdt_post_types_nonce'], 'srdt_post_types_action' ) ) {
		// Additional capability check
		if ( ! current_user_can( 'SR' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'srdt' ) );
		}

		$new_post_types = [];
		if ( isset( $_POST['srdt_post_types'] ) && is_array( $_POST['srdt_post_types'] ) ) {
			$new_post_types = array_map( 'sanitize_text_field', wp_unslash( $_POST['srdt_post_types'] ) );
			
			// Get all valid post types (public + FSE types)
			$valid_post_types = get_post_types( [ 'public' => true ] );
			
			// Add FSE post types to valid list if block theme is active
			if ( wp_is_block_theme() ) {
				$fse_types = [ 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' ];
				$valid_post_types = array_merge( $valid_post_types, array_combine( $fse_types, $fse_types ) );
			}
			
			// Filter to only include valid post types
			$new_post_types = array_intersect( $new_post_types, array_keys( $valid_post_types ) );
		}
		
		update_option( 'srdt_post_types', $new_post_types );
		$selected_post_types = $new_post_types;
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Post types selection updated!', 'srdt' ) . '</p></div>';
	}

	// Handle export form.
	if ( isset( $_POST['srdt_export_nonce'] ) && wp_verify_nonce( $_POST['srdt_export_nonce'], 'srdt_export_action' ) ) {
		// Additional capability check
		if ( ! current_user_can( 'SR' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'srdt' ) );
		}

		// Run full export.
		SRDT_Sync_Posts::export_options_to_json();
		SRDT_Sync_Posts::export_menus_to_json();

		$posts = get_posts( [
			'post_type'      => 'any',
			'posts_per_page' => -1,
			'post_status'    => 'any',
		] );

		foreach ( $posts as $post ) {
			SRDT_Sync_Posts::export_post_to_json( $post->ID, $post );
		}

		echo '<div class="notice notice-success"><p>' . esc_html__( 'Full export completed!', 'srdt' ) . '</p></div>';
	}

	// Handle Generate Modules Pages form.
	if ( isset( $_POST['srdt_generate_modules'] ) && isset( $_POST['srdt_generate_modules_nonce'] ) && wp_verify_nonce( $_POST['srdt_generate_modules_nonce'], 'srdt_generate_modules_action' ) ) {
		if ( ! current_user_can( 'SR' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'srdt' ) );
		}
		$gen_results = srdt_generate_modules_pages();
		if ( isset( $gen_results['error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $gen_results['error'] ) . '</p></div>';
		} else {
			$created = isset( $gen_results['created'] ) ? count( (array) $gen_results['created'] ) : 0;
			$skipped = isset( $gen_results['skipped'] ) ? count( (array) $gen_results['skipped'] ) : 0;
			$errors  = isset( $gen_results['errors'] ) ? count( (array) $gen_results['errors'] ) : 0;
			$modules_created = ! empty( $gen_results['modules_page_created'] );
			$summary  = $modules_created ? __( 'Modules page created. ', 'srdt' ) : __( 'Modules page already existed. ', 'srdt' );
			$summary .= sprintf( __( 'Pages created: %d. Skipped: %d. Errors: %d.', 'srdt' ), $created, $skipped, $errors );
			echo '<div class="notice notice-success"><p>' . esc_html( $summary ) . '</p></div>';
			if ( ! empty( $gen_results['errors'] ) ) {
				$errs = '<ul style="margin: .5em 0 0 1.2em;">';
				foreach ( $gen_results['errors'] as $err ) {
					$errs .= '<li>' . esc_html( $err ) . '</li>';
				}
				$errs .= '</ul>';
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'Some issues occurred:', 'srdt' ) . '</p>' . $errs . '</div>';
			}
		}
	}

	// Handle Dump Database action.
	if ( isset( $_POST['srdt_dump_db'] ) && isset( $_POST['srdt_dump_db_nonce'] ) && wp_verify_nonce( $_POST['srdt_dump_db_nonce'], 'srdt_dump_db_action' ) ) {
		if ( ! current_user_can( 'SR' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'srdt' ) );
		}

		$database_dir = trailingslashit( get_stylesheet_directory() ) . 'sync/database/';
		if ( ! is_dir( $database_dir ) ) {
			wp_mkdir_p( $database_dir );
		}
		// Look for both tar.gz and sql files for counting
		$before_tar = glob( $database_dir . '*.tar.gz' );
		$before_sql = glob( $database_dir . '*.sql' );
		$before = array_merge( $before_tar, $before_sql );
		$before_count = is_array( $before ) ? count( $before ) : 0;

		SRDT_Sync_Posts::dump_database();
		clearstatcache();

		$after_tar = glob( $database_dir . '*.tar.gz' );
		$after_sql = glob( $database_dir . '*.sql' );
		$after = array_merge( $after_tar, $after_sql );
		$after_count = is_array( $after ) ? count( $after ) : 0;
		$created = max( 0, $after_count - $before_count );
		$latest = '';
		if ( ! empty( $after ) ) {
			usort( $after, function( $a, $b ) { return filemtime( $b ) <=> filemtime( $a ); } );
			$latest = $after[0];
		}

		if ( $created > 0 && $latest ) {
			echo '<div class="notice notice-success"><p>' . sprintf( esc_html__( 'Database dumped to: %s', 'srdt' ), '<code>' . esc_html( $latest ) . '</code>' ) . '</p></div>';
		} else {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Database dump failed or no new dump was created.', 'srdt' ) . '</p></div>';
		}
	}

	// Handle Import Database action.
	if ( isset( $_POST['srdt_import_db'] ) && isset( $_POST['srdt_import_db_nonce'] ) && wp_verify_nonce( $_POST['srdt_import_db_nonce'], 'srdt_import_db_action' ) ) {
		if ( ! current_user_can( 'SR' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'srdt' ) );
		}

		$database_dir = trailingslashit( get_stylesheet_directory() ) . 'sync/database/';
		if ( ! is_dir( $database_dir ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'No database directory found in the current theme.', 'srdt' ) . '</p></div>';
		} else {
			// Look for both tar.gz and sql files
			$tar_files = glob( $database_dir . '*.tar.gz' );
			$sql_files = glob( $database_dir . '*.sql' );
			$files = array_merge( $tar_files, $sql_files );
			
			if ( empty( $files ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'No database dump files found to import.', 'srdt' ) . '</p></div>';
			} else {
				usort( $files, function( $a, $b ) { return filemtime( $b ) <=> filemtime( $a ); } );
				$latest = $files[0];
				SRDT_Sync_Posts::import_database();
				echo '<div class="notice notice-success"><p>' . sprintf( esc_html__( 'Import completed from: %s. Site URL and Home restored.', 'srdt' ), '<code>' . esc_html( basename( $latest ) ) . '</code>' ) . '</p></div>';
			}
		}
	}

	// Handle Delete Dump action.
	if ( isset( $_POST['srdt_delete_dump'] ) && isset( $_POST['srdt_delete_dump_nonce'] ) && wp_verify_nonce( $_POST['srdt_delete_dump_nonce'], 'srdt_delete_dump_action' ) ) {
		if ( ! current_user_can( 'SR' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'srdt' ) );
		}

		$database_dir = trailingslashit( get_stylesheet_directory() ) . 'sync/database/';
		$dump_file    = isset( $_POST['srdt_dump_file'] ) ? sanitize_text_field( wp_unslash( $_POST['srdt_dump_file'] ) ) : '';
		$dump_file    = basename( $dump_file ); // prevent traversal

		if ( empty( $dump_file ) || ! preg_match( '/\.(sql|tar\.gz)$/i', $dump_file ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Invalid dump file specified.', 'srdt' ) . '</p></div>';
		} else {
			$full_path = $database_dir . $dump_file;
			$dir_real  = realpath( $database_dir );
			$file_real = is_file( $full_path ) ? realpath( $full_path ) : false;
			if ( $dir_real && $file_real && strpos( $file_real, $dir_real ) === 0 && is_file( $file_real ) ) {
				if ( @unlink( $file_real ) ) {
					echo '<div class="notice notice-success"><p>' . sprintf( esc_html__( 'Deleted dump: %s', 'srdt' ), '<code>' . esc_html( $dump_file ) . '</code>' ) . '</p></div>';
				} else {
					echo '<div class="notice notice-error"><p>' . sprintf( esc_html__( 'Could not delete file: %s', 'srdt' ), '<code>' . esc_html( $dump_file ) . '</code>' ) . '</p></div>';
				}
			} else {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Dump file not found or invalid path.', 'srdt' ) . '</p></div>';
			}
		}
	}

	// Handle Delete Plugin Backup action.
	if ( isset( $_POST['srdt_delete_plugin'] ) && isset( $_POST['srdt_delete_plugin_nonce'] ) && wp_verify_nonce( $_POST['srdt_delete_plugin_nonce'], 'srdt_delete_plugin_action' ) ) {
		if ( ! current_user_can( 'SR' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'srdt' ) );
		}

		$plugins_dir = trailingslashit( get_stylesheet_directory() ) . 'sync/plugins/';
		$plugin_file = isset( $_POST['srdt_plugin_file'] ) ? sanitize_text_field( wp_unslash( $_POST['srdt_plugin_file'] ) ) : '';
		$plugin_file = basename( $plugin_file ); // prevent traversal

		if ( empty( $plugin_file ) || ! preg_match( '/\.tar\.gz$/i', $plugin_file ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Invalid plugin backup file specified.', 'srdt' ) . '</p></div>';
		} else {
			$full_path = $plugins_dir . $plugin_file;
			$dir_real  = realpath( $plugins_dir );
			$file_real = is_file( $full_path ) ? realpath( $full_path ) : false;
			if ( $dir_real && $file_real && strpos( $file_real, $dir_real ) === 0 && is_file( $file_real ) ) {
				if ( @unlink( $file_real ) ) {
					echo '<div class="notice notice-success"><p>' . sprintf( esc_html__( 'Deleted plugin backup: %s', 'srdt' ), '<code>' . esc_html( $plugin_file ) . '</code>' ) . '</p></div>';
				} else {
					echo '<div class="notice notice-error"><p>' . sprintf( esc_html__( 'Could not delete file: %s', 'srdt' ), '<code>' . esc_html( $plugin_file ) . '</code>' ) . '</p></div>';
				}
			} else {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Plugin backup file not found or invalid path.', 'srdt' ) . '</p></div>';
			}
		}
	}

	// Handle Delete All Plugin Backups action.
	if ( isset( $_POST['srdt_delete_all_plugins'] ) && isset( $_POST['srdt_delete_all_plugins_nonce'] ) && wp_verify_nonce( $_POST['srdt_delete_all_plugins_nonce'], 'srdt_delete_all_plugins_action' ) ) {
		if ( ! current_user_can( 'SR' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'srdt' ) );
		}

		$plugins_dir = trailingslashit( get_stylesheet_directory() ) . 'sync/plugins/';
		$plugin_files = is_dir( $plugins_dir ) ? glob( $plugins_dir . '*.tar.gz' ) : [];
		
		$deleted_count = 0;
		$failed_count = 0;
		
		foreach ( $plugin_files as $plugin_file ) {
			$dir_real  = realpath( $plugins_dir );
			$file_real = is_file( $plugin_file ) ? realpath( $plugin_file ) : false;
			if ( $dir_real && $file_real && strpos( $file_real, $dir_real ) === 0 && is_file( $file_real ) ) {
				if ( @unlink( $file_real ) ) {
					$deleted_count++;
				} else {
					$failed_count++;
				}
			} else {
				$failed_count++;
			}
		}
		
		if ( $deleted_count > 0 ) {
			echo '<div class="notice notice-success"><p>' . sprintf( esc_html__( 'Deleted %d plugin backup(s).', 'srdt' ), $deleted_count );
			if ( $failed_count > 0 ) {
				echo ' ' . sprintf( esc_html__( '%d file(s) could not be deleted.', 'srdt' ), $failed_count );
			}
			echo '</p></div>';
		} else {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'No plugin backups were deleted.', 'srdt' ) . '</p></div>';
		}
	}

	// Handle Download Plugin Backup action.
	if ( isset( $_GET['srdt_download_plugin'] ) && isset( $_GET['srdt_download_plugin_nonce'] ) && wp_verify_nonce( $_GET['srdt_download_plugin_nonce'], 'srdt_download_plugin_action' ) ) {
		if ( ! current_user_can( 'SR' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'srdt' ) );
		}

		$plugins_dir = trailingslashit( get_stylesheet_directory() ) . 'sync/plugins/';
		$plugin_file = isset( $_GET['srdt_plugin_file'] ) ? sanitize_text_field( wp_unslash( $_GET['srdt_plugin_file'] ) ) : '';
		$plugin_file = basename( $plugin_file ); // prevent traversal

		if ( empty( $plugin_file ) || ! preg_match( '/\.tar\.gz$/i', $plugin_file ) ) {
			wp_die( esc_html__( 'Invalid plugin backup file specified.', 'srdt' ) );
		}

		$full_path = $plugins_dir . $plugin_file;
		$dir_real  = realpath( $plugins_dir );
		$file_real = is_file( $full_path ) ? realpath( $full_path ) : false;
		
		if ( $dir_real && $file_real && strpos( $file_real, $dir_real ) === 0 && is_file( $file_real ) ) {
			// Set headers for download
			header( 'Content-Type: application/gzip' );
			header( 'Content-Disposition: attachment; filename="' . $plugin_file . '"' );
			header( 'Content-Length: ' . filesize( $file_real ) );
			header( 'Cache-Control: no-cache, must-revalidate' );
			header( 'Expires: 0' );
			
			// Output file
			readfile( $file_real );
			exit;
		} else {
			wp_die( esc_html__( 'Plugin backup file not found or invalid path.', 'srdt' ) );
		}
	}

	// Handle Backup Plugins action.
	if ( isset( $_POST['srdt_backup_plugins'] ) && isset( $_POST['srdt_backup_plugins_nonce'] ) && wp_verify_nonce( $_POST['srdt_backup_plugins_nonce'], 'srdt_backup_plugins_action' ) ) {
		if ( ! current_user_can( 'SR' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'srdt' ) );
		}

		$plugins_target = trailingslashit( get_stylesheet_directory() ) . 'sync/plugins/';
		if ( ! is_dir( $plugins_target ) ) {
			wp_mkdir_p( $plugins_target );
		}

		$created = (int) SRDT_Sync_Posts::backup_plugins();
		clearstatcache();

		echo '<div class="notice notice-success"><p>' . sprintf( esc_html__( 'Created %d plugin tar.gz backup(s) in %s', 'srdt' ), (int) $created, '<code>' . esc_html( $plugins_target ) . '</code>' ) . '</p></div>';
	}

	// Get the current resolved path for display.
	$resolved_path = srdt_get_sync_path();
		
	// Get all public post types.
	$all_post_types = srdt_get_available_post_types();

	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'DB Version Control', 'srdt' ); ?></h1>
        <form method="post">
            <?php wp_nonce_field( 'srdt_export_action', 'srdt_export_nonce' ); ?>
            <p><?php esc_html_e( 'This will export all posts, options, and menus to JSON files.', 'srdt' ); ?></p>
            <?php submit_button( esc_html__( 'Run Full Export', 'srdt' ) ); ?>
        </form>

        <hr />

        <form method="post">
            <?php wp_nonce_field( 'srdt_post_types_action', 'srdt_post_types_nonce' ); ?>
            <h2><?php esc_html_e( 'Post Types to Export/Import', 'srdt' ); ?></h2>
            <p><label for="srdt-post-types-select"><?php esc_html_e( 'Select which post types should be included in exports and imports.', 'srdt' ); ?></label></p>
            <select name="srdt_post_types[]" multiple="multiple" id="srdt-post-types-select" style="width: 100%; height: 200px;">
                <?php foreach ( $all_post_types as $post_type => $post_type_obj ) : ?>
                    <option value="<?php echo esc_attr( $post_type ); ?>" <?php selected( in_array( $post_type, $selected_post_types, true ) ); ?>>
                        <?php echo esc_html( $post_type_obj->label ); ?> (<?php echo esc_html( $post_type ); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <?php submit_button( esc_html__( 'Save Post Types', 'srdt' ), 'secondary', 'srdt_post_types_save' ); ?>
        </form>

        <hr />

        <form method="post">
            <?php wp_nonce_field( 'srdt_sync_path_action', 'srdt_sync_path_nonce' ); ?>
            <h2><?php esc_html_e( 'Custom Sync Folder Path', 'srdt' ); ?></h2>
            <p><label for="srdt_sync_path"><?php esc_html_e( 'Enter the full or relative path (from site root) where JSON files should be saved.', 'srdt' ); ?></label></p>
            <input type="text" name="srdt_sync_path" id="srdt_sync_path" value="<?php echo esc_attr( $custom_path ); ?>" style="width: 100%;" placeholder="<?php esc_attr_e( 'e.g., wp-content/plugins/db-version-control/sync-testing-folder/', 'srdt' ); ?>">
            <p><strong><?php esc_html_e( 'Current resolved path:', 'srdt' ); ?></strong> <code><?php echo esc_html( $resolved_path ); ?></code></p>
            <?php submit_button( esc_html__( 'Save Folder Path', 'srdt' ), 'secondary', 'srdt_sync_path_save' ); ?>
        </form>

        <hr />

        <form method="post">
            <?php wp_nonce_field( 'srdt_generate_modules_action', 'srdt_generate_modules_nonce' ); ?>
            <h2><?php esc_html_e( 'Modules Pages', 'srdt' ); ?></h2>
            <p><?php esc_html_e( 'Scan theme ACF field groups for fields starting with "Partial" and generate child pages under the "Modules" parent.', 'srdt' ); ?></p>
            <?php submit_button( esc_html__( 'Generate modules pages', 'srdt' ), 'secondary', 'srdt_generate_modules' ); ?>
        </form>

        <hr />

        <h2><?php esc_html_e( 'Database', 'srdt' ); ?></h2>
        <form method="post">
            <?php wp_nonce_field( 'srdt_dump_db_action', 'srdt_dump_db_nonce' ); ?>
            <p><?php esc_html_e( 'Dump the current database to the active theme\'s sync/database folder.', 'srdt' ); ?></p>
            <?php submit_button( esc_html__( 'Dump database', 'srdt' ), 'secondary', 'srdt_dump_db' ); ?>
        </form>
        <?php
        // List available database dump files (both tar.gz and sql) in theme sync/database folder.
        $database_dir = trailingslashit( get_stylesheet_directory() ) . 'sync/database/';
        $tar_files = is_dir( $database_dir ) ? glob( $database_dir . '*.tar.gz' ) : [];
        $sql_files = is_dir( $database_dir ) ? glob( $database_dir . '*.sql' ) : [];
        $srdt_files = array_merge( $tar_files, $sql_files );
        
        if ( ! empty( $srdt_files ) ) {
            usort( $srdt_files, function( $a, $b ) { return filemtime( $b ) <=> filemtime( $a ); } );
            echo '<h3>' . esc_html__( 'Available database dumps', 'srdt' ) . '</h3>';
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead>';
            echo '<tr>';
            echo '<th scope="col">' . esc_html__( 'File Name', 'srdt' ) . '</th>';
            echo '<th scope="col">' . esc_html__( 'Size', 'srdt' ) . '</th>';
            echo '<th scope="col">' . esc_html__( 'Actions', 'srdt' ) . '</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';
            foreach ( $srdt_files as $srdt_file ) {
                $srdt_basename = basename( $srdt_file );
                $srdt_size     = size_format( @filesize( $srdt_file ) );
                echo '<tr>';
                echo '<td><code>' . esc_html( $srdt_basename ) . '</code></td>';
                echo '<td>' . esc_html( $srdt_size ) . '</td>';
                echo '<td>';
                echo '<form method="post" style="display:inline;">';
                wp_nonce_field( 'srdt_delete_dump_action', 'srdt_delete_dump_nonce' );
                echo '<input type="hidden" name="srdt_dump_file" value="' . esc_attr( $srdt_basename ) . '" />';
                submit_button( esc_html__( 'Delete', 'srdt' ), 'button-secondary button-small', 'srdt_delete_dump', false, [ 'onclick' => "return confirm('" . esc_js( __( 'Delete this dump file?', 'srdt' ) ) . "');" ] );
                echo '</form>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody>';
            echo '</table>';
        } else {
            echo '<p>' . esc_html__( 'No database dump files found.', 'srdt' ) . '</p>';
        }
        ?>
        <form method="post" style="margin-top: 10px;">
            <?php wp_nonce_field( 'srdt_import_db_action', 'srdt_import_db_nonce' ); ?>
            <p><?php esc_html_e( 'Import the most recent SQL dump from the theme sync/database folder. The Site URL and Home settings will be restored after import.', 'srdt' ); ?></p>
            <?php submit_button( esc_html__( 'Import database', 'srdt' ), 'secondary', 'srdt_import_db' ); ?>
        </form>

        <hr />

        <h2><?php esc_html_e( 'Plugins', 'srdt' ); ?></h2>
        <form method="post">
            <?php wp_nonce_field( 'srdt_backup_plugins_action', 'srdt_backup_plugins_nonce' ); ?>
            <p><?php esc_html_e( 'Archive each plugin under wp-content/plugins into compressed tar.gz files in the theme\'s sync/plugins folder.', 'srdt' ); ?></p>
            <?php submit_button( esc_html__( 'Backup plugins', 'srdt' ), 'secondary', 'srdt_backup_plugins' ); ?>
        </form>
        <?php
        // List available plugin backup files in theme sync/plugins folder.
        $plugins_dir = trailingslashit( get_stylesheet_directory() ) . 'sync/plugins/';
        $plugin_files = is_dir( $plugins_dir ) ? glob( $plugins_dir . '*.tar.gz' ) : [];
        
        if ( ! empty( $plugin_files ) ) {
            usort( $plugin_files, function( $a, $b ) { return filemtime( $b ) <=> filemtime( $a ); } );
            echo '<h3>' . esc_html__( 'Available plugin backups', 'srdt' ) . '</h3>';
            
            // Add bulk action buttons
            echo '<div style="margin-bottom: 10px;">';
            if ( count( $plugin_files ) > 1 ) {
                echo '<button type="button" id="srdt-download-all-plugins" class="button button-secondary" style="margin-right: 10px;">' . esc_html__( 'Download All', 'srdt' ) . '</button>';
            }
            if ( count( $plugin_files ) > 0 ) {
                echo '<form method="post" style="display:inline;">';
                wp_nonce_field( 'srdt_delete_all_plugins_action', 'srdt_delete_all_plugins_nonce' );
                submit_button( esc_html__( 'Delete All Plugin Backups', 'srdt' ), 'button-secondary', 'srdt_delete_all_plugins', false, [ 'onclick' => "return confirm('" . esc_js( __( 'Are you sure you want to delete ALL plugin backups? This action cannot be undone.', 'srdt' ) ) . "');" ] );
                echo '</form>';
            }
            echo '</div>';
            
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead>';
            echo '<tr>';
            echo '<th scope="col">' . esc_html__( 'File Name', 'srdt' ) . '</th>';
            echo '<th scope="col">' . esc_html__( 'Size', 'srdt' ) . '</th>';
            echo '<th scope="col">' . esc_html__( 'Actions', 'srdt' ) . '</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';
            foreach ( $plugin_files as $plugin_file ) {
                $plugin_basename = basename( $plugin_file );
                $plugin_size     = size_format( @filesize( $plugin_file ) );
                
                // Create download URL
                $download_url = add_query_arg( [
                    'srdt_download_plugin' => '1',
                    'srdt_plugin_file' => $plugin_basename,
                    'srdt_download_plugin_nonce' => wp_create_nonce( 'srdt_download_plugin_action' )
                ] );
                
                echo '<tr>';
                echo '<td><code>' . esc_html( $plugin_basename ) . '</code></td>';
                echo '<td>' . esc_html( $plugin_size ) . '</td>';
                echo '<td>';
                echo '<a href="' . esc_url( $download_url ) . '" class="button button-secondary button-small srdt-download-plugin" data-filename="' . esc_attr( $plugin_basename ) . '" style="margin-right: 5px;">' . esc_html__( 'Download', 'srdt' ) . '</a>';
                echo '<form method="post" style="display:inline;">';
                wp_nonce_field( 'srdt_delete_plugin_action', 'srdt_delete_plugin_nonce' );
                echo '<input type="hidden" name="srdt_plugin_file" value="' . esc_attr( $plugin_basename ) . '" />';
                submit_button( esc_html__( 'Delete', 'srdt' ), 'button-secondary button-small', 'srdt_delete_plugin', false, [ 'onclick' => "return confirm('" . esc_js( __( 'Delete this plugin backup?', 'srdt' ) ) . "');" ] );
                echo '</form>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody>';
            echo '</table>';
        } else {
            echo '<p>' . esc_html__( 'No plugin backup files found.', 'srdt' ) . '</p>';
        }
        ?>
    </div>

	<script>
	jQuery(document).ready(function($) {
		// Handle "Download All" plugin backups
		$('#srdt-download-all-plugins').on('click', function() {
			var downloadLinks = $('.srdt-download-plugin');
			var totalFiles = downloadLinks.length;
			var currentIndex = 0;
			
			if (totalFiles === 0) {
				alert(<?php echo wp_json_encode( esc_html__( 'No plugin backups found to download.', 'srdt' ) ); ?>);
				return;
			}
			
			// Confirm with user
			var confirmMessage = <?php echo wp_json_encode( esc_html__( 'This will download %d plugin backup files. Continue?', 'srdt' ) ); ?>;
			confirmMessage = confirmMessage.replace('%d', totalFiles);
			if (!confirm(confirmMessage)) {
				return;
			}
			
			// Function to download next file
			function downloadNext() {
				if (currentIndex >= totalFiles) {
					alert(<?php echo wp_json_encode( esc_html__( 'All plugin backups have been queued for download.', 'srdt' ) ); ?>);
					return;
				}
				
				var link = downloadLinks.eq(currentIndex);
				var filename = link.data('filename');
				
				// Create a temporary link and click it
				var tempLink = $('<a>').attr({
					href: link.attr('href'),
					download: filename
				}).appendTo('body');
				
				tempLink[0].click();
				tempLink.remove();
				
				currentIndex++;
				
				// Wait 500ms before next download to avoid browser blocking
				setTimeout(downloadNext, 500);
			}
			
			// Start downloading
			downloadNext();
		});
	});
	</script>
	<?php
}

/**
 * Get all available post types for the settings page.
 * 
 * @since  1.1.0
 * @return array
 */
function srdt_get_available_post_types() {
    $post_types = get_post_types( [ 'public' => true ], 'objects' );
    
    // Add FSE post types if block theme is active
    if ( wp_is_block_theme() ) {
        $fse_types = [
            'wp_template' => (object) [
                'label' => __( 'Templates (FSE)', 'srdt' ),
                'name' => 'wp_template'
            ],
            'wp_template_part' => (object) [
                'label' => __( 'Template Parts (FSE)', 'srdt' ),
                'name' => 'wp_template_part'
            ],
            'wp_global_styles' => (object) [
                'label' => __( 'Global Styles (FSE)', 'srdt' ),
                'name' => 'wp_global_styles'
            ],
            'wp_navigation' => (object) [
                'label' => __( 'Navigation (FSE)', 'srdt' ),
                'name' => 'wp_navigation'
            ],
        ];
        
        $post_types = array_merge( $post_types, $fse_types );
    }
    
    return $post_types;
}
