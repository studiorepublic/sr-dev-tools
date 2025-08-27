<?php
/**
 * Get the sync path for exports
 * 
 * @package   DB Version Control
 * @author    Robert DeVore <me@robertdevore.com>
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Get the sync path for exports
 * 
 * @param string $subfolder Optional subfolder name
 * 
 * @since  1.0.0
 * @return string
 */
function dbvc_get_sync_path( $subfolder = '' ) {
	$custom_path = get_option( 'dbvc_sync_path', '' );
	
	if ( ! empty( $custom_path ) ) {
		// Validate and sanitize the custom path
		$custom_path = dbvc_validate_sync_path( $custom_path );
		if ( false === $custom_path ) {
			// Fall back to default if invalid
			$base_path = DBVC_PLUGIN_PATH . 'sync/';
		} else {
			// Remove leading slash and treat as relative to ABSPATH
			$custom_path = ltrim( $custom_path, '/' );
			$base_path = trailingslashit( ABSPATH ) . $custom_path;
		}
	} else {
		// Default to plugin's sync folder
		$base_path = DBVC_PLUGIN_PATH . 'sync/';
	}
	
	$base_path = trailingslashit( $base_path );
	
	if ( ! empty( $subfolder ) ) {
		// Sanitize subfolder name
		$subfolder = sanitize_file_name( $subfolder );
		$base_path .= trailingslashit( $subfolder );
	}
	
	return $base_path;
}

/**
 * Validate sync path to prevent directory traversal and other security issues.
 * 
 * @param string $path The path to validate.
 * 
 * @since  1.0.0
 * @return string|false Validated path or false if invalid.
 */
function dbvc_validate_sync_path( $path ) {
	if ( empty( $path ) ) {
		return '';
	}
	
	// Remove any null bytes
	$path = str_replace( chr( 0 ), '', $path );
	
	// Check for directory traversal attempts
	if ( strpos( $path, '..' ) !== false ) {
		return false;
	}
	
	// Check for other potentially dangerous characters
	$dangerous_chars = [ '<', '>', '"', '|', '?', '*', chr( 0 ) ];
	foreach ( $dangerous_chars as $char ) {
		if ( strpos( $path, $char ) !== false ) {
			return false;
		}
	}
	
	// Normalize slashes
	$path = str_replace( '\\', '/', $path );
	
	// Remove any double slashes
	$path = preg_replace( '#/+#', '/', $path );
	
	// Ensure path is within allowed boundaries (wp-content or plugin directory)
	$allowed_prefixes = [
		'wp-content/',
		'wp-content/plugins/',
		'wp-content/uploads/',
		'wp-content/themes/',
	];
	
	$is_allowed = false;
	foreach ( $allowed_prefixes as $prefix ) {
		if ( strpos( ltrim( $path, '/' ), $prefix ) === 0 ) {
			$is_allowed = true;
			break;
		}
	}
	
	// Also allow relative paths within the plugin directory
	if ( ! $is_allowed && strpos( $path, '/' ) !== 0 ) {
		$is_allowed = true;
	}
	
	return $is_allowed ? $path : false;
}

/**
 * Sanitize JSON file content before writing.
 * 
 * @param mixed $data The data to sanitize.
 * 
 * @since  1.0.0
 * @return mixed Sanitized data.
 */
function dbvc_sanitize_json_data( $data ) {
	if ( is_array( $data ) ) {
		return array_map( 'dbvc_sanitize_json_data', $data );
	}
	
	if ( is_string( $data ) ) {
		// Remove any null bytes and other control characters
		$data = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $data );
	}
	
	return $data;
}

/**
 * Check if a file path is safe for writing.
 * 
 * @param string $file_path The file path to check.
 * 
 * @since  1.0.0
 * @return bool True if safe, false otherwise.
 */
function dbvc_is_safe_file_path( $file_path ) {
	// Check for null bytes
	if ( strpos( $file_path, chr( 0 ) ) !== false ) {
		return false;
	}
	
	// Check for directory traversal
	if ( strpos( $file_path, '..' ) !== false ) {
		return false;
	}
	
	// Ensure file is within WordPress directory structure
	$wp_path = realpath( ABSPATH );
	$resolved_path = realpath( dirname( $file_path ) );
	
	if ( false === $resolved_path || strpos( $resolved_path, $wp_path ) !== 0 ) {
		return false;
	}
	
	// Check file extension
	$allowed_extensions = [ 'json' ];
	$extension = pathinfo( $file_path, PATHINFO_EXTENSION );
	
 return in_array( strtolower( $extension ), $allowed_extensions, true );
}

/**
 * Generate module pages based on ACF field names starting with "Partial".
 * - Ensures a parent page titled "Modules" exists.
 * - Scans current theme's acf-json for ACF field groups and collects field names
 *   that start with "Partial" (case-sensitive).
 * - Ensures a child page under "Modules" exists for each collected name.
 *
 * @since 1.1.0
 * @return array Result data including created/skipped counts and messages.
 */
function dbvc_generate_modules_pages() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return [ 'error' => __( 'Insufficient permissions.', 'dbvc' ) ];
	}

	$results = [
		'modules_page_created' => false,
		'modules_page_id'      => 0,
		'created'              => [],
		'skipped'              => [],
		'errors'               => [],
		'partials'             => [],
	];

	// 1) Ensure Modules page exists
	$modules_page = get_page_by_title( 'Modules' );
	if ( ! $modules_page ) {
		$modules_id = wp_insert_post( [
			'post_title'   => 'Modules',
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_parent'  => 0,
			'post_content' => '',
		] );
		if ( is_wp_error( $modules_id ) ) {
			$results['errors'][] = sprintf( /* translators: %s: error message */ __( 'Failed to create Modules page: %s', 'dbvc' ), $modules_id->get_error_message() );
			return $results;
		}
		$results['modules_page_created'] = true;
		$results['modules_page_id'] = (int) $modules_id;
	} else {
		$results['modules_page_id'] = (int) $modules_page->ID;
	}

	$modules_id = $results['modules_page_id'];
	// 2) Gather Partial* field names from theme acf-json
	$acf_dir = trailingslashit( get_stylesheet_directory() ) . 'acf-json';
	if ( ! is_dir( $acf_dir ) ) {
		// No ACF JSON directory; nothing to create beyond Modules page
		return $results;
	}

	$files = glob( $acf_dir . '/*.json' );

	if ( $files ) {
		foreach ( $files as $file ) {
			$content = file_get_contents( $file );
			if ( false === $content ) {
				$results['errors'][] = sprintf( __( 'Unable to read file: %s', 'dbvc' ), esc_html( $file ) );
				continue;
			}
			$data = json_decode( $content, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				$results['errors'][] = sprintf( __( 'Invalid JSON in file: %s', 'dbvc' ), esc_html( $file ) );
				continue;
			}

			$partials = dbvc_acf_collect_partial_field_names( $data );
			$results['partials'] = array_values( array_unique( array_merge( $results['partials'], $partials ) ) );
		}
	}

	// 3) Create missing child pages under Modules
	// Fetch existing children once for efficiency
	$existing_children = get_children( [
		'post_parent' => $modules_id,
		'post_type'   => 'page',
		'post_status' => 'any',
		'numberposts' => -1,
	] );

	$existing_by_title = [];
	$existing_by_slug  = [];
	if ( $existing_children ) {
		foreach ( $existing_children as $child ) {
			$existing_by_title[ $child->post_title ] = $child;
			$existing_by_slug[ $child->post_name ]   = $child;
		}
	}

	foreach ( $results['partials'] as $partial_name ) {
		$partial_name = wp_strip_all_tags( $partial_name );
		$slug = sanitize_title( $partial_name );

		$exists = isset( $existing_by_title[ $partial_name ] ) || isset( $existing_by_slug[ $slug ] );
		if ( $exists ) {
			$results['skipped'][] = $partial_name;
			continue;
		}

		$new_id = wp_insert_post( [
			'post_title'   => $partial_name,
			'post_name'    => $slug,
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_parent'  => $modules_id,
			'post_content' => '',
		] );

		if ( is_wp_error( $new_id ) ) {
			$results['errors'][] = sprintf( __( 'Failed to create page for "%s": %s', 'dbvc' ), $partial_name, $new_id->get_error_message() );
			continue;
		}

		$results['created'][] = $partial_name;
	}

	return $results;
}

/**
 * Recursively collect ACF field names starting with "Partial" from a decoded JSON array.
 *
 * @param array $data Decoded ACF JSON data.
 * @return array List of field names (strings).
 */
function dbvc_acf_collect_partial_field_names( $data ) {
	$found = [];

	$walker = function( $node ) use ( & $walker, & $found ) {
		if ( is_array( $node ) ) {
			// If this looks like a field with a 'name'
			if ( isset( $node['title'] ) && is_string( $node['title'] ) ) {
				$name = $node['title'];
				if ( substr( $name, 0, 7 ) === 'Partial' ) {
					$found[] = $name;
				}
			}

			// Recurse into common ACF keys
			$keys_to_check = [ 'fields', 'sub_fields', 'layouts', 'tabs' ];
			foreach ( $keys_to_check as $key ) {
				if ( isset( $node[ $key ] ) ) {
					$walker( $node[ $key ] );
				}
			}

			// Also iterate all values in case fields are nested in unexpected structures
			foreach ( $node as $value ) {
				if ( is_array( $value ) ) {
					$walker( $value );
				}
			}
		}
	};

	$walker( $data );

	return $found;
}
