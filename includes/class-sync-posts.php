<?php
/**
 * Get the sync path for exports
 * 
 * @package   DB Version Control
 * @author    Robert DeVore <me@robertdevore.com
 * @since     1.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Get the sync path for exports
 * 
 * @package   DB Version Control
 * @author    Robert DeVore <me@robertdevore.com>
 * @since     1.0.0
 * @return string
 */
class DBVC_Sync_Posts {

	/**
	 * Get the selected post types for export/import.
	 * 
	 * @since  1.0.0
	 * @return array
	 */
	public static function get_supported_post_types() {
		$selected_types = get_option( 'dbvc_post_types', [] );

		// If no post types are selected, default to post and page.
		if ( empty( $selected_types ) ) {
			$selected_types = [ 'post', 'page' ];
		}

		// Allow other plugins to modify supported post types.
		return apply_filters( 'dbvc_supported_post_types', $selected_types );
	}

	public static function export_post_to_json( $post_id, $post ) {
		// Validate inputs
		if ( ! is_numeric( $post_id ) || $post_id <= 0 ) {
			return;
		}
		
		if ( ! is_object( $post ) || ! isset( $post->post_type ) ) {
			return;
		}
		
		if ( wp_is_post_revision( $post_id ) || $post->post_status !== 'publish' ) {
			return;
		}

		$supported_types = self::get_supported_post_types();
		if ( ! in_array( $post->post_type, $supported_types, true ) ) {
			return;
		}

		// Check if user has permission to read this post type (skip for WP-CLI)
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			$post_type_obj = get_post_type_object( $post->post_type );
			if ( ! $post_type_obj || ! current_user_can( $post_type_obj->cap->read_post, $post_id ) ) {
				return;
			}
		}

		$data = [
			'ID'           => absint( $post_id ),
			'post_title'   => sanitize_text_field( $post->post_title ),
			'post_content' => wp_kses_post( $post->post_content ),
			'post_excerpt' => sanitize_textarea_field( $post->post_excerpt ),
			'post_type'    => sanitize_text_field( $post->post_type ),
			'post_status'  => sanitize_text_field( $post->post_status ),
			'meta'         => self::sanitize_post_meta( get_post_meta( $post_id ) ),
		];

		// Allow other plugins to modify the export data
		$data = apply_filters( 'dbvc_export_post_data', $data, $post_id, $post );
		
		// Sanitize the final data
		$data = dbvc_sanitize_json_data( $data );

        $path = dbvc_get_sync_path( $post->post_type );

		if ( ! is_dir( $path ) ) {
			if ( ! wp_mkdir_p( $path ) ) {
				error_log( 'DBVC: Failed to create directory: ' . $path );
				return;
			}
		}

		$file_path = $path . sanitize_file_name( $post->post_type . '-' . $post_id . '.json' );
		
		// Allow other plugins to modify the file path.
		$file_path = apply_filters( 'dbvc_export_post_file_path', $file_path, $post_id, $post );
		
		// Validate the final file path
		if ( ! dbvc_is_safe_file_path( $file_path ) ) {
			error_log( 'DBVC: Unsafe file path detected: ' . $file_path );
			return;
		}

		$json_content = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $json_content ) {
			error_log( 'DBVC: Failed to encode JSON for post ' . $post_id );
			return;
		}
		
		$result = file_put_contents( $file_path, $json_content );
		if ( false === $result ) {
			error_log( 'DBVC: Failed to write file: ' . $file_path );
			return;
		}

		// Allow other plugins to perform additional actions after export.
		do_action( 'dbvc_after_export_post', $post_id, $post, $file_path );
	}

    /**
     * Import all JSON files for supported post types.
     * 
     * @since  1.0.0
     * @return void
     */
	public static function import_all_json_files() {
        $supported_types = self::get_supported_post_types();
        
        foreach ( $supported_types as $post_type ) {
            $path  = dbvc_get_sync_path( $post_type );
            $files = glob( $path . '*.json' );
            
            if ( empty( $files ) ) {
                continue;
            }
            
            foreach ( $files as $file ) {
                $json = json_decode( file_get_contents( $file ), true );
                if ( empty( $json ) ) {
                    continue;
                }

                $post_id = wp_insert_post( [
                    'ID'           => $json['ID'],
                    'post_title'   => $json['post_title'],
                    'post_content' => $json['post_content'],
                    'post_excerpt' => $json['post_excerpt'],
                    'post_type'    => $json['post_type'],
                    'post_status'  => $json['post_status'],
                ] );

                if ( ! is_wp_error( $post_id ) && isset( $json['meta'] ) ) {
                    foreach ( $json['meta'] as $key => $values ) {
                        foreach ( $values as $value ) {
                            update_post_meta( $post_id, $key, maybe_unserialize( $value ) );
                        }
                    }
                }
            }
        }
	}

    /**
     * Export options to JSON file.
     * 
     * @since  1.0.0
     * @return void
     */
    public static function export_options_to_json() {
		// Check user capabilities for options export (skip for WP-CLI)
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
		}

        $all_options = wp_load_alloptions();
        $excluded_keys = [
            'siteurl', 'home', 'blogname', 'blogdescription',
            'admin_email', 'users_can_register', 'start_of_week', 'upload_path',
            'upload_url_path', 'cron', 'recently_edited', 'rewrite_rules',
            // Security-sensitive options
            'auth_key', 'auth_salt', 'logged_in_key', 'logged_in_salt',
            'nonce_key', 'nonce_salt', 'secure_auth_key', 'secure_auth_salt',
            'secret_key', 'db_version', 'initial_db_version',
        ];

        // Allow other plugins to modify excluded keys
        $excluded_keys = apply_filters( 'dbvc_excluded_option_keys', $excluded_keys );

        $filtered = array_diff_key( $all_options, array_flip( $excluded_keys ) );
        
        // Sanitize options data
        $filtered = self::sanitize_options_data( $filtered );
        
        // Allow other plugins to modify the options data before export
        $filtered = apply_filters( 'dbvc_export_options_data', $filtered );

        $path = dbvc_get_sync_path();
        if ( ! is_dir( $path ) ) {
            if ( ! wp_mkdir_p( $path ) ) {
				error_log( 'DBVC: Failed to create directory: ' . $path );
				return;
			}
        }

        $file_path = $path . 'options.json';
        
        // Allow other plugins to modify the options file path.
        $file_path = apply_filters( 'dbvc_export_options_file_path', $file_path );
        
        // Validate file path
		if ( ! dbvc_is_safe_file_path( $file_path ) ) {
			error_log( 'DBVC: Unsafe file path detected: ' . $file_path );
			return;
		}

		$json_content = wp_json_encode( $filtered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $json_content ) {
			error_log( 'DBVC: Failed to encode options JSON' );
			return;
		}

        $result = file_put_contents( $file_path, $json_content );
		if ( false === $result ) {
			error_log( 'DBVC: Failed to write options file: ' . $file_path );
			return;
		}
        
        // Allow other plugins to perform additional actions after options export
        do_action( 'dbvc_after_export_options', $file_path, $filtered );
    }

	/**
	 * Sanitize post meta data.
	 * 
	 * @param array $meta_data Raw meta data.
	 * 
	 * @since  1.0.0
	 * @return array Sanitized meta data.
	 */
	private static function sanitize_post_meta( $meta_data ) {
		$sanitized = [];
		
		foreach ( $meta_data as $key => $values ) {
			$key = sanitize_text_field( $key );
			$sanitized[ $key ] = [];
			
			foreach ( $values as $value ) {
				// Basic sanitization - more specific sanitization may be needed based on meta key
				if ( is_serialized( $value ) ) {
					$unserialized = maybe_unserialize( $value );
					$sanitized[ $key ][] = dbvc_sanitize_json_data( $unserialized );
				} else {
					$sanitized[ $key ][] = sanitize_textarea_field( $value );
				}
			}
		}
		
		return $sanitized;
	}

	/**
	 * Sanitize options data.
	 * 
	 * @param array $options_data Raw options data.
	 * 
	 * @since  1.0.0
	 * @return array Sanitized options data.
	 */
	private static function sanitize_options_data( $options_data ) {
		$sanitized = [];
		
		foreach ( $options_data as $key => $value ) {
			$key = sanitize_text_field( $key );
			
			if ( is_serialized( $value ) ) {
				$unserialized = maybe_unserialize( $value );
				$sanitized[ $key ] = dbvc_sanitize_json_data( $unserialized );
			} else {
				$sanitized[ $key ] = dbvc_sanitize_json_data( $value );
			}
		}
		
		return $sanitized;
	}

    /**
     * Import options from JSON file.
     * 
     * @since  1.0.0
     * @return void
     */
    public static function import_options_from_json() {
        $file_path = dbvc_get_sync_path() . 'options.json';
        if ( ! file_exists( $file_path ) ) {
            return;
        }

        $options = json_decode( file_get_contents( $file_path ), true );
        if ( empty( $options ) ) {
            return;
        }

        foreach ( $options as $key => $value ) {
            update_option( $key, maybe_unserialize( $value ) );
        }
    }

    /**
     * Export all menus to JSON file.
     * 
     * @since  1.0.0
     * @return void
     */
    public static function export_menus_to_json() {
        $menus = wp_get_nav_menus();
        $data  = [];

        foreach ( $menus as $menu ) {
            $items = wp_get_nav_menu_items( $menu->term_id );
            $menu_data = [
                'name'           => $menu->name,
                'slug'           => $menu->slug,
                'locations'      => array_keys( array_filter( get_nav_menu_locations(), fn( $id ) => $id === $menu->term_id ) ),
                'items'          => array_map( fn( $item ) => (array) $item, $items ),
            ];
            
            // Allow other plugins to modify individual menu data
            $data[] = apply_filters( 'dbvc_export_menu_data', $menu_data, $menu );
        }
        
        // Allow other plugins to modify all menus data
        $data = apply_filters( 'dbvc_export_menus_data', $data );

        $path = dbvc_get_sync_path();
        if ( ! is_dir( $path ) ) {
            wp_mkdir_p( $path );
        }

        $file_path = $path . 'menus.json';
        
        // Allow other plugins to modify the menus file path
        $file_path = apply_filters( 'dbvc_export_menus_file_path', $file_path );

        file_put_contents(
            $file_path,
            wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
        );
        
        // Allow other plugins to perform additional actions after menus export
        do_action( 'dbvc_after_export_menus', $file_path, $data );
    }

    /**
     * Import menus from JSON file.
     * 
     * @since  1.0.0
     * @return void
     */
    public static function import_menus_from_json() {
        $file = dbvc_get_sync_path() . 'menus.json';
        if ( ! file_exists( $file ) ) return;

        $menus = json_decode( file_get_contents( $file ), true );
        foreach ( $menus as $menu_data ) {
            $menu_id = wp_create_nav_menu( $menu_data['name'] );

            foreach ( $menu_data['items'] as $item ) {
                wp_update_nav_menu_item( $menu_id, 0, [
                    'menu-item-title'     => $item['title'],
                    'menu-item-object'    => $item['object'],
                    'menu-item-object-id' => $item['object_id'],
                    'menu-item-type'      => $item['type'],
                    'menu-item-status'    => 'publish',
                ] );
            }

            foreach ( $menu_data['locations'] as $loc ) {
                $locations = get_nav_menu_locations();
                $locations[ $loc ] = $menu_id;
                set_theme_mod( 'nav_menu_locations', $locations );
            }
        }
    }

    /**
     * Export posts in batches for better performance.
     * 
     * @param int $batch_size Number of posts to process per batch.
     * @param int $offset     Starting offset for the batch.
     * 
     * @since  1.0.0
     * @return array Results with processed count and remaining count.
     */
    public static function export_posts_batch( $batch_size = 50, $offset = 0 ) {
        $supported_types = self::get_supported_post_types();
        
        $posts = get_posts( [
            'post_type'      => $supported_types,
            'posts_per_page' => $batch_size,
            'offset'         => $offset,
            'post_status'    => 'any',
        ] );
        
        $processed = 0;
        foreach ( $posts as $post ) {
            self::export_post_to_json( $post->ID, $post );
            $processed++;
        }
        
        // Get total count for progress tracking
        $total_posts = self::wp_count_posts_by_type( $supported_types );
        $remaining = max( 0, $total_posts - ( $offset + $processed ) );
        
        return [
            'processed' => $processed,
            'remaining' => $remaining,
            'total'     => $total_posts,
            'offset'    => $offset + $processed,
        ];
    }

    /**
     * Import posts in batches for better performance.
     * 
     * @param int $batch_size Number of files to process per batch.
     * @param int $offset     Starting offset for the batch.
     * 
     * @since  1.0.0
     * @return array Results with processed count and remaining count.
     */
    public static function import_posts_batch( $batch_size = 50, $offset = 0 ) {
        $supported_types = self::get_supported_post_types();
        $all_files = [];
        
        // Collect all JSON files from all post type directories
        foreach ( $supported_types as $post_type ) {
            $path = dbvc_get_sync_path( $post_type );
            $files = glob( $path . '*.json' );
            if ( ! empty( $files ) ) {
                $all_files = array_merge( $all_files, $files );
            }
        }
        
        // Process batch
        $batch_files = array_slice( $all_files, $offset, $batch_size );
        $processed = 0;
        
        foreach ( $batch_files as $file ) {
            $json = json_decode( file_get_contents( $file ), true );
            if ( empty( $json ) ) {
                continue;
            }
            
            // Validate required fields
            if ( ! isset( $json['ID'], $json['post_type'], $json['post_title'] ) ) {
                continue;
            }
            
            $post_id = wp_insert_post( [
                'ID'           => absint( $json['ID'] ),
                'post_title'   => sanitize_text_field( $json['post_title'] ),
                'post_content' => wp_kses_post( $json['post_content'] ?? '' ),
                'post_excerpt' => sanitize_textarea_field( $json['post_excerpt'] ?? '' ),
                'post_type'    => sanitize_text_field( $json['post_type'] ),
                'post_status'  => sanitize_text_field( $json['post_status'] ?? 'draft' ),
            ] );
            
            if ( ! is_wp_error( $post_id ) && isset( $json['meta'] ) && is_array( $json['meta'] ) ) {
                foreach ( $json['meta'] as $key => $values ) {
                    if ( is_array( $values ) ) {
                        foreach ( $values as $value ) {
                            update_post_meta( $post_id, sanitize_text_field( $key ), maybe_unserialize( $value ) );
                        }
                    }
                }
            }
            
            $processed++;
        }
        
        $total_files = count( $all_files );
        $remaining = max( 0, $total_files - ( $offset + $processed ) );
        
        return [
            'processed' => $processed,
            'remaining' => $remaining,
            'total'     => $total_files,
            'offset'    => $offset + $processed,
        ];
    }

    /**
     * Get total count of posts for all supported post types.
     * 
     * @param array $post_types Post types to count.
     * 
     * @since  1.0.0
     * @return int Total post count.
     */
    private static function wp_count_posts_by_type( $post_types ) {
        $total = 0;
        
        foreach ( $post_types as $post_type ) {
            $counts = wp_count_posts( $post_type );
            if ( $counts ) {
                foreach ( $counts as $status => $count ) {
                    $total += $count;
                }
            }
        }
        
        return $total;
    }

}
