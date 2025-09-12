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
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'srdt' ) );
	}

	$custom_path         = get_option( 'srdt_sync_path', '' );
	$selected_post_types = get_option( 'srdt_post_types', [] );

	// Handle custom sync path form.
	if ( isset( $_POST['srdt_sync_path_save'] ) && wp_verify_nonce( $_POST['srdt_sync_path_nonce'], 'srdt_sync_path_action' ) ) {
		// Additional capability check
		if ( ! current_user_can( 'manage_options' ) ) {
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
		if ( ! current_user_can( 'manage_options' ) ) {
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
		if ( ! current_user_can( 'manage_options' ) ) {
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
		if ( ! current_user_can( 'manage_options' ) ) {
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
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'srdt' ) );
		}

		$database_dir = trailingslashit( get_stylesheet_directory() ) . 'sync/database/';
		if ( ! is_dir( $database_dir ) ) {
			wp_mkdir_p( $database_dir );
		}
		$before = glob( $database_dir . '*.sql' );
		$before_count = is_array( $before ) ? count( $before ) : 0;

		SRDT_Sync_Posts::dump_database();
		clearstatcache();

		$after = glob( $database_dir . '*.sql' );
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
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'srdt' ) );
		}

		$database_dir = trailingslashit( get_stylesheet_directory() ) . 'sync/database/';
		if ( ! is_dir( $database_dir ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'No database directory found in the current theme.', 'srdt' ) . '</p></div>';
		} else {
			$files = glob( $database_dir . '*.sql' );
			if ( empty( $files ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'No SQL dump files found to import.', 'srdt' ) . '</p></div>';
			} else {
				usort( $files, function( $a, $b ) { return filemtime( $b ) <=> filemtime( $a ); } );
				$latest = $files[0];
				SRDT_Sync_Posts::import_database();
				echo '<div class="notice notice-success"><p>' . sprintf( esc_html__( 'Import completed from: %s. Site URL and Home restored.', 'srdt' ), '<code>' . esc_html( $latest ) . '</code>' ) . '</p></div>';
			}
		}
	}

	// Handle Delete Dump action.
	if ( isset( $_POST['srdt_delete_dump'] ) && isset( $_POST['srdt_delete_dump_nonce'] ) && wp_verify_nonce( $_POST['srdt_delete_dump_nonce'], 'srdt_delete_dump_action' ) ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'srdt' ) );
		}

		$database_dir = trailingslashit( get_stylesheet_directory() ) . 'sync/database/';
		$dump_file    = isset( $_POST['srdt_dump_file'] ) ? sanitize_text_field( wp_unslash( $_POST['srdt_dump_file'] ) ) : '';
		$dump_file    = basename( $dump_file ); // prevent traversal

		if ( empty( $dump_file ) || ! preg_match( '/\.sql$/i', $dump_file ) ) {
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

	// Handle Backup Plugins action.
	if ( isset( $_POST['srdt_backup_plugins'] ) && isset( $_POST['srdt_backup_plugins_nonce'] ) && wp_verify_nonce( $_POST['srdt_backup_plugins_nonce'], 'srdt_backup_plugins_action' ) ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'srdt' ) );
		}

		$plugins_target = trailingslashit( get_stylesheet_directory() ) . 'sync/plugins/';
		if ( ! is_dir( $plugins_target ) ) {
			wp_mkdir_p( $plugins_target );
		}
		$before = glob( $plugins_target . '*.zip' );
		$before_count = is_array( $before ) ? count( $before ) : 0;

		SRDT_Sync_Posts::backup_plugins();
		clearstatcache();

		$after = glob( $plugins_target . '*.zip' );
		$after_count = is_array( $after ) ? count( $after ) : 0;
		$created = max( 0, $after_count - $before_count );

		if ( $created > 0 ) {
			echo '<div class="notice notice-success"><p>' . sprintf( esc_html__( 'Created %d plugin backup(s) in %s', 'srdt' ), (int) $created, '<code>' . esc_html( $plugins_target ) . '</code>' ) . '</p></div>';
		} else {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'No new plugin backups were created.', 'srdt' ) . '</p></div>';
		}
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
            <select name="srdt_post_types[]" multiple="multiple" id="srdt-post-types-select" style="width: 100%;">
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
        // List available SQL dump files in theme sync/database folder.
        $database_dir = trailingslashit( get_stylesheet_directory() ) . 'sync/database/';
        $srdt_files = is_dir( $database_dir ) ? glob( $database_dir . '*.sql' ) : [];
        if ( ! empty( $srdt_files ) ) {
            usort( $srdt_files, function( $a, $b ) { return filemtime( $b ) <=> filemtime( $a ); } );
            echo '<h3>' . esc_html__( 'Available database dumps', 'srdt' ) . '</h3>';
            echo '<ul class="srdt-dumps-list">';
            foreach ( $srdt_files as $srdt_file ) {
                $srdt_basename = basename( $srdt_file );
                $srdt_size     = size_format( @filesize( $srdt_file ) );
                $srdt_time     = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), @filemtime( $srdt_file ) );
                echo '<li>';
                echo '<code>' . esc_html( $srdt_basename ) . '</code> <small>(' . esc_html( $srdt_size ) . ', ' . esc_html( $srdt_time ) . ')</small> ';
                echo '<form method="post" style="display:inline;margin-left:8px;">';
                wp_nonce_field( 'srdt_delete_dump_action', 'srdt_delete_dump_nonce' );
                echo '<input type="hidden" name="srdt_dump_file" value="' . esc_attr( $srdt_basename ) . '" />';
                submit_button( esc_html__( 'Delete', 'srdt' ), 'link delete', 'srdt_delete_dump', false, [ 'onclick' => "return confirm('" . esc_js( __( 'Delete this dump file?', 'srdt' ) ) . "');" ] );
                echo '</form>';
                echo '</li>';
            }
            echo '</ul>';
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
            <p><?php esc_html_e( 'Zip each plugin under wp-content/plugins into the theme\'s sync/plugins folder.', 'srdt' ); ?></p>
            <?php submit_button( esc_html__( 'Backup plugins', 'srdt' ), 'secondary', 'srdt_backup_plugins' ); ?>
        </form>
    </div>

	<script>
	jQuery(document).ready(function($) {
		$('#srdt-post-types-select').select2({
			placeholder: <?php echo wp_json_encode( esc_html__( 'Select post types...', 'srdt' ) ); ?>,
			allowClear: false
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
