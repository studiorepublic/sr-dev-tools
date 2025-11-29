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
class SRDT_Sync_Posts {

	/**
	 * Check if we're in production environment and should block operations.
	 * 
	 * @since  1.0.0
	 * @return bool True if in production and should block, false otherwise.
	 */
	private static function is_production_blocked() {
		$wp_env = defined( 'WP_ENV' ) ? WP_ENV : ( getenv( 'WP_ENV' ) ?: 'production' );
		if ( 'production' === $wp_env ) {
			// Remove sync folder if it exists
			$sync_path = srdt_get_sync_path();
			if ( is_dir( $sync_path ) ) {
				srdt_remove_directory_recursive( $sync_path );
			}
			return true;
		}
		return false;
	}

	/**
	 * Get the selected post types for export/import.
	 * 
	 * @since  1.0.0
	 * @return array
	 */
	public static function get_supported_post_types() {
		$selected_types = get_option( 'srdt_post_types', [] );

		// If no post types are selected, default to post, page, and FSE types.
		if ( empty( $selected_types ) ) {
			$selected_types = [ 'post', 'page', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' ];
		}

		// Allow other plugins to modify supported post types.
		return apply_filters( 'srdt_supported_post_types', $selected_types );
	}

    /**
     * Export a single post to JSON file.
     * 
     * @param int    $post_id Post ID.
     * @param object $post    Post object.
     * 
     * @since  1.0.0
     * @return void
     */
	public static function export_post_to_json( $post_id, $post ) {
		// Validate inputs.
		if ( ! is_numeric( $post_id ) || $post_id <= 0 ) {
			return;
		}

		if ( ! is_object( $post ) || ! isset( $post->post_type ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// For FSE content, allow draft status as templates can be in draft.
		$allowed_statuses = [ 'publish' ];
		if ( in_array( $post->post_type, [ 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' ], true ) ) {
			$allowed_statuses[] = 'draft';
			$allowed_statuses[] = 'auto-draft';
		}

		if ( ! in_array( $post->post_status, $allowed_statuses, true ) ) {
			return;
		}

		$supported_types = self::get_supported_post_types();
		if ( ! in_array( $post->post_type, $supported_types, true ) ) {
			return;
		}

		// Check if user has permission to read this post type (skip for WP-CLI).
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			$post_type_obj = get_post_type_object( $post->post_type );
			if ( ! $post_type_obj || ! current_user_can( $post_type_obj->cap->read_post, $post_id ) ) {
				return;
			}
		}

		$data = [
			'ID'           => absint( $post_id ), // kept for backward compatibility, not used as identifier during import
			'post_title'   => sanitize_text_field( $post->post_title ),
			'post_content' => wp_kses_post( $post->post_content ),
			'post_excerpt' => sanitize_textarea_field( $post->post_excerpt ),
			'post_type'    => sanitize_text_field( $post->post_type ),
			'post_status'  => sanitize_text_field( $post->post_status ),
			'post_name'    => sanitize_text_field( $post->post_name ),
			'meta'         => self::sanitize_post_meta( get_post_meta( $post_id ) ),
		];

		// Provide slug-based path information for hierarchical post types (e.g., pages).
		if ( is_post_type_hierarchical( $post->post_type ) ) {
			// Full path like "parent/child". get_page_uri works for hierarchical types.
			$data['post_path'] = get_page_uri( $post_id );
			if ( ! empty( $post->post_parent ) ) {
				$parent = get_post( $post->post_parent );
				if ( $parent && ! is_wp_error( $parent ) ) {
					$data['parent_slug'] = sanitize_text_field( $parent->post_name );
					$data['parent_path'] = get_page_uri( $parent->ID );
				}
			}
		}

		// Add FSE-specific data.
		if ( in_array( $post->post_type, [ 'wp_template', 'wp_template_part' ], true ) ) {
			$data['theme']  = get_stylesheet();
			$data['slug']   = $post->post_name;
			$data['source'] = get_post_meta( $post_id, 'origin', true ) ?: 'custom';
		}

		// Allow other plugins to modify the export data
		$data = apply_filters( 'srdt_export_post_data', $data, $post_id, $post );
		
		// Sanitize the final data
		$data = srdt_sanitize_json_data( $data );

        $path = srdt_get_sync_path( $post->post_type );

		if ( ! is_dir( $path ) ) {
			if ( ! wp_mkdir_p( $path ) ) {
				error_log( 'SRDT: Failed to create directory: ' . $path );
				return;
			}
		}

		$file_path = $path . sanitize_file_name( $post->post_type . '-' . $post_id . '.json' );
		
		// Allow other plugins to modify the file path.
		$file_path = apply_filters( 'srdt_export_post_file_path', $file_path, $post_id, $post );
		
		// Validate the final file path
		if ( ! srdt_is_safe_file_path( $file_path ) ) {
			error_log( 'SRDT: Unsafe file path detected: ' . $file_path );
			return;
		}

		$json_content = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $json_content ) {
			error_log( 'SRDT: Failed to encode JSON for post ' . $post_id );
			return;
		}
		
		$result = file_put_contents( $file_path, $json_content );
		if ( false === $result ) {
			error_log( 'SRDT: Failed to write file: ' . $file_path );
			return;
		}

		// Allow other plugins to perform additional actions after export.
		do_action( 'srdt_after_export_post', $post_id, $post, $file_path );
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
            $path  = srdt_get_sync_path( $post_type );
            $files = glob( $path . '*.json' );
            
            if ( empty( $files ) ) {
                continue;
            }
            
            foreach ( $files as $file ) {
                $json = json_decode( file_get_contents( $file ), true );
                if ( empty( $json ) ) {
                    continue;
                }

                // Validate required fields (use slug/path instead of ID)
                if ( ! isset( $json['post_type'] ) ) {
                    continue;
                }

                $post_type = sanitize_text_field( $json['post_type'] );
                $title     = isset( $json['post_title'] ) ? sanitize_text_field( $json['post_title'] ) : '';
                $slug      = isset( $json['post_name'] ) ? sanitize_title( $json['post_name'] ) : sanitize_title( $title );
                $post_stat = isset( $json['post_status'] ) ? sanitize_text_field( $json['post_status'] ) : 'draft';

                $existing  = null;
                $parent_id = 0;

                // Resolve parent and find existing post by path/slug if hierarchical
                if ( is_post_type_hierarchical( $post_type ) ) {
                    $post_path   = isset( $json['post_path'] ) ? sanitize_text_field( $json['post_path'] ) : $slug;
                    $existing    = get_page_by_path( $post_path, OBJECT, $post_type );
                    $parent_path = isset( $json['parent_path'] ) ? sanitize_text_field( $json['parent_path'] ) : '';
                    $parent_slug = isset( $json['parent_slug'] ) ? sanitize_title( $json['parent_slug'] ) : '';
                    if ( $parent_path ) {
                        $parent = get_page_by_path( $parent_path, OBJECT, $post_type );
                        if ( $parent ) { $parent_id = $parent->ID; }
                    } elseif ( $parent_slug ) {
                        $parent = get_page_by_path( $parent_slug, OBJECT, $post_type );
                        if ( $parent ) { $parent_id = $parent->ID; }
                    }
                } else {
                    // Non-hierarchical: lookup by slug within post type
                    if ( $slug ) {
                        $existing = get_page_by_path( $slug, OBJECT, $post_type );
                        if ( ! $existing ) {
                            $q = new WP_Query( [
                                'name'           => $slug,
                                'post_type'      => $post_type,
                                'posts_per_page' => 1,
                                'post_status'    => 'any',
                            ] );
                            if ( $q->have_posts() ) { $existing = $q->posts[0]; }
                        }
                    }
                }

                $postarr = [
                    'post_title'   => $title,
                    'post_name'    => $slug,
                    'post_content' => wp_kses_post( $json['post_content'] ?? '' ),
                    'post_excerpt' => sanitize_textarea_field( $json['post_excerpt'] ?? '' ),
                    'post_type'    => $post_type,
                    'post_status'  => $post_stat,
                    'post_parent'  => $parent_id,
                ];
                if ( $existing && isset( $existing->ID ) ) {
                    $postarr['ID'] = absint( $existing->ID );
                }

                $post_id = wp_insert_post( $postarr );

                if ( ! is_wp_error( $post_id ) && isset( $json['meta'] ) ) {
                    foreach ( $json['meta'] as $key => $values ) {
                        foreach ( $values as $value ) {
                            update_post_meta( $post_id, sanitize_text_field( $key ), maybe_unserialize( $value ) );
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
			if ( ! current_user_can( 'SR' ) ) {
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
        $excluded_keys = apply_filters( 'srdt_excluded_option_keys', $excluded_keys );

        $filtered = array_diff_key( $all_options, array_flip( $excluded_keys ) );
        
        // Sanitize options data
        $filtered = self::sanitize_options_data( $filtered );
        
        // Allow other plugins to modify the options data before export
        $filtered = apply_filters( 'srdt_export_options_data', $filtered );

        $path = srdt_get_sync_path();
        if ( ! is_dir( $path ) ) {
            if ( ! wp_mkdir_p( $path ) ) {
				error_log( 'SRDT: Failed to create directory: ' . $path );
				return;
			}
        }

        $file_path = $path . 'options.json';
        
        // Allow other plugins to modify the options file path.
        $file_path = apply_filters( 'srdt_export_options_file_path', $file_path );
        
        // Validate file path
		if ( ! srdt_is_safe_file_path( $file_path ) ) {
			error_log( 'SRDT: Unsafe file path detected: ' . $file_path );
			return;
		}

		$json_content = wp_json_encode( $filtered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $json_content ) {
			error_log( 'SRDT: Failed to encode options JSON' );
			return;
		}

        $result = file_put_contents( $file_path, $json_content );
		if ( false === $result ) {
			error_log( 'SRDT: Failed to write options file: ' . $file_path );
			return;
		}
        
        // Allow other plugins to perform additional actions after options export
        do_action( 'srdt_after_export_options', $file_path, $filtered );
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
					$sanitized[ $key ][] = srdt_sanitize_json_data( $unserialized );
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
				$sanitized[ $key ] = srdt_sanitize_json_data( $unserialized );
			} else {
				$sanitized[ $key ] = srdt_sanitize_json_data( $value );
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
        $file_path = srdt_get_sync_path() . 'options.json';
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
            $data[] = apply_filters( 'srdt_export_menu_data', $menu_data, $menu );
        }
        
        // Allow other plugins to modify all menus data
        $data = apply_filters( 'srdt_export_menus_data', $data );

        $path = srdt_get_sync_path();
        if ( ! is_dir( $path ) ) {
            wp_mkdir_p( $path );
        }

        $file_path = $path . 'menus.json';
        
        // Allow other plugins to modify the menus file path
        $file_path = apply_filters( 'srdt_export_menus_file_path', $file_path );

        file_put_contents(
            $file_path,
            wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
        );
        
        // Allow other plugins to perform additional actions after menus export
        do_action( 'srdt_after_export_menus', $file_path, $data );
    }

    /**
     * Import menus from JSON file.
     * 
     * @since  1.0.0
     * @return void
     */
    public static function import_menus_from_json() {
        $file = srdt_get_sync_path() . 'menus.json';
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
            $path = srdt_get_sync_path( $post_type );
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
            
         			// Validate required fields (use slug/path instead of ID)
         			if ( ! isset( $json['post_type'] ) ) {
         				continue;
         			}
			
         			$post_type = sanitize_text_field( $json['post_type'] );
         			$title     = isset( $json['post_title'] ) ? sanitize_text_field( $json['post_title'] ) : '';
         			$slug      = isset( $json['post_name'] ) ? sanitize_title( $json['post_name'] ) : sanitize_title( $title );
         			$post_stat = isset( $json['post_status'] ) ? sanitize_text_field( $json['post_status'] ) : 'draft';
			
         			$existing  = null;
         			$parent_id = 0;
			
         			// Resolve parent and find existing post by path/slug if hierarchical
         			if ( is_post_type_hierarchical( $post_type ) ) {
         				$post_path   = isset( $json['post_path'] ) ? sanitize_text_field( $json['post_path'] ) : $slug;
         				$existing    = get_page_by_path( $post_path, OBJECT, $post_type );
         				$parent_path = isset( $json['parent_path'] ) ? sanitize_text_field( $json['parent_path'] ) : '';
         				$parent_slug = isset( $json['parent_slug'] ) ? sanitize_title( $json['parent_slug'] ) : '';
         				if ( $parent_path ) {
         					$parent = get_page_by_path( $parent_path, OBJECT, $post_type );
         					if ( $parent ) { $parent_id = $parent->ID; }
         				} elseif ( $parent_slug ) {
         					$parent = get_page_by_path( $parent_slug, OBJECT, $post_type );
         					if ( $parent ) { $parent_id = $parent->ID; }
         				}
         			} else {
         				// Non-hierarchical: lookup by slug within post type
         				if ( $slug ) {
         					$existing = get_page_by_path( $slug, OBJECT, $post_type );
         					if ( ! $existing ) {
         						$q = new WP_Query( [
         							'name'           => $slug,
         							'post_type'      => $post_type,
         							'posts_per_page' => 1,
         							'post_status'    => 'any',
         						] );
         						if ( $q->have_posts() ) { $existing = $q->posts[0]; }
         					}
         				}
         			}
			
         			$postarr = [
         				'post_title'   => $title,
         				'post_name'    => $slug,
         				'post_content' => wp_kses_post( $json['post_content'] ?? '' ),
         				'post_excerpt' => sanitize_textarea_field( $json['post_excerpt'] ?? '' ),
         				'post_type'    => $post_type,
         				'post_status'  => $post_stat,
         				'post_parent'  => $parent_id,
         			];
         			if ( $existing && isset( $existing->ID ) ) {
         				$postarr['ID'] = absint( $existing->ID );
         			}
			
         			$post_id = wp_insert_post( $postarr );
			
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

    /**
     * Export FSE theme data to JSON.
     * 
     * @since  1.1.0
     * @return void
     */
	public static function export_fse_theme_data() {
		// Check if WordPress is fully loaded.
		if ( ! did_action( 'wp_loaded' ) ) {
			return;
		}

		if ( ! wp_is_block_theme() ) {
			return;
		}

		// Skip during admin page loads to prevent conflicts.
		if ( is_admin() && ! wp_doing_ajax() && ! defined( 'WP_CLI' ) ) {
			return;
		}

		// Check user capabilities for FSE export (skip for WP-CLI).
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			if ( ! current_user_can( 'SR' ) ) {
				return;
			}
		}

		$theme_data = [
			'theme_name' => get_stylesheet(),
			'custom_css' => wp_get_custom_css(),
		];

		// Safely get theme JSON data - only if the system is ready.
		if ( class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			try {
				// Additional check to ensure the theme JSON system is initialized.
				if ( did_action( 'init' ) && ! is_admin() ) {
					$theme_json_resolver = WP_Theme_JSON_Resolver::get_merged_data();
					if ( $theme_json_resolver && method_exists( $theme_json_resolver, 'get_raw_data' ) ) {
						$theme_data['theme_json'] = $theme_json_resolver->get_raw_data();
					} else {
						$theme_data['theme_json'] = [];
					}
				} else {
					// Skip theme JSON during admin loads.
					$theme_data['theme_json'] = [];
				}
			} catch ( Exception $e ) {
				error_log( 'SRDT: Failed to get theme JSON data: ' . $e->getMessage() );
				$theme_data['theme_json'] = [];
			} catch ( Error $e ) {
				error_log( 'SRDT: Fatal error getting theme JSON data: ' . $e->getMessage() );
				$theme_data['theme_json'] = [];
			}
		} else {
			$theme_data['theme_json'] = [];
		}

		// Allow other plugins to modify FSE theme data.
		$theme_data = apply_filters( 'srdt_export_fse_theme_data', $theme_data );

		$path = srdt_get_sync_path( 'theme' );
		if ( ! is_dir( $path ) ) {
			if ( ! wp_mkdir_p( $path ) ) {
				error_log( 'SRDT: Failed to create theme directory: ' . $path );
				return;
			}
		}

		$file_path = $path . 'theme-data.json';
		
		// Allow other plugins to modify the FSE theme file path.
		$file_path = apply_filters( 'srdt_export_fse_theme_file_path', $file_path );
		
		// Validate file path.
		if ( ! srdt_is_safe_file_path( $file_path ) ) {
			error_log( 'SRDT: Unsafe file path detected: ' . $file_path );
			return;
		}

		$json_content = wp_json_encode( $theme_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $json_content ) {
			error_log( 'SRDT: Failed to encode FSE theme JSON' );
			return;
		}

		$result = file_put_contents( $file_path, $json_content );
		if ( false === $result ) {
			error_log( 'SRDT: Failed to write FSE theme file: ' . $file_path );
			return;
		}

		do_action( 'srdt_after_export_fse_theme_data', $file_path, $theme_data );
	}

	/**
	 * Import FSE theme data from JSON.
	 * 
	 * @since  1.1.0
	 * @return void
	 */
	public static function import_fse_theme_data() {
		// Check user capabilities for FSE import.
		if ( ! current_user_can( 'SR' ) ) {
			return;
		}

		$file_path = srdt_get_sync_path( 'theme' ) . 'theme-data.json';
		if ( ! file_exists( $file_path ) ) {
			return;
		}

		$theme_data = json_decode( file_get_contents( $file_path ), true );
		if ( empty( $theme_data ) ) {
			return;
		}

		// Import custom CSS.
		if ( isset( $theme_data['custom_css'] ) && ! empty( $theme_data['custom_css'] ) ) {
			wp_update_custom_css_post( $theme_data['custom_css'] );
		}

		// Allow other plugins to handle additional FSE import data.
		do_action( 'srdt_after_import_fse_theme_data', $theme_data );
	}

	/**
	 * Dump the database to theme sync/database directory.
	 *
	 * Creates a SQL dump using WP-CLI if available, otherwise falls back to mysqldump.
	 * The SQL dump is then compressed into a tar.gz archive for better compression and compatibility.
	 *
	 * @since 1.2.0
	 * @param array $args       Positional CLI args (unused).
	 * @param array $assoc_args Associative CLI args (unused).
	 * @return void
	 */
	public static function dump_database( $args = [], $assoc_args = [] ) {
		// Check for production environment
		if ( self::is_production_blocked() ) {
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				\WP_CLI::error( 'SR Dev Tools is not allowed in production environments.' );
			}
			return;
		}

		// Only allow via CLI or admins.
		if ( ( ! defined( 'WP_CLI' ) || ! WP_CLI ) && ! current_user_can( 'SR' ) ) {
			return;
		}

		$target_dir = trailingslashit( get_stylesheet_directory() ) . 'sync/database/';
		if ( ! is_dir( $target_dir ) ) {
			if ( ! wp_mkdir_p( $target_dir ) ) {
				error_log( 'SRDT: Failed to create database resources directory: ' . $target_dir );
				return;
			}
		}

		// Check if tar command is available
		$tar_available = false;
		if ( function_exists( 'shell_exec' ) ) {
			$which = @shell_exec( 'command -v tar 2>/dev/null' );
			$tar_available = is_string( $which ) && trim( $which ) !== '';
		}

		if ( ! $tar_available ) {
			error_log( 'SRDT: tar command not available for database backup compression' );
			return;
		}

		$sql_filename = 'database-' . gmdate( 'Ymd-His' ) . '.sql';
		$tar_filename = 'database-' . gmdate( 'Ymd-His' ) . '.tar.gz';
		$temp_sql_path = $target_dir . $sql_filename;
		$final_tar_path = $target_dir . $tar_filename;

		// Validate file paths
		if ( function_exists( 'srdt_is_safe_file_path' ) && ! srdt_is_safe_file_path( $temp_sql_path ) ) {
			error_log( 'SRDT: Unsafe database dump file path detected: ' . $temp_sql_path );
			return;
		}
		if ( function_exists( 'srdt_is_safe_file_path' ) && ! srdt_is_safe_file_path( $final_tar_path ) ) {
			error_log( 'SRDT: Unsafe database tar file path detected: ' . $final_tar_path );
			return;
		}

		$export_ok = false;

		// Prefer WP-CLI if available
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			\WP_CLI::runcommand( 'db export ' . escapeshellarg( $temp_sql_path ), [ 'return' => 'all', 'exit_error' => false ] );
			$export_ok = file_exists( $temp_sql_path ) && filesize( $temp_sql_path ) > 0;
		}

		// Fallback to mysqldump or PHP exporter
		if ( ! $export_ok ) {
			if ( ! defined( 'DB_NAME' ) || ! defined( 'DB_USER' ) || ! defined( 'DB_HOST' ) ) {
				error_log( 'SRDT: Database constants missing; cannot perform dump. Falling back to PHP exporter if possible.' );
			} else {
				$host   = DB_HOST;
				$port   = null;
				$socket = null;
				if ( false !== strpos( $host, ':' ) ) {
					list( $host_part, $extra ) = explode( ':', $host, 2 );
					$host = $host_part;
					if ( is_numeric( $extra ) ) {
						$port = (int) $extra;
					} else {
						$socket = $extra;
					}
				}

				$mysqldump_available = false;
				if ( function_exists( 'shell_exec' ) ) {
					$which = @shell_exec( 'command -v mysqldump 2>/dev/null' );
					$mysqldump_available = is_string( $which ) && trim( $which ) !== '';
				}

				if ( function_exists( 'exec' ) && $mysqldump_available ) {
					// Create a temporary MySQL option file for credentials
					$tmp_opt_file = tempnam(sys_get_temp_dir(), 'srdt_mycnf_');
					$opt_contents = "[client]\n";
					$opt_contents .= "user=" . DB_USER . "\n";
					if ( defined( 'DB_PASSWORD' ) && DB_PASSWORD !== '' ) {
						$opt_contents .= "password=" . DB_PASSWORD . "\n";
					}
					$opt_contents .= "host=" . $host . "\n";
					if ( $port )   { $opt_contents .= "port=" . (string) $port . "\n"; }
					if ( $socket ) { $opt_contents .= "socket=" . $socket . "\n"; }
					// Write the option file
					file_put_contents( $tmp_opt_file, $opt_contents );

					$cmd  = 'mysqldump --defaults-extra-file=' . escapeshellarg( $tmp_opt_file );
					$cmd .= ' --single-transaction --quick --lock-tables=false';
					$cmd .= ' ' . escapeshellarg( DB_NAME ) . ' > ' . escapeshellarg( $temp_sql_path ) . ' 2>&1';

					$output = [];
					$return = 0;
					exec( $cmd, $output, $return );
					// Remove the temporary option file
					@unlink( $tmp_opt_file );
					if ( 0 !== $return ) {
						error_log( 'SRDT: mysqldump failed: ' . implode( "\n", $output ) );
					} else {
						$export_ok = file_exists( $temp_sql_path ) && filesize( $temp_sql_path ) > 0;
					}
				}
			}

			// Final fallback: pure-PHP exporter
			if ( ! $export_ok ) {
				$export_ok = self::dump_database_via_php( $temp_sql_path );
			}
		}

		if ( ! $export_ok ) {
			error_log( 'SRDT: Database export failed to create a valid dump file.' );
			// Clean up temp file if it exists
			if ( file_exists( $temp_sql_path ) ) {
				@unlink( $temp_sql_path );
			}
			return;
		}

		// Compress the SQL file using tar
		$cmd = 'cd ' . escapeshellarg( $target_dir ) . ' && tar -czf ' . escapeshellarg( $tar_filename ) . ' ' . escapeshellarg( $sql_filename ) . ' 2>&1';
		$output = [];
		$return = 0;
		exec( $cmd, $output, $return );

		if ( 0 !== $return ) {
			error_log( 'SRDT: tar compression failed for database dump: ' . implode( "\n", $output ) );
			// Clean up temp file
			if ( file_exists( $temp_sql_path ) ) {
				@unlink( $temp_sql_path );
			}
			return;
		}

		// Remove the temporary SQL file
		if ( file_exists( $temp_sql_path ) ) {
			@unlink( $temp_sql_path );
		}

		// Verify the tar.gz file was created successfully
		if ( ! file_exists( $final_tar_path ) || filesize( $final_tar_path ) === 0 ) {
			error_log( 'SRDT: Database tar.gz file was not created successfully: ' . $final_tar_path );
			return;
		}

		do_action( 'srdt_after_dump_database', $final_tar_path );
	}

	/**
	 * Import the most recent SQL dump from theme sync/database.
	 *
	 * Extracts tar.gz database backups and imports the SQL file.
	 * Restores siteurl and home options after import.
	 * Uses WP-CLI if available, otherwise falls back to mysql client.
	 *
	 * @since 1.2.0
	 * @param array $args       Positional CLI args (unused).
	 * @param array $assoc_args Associative CLI args (unused).
	 * @return void
	 */
	public static function import_database( $args = [], $assoc_args = [] ) {
		// Only allow via CLI or admins.
		if ( ( ! defined( 'WP_CLI' ) || ! WP_CLI ) && ! current_user_can( 'SR' ) ) {
			return;
		}

		$source_dir = trailingslashit( get_stylesheet_directory() ) . 'sync/database/';
		if ( ! is_dir( $source_dir ) ) {
			return;
		}

		// Look for tar.gz files first, then fall back to .sql files for backward compatibility
		$tar_files = glob( $source_dir . '*.tar.gz' );
		$sql_files = glob( $source_dir . '*.sql' );
		$files = array_merge( $tar_files, $sql_files );
		
		if ( empty( $files ) ) {
			return;
		}

		usort( $files, function( $a, $b ) {
			return filemtime( $b ) <=> filemtime( $a );
		} );
		$file_path = $files[0];

		if ( function_exists( 'srdt_is_safe_file_path' ) && ! srdt_is_safe_file_path( $file_path ) ) {
			error_log( 'SRDT: Unsafe database import file path detected: ' . $file_path );
			return;
		}

		$old_siteurl = get_option( 'siteurl' );
		$old_home    = get_option( 'home' );

		$sql_file_path = $file_path;
		$temp_sql_file = null;

		// If it's a tar.gz file, extract it first
		if ( preg_match( '/\.tar\.gz$/i', $file_path ) ) {
			// Check if tar command is available
			$tar_available = false;
			if ( function_exists( 'shell_exec' ) ) {
				$which = @shell_exec( 'command -v tar 2>/dev/null' );
				$tar_available = is_string( $which ) && trim( $which ) !== '';
			}

			if ( ! $tar_available ) {
				error_log( 'SRDT: tar command not available for database import extraction' );
				return;
			}

			// Extract the tar.gz file to get the SQL file
			$cmd = 'cd ' . escapeshellarg( $source_dir ) . ' && tar -tzf ' . escapeshellarg( basename( $file_path ) ) . ' 2>&1';
			$output = [];
			$return = 0;
			exec( $cmd, $output, $return );

			if ( 0 !== $return || empty( $output ) ) {
				error_log( 'SRDT: Failed to list contents of tar.gz file: ' . implode( "\n", $output ) );
				return;
			}

			$sql_filename = trim( $output[0] ); // Get the first (and should be only) file in the archive
			$temp_sql_file = $source_dir . 'temp_' . $sql_filename;

			// Extract the SQL file
			$cmd = 'cd ' . escapeshellarg( $source_dir ) . ' && tar -xzf ' . escapeshellarg( basename( $file_path ) ) . ' -O > ' . escapeshellarg( $temp_sql_file ) . ' 2>&1';
			$output = [];
			$return = 0;
			exec( $cmd, $output, $return );

			if ( 0 !== $return ) {
				error_log( 'SRDT: Failed to extract tar.gz database file: ' . implode( "\n", $output ) );
				return;
			}

			if ( ! file_exists( $temp_sql_file ) || filesize( $temp_sql_file ) === 0 ) {
				error_log( 'SRDT: Extracted SQL file is empty or does not exist: ' . $temp_sql_file );
				return;
			}

			$sql_file_path = $temp_sql_file;
		}

		$import_ok = false;

		// Prefer WP-CLI if available
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			\WP_CLI::runcommand( 'db import ' . escapeshellarg( $sql_file_path ), [ 'return' => 'all', 'exit_error' => false ] );
			$import_ok = true; // If command returned, assume import attempted
		}

		// Fallback to mysql client
		if ( ! $import_ok ) {
			if ( ! defined( 'DB_NAME' ) || ! defined( 'DB_USER' ) || ! defined( 'DB_HOST' ) ) {
				error_log( 'SRDT: Database constants missing; cannot perform import.' );
			} else {
				$host   = DB_HOST;
				$port   = null;
				$socket = null;
				if ( false !== strpos( $host, ':' ) ) {
					list( $host_part, $extra ) = explode( ':', $host, 2 );
					$host = $host_part;
					if ( is_numeric( $extra ) ) {
						$port = (int) $extra;
					} else {
						$socket = $extra;
					}
				}

				$cmd  = 'mysql';
				$cmd .= ' -h ' . escapeshellarg( $host );
				if ( $port )   { $cmd .= ' -P ' . escapeshellarg( (string) $port ); }
				if ( $socket ) { $cmd .= ' --socket=' . escapeshellarg( $socket ); }
				$cmd .= ' -u ' . escapeshellarg( DB_USER );
				// Do not pass password on command line; use MYSQL_PWD environment variable instead.
				$cmd .= ' ' . escapeshellarg( DB_NAME ) . ' < ' . escapeshellarg( $sql_file_path ) . ' 2>&1';

				$output = [];
				$return = 0;
				$env = null;
				if ( defined( 'DB_PASSWORD' ) && DB_PASSWORD !== '' ) {
					$env = array_merge($_ENV, ['MYSQL_PWD' => DB_PASSWORD]);
				}
				exec( $cmd, $output, $return, $env );
				if ( 0 !== $return ) {
					error_log( 'SRDT: mysql import failed: ' . implode( "\n", $output ) );
				} else {
					$import_ok = true;
				}
			}
		}

		// Clean up temporary SQL file if it was extracted
		if ( $temp_sql_file && file_exists( $temp_sql_file ) ) {
			@unlink( $temp_sql_file );
		}

		// Restore critical URLs
		if ( $import_ok ) {
			update_option( 'siteurl', $old_siteurl );
			update_option( 'home', $old_home );
			do_action( 'srdt_after_import_database', $file_path, $old_siteurl, $old_home );
		}
	}

	/**
	 * Backup each plugin directory in wp-content/plugins into individual tar.gz files
	 * in the current theme's sync/plugins directory.
	 *
	 * @since 1.2.0
	 * @param array $args       Positional CLI args (unused).
	 * @param array $assoc_args Associative CLI args (unused).
	 * @return int Number of plugin tar.gz files created during this run.
	 */
	public static function backup_plugins( $args = [], $assoc_args = [] ) {
		// Check for production environment
		if ( self::is_production_blocked() ) {
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				\WP_CLI::error( 'SR Dev Tools is not allowed in production environments.' );
			}
			return 0;
		}

		// Only allow via CLI or admins.
		if ( ( ! defined( 'WP_CLI' ) || ! WP_CLI ) && ! current_user_can( 'SR' ) ) {
			return 0;
		}

		$plugins_root = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins';
		if ( ! is_dir( $plugins_root ) ) {
			return 0;
		}

		$target_dir = trailingslashit( get_stylesheet_directory() ) . 'sync/plugins/';
		if ( ! is_dir( $target_dir ) ) {
			if ( ! wp_mkdir_p( $target_dir ) ) {
				error_log( 'SRDT: Failed to create plugins resources directory: ' . $target_dir );
				return 0;
			}
		}

		$dirs = glob( trailingslashit( $plugins_root ) . '*', GLOB_ONLYDIR );
		if ( empty( $dirs ) ) {
			return 0;
		}

		// Check if tar command is available
		$tar_available = false;
		if ( function_exists( 'shell_exec' ) ) {
			$which = @shell_exec( 'command -v tar 2>/dev/null' );
			$tar_available = is_string( $which ) && trim( $which ) !== '';
		}

		if ( ! $tar_available ) {
			error_log( 'SRDT: tar command not available for plugin backups' );
			return 0;
		}

		$created_count = 0;

		foreach ( $dirs as $dir ) {
			$slug = basename( $dir );
			$tar_path = $target_dir . $slug . '.tar.gz';

			if ( function_exists( 'srdt_is_safe_file_path' ) && ! srdt_is_safe_file_path( $tar_path ) ) {
				error_log( 'SRDT: Unsafe plugins backup tar path detected: ' . $tar_path );
				continue;
			}

			// Use tar command to create compressed archive
			$cmd = 'cd ' . escapeshellarg( $plugins_root ) . ' && tar -czf ' . escapeshellarg( $tar_path ) . ' --exclude="*.DS_Store" --exclude=".git" --exclude="*.tmp" --exclude="*.log" ' . escapeshellarg( $slug ) . ' 2>&1';
			$output = [];
			$return = 0;
			exec( $cmd, $output, $return );
			
			if ( 0 !== $return ) {
				error_log( 'SRDT: tar command failed for ' . $slug . ': ' . implode( "\n", $output ) );
				continue;
			}

			$created_count++;
			do_action( 'srdt_after_backup_plugin', $tar_path, $dir );
		}

		do_action( 'srdt_after_backup_plugins', $target_dir );
		return $created_count;
	}

	/**
	 * Install a plugin from a tar.gz archive.
	 *
	 * @since 1.3.0
	 * @param string $tar_path Full path to the tar.gz plugin archive.
	 * @return array Result with 'success' boolean and 'message' string.
	 */
	public static function install_plugin_from_archive( $tar_path ) {
		// Check for production environment
		if ( self::is_production_blocked() ) {
			return [
				'success' => false,
				'message' => __( 'SR Dev Tools is not allowed in production environments.', 'srdt' ),
			];
		}

		// Only allow via CLI or admins.
		if ( ( ! defined( 'WP_CLI' ) || ! WP_CLI ) && ! current_user_can( 'SR' ) ) {
			return [
				'success' => false,
				'message' => __( 'Insufficient permissions.', 'srdt' ),
			];
		}

		// Validate file path
		if ( function_exists( 'srdt_is_safe_file_path' ) && ! srdt_is_safe_file_path( $tar_path ) ) {
			return [
				'success' => false,
				'message' => __( 'Unsafe file path detected.', 'srdt' ),
			];
		}

		// Check if file exists
		if ( ! file_exists( $tar_path ) || ! is_file( $tar_path ) ) {
			return [
				'success' => false,
				'message' => __( 'Plugin archive file not found.', 'srdt' ),
			];
		}

		// Check if tar command is available
		$tar_available = false;
		if ( function_exists( 'shell_exec' ) ) {
			$which = @shell_exec( 'command -v tar 2>/dev/null' );
			$tar_available = is_string( $which ) && trim( $which ) !== '';
		}

		if ( ! $tar_available ) {
			return [
				'success' => false,
				'message' => __( 'tar command not available for plugin installation.', 'srdt' ),
			];
		}

		$plugins_root = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins';
		if ( ! is_dir( $plugins_root ) || ! is_writable( $plugins_root ) ) {
			return [
				'success' => false,
				'message' => __( 'Plugins directory is not writable.', 'srdt' ),
			];
		}

		$plugin_slug = basename( $tar_path, '.tar.gz' );
		$plugin_dir = trailingslashit( $plugins_root ) . $plugin_slug;

		// Check if plugin already exists
		if ( is_dir( $plugin_dir ) ) {
			return [
				'success' => false,
				'message' => sprintf( __( 'Plugin "%s" already exists. Please delete it first.', 'srdt' ), $plugin_slug ),
			];
		}

		// Extract the tar.gz file
		$cmd = 'cd ' . escapeshellarg( $plugins_root ) . ' && tar -xzf ' . escapeshellarg( $tar_path ) . ' 2>&1';
		$output = [];
		$return = 0;
		exec( $cmd, $output, $return );

		if ( 0 !== $return ) {
			error_log( 'SRDT: tar extraction failed for ' . $plugin_slug . ': ' . implode( "\n", $output ) );
			return [
				'success' => false,
				'message' => sprintf( __( 'Failed to extract plugin "%s". Check error logs.', 'srdt' ), $plugin_slug ),
			];
		}

		// Verify extraction was successful
		if ( ! is_dir( $plugin_dir ) ) {
			return [
				'success' => false,
				'message' => sprintf( __( 'Plugin "%s" was not extracted successfully.', 'srdt' ), $plugin_slug ),
			];
		}

		do_action( 'srdt_after_install_plugin', $plugin_dir, $tar_path );

		return [
			'success' => true,
			'message' => sprintf( __( 'Plugin "%s" installed successfully.', 'srdt' ), $plugin_slug ),
		];
	}

	/**
	 * Install all plugins from tar.gz archives in the theme's sync/plugins directory.
	 *
	 * @since 1.3.0
	 * @param array $args       Positional CLI args (unused).
	 * @param array $assoc_args Associative CLI args (unused).
	 * @return array Results with counts and messages.
	 */
	public static function install_all_plugins( $args = [], $assoc_args = [] ) {
		// Check for production environment
		if ( self::is_production_blocked() ) {
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				\WP_CLI::error( 'SR Dev Tools is not allowed in production environments.' );
			}
			return [
				'success' => 0,
				'failed' => 0,
				'skipped' => 0,
				'messages' => [ __( 'SR Dev Tools is not allowed in production environments.', 'srdt' ) ],
			];
		}

		// Only allow via CLI or admins.
		if ( ( ! defined( 'WP_CLI' ) || ! WP_CLI ) && ! current_user_can( 'SR' ) ) {
			return [
				'success' => 0,
				'failed' => 0,
				'skipped' => 0,
				'messages' => [ __( 'Insufficient permissions.', 'srdt' ) ],
			];
		}

		$plugins_dir = trailingslashit( get_stylesheet_directory() ) . 'sync/plugins/';
		$plugin_files = is_dir( $plugins_dir ) ? glob( $plugins_dir . '*.tar.gz' ) : [];

		if ( empty( $plugin_files ) ) {
			return [
				'success' => 0,
				'failed' => 0,
				'skipped' => 0,
				'messages' => [ __( 'No plugin archives found to install.', 'srdt' ) ],
			];
		}

		$results = [
			'success' => 0,
			'failed' => 0,
			'skipped' => 0,
			'messages' => [],
		];

		foreach ( $plugin_files as $plugin_file ) {
			$result = self::install_plugin_from_archive( $plugin_file );
			
			if ( $result['success'] ) {
				$results['success']++;
			} else {
				// Check if it was skipped (already exists) or failed
				if ( strpos( $result['message'], 'already exists' ) !== false ) {
					$results['skipped']++;
				} else {
					$results['failed']++;
				}
			}
			
			$results['messages'][] = $result['message'];
		}

		do_action( 'srdt_after_install_all_plugins', $results );

		return $results;
	}

	/**
	 * Pure-PHP database exporter used when CLI tools are unavailable.
	 *
	 * @since 1.2.1
	 * @param string $file_path Destination .sql file path.
	 * @return bool True on success.
	 */
	private static function dump_database_via_php( $file_path ) {
		if ( function_exists( 'srdt_is_safe_file_path' ) && ! srdt_is_safe_file_path( $file_path ) ) {
			return false;
		}

		global $wpdb;

		if ( ! is_writable( dirname( $file_path ) ) ) {
			return false;
		}

		@set_time_limit( 0 );

		$fh = @fopen( $file_path, 'w' );
		if ( ! $fh ) {
			error_log( 'SRDT: Cannot open dump file for writing: ' . $file_path );
			return false;
		}

		$header  = "-- SRDT SQL Dump\n";
		$header .= "-- Host: " . ( defined( 'DB_HOST' ) ? DB_HOST : 'unknown' ) . "\n";
		$header .= "-- Generation Time: " . gmdate( 'Y-m-d H:i:s' ) . " UTC\n\n";
		$header .= "SET NAMES utf8mb4;\nSET foreign_key_checks=0;\nSET sql_mode='NO_AUTO_VALUE_ON_ZERO';\nSET time_zone='+00:00';\n\n";
		fwrite( $fh, $header );

		$tables = $wpdb->get_col( 'SHOW TABLES' );
		if ( empty( $tables ) ) {
			fclose( $fh );
			return false;
		}

		foreach ( $tables as $table ) {
			$create = $wpdb->get_row( "SHOW CREATE TABLE `$table`", ARRAY_N );
			if ( ! empty( $create[1] ) ) {
				fwrite( $fh, "--\n-- Table structure for table `$table`\n--\n\n" );
				fwrite( $fh, "DROP TABLE IF EXISTS `$table`;\n" );
				fwrite( $fh, $create[1] . ";\n\n" );
			}

			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" );
			if ( $count > 0 ) {
				fwrite( $fh, "--\n-- Dumping data for table `$table`\n--\n\n" );

				$columns = $wpdb->get_col( "SHOW COLUMNS FROM `$table`", 0 );
				if ( empty( $columns ) ) {
					continue;
				}
				$col_list = '`' . implode( '`,`', array_map( 'strval', $columns ) ) . '`';

				$batch  = 1000;
				$offset = 0;
				while ( $offset < $count ) {
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `$table` LIMIT %d OFFSET %d", $batch, $offset ), ARRAY_A );
					if ( empty( $rows ) ) {
						break;
					}

					$values = [];
					foreach ( $rows as $row ) {
						$vals = [];
						foreach ( $columns as $col ) {
							$val = array_key_exists( $col, $row ) ? $row[ $col ] : null;
							if ( is_null( $val ) ) {
								$vals[] = 'NULL';
							} else {
								if ( is_bool( $val ) ) {
									$val = (int) $val;
								}
								if ( is_numeric( $val ) && ! is_string( $val ) ) {
									$vals[] = (string) $val;
								} else {
									$str = (string) $val;
									$str = str_replace( ["\\", "\0", "\n", "\r", "\x1a"], ["\\\\", "\\0", "\\n", "\\r", "\\Z"], $str );
									$str = str_replace( ["'"], ["\\'"], $str );
									$vals[] = "'" . $str . "'";
								}
							}
						}
						$values[] = '(' . implode( ',', $vals ) . ')';
					}

					if ( ! empty( $values ) ) {
						$sql = "INSERT INTO `$table` ($col_list) VALUES\n" . implode( ",\n", $values ) . ";\n";
						fwrite( $fh, $sql );
					}

					$offset += $batch;
				}

				fwrite( $fh, "\n" );
			}
		}

		fwrite( $fh, "SET foreign_key_checks=1;\n" );
		fclose( $fh );
		clearstatcache();

		return file_exists( $file_path ) && filesize( $file_path ) > 0;
	}
}

// Register WP-CLI commands if available.
if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
	\WP_CLI::add_command( 'srdt dump-db', [ 'SRDT_Sync_Posts', 'dump_database' ] );
	\WP_CLI::add_command( 'srdt import-db', [ 'SRDT_Sync_Posts', 'import_database' ] );
	\WP_CLI::add_command( 'srdt backup-plugins', [ 'SRDT_Sync_Posts', 'backup_plugins' ] );
	\WP_CLI::add_command( 'srdt install-plugins', [ 'SRDT_Sync_Posts', 'install_all_plugins' ] );
}
