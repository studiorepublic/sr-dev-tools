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
function dbvc_render_export_page() {
	// Check user capabilities
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'dbvc' ) );
	}

	$custom_path         = get_option( 'dbvc_sync_path', '' );
	$selected_post_types = get_option( 'dbvc_post_types', [] );

	// Handle custom sync path form.
	if ( isset( $_POST['dbvc_sync_path_save'] ) && wp_verify_nonce( $_POST['dbvc_sync_path_nonce'], 'dbvc_sync_path_action' ) ) {
		// Additional capability check
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'dbvc' ) );
		}

		$new_path = sanitize_text_field( wp_unslash( $_POST['dbvc_sync_path'] ) );
		
		// Validate path to prevent directory traversal
		$new_path = dbvc_validate_sync_path( $new_path );
		if ( false === $new_path ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Invalid sync path provided. Path cannot contain ../ or other unsafe characters.', 'dbvc' ) . '</p></div>';
		} else {
			update_option( 'dbvc_sync_path', $new_path );
			$custom_path = $new_path;

			// Create the directory immediately to test the path.
			$resolved_path = dbvc_get_sync_path();
			if ( wp_mkdir_p( $resolved_path ) ) {
				echo '<div class="notice notice-success"><p>' . sprintf( esc_html__( 'Sync folder updated and created at: %s', 'dbvc' ), '<code>' . esc_html( $resolved_path ) . '</code>' ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error"><p>' . sprintf( esc_html__( 'Sync folder setting saved, but could not create directory at: %s. Please check permissions.', 'dbvc' ), '<code>' . esc_html( $resolved_path ) . '</code>' ) . '</p></div>';
			}
		}
	}

	// Handle post types selection form.
	if ( isset( $_POST['dbvc_post_types_save'] ) && wp_verify_nonce( $_POST['dbvc_post_types_nonce'], 'dbvc_post_types_action' ) ) {
		// Additional capability check
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'dbvc' ) );
		}

		$new_post_types = [];
		if ( isset( $_POST['dbvc_post_types'] ) && is_array( $_POST['dbvc_post_types'] ) ) {
			$new_post_types = array_map( 'sanitize_text_field', wp_unslash( $_POST['dbvc_post_types'] ) );
			
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
		
		update_option( 'dbvc_post_types', $new_post_types );
		$selected_post_types = $new_post_types;
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Post types selection updated!', 'dbvc' ) . '</p></div>';
	}

	// Handle export form.
	if ( isset( $_POST['dbvc_export_nonce'] ) && wp_verify_nonce( $_POST['dbvc_export_nonce'], 'dbvc_export_action' ) ) {
		// Additional capability check
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'dbvc' ) );
		}

		// Run full export.
		DBVC_Sync_Posts::export_options_to_json();
		DBVC_Sync_Posts::export_menus_to_json();

		$posts = get_posts( [
			'post_type'      => 'any',
			'posts_per_page' => -1,
			'post_status'    => 'any',
		] );

		foreach ( $posts as $post ) {
			DBVC_Sync_Posts::export_post_to_json( $post->ID, $post );
		}

		echo '<div class="notice notice-success"><p>' . esc_html__( 'Full export completed!', 'dbvc' ) . '</p></div>';
	}

	// Handle Generate Modules Pages form.
	if ( isset( $_POST['dbvc_generate_modules'] ) && isset( $_POST['dbvc_generate_modules_nonce'] ) && wp_verify_nonce( $_POST['dbvc_generate_modules_nonce'], 'dbvc_generate_modules_action' ) ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'dbvc' ) );
		}
		$gen_results = dbvc_generate_modules_pages();
		if ( isset( $gen_results['error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $gen_results['error'] ) . '</p></div>';
		} else {
			$created = isset( $gen_results['created'] ) ? count( (array) $gen_results['created'] ) : 0;
			$skipped = isset( $gen_results['skipped'] ) ? count( (array) $gen_results['skipped'] ) : 0;
			$errors  = isset( $gen_results['errors'] ) ? count( (array) $gen_results['errors'] ) : 0;
			$modules_created = ! empty( $gen_results['modules_page_created'] );
			$summary  = $modules_created ? __( 'Modules page created. ', 'dbvc' ) : __( 'Modules page already existed. ', 'dbvc' );
			$summary .= sprintf( __( 'Pages created: %d. Skipped: %d. Errors: %d.', 'dbvc' ), $created, $skipped, $errors );
			echo '<div class="notice notice-success"><p>' . esc_html( $summary ) . '</p></div>';
			if ( ! empty( $gen_results['errors'] ) ) {
				$errs = '<ul style="margin: .5em 0 0 1.2em;">';
				foreach ( $gen_results['errors'] as $err ) {
					$errs .= '<li>' . esc_html( $err ) . '</li>';
				}
				$errs .= '</ul>';
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'Some issues occurred:', 'dbvc' ) . '</p>' . $errs . '</div>';
			}
		}
	}

	// Get the current resolved path for display.
	$resolved_path = dbvc_get_sync_path();
		
	// Get all public post types.
	$all_post_types = dbvc_get_available_post_types();

	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'DB Version Control', 'dbvc' ); ?></h1>
        <form method="post">
            <?php wp_nonce_field( 'dbvc_export_action', 'dbvc_export_nonce' ); ?>
            <p><?php esc_html_e( 'This will export all posts, options, and menus to JSON files.', 'dbvc' ); ?></p>
            <?php submit_button( esc_html__( 'Run Full Export', 'dbvc' ) ); ?>
        </form>

        <hr />

        <form method="post">
            <?php wp_nonce_field( 'dbvc_post_types_action', 'dbvc_post_types_nonce' ); ?>
            <h2><?php esc_html_e( 'Post Types to Export/Import', 'dbvc' ); ?></h2>
            <p><?php esc_html_e( 'Select which post types should be included in exports and imports.', 'dbvc' ); ?></p>
            <select name="dbvc_post_types[]" multiple="multiple" id="dbvc-post-types-select" style="width: 100%;">
                <?php foreach ( $all_post_types as $post_type => $post_type_obj ) : ?>
                    <option value="<?php echo esc_attr( $post_type ); ?>" <?php selected( in_array( $post_type, $selected_post_types, true ) ); ?>>
                        <?php echo esc_html( $post_type_obj->label ); ?> (<?php echo esc_html( $post_type ); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <?php submit_button( esc_html__( 'Save Post Types', 'dbvc' ), 'secondary', 'dbvc_post_types_save' ); ?>
        </form>

        <hr />

        <form method="post">
            <?php wp_nonce_field( 'dbvc_sync_path_action', 'dbvc_sync_path_nonce' ); ?>
            <h2><?php esc_html_e( 'Custom Sync Folder Path', 'dbvc' ); ?></h2>
            <p><?php esc_html_e( 'Enter the full or relative path (from site root) where JSON files should be saved.', 'dbvc' ); ?></p>
            <input type="text" name="dbvc_sync_path" value="<?php echo esc_attr( $custom_path ); ?>" style="width: 100%;" placeholder="<?php esc_attr_e( 'e.g., wp-content/plugins/db-version-control/sync-testing-folder/', 'dbvc' ); ?>">
            <p><strong><?php esc_html_e( 'Current resolved path:', 'dbvc' ); ?></strong> <code><?php echo esc_html( $resolved_path ); ?></code></p>
            <?php submit_button( esc_html__( 'Save Folder Path', 'dbvc' ), 'secondary', 'dbvc_sync_path_save' ); ?>
        </form>

        <hr />

        <form method="post">
            <?php wp_nonce_field( 'dbvc_generate_modules_action', 'dbvc_generate_modules_nonce' ); ?>
            <h2><?php esc_html_e( 'Modules Pages', 'dbvc' ); ?></h2>
            <p><?php esc_html_e( 'Scan theme ACF field groups for fields starting with "Partial" and generate child pages under the "Modules" parent.', 'dbvc' ); ?></p>
            <?php submit_button( esc_html__( 'Generate modules pages', 'dbvc' ), 'secondary', 'dbvc_generate_modules' ); ?>
        </form>
    </div>

	<script>
	jQuery(document).ready(function($) {
		$('#dbvc-post-types-select').select2({
			placeholder: <?php echo wp_json_encode( esc_html__( 'Select post types...', 'dbvc' ) ); ?>,
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
function dbvc_get_available_post_types() {
    $post_types = get_post_types( [ 'public' => true ], 'objects' );
    
    // Add FSE post types if block theme is active
    if ( wp_is_block_theme() ) {
        $fse_types = [
            'wp_template' => (object) [
                'label' => __( 'Templates (FSE)', 'dbvc' ),
                'name' => 'wp_template'
            ],
            'wp_template_part' => (object) [
                'label' => __( 'Template Parts (FSE)', 'dbvc' ),
                'name' => 'wp_template_part'
            ],
            'wp_global_styles' => (object) [
                'label' => __( 'Global Styles (FSE)', 'dbvc' ),
                'name' => 'wp_global_styles'
            ],
            'wp_navigation' => (object) [
                'label' => __( 'Navigation (FSE)', 'dbvc' ),
                'name' => 'wp_navigation'
            ],
        ];
        
        $post_types = array_merge( $post_types, $fse_types );
    }
    
    return $post_types;
}
