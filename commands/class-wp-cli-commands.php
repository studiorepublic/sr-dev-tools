<?php
/**
 * Get the sync path for exports
 *
 * @package   SR Dev Tools
 * @author    Chris Todhunter
 * @since     1.0.0
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
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'srdt', 'SRDT_WP_CLI_Commands' );
}

class SRDT_WP_CLI_Commands {

	/**
	 * Export all posts to JSON.
	 *
	 * ## OPTIONS
	 * 
	 * [--batch-size=<number>]
	 * : Number of posts to process per batch. Use 0 to disable batching. Default: 50
	 *
	 * ## EXAMPLES
	 * wp srdt export
	 * wp srdt export --batch-size=100
	 * wp srdt export --batch-size=0
     * 
     * @since  1.0.0
     * @return void
	 */
	public function export( $args, $assoc_args ) {
		$batch_size = isset( $assoc_args['batch-size'] ) ? absint( $assoc_args['batch-size'] ) : 50;
		$no_batch = ( 0 === $batch_size );
		
		// Export options and menus first (these are typically small)
        SRDT_Sync_Posts::export_options_to_json();
        SRDT_Sync_Posts::export_menus_to_json();
        
        if ( $no_batch ) {
			// Legacy behavior - export all at once.
			$post_types = SRDT_Sync_Posts::get_supported_post_types();
			$posts = get_posts( [
				'post_type'      => $post_types,
				'posts_per_page' => -1,
				'post_status'    => 'any',
			] );

			WP_CLI::log( "Exporting all posts at once (no batching)" );

			foreach ( $posts as $post ) {
				SRDT_Sync_Posts::export_post_to_json( $post->ID, $post );
			}
			
			WP_CLI::success( sprintf( 'All %d posts exported to JSON. Post types: %s', count( $posts ), implode( ', ', $post_types ) ) );
		} else {
			// Batch processing
			$offset = 0;
			$total_processed = 0;
			
			WP_CLI::log( "Starting batch export with batch size: {$batch_size}" );
			
			do {
				$result = SRDT_Sync_Posts::export_posts_batch( $batch_size, $offset );
				$total_processed += $result['processed'];
				$offset = $result['offset'];
				
				if ( $result['processed'] > 0 ) {
					WP_CLI::log( sprintf( 
						'Processed batch: %d posts | Total: %d/%d | Remaining: %d',
						$result['processed'],
						$total_processed,
						$result['total'],
						$result['remaining']
					) );
				}
				
				// Small delay to prevent overwhelming the server
				if ( $result['remaining'] > 0 ) {
					usleep( 100000 ); // 0.1 second
				}
				
			} while ( $result['remaining'] > 0 && $result['processed'] > 0 );
			
			$post_types = SRDT_Sync_Posts::get_supported_post_types();
			WP_CLI::success( sprintf( 
				'Batch export completed! Processed %d posts across post types: %s', 
				$total_processed,
				implode( ', ', $post_types )
			) );
		}
	}

	/**
	 * Import all JSON files into DB.
	 *
	 * ## OPTIONS
	 * 
	 * [--batch-size=<number>]
	 * : Number of files to process per batch. Use 0 to disable batching. Default: 50
	 *
	 * ## EXAMPLES
	 * wp srdt import
	 * wp srdt import --batch-size=25
	 * wp srdt import --batch-size=0
     * 
     * @since  1.0.0
     * @return void
	 */
	public function import( $args, $assoc_args ) {
		WP_CLI::warning( 'This will overwrite existing data. Make sure you have a backup.' );
		
		$batch_size = isset( $assoc_args['batch-size'] ) ? absint( $assoc_args['batch-size'] ) : 50;
		$no_batch = ( 0 === $batch_size );
		
		// Import options and menus first
        SRDT_Sync_Posts::import_options_from_json();
        SRDT_Sync_Posts::import_menus_from_json();
        
        if ( $no_batch ) {
			// Legacy behavior - import all at once
			WP_CLI::log( "Importing all files at once (no batching)" );
			SRDT_Sync_Posts::import_all_json_files();
			WP_CLI::success( 'All JSON files imported to DB.' );
		} else {
			// Batch processing
			$offset = 0;
			$total_processed = 0;
			
			WP_CLI::log( "Starting batch import with batch size: {$batch_size}" );
			
			do {
				$result = SRDT_Sync_Posts::import_posts_batch( $batch_size, $offset );
				$total_processed += $result['processed'];
				$offset = $result['offset'];
				
				if ( $result['processed'] > 0 ) {
					WP_CLI::log( sprintf( 
						'Processed batch: %d files | Total: %d/%d | Remaining: %d',
						$result['processed'],
						$total_processed,
						$result['total'],
						$result['remaining']
					) );
				}
				
				// Small delay to prevent overwhelming the database
				if ( $result['remaining'] > 0 ) {
					usleep( 250000 ); // 0.25 second (imports are more intensive)
				}
				
			} while ( $result['remaining'] > 0 && $result['processed'] > 0 );
			
			WP_CLI::success( sprintf( 
				'Batch import completed! Processed %d files.',
				$total_processed
			) );
		}
	}

	/**
	 * Generate module pages based on ACF field names starting with "Partial".
	 *
	 * Scans the current theme's acf-json directory for ACF field groups and creates
	 * child pages under a "Modules" parent page for each field starting with "Partial".
	 *
	 * ## EXAMPLES
	 * wp srdt generate-modules
     * 
     * @since  1.6.1
     * @return void
	 */
	public function generate_modules( $args, $assoc_args ) {
		WP_CLI::log( 'Scanning ACF field groups for Partial fields...' );
		
		$results = srdt_generate_modules_pages();
		
		// Check for errors
		if ( isset( $results['error'] ) ) {
			WP_CLI::error( $results['error'] );
			return;
		}
		
		// Display results
		$created_count = isset( $results['created'] ) ? count( $results['created'] ) : 0;
		$skipped_count = isset( $results['skipped'] ) ? count( $results['skipped'] ) : 0;
		$errors_count  = isset( $results['errors'] ) ? count( $results['errors'] ) : 0;
		$partials_count = isset( $results['partials'] ) ? count( $results['partials'] ) : 0;
		
		// Modules page status
		if ( ! empty( $results['modules_page_created'] ) ) {
			WP_CLI::log( '✓ Created "Modules" parent page' );
		} else {
			WP_CLI::log( '✓ "Modules" parent page already exists' );
		}
		
		// Found partials
		WP_CLI::log( sprintf( 'Found %d Partial field(s) in ACF JSON files', $partials_count ) );
		
		// Created pages
		if ( $created_count > 0 ) {
			WP_CLI::log( sprintf( '✓ Created %d new page(s):', $created_count ) );
			foreach ( $results['created'] as $page_name ) {
				WP_CLI::log( '  - ' . $page_name );
			}
		}
		
		// Skipped pages
		if ( $skipped_count > 0 ) {
			WP_CLI::log( sprintf( '⊘ Skipped %d existing page(s):', $skipped_count ) );
			foreach ( $results['skipped'] as $page_name ) {
				WP_CLI::log( '  - ' . $page_name );
			}
		}
		
		// Errors
		if ( $errors_count > 0 ) {
			WP_CLI::warning( sprintf( 'Encountered %d error(s):', $errors_count ) );
			foreach ( $results['errors'] as $error ) {
				WP_CLI::warning( '  - ' . $error );
			}
		}
		
		// Final summary
		if ( $errors_count > 0 ) {
			WP_CLI::warning( sprintf( 
				'Module pages generation completed with errors. Created: %d, Skipped: %d, Errors: %d',
				$created_count,
				$skipped_count,
				$errors_count
			) );
		} else {
			WP_CLI::success( sprintf( 
				'Module pages generation completed! Created: %d, Skipped: %d',
				$created_count,
				$skipped_count
			) );
		}
	}
}
