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
			if ( ! current_user_can( 'edit_theme_options' ) ) {
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
		if ( ! current_user_can( 'edit_theme_options' ) ) {
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
	 *
	 * @since 1.2.0
	 * @param array $args       Positional CLI args (unused).
	 * @param array $assoc_args Associative CLI args (unused).
	 * @return void
	 */
	public static function dump_database( $args = [], $assoc_args = [] ) {
		// Only allow via CLI or admins.
		if ( ( ! defined( 'WP_CLI' ) || ! WP_CLI ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$target_dir = trailingslashit( get_stylesheet_directory() ) . 'sync/database/';
		if ( ! is_dir( $target_dir ) ) {
			if ( ! wp_mkdir_p( $target_dir ) ) {
				error_log( 'SRDT: Failed to create database resources directory: ' . $target_dir );
				return;
			}
		}

		$filename  = 'database-' . gmdate( 'Ymd-His' ) . '.sql';
		$file_path = $target_dir . $filename;

		// Validate file path
		if ( function_exists( 'srdt_is_safe_file_path' ) && ! srdt_is_safe_file_path( $file_path ) ) {
			error_log( 'SRDT: Unsafe database dump file path detected: ' . $file_path );
			return;
		}

		$export_ok = false;

		// Prefer WP-CLI if available
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			\WP_CLI::runcommand( 'db export ' . escapeshellarg( $file_path ), [ 'return' => 'all', 'exit_error' => false ] );
			$export_ok = file_exists( $file_path ) && filesize( $file_path ) > 0;
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
					$cmd .= ' ' . escapeshellarg( DB_NAME ) . ' > ' . escapeshellarg( $file_path ) . ' 2>&1';

					$output = [];
					$return = 0;
					exec( $cmd, $output, $return );
					// Remove the temporary option file
					@unlink( $tmp_opt_file );
					if ( 0 !== $return ) {
						error_log( 'SRDT: mysqldump failed: ' . implode( "\n", $output ) );
					} else {
						$export_ok = file_exists( $file_path ) && filesize( $file_path ) > 0;
					}
				}
			}

			// Final fallback: pure-PHP exporter
			if ( ! $export_ok ) {
				$export_ok = self::dump_database_via_php( $file_path );
			}
		}

		if ( ! $export_ok ) {
			error_log( 'SRDT: Database export failed to create a valid dump file.' );
			return;
		}

		do_action( 'srdt_after_dump_database', $file_path );
	}

	/**
	 * Import the most recent SQL dump from theme sync/database.
	 *
	 * Restores siteurl and home options after import.
	 * Uses multiple strategies for maximum reliability.
	 *
	 * @since 1.2.0
	 * @param array $args       Positional CLI args (unused).
	 * @param array $assoc_args Associative CLI args (unused).
	 * @return bool True on success, false on failure.
	 */
	public static function import_database( $args = [], $assoc_args = [] ) {
		// Only allow via CLI or admins.
		if ( ( ! defined( 'WP_CLI' ) || ! WP_CLI ) && ! current_user_can( 'manage_options' ) ) {
			error_log( 'SRDT: Database import requires admin privileges or WP-CLI context.' );
			return false;
		}

		// Find and validate the SQL file
		$file_path = self::get_latest_sql_file();
		if ( ! $file_path ) {
			error_log( 'SRDT: No SQL dump file found for import.' );
			return false;
		}

		// Validate the SQL file
		$file_info = self::validate_sql_file( $file_path );
		if ( ! $file_info['valid'] ) {
			error_log( 'SRDT: SQL file validation failed: ' . $file_info['error'] );
			return false;
		}

		// Validate database connection
		if ( ! self::validate_database_connection() ) {
			error_log( 'SRDT: Database connection validation failed.' );
			return false;
		}

		// Store current critical options
		$old_siteurl = get_option( 'siteurl' );
		$old_home    = get_option( 'home' );
		$old_stylesheet = get_option( 'stylesheet' );
		$old_template = get_option( 'template' );
		$pre_import_table_count = self::get_table_count();

		// Log import start
		error_log( sprintf( 'SRDT: Starting database import from %s (%.2f MB)', basename( $file_path ), $file_info['size_mb'] ) );

		$import_result = false;
		$import_method = '';

		// Try import methods in order of preference
		$methods = [
			'wp_cli'       => 'import_via_wp_cli',
			'mysql_client' => 'import_via_mysql_client',
			'php'          => 'import_via_php'
		];

		foreach ( $methods as $method_name => $method_function ) {
			if ( method_exists( __CLASS__, $method_function ) ) {
				error_log( "SRDT: Attempting import via {$method_name}" );
				$import_result = self::$method_function( $file_path, $file_info );
				
				if ( $import_result ) {
					$import_method = $method_name;
					error_log( "SRDT: Import successful via {$method_name}" );
					break;
				} else {
					error_log( "SRDT: Import failed via {$method_name}, trying next method" );
				}
			}
		}

		if ( ! $import_result ) {
			error_log( 'SRDT: All import methods failed.' );
			return false;
		}

		// Verify import success
		$verification = self::verify_import_success( $pre_import_table_count, $file_info );
		if ( ! $verification['success'] ) {
			error_log( 'SRDT: Import verification failed: ' . $verification['message'] );
			return false;
		}

		// Restore critical URLs and theme with enhanced reliability
		$restore_result = self::restore_critical_options( $old_siteurl, $old_home, $old_stylesheet, $old_template );
		if ( ! $restore_result['success'] ) {
			error_log( 'SRDT: Failed to restore critical options: ' . $restore_result['message'] );
			// Continue anyway as the import was successful
		}

		// Log success
		error_log( sprintf( 'SRDT: Database import completed successfully via %s. %s', $import_method, $verification['message'] ) );

		// Fire action hook
		do_action( 'srdt_after_import_database', $file_path, $old_siteurl, $old_home, $import_method );

		return true;
	}

	/**
	 * Get the latest SQL file from the sync directory.
	 *
	 * @since 1.2.1
	 * @return string|false File path on success, false on failure.
	 */
	private static function get_latest_sql_file() {
		$source_dir = trailingslashit( get_stylesheet_directory() ) . 'sync/database/';
		
		if ( ! is_dir( $source_dir ) ) {
			return false;
		}

		$files = glob( $source_dir . '*.sql' );
		if ( empty( $files ) ) {
			return false;
		}

		// Sort by modification time, newest first
		usort( $files, function( $a, $b ) {
			return filemtime( $b ) <=> filemtime( $a );
		} );

		$file_path = $files[0];

		// Validate file path security
		if ( function_exists( 'srdt_is_safe_file_path' ) && ! srdt_is_safe_file_path( $file_path ) ) {
			return false;
		}

		return $file_path;
	}

	/**
	 * Validate SQL file for import.
	 *
	 * @since 1.2.1
	 * @param string $file_path Path to SQL file.
	 * @return array Validation result with 'valid', 'error', 'size_mb', 'line_count'.
	 */
	private static function validate_sql_file( $file_path ) {
		$result = [
			'valid'      => false,
			'error'      => '',
			'size_mb'    => 0,
			'line_count' => 0
		];

		if ( ! file_exists( $file_path ) ) {
			$result['error'] = 'File does not exist';
			return $result;
		}

		if ( ! is_readable( $file_path ) ) {
			$result['error'] = 'File is not readable';
			return $result;
		}

		$file_size = filesize( $file_path );
		if ( $file_size === false || $file_size === 0 ) {
			$result['error'] = 'File is empty or unreadable';
			return $result;
		}

		$result['size_mb'] = $file_size / 1024 / 1024;

		// Check if file is too large (configurable limit)
		$max_size_mb = apply_filters( 'srdt_max_import_file_size_mb', 500 );
		if ( $result['size_mb'] > $max_size_mb ) {
			$result['error'] = sprintf( 'File too large (%.2f MB > %d MB limit)', $result['size_mb'], $max_size_mb );
			return $result;
		}

		// Basic SQL file validation - check first few lines
		$handle = fopen( $file_path, 'r' );
		if ( ! $handle ) {
			$result['error'] = 'Cannot open file for reading';
			return $result;
		}

		$has_sql_content = false;
		$line_count = 0;
		$lines_to_check = 50; // Check first 50 lines

		while ( ( $line = fgets( $handle ) ) !== false && $line_count < $lines_to_check ) {
			$line_count++;
			$trimmed = trim( $line );
			
			// Skip empty lines and comments
			if ( empty( $trimmed ) || strpos( $trimmed, '--' ) === 0 || strpos( $trimmed, '#' ) === 0 ) {
				continue;
			}

			// Look for SQL keywords
			if ( preg_match( '/^(CREATE|INSERT|DROP|ALTER|SET|USE)\s+/i', $trimmed ) ) {
				$has_sql_content = true;
				break;
			}
		}

		// Count total lines for progress tracking
		while ( fgets( $handle ) !== false ) {
			$line_count++;
		}
		fclose( $handle );

		$result['line_count'] = $line_count;

		if ( ! $has_sql_content ) {
			$result['error'] = 'File does not appear to contain valid SQL content';
			return $result;
		}

		$result['valid'] = true;
		return $result;
	}

	/**
	 * Validate database connection.
	 *
	 * @since 1.2.1
	 * @return bool True if connection is valid.
	 */
	private static function validate_database_connection() {
		global $wpdb;

		// Check if required constants are defined
		if ( ! defined( 'DB_NAME' ) || ! defined( 'DB_USER' ) || ! defined( 'DB_HOST' ) ) {
			return false;
		}

		// Test database connection
		$test_query = $wpdb->get_var( "SELECT 1" );
		if ( $test_query !== '1' ) {
			return false;
		}

		// Check if we can write to the database
		$test_table = $wpdb->prefix . 'srdt_import_test_' . time();
		$create_result = $wpdb->query( "CREATE TEMPORARY TABLE `{$test_table}` (id INT)" );
		if ( $create_result === false ) {
			return false;
		}

		return true;
	}

	/**
	 * Import database via WP-CLI.
	 *
	 * @since 1.2.1
	 * @param string $file_path Path to SQL file.
	 * @param array  $file_info File information from validation.
	 * @return bool True on success.
	 */
	private static function import_via_wp_cli( $file_path, $file_info ) {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WP_CLI' ) ) {
			return false;
		}

		try {
			$result = \WP_CLI::runcommand( 
				'db import ' . escapeshellarg( $file_path ), 
				[ 
					'return' => 'all', 
					'exit_error' => false,
					'launch' => false
				] 
			);

			// Check if command was successful
			if ( $result->return_code === 0 ) {
				return true;
			} else {
				error_log( 'SRDT: WP-CLI import failed with return code ' . $result->return_code );
				if ( ! empty( $result->stderr ) ) {
					error_log( 'SRDT: WP-CLI error: ' . $result->stderr );
				}
				return false;
			}
		} catch ( Exception $e ) {
			error_log( 'SRDT: WP-CLI import exception: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Import database via mysql client.
	 *
	 * @since 1.2.1
	 * @param string $file_path Path to SQL file.
	 * @param array  $file_info File information from validation.
	 * @return bool True on success.
	 */
	private static function import_via_mysql_client( $file_path, $file_info ) {
		// Check if mysql client is available
		if ( ! function_exists( 'exec' ) ) {
			return false;
		}

		$mysql_available = false;
		if ( function_exists( 'shell_exec' ) ) {
			$which = @shell_exec( 'command -v mysql 2>/dev/null' );
			$mysql_available = is_string( $which ) && trim( $which ) !== '';
		}

		if ( ! $mysql_available ) {
			return false;
		}

		// Parse database host
		$host = DB_HOST;
		$port = null;
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

		// Create temporary MySQL config file for security
		$config_file = self::create_mysql_config_file( $host, $port, $socket );
		if ( ! $config_file ) {
			return false;
		}

		try {
			// Build mysql command
			$cmd = 'mysql --defaults-extra-file=' . escapeshellarg( $config_file );
			$cmd .= ' --default-character-set=utf8mb4';
			$cmd .= ' ' . escapeshellarg( DB_NAME );
			$cmd .= ' < ' . escapeshellarg( $file_path );
			$cmd .= ' 2>&1';

			$output = [];
			$return_code = 0;
			exec( $cmd, $output, $return_code );

			// Clean up config file
			@unlink( $config_file );

			if ( $return_code === 0 ) {
				return true;
			} else {
				error_log( 'SRDT: MySQL client import failed with return code ' . $return_code );
				if ( ! empty( $output ) ) {
					error_log( 'SRDT: MySQL client error: ' . implode( "\n", $output ) );
				}
				return false;
			}
		} catch ( Exception $e ) {
			@unlink( $config_file );
			error_log( 'SRDT: MySQL client import exception: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Import database via pure PHP (enhanced version).
	 *
	 * @since 1.2.1
	 * @param string $file_path Path to SQL file.
	 * @param array  $file_info File information from validation.
	 * @return bool True on success.
	 */
	private static function import_via_php( $file_path, $file_info ) {
		global $wpdb;

		@set_time_limit( 0 );
		@ini_set( 'memory_limit', '512M' );

		$handle = fopen( $file_path, 'r' );
		if ( ! $handle ) {
			error_log( 'SRDT: Cannot open SQL file for PHP import' );
			return false;
		}

		$query = '';
		$line_number = 0;
		$queries_executed = 0;
		$errors = 0;
		$max_errors = 10; // Stop after too many errors

		// Progress tracking
		$progress_interval = max( 1000, intval( $file_info['line_count'] / 20 ) );

		while ( ( $line = fgets( $handle ) ) !== false ) {
			$line_number++;
			$line = trim( $line );

			// Skip empty lines and comments
			if ( empty( $line ) || strpos( $line, '--' ) === 0 || strpos( $line, '#' ) === 0 ) {
				continue;
			}

			// Handle SET statements and other single-line commands
			if ( preg_match( '/^(SET|USE)\s+/i', $line ) && substr( $line, -1 ) === ';' ) {
				$result = $wpdb->query( rtrim( $line, ';' ) );
				if ( $result === false ) {
					$errors++;
					error_log( "SRDT: PHP import error on line {$line_number}: " . $wpdb->last_error );
					if ( $errors >= $max_errors ) {
						break;
					}
				}
				continue;
			}

			// Accumulate multi-line queries
			$query .= $line . "\n";

			// Execute when we hit a semicolon at the end of a line
			if ( substr( $line, -1 ) === ';' ) {
				$query = trim( $query );
				if ( ! empty( $query ) ) {
					$result = $wpdb->query( rtrim( $query, ';' ) );
					if ( $result === false ) {
						$errors++;
						error_log( "SRDT: PHP import error on line {$line_number}: " . $wpdb->last_error );
						if ( $errors >= $max_errors ) {
							break;
						}
					} else {
						$queries_executed++;
					}
				}
				$query = '';

				// Progress logging
				if ( $line_number % $progress_interval === 0 ) {
					$progress = ( $line_number / $file_info['line_count'] ) * 100;
					error_log( sprintf( 'SRDT: PHP import progress: %.1f%% (%d queries executed)', $progress, $queries_executed ) );
				}
			}
		}

		fclose( $handle );

		if ( $errors >= $max_errors ) {
			error_log( "SRDT: PHP import stopped due to too many errors ({$errors})" );
			return false;
		}

		error_log( "SRDT: PHP import completed. {$queries_executed} queries executed, {$errors} errors" );
		return $errors < $max_errors;
	}

	/**
	 * Create temporary MySQL configuration file.
	 *
	 * @since 1.2.1
	 * @param string   $host   Database host.
	 * @param int|null $port   Database port.
	 * @param string   $socket Database socket.
	 * @return string|false Path to config file or false on failure.
	 */
	private static function create_mysql_config_file( $host, $port = null, $socket = null ) {
		$config_file = tempnam( sys_get_temp_dir(), 'srdt_mysql_' );
		if ( ! $config_file ) {
			return false;
		}

		$config_content = "[client]\n";
		$config_content .= "user=" . DB_USER . "\n";
		
		if ( defined( 'DB_PASSWORD' ) && DB_PASSWORD !== '' ) {
			$config_content .= "password=" . DB_PASSWORD . "\n";
		}
		
		$config_content .= "host=" . $host . "\n";
		
		if ( $port ) {
			$config_content .= "port=" . $port . "\n";
		}
		
		if ( $socket ) {
			$config_content .= "socket=" . $socket . "\n";
		}

		$result = file_put_contents( $config_file, $config_content );
		if ( $result === false ) {
			@unlink( $config_file );
			return false;
		}

		// Set restrictive permissions
		chmod( $config_file, 0600 );

		return $config_file;
	}

	/**
	 * Verify import success.
	 *
	 * @since 1.2.1
	 * @param int   $pre_import_table_count Table count before import.
	 * @param array $file_info              File information.
	 * @return array Verification result with 'success' and 'message'.
	 */
	private static function verify_import_success( $pre_import_table_count, $file_info ) {
		$result = [
			'success' => false,
			'message' => ''
		];

		// Check if database is accessible
		global $wpdb;
		$test_query = $wpdb->get_var( "SELECT 1" );
		if ( $test_query !== '1' ) {
			$result['message'] = 'Database connection lost after import';
			return $result;
		}

		// Check table count
		$post_import_table_count = self::get_table_count();
		if ( $post_import_table_count === false ) {
			$result['message'] = 'Cannot verify table count after import';
			return $result;
		}

		// Basic WordPress tables check
		$required_tables = [ 'posts', 'users', 'options' ];
		$missing_tables = [];
		
		foreach ( $required_tables as $table ) {
			$full_table_name = $wpdb->prefix . $table;
			$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $full_table_name ) );
			if ( ! $exists ) {
				$missing_tables[] = $full_table_name;
			}
		}

		if ( ! empty( $missing_tables ) ) {
			$result['message'] = 'Missing required tables: ' . implode( ', ', $missing_tables );
			return $result;
		}

		$result['success'] = true;
		$result['message'] = sprintf( 
			'Import verified: %d tables present (was %d)', 
			$post_import_table_count, 
			$pre_import_table_count 
		);

		return $result;
	}

	/**
	 * Get database table count.
	 *
	 * @since 1.2.1
	 * @return int|false Table count or false on failure.
	 */
	private static function get_table_count() {
		global $wpdb;
		
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '" . DB_NAME . "'" );
		
		return $count !== null ? (int) $count : false;
	}

	/**
	 * Restore critical options after database import with enhanced reliability.
	 *
	 * @since 1.2.2
	 * @param string $old_siteurl    Original siteurl value.
	 * @param string $old_home       Original home value.
	 * @param string $old_stylesheet Original stylesheet (theme) value.
	 * @param string $old_template   Original template (parent theme) value.
	 * @return array Result with 'success' and 'message'.
	 */
	private static function restore_critical_options( $old_siteurl, $old_home, $old_stylesheet = '', $old_template = '' ) {
		global $wpdb;

		$result = [
			'success' => false,
			'message' => ''
		];

		// Log the restoration attempt
		error_log( sprintf( 'SRDT: Attempting to restore critical options - siteurl: %s, home: %s, stylesheet: %s, template: %s', 
			$old_siteurl, $old_home, $old_stylesheet, $old_template ) );

		// Clear WordPress caches to ensure fresh data
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		// Clear options cache specifically
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'siteurl', 'options' );
			wp_cache_delete( 'home', 'options' );
			wp_cache_delete( 'stylesheet', 'options' );
			wp_cache_delete( 'template', 'options' );
		}

		// Verify we have valid values to restore
		if ( empty( $old_siteurl ) || empty( $old_home ) ) {
			$result['message'] = 'Invalid original values provided';
			return $result;
		}

		// Use direct database queries to bypass caching issues
		$options_table = $wpdb->prefix . 'options';
		
		// Update siteurl
		$siteurl_result = $wpdb->update(
			$options_table,
			[ 'option_value' => $old_siteurl ],
			[ 'option_name' => 'siteurl' ],
			[ '%s' ],
			[ '%s' ]
		);

		// Update home
		$home_result = $wpdb->update(
			$options_table,
			[ 'option_value' => $old_home ],
			[ 'option_name' => 'home' ],
			[ '%s' ],
			[ '%s' ]
		);

		// Update theme options if provided
		$stylesheet_result = false;
		$template_result = false;
		
		if ( ! empty( $old_stylesheet ) ) {
			$stylesheet_result = $wpdb->update(
				$options_table,
				[ 'option_value' => $old_stylesheet ],
				[ 'option_name' => 'stylesheet' ],
				[ '%s' ],
				[ '%s' ]
			);
		}
		
		if ( ! empty( $old_template ) ) {
			$template_result = $wpdb->update(
				$options_table,
				[ 'option_value' => $old_template ],
				[ 'option_name' => 'template' ],
				[ '%s' ],
				[ '%s' ]
			);
		}

		// Log the database update results
		error_log( sprintf( 'SRDT: Direct DB update results - siteurl: %s, home: %s, stylesheet: %s, template: %s', 
			$siteurl_result !== false ? 'success' : 'failed',
			$home_result !== false ? 'success' : 'failed',
			! empty( $old_stylesheet ) ? ( $stylesheet_result !== false ? 'success' : 'failed' ) : 'skipped',
			! empty( $old_template ) ? ( $template_result !== false ? 'success' : 'failed' ) : 'skipped'
		) );

		// If direct updates failed, try insert (in case the options don't exist)
		if ( $siteurl_result === false ) {
			$siteurl_insert = $wpdb->replace(
				$options_table,
				[
					'option_name'  => 'siteurl',
					'option_value' => $old_siteurl,
					'autoload'     => 'yes'
				],
				[ '%s', '%s', '%s' ]
			);
			error_log( 'SRDT: siteurl insert result: ' . ( $siteurl_insert !== false ? 'success' : 'failed' ) );
		}

		if ( $home_result === false ) {
			$home_insert = $wpdb->replace(
				$options_table,
				[
					'option_name'  => 'home',
					'option_value' => $old_home,
					'autoload'     => 'yes'
				],
				[ '%s', '%s', '%s' ]
			);
			error_log( 'SRDT: home insert result: ' . ( $home_insert !== false ? 'success' : 'failed' ) );
		}

		// Handle theme option fallbacks if needed
		if ( ! empty( $old_stylesheet ) && $stylesheet_result === false ) {
			$stylesheet_insert = $wpdb->replace(
				$options_table,
				[
					'option_name'  => 'stylesheet',
					'option_value' => $old_stylesheet,
					'autoload'     => 'yes'
				],
				[ '%s', '%s', '%s' ]
			);
			error_log( 'SRDT: stylesheet insert result: ' . ( $stylesheet_insert !== false ? 'success' : 'failed' ) );
		}

		if ( ! empty( $old_template ) && $template_result === false ) {
			$template_insert = $wpdb->replace(
				$options_table,
				[
					'option_name'  => 'template',
					'option_value' => $old_template,
					'autoload'     => 'yes'
				],
				[ '%s', '%s', '%s' ]
			);
			error_log( 'SRDT: template insert result: ' . ( $template_insert !== false ? 'success' : 'failed' ) );
		}

		// Clear caches again after updates
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		// Force WordPress to reload options
		if ( function_exists( 'wp_load_alloptions' ) ) {
			wp_load_alloptions( true ); // Force refresh
		}

		// Verify the options were actually updated
		$verification_attempts = 3;
		$verification_success = false;

		for ( $i = 0; $i < $verification_attempts; $i++ ) {
			// Clear cache before each verification attempt
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( 'alloptions', 'options' );
				wp_cache_delete( 'siteurl', 'options' );
				wp_cache_delete( 'home', 'options' );
			}

			// Get fresh values from database
			$current_siteurl = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$options_table} WHERE option_name = %s", 'siteurl' ) );
			$current_home = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$options_table} WHERE option_name = %s", 'home' ) );

			error_log( sprintf( 'SRDT: Verification attempt %d - Current siteurl: %s, Current home: %s', 
				$i + 1, $current_siteurl, $current_home ) );

			if ( $current_siteurl === $old_siteurl && $current_home === $old_home ) {
				$verification_success = true;
				break;
			}

			// Wait a moment before next attempt
			if ( $i < $verification_attempts - 1 ) {
				usleep( 500000 ); // 0.5 seconds
			}
		}

		if ( $verification_success ) {
			$result['success'] = true;
			$theme_message = '';
			if ( ! empty( $old_stylesheet ) || ! empty( $old_template ) ) {
				$theme_parts = [];
				if ( ! empty( $old_stylesheet ) ) {
					$theme_parts[] = "stylesheet to {$old_stylesheet}";
				}
				if ( ! empty( $old_template ) ) {
					$theme_parts[] = "template to {$old_template}";
				}
				$theme_message = ' and ' . implode( ' and ', $theme_parts );
			}
			$result['message'] = sprintf( 'Successfully restored siteurl to %s and home to %s%s', $old_siteurl, $old_home, $theme_message );
			error_log( 'SRDT: Critical options restoration verified successfully' );
		} else {
			$result['message'] = sprintf( 
				'Failed to verify restoration - Expected siteurl: %s, home: %s. Current siteurl: %s, home: %s',
				$old_siteurl, $old_home, $current_siteurl ?? 'null', $current_home ?? 'null'
			);
			error_log( 'SRDT: Critical options restoration verification failed: ' . $result['message'] );
		}

		return $result;
	}

	/**
	 * Backup each plugin directory in wp-content/plugins into individual zip files
	 * in the current theme's sync/plugins directory.
	 *
	 * @since 1.2.0
	 * @param array $args       Positional CLI args (unused).
	 * @param array $assoc_args Associative CLI args (unused).
	 * @return int Number of plugin zip files created during this run.
	 */
	public static function backup_plugins( $args = [], $assoc_args = [] ) {
		// Only allow via CLI or admins.
		if ( ( ! defined( 'WP_CLI' ) || ! WP_CLI ) && ! current_user_can( 'manage_options' ) ) {
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

		// Clean up zip files that don't have matching plugin directories
		$existing_zips = glob( $target_dir . '*.zip' );
		if ( ! empty( $existing_zips ) ) {
			// Get current plugin directory names (slugs)
			$current_plugin_slugs = array_map( 'basename', $dirs );
			
			foreach ( $existing_zips as $zip_file ) {
				$zip_basename = basename( $zip_file, '.zip' );
				
				// If this zip file doesn't have a corresponding plugin directory, remove it
				if ( ! in_array( $zip_basename, $current_plugin_slugs, true ) ) {
					if ( @unlink( $zip_file ) ) {
						error_log( 'SRDT: Removed orphaned plugin backup: ' . basename( $zip_file ) );
					} else {
						error_log( 'SRDT: Failed to remove orphaned plugin backup: ' . basename( $zip_file ) );
					}
				}
			}
		}

		$timestamp = gmdate( 'Ymd-His' );
		$zip_available = class_exists( 'ZipArchive' );
		$created_count = 0;

		foreach ( $dirs as $dir ) {
			$slug = basename( $dir );
			$zip_path = $target_dir . $slug . '.zip';

			if ( function_exists( 'srdt_is_safe_file_path' ) && ! srdt_is_safe_file_path( $zip_path ) ) {
				error_log( 'SRDT: Unsafe plugins backup zip path detected: ' . $zip_path );
				continue;
			}

			if ( $zip_available ) {
				$zip = new ZipArchive();
				if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
					error_log( 'SRDT: Failed to create zip: ' . $zip_path );
					continue;
				}

				$files = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
					RecursiveIteratorIterator::LEAVES_ONLY
				);
				foreach ( $files as $name => $file ) {
					if ( $file->isDir() ) {
						continue;
					}
					$filepath  = $file->getRealPath();
					$localname = $slug . '/' . substr( $filepath, strlen( trailingslashit( $dir ) ) );
					$zip->addFile( $filepath, $localname );
				}
				$zip->close();
				$created_count++;
			} else {
				// Fallback to system zip command
				$cmd = 'cd ' . escapeshellarg( $plugins_root ) . ' && zip -r -q ' . escapeshellarg( $zip_path ) . ' ' . escapeshellarg( $slug ) . ' -x "*.DS_Store" 2>&1';
				$output = [];
				$return = 0;
				exec( $cmd, $output, $return );
				if ( 0 !== $return ) {
					error_log( 'SRDT: zip command failed for ' . $slug . ': ' . implode( "\n", $output ) );
					continue;
				}
				$created_count++;
			}

			do_action( 'srdt_after_backup_plugin', $zip_path, $dir );
		}

		do_action( 'srdt_after_backup_plugins', $target_dir );
		return $created_count;
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
}
