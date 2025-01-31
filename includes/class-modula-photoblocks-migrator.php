<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Modula_Photoblocks_Migrator {

	/**
	 * Holds the class object.
	 *
	 * @var object
	 *
	 * @since 1.0.0
	 */
	public static $instance;

	/**
	 * Primary class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		require_once MODULA_PBG_MIGRATOR_PATH . 'includes/class-modula-plugin-checker.php';

		if ( class_exists( 'Modula_Plugin_Checker' ) ) {

			$modula_checker = Modula_Plugin_Checker::get_instance();

			if ( ! $modula_checker->check_for_modula() ) {

				if ( is_admin() ) {
					add_action( 'admin_notices', array( $modula_checker, 'display_modula_notice' ) );
				}

			} else {

				// Add AJAX
				add_action( 'wp_ajax_modula_importer_photoblocks_gallery_import', array(
					$this,
					'photoblocks_gallery_import'
				) );
				add_action( 'wp_ajax_modula_importer_photoblocks_gallery_imported_update', array(
					$this,
					'update_imported'
				) );

				// Add infor used for Modula's migrate functionality
				add_filter( 'modula_migrator_sources', array( $this, 'add_source' ), 15, 1 );
				add_filter( 'modula_source_galleries_photoblocks', array( $this, 'add_source_galleries' ), 15, 1 );
				add_filter( 'modula_g_gallery_photoblocks', array( $this, 'add_gallery_info' ), 15, 3 );
				add_filter( 'modula_migrator_images_photoblocks', array( $this, 'migrator_images' ), 15, 2 );
			}
		}

	}


	/**
	 * Returns the singleton instance of the class.
	 *
	 * @since 1.0.0
	 */
	public static function get_instance() {

		if ( ! isset( self::$instance ) && ! ( self::$instance instanceof Modula_Photoblocks_Migrator ) ) {
			self::$instance = new Modula_Photoblocks_Migrator();
		}

		return self::$instance;

	}


	/**
	 * Get all Gallery PhotoBlocks galleries
	 *
	 * @return mixed
	 *
	 * @since 1.0.0
	 */
	public function get_galleries() {

		global $wpdb;
		$empty_galleries = array();

		if ( ! $wpdb->get_var( "SHOW TABLES LIKE '" . $wpdb->prefix . "photoblocks'" ) ) {
			return false;
		}
		$galleries = $wpdb->get_results( " SELECT * FROM " . $wpdb->prefix . "photoblocks" );
		if ( count( $galleries ) != 0 ) {
			foreach ( $galleries as $key => $gallery ) {
				$count = $this->images_count( $gallery->id );

				if ( $count == 0 ) {
					unset( $galleries[ $key ] );
					$empty_galleries[ $key ] = $gallery;
				}
			}

			if ( count( $galleries ) != 0 ) {
				$return_galleries['valid_galleries'] = $galleries;
			}
			if ( count( $empty_galleries ) != 0 ) {
				$return_galleries['empty_galleries'] = $empty_galleries;
			}

			if ( count( $return_galleries ) != 0 ) {
				return $return_galleries;
			}
		}

		return false;
	}


	/**
	 * Get gallery image count
	 *
	 * @param $id
	 *
	 * @return int
	 *
	 * @since 1.0.0
	 */
	public function images_count( $id ) {

		global $wpdb;

		$sql     = $wpdb->prepare( "SELECT * FROM " . $wpdb->prefix . "photoblocks
    						WHERE id = %d LIMIT 1",
			$id );
		$gallery = $wpdb->get_row( $sql );
		$blocks  = json_decode( $gallery->blocks );

		return count( $blocks );
	}


	/**
	 * Imports a gallery from PhotoBlocks to Modula
	 *
	 * @since 1.0.0
	 */
	public function photoblocks_gallery_import( $gallery_id = '' ) {

		global $wpdb;

		$modula_importer = Modula_Importer::get_instance();

		// Set max execution time so we don't timeout
		ini_set( 'max_execution_time', 0 );
		set_time_limit( 0 );

		// If no gallery ID, get from AJAX request
		if ( empty( $gallery_id ) ) {

			// Run a security check first.
			check_ajax_referer( 'modula-importer', 'nonce' );

			if ( ! isset( $_POST['id'] ) ) {
				$this->modula_import_result( false, esc_html__( 'No gallery was selected', 'modula-photoblocks-migrator' ), false );
			}

			$gallery_id = absint( $_POST['id'] );

		}

		$imported_galleries = get_option( 'modula_importer' );
		// If already migrated don't migrate
		if ( isset( $imported_galleries['galleries']['photoblocks'][ $gallery_id ] ) ) {

			$modula_gallery = get_post_type( $imported_galleries['galleries']['photoblocks'][ $gallery_id ] );

			if ( 'modula-gallery' == $modula_gallery ) {
				// Trigger delete function if option is set to delete
				if ( isset( $_POST['clean'] ) && 'delete' == $_POST['clean'] ) {
					$this->clean_entries( $gallery_id );
				}
				$this->modula_import_result( false, esc_html__( 'Gallery already migrated!', 'modula-photoblocks-migrator' ), false );
			}
		}

		$sql = $wpdb->prepare( "SELECT * FROM " . $wpdb->prefix . "photoblocks
    						WHERE id = %d LIMIT 1",
			$gallery_id );

		$gallery = $wpdb->get_row( $sql );

		$gallery_blocks = json_decode( $gallery->blocks );
		$gallery_data   = json_decode( $gallery->data );
		$images         = array();

		foreach ( $gallery_blocks as $block ) {

			if ( null != $block->image->id ) {

				$images[] = array(
					'id'          => $block->image->id,
					'description' => ( null != $block->caption->description->text ) ? $block->caption->description->text : '',
					'title'       => ( null != $block->caption->title->text ) ? $block->caption->title->text : '',
					'alt'         => ( null != $block->image->alt ) ? $block->image->alt : '',
					'link'        => ( null != $block->click->link ) ? $block->click->link : '',
					'target'      => ( null != $block->click->target && '_blank' == $block->click->target ) ? 1 : 0,
					'width'       => ( null != $block->geometry->colspan ) ? 3 * absint( $block->geometry->colspan ) : 1,
					'height'      => ( null != $block->geometry->rowspan ) ? 3 * absint( $block->geometry->rowspan ) : 1

				);
			}
		}

		// Build Modula Gallery modula-images metadata
		$modula_images = array();

		if ( is_array( $images ) && count( $images ) > 0 ) {
			// Add each image to Media Library
			foreach ( $images as $image ) {
				$image_src       = wp_get_attachment_image_src( $image['id'], 'full' );
				$modula_images[] = apply_filters( 'modula_migrate_image_data', array(
					'id'          => absint( $image['id'] ),
					'alt'         => sanitize_text_field( $image['alt'] ),
					'title'       => sanitize_text_field( $image['title'] ),
					'description' => wp_filter_post_kses( $image['description'] ),
					'halign'      => 'center',
					'valign'      => 'middle',
					'link'        => esc_url_raw( $image['link'] ),
					'target'      => absint( $image['target'] ),
					'width'       => absint( $image['width'] ),
					'height'      => absint( $image['height'] ),
					'filters'     => ''
				), $image, $gallery_data, 'photoblocks' );
			}
		}

		if ( count( $modula_images ) == 0 ) {
			// Trigger delete function if option is set to delete
			if ( isset( $_POST['clean'] ) && 'delete' == $_POST['clean'] ) {
				$this->clean_entries( $gallery_id );
			}
			$this->modula_import_result( false, esc_html__( 'No images found in gallery. Skipping gallery...', 'modula-photoblocks-migrator' ), false );
		}

		// Get Modula Gallery defaults, used to set modula-settings metadata
		$default_modula_settings = Modula_CPT_Fields_Helper::get_defaults();

		$modula_settings = apply_filters( 'modula_migrate_gallery_data', array(
			'type' => 'custom-grid',
		), $gallery_data, 'photoblocks' );

		$modula_settings = wp_parse_args( $modula_settings, $default_modula_settings );

		// Create Modula CPT
		$modula_gallery_id = wp_insert_post( array(
			'post_type'   => 'modula-gallery',
			'post_status' => 'publish',
			'post_title'  => sanitize_text_field( $gallery_data->name ),
		) );


		// Attach meta modula-settings to Modula CPT
		update_post_meta( $modula_gallery_id, 'modula-settings', $modula_settings );

		// Attach meta modula-images to Modula CPT
		update_post_meta( $modula_gallery_id, 'modula-images', $modula_images );

		$ftg_shortcode    = '[photoblocks id=' . $gallery_id . ']';
		$modula_shortcode = '[modula id="' . $modula_gallery_id . '"]';

		// Replace Gallery PhotoBlocks shortcode with Modula Shortcode in Posts, Pages and CPTs
		$sql = $wpdb->prepare( "UPDATE " . $wpdb->prefix . "posts SET post_content = REPLACE(post_content, '%s', '%s')",
			$ftg_shortcode, $modula_shortcode );
		$wpdb->query( $sql );

		if ( isset( $_POST['clean'] ) && 'delete' == sanitize_text_field( wp_unslash( $_POST['clean'] ) ) ) {
			$this->clean_entries( $gallery_id );
		}

		$this->modula_import_result( true, wp_kses_post( '<i class="imported-check dashicons dashicons-yes"></i>' ), $modula_gallery_id );
	}

	/**
	 * Update imported galleries
	 *
	 *
	 * @since 1.0.0
	 */
	public function update_imported() {

		check_ajax_referer( 'modula-importer', 'nonce' );

		if ( ! isset( $_POST['galleries'] ) ) {
			wp_send_json_error();
		}
		$galleries = array_map( 'absint', wp_unslash( $_POST['galleries'] ) );

		$importer_settings = get_option( 'modula_importer' );

		if ( ! is_array( $importer_settings ) ) {
			$importer_settings = array();
		}

		if ( ! isset( $importer_settings['galleries']['photoblocks'] ) ) {
			$importer_settings['galleries']['photoblocks'] = array();
		}

		if ( is_array( $galleries ) && count( $galleries ) > 0 ) {
			foreach ( $galleries as $key => $value ) {
				$importer_settings['galleries']['photoblocks'][ absint( $key ) ] = absint( $value );
			}
		}
		// Remember that this gallery has been imported
		update_option( 'modula_importer', $importer_settings );

		// Set url for migration complete
		$url = admin_url( 'edit.php?post_type=modula-gallery&page=modula&modula-tab=importer&migration=complete' );

		if ( isset( $_POST['clean'] ) && 'delete' ==  sanitize_text_field( wp_unslash( $_POST['clean'] ) ) ) {
			// Set url for migration and cleaning complete
			$url = admin_url( 'edit.php?post_type=modula-gallery&page=modula&modula-tab=importer&migration=complete&delete=complete' );
		}

		echo esc_url( $url );
		wp_die();
	}


	/**
	 * Returns result
	 *
	 * @param      $success
	 * @param      $message
	 * @param bool $modula_gallery_id
	 *
	 * @since 1.0.0
	 */
	public function modula_import_result( $success, $message, $modula_gallery_id = false ) {

		echo json_encode( array(
			'success'           => (bool) $success,
			'message'           => (string) $message,
			'modula_gallery_id' => $modula_gallery_id
		) );
		die;
	}

	/**
	 * Delete old entries from database
	 *
	 * @param $gallery_id
	 *
	 * @since 1.0.0
	 */
	public function clean_entries( $gallery_id ) {

		global $wpdb;

		$sql = $wpdb->prepare( "DELETE FROM  " . $wpdb->prefix . "photoblocks WHERE id = $gallery_id" );
		$wpdb->query( $sql );
	}

	/**
	 * Add Photoblocks source to Modula gallery sources
	 *
	 * @param $sources
	 *
	 * @return mixed
	 * @since 1.0.0
	 */
	public function add_source( $sources ) {

		global $wpdb;

		if ( $wpdb->get_var( "SHOW TABLES LIKE '" . $wpdb->prefix . "photoblocks'" ) ) {
			$photoblolcks = $wpdb->get_results( " SELECT COUNT(id) FROM " . $wpdb->prefix . "photoblocks" );
		}

		$photoblocks_return = ( null != $photoblolcks ) ? get_object_vars( $photoblolcks[0] ) : false;

		if ( $photoblolcks && null != $photoblolcks && ! empty( $photoblolcks ) && $photoblocks_return && '0' != $photoblocks_return['COUNT(id)'] ) {
			$sources['photoblocks'] = 'Photoblocks Gallery';
		}

		return $sources;
	}


	/**
	 * Add our source galleries
	 *
	 * @param $galleries
	 *
	 * @return false|mixed
	 * @since 1.0.0
	 */
	public function add_source_galleries( $galleries ) {

		return $this->get_galleries();
	}

	/**
	 * Return Gallery info
	 *
	 * @param $g_gallery
	 * @param $gallery
	 * @param $import_settings
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public function add_gallery_info( $g_gallery, $gallery, $import_settings ) {

		$modula_gallery = get_post_type( $import_settings['galleries']['photoblocks'][ $gallery->id ] );
		$imported       = false;

		if ( isset( $import_settings['galleries']['photoblocks'] ) && 'modula-gallery' === $modula_gallery ) {
			$imported = true;
		}

		return array(
			'id'       => $gallery->id,
			'imported' => $imported,
			'title'    => '<a href="' . admin_url( '/post.php?post=' . $gallery->id . '&action=edit' ) . '" target="_blank">' . esc_html( $gallery->name ) . '</a>',
			'count'    => $this->images_count( $gallery->id )
		);
	}

	/**
	 * Return Photoblocks images
	 *
	 * @param $images
	 * @param $data
	 *
	 * @return mixed
	 *
	 * @since 1.0.0
	 */
	public function migrator_images( $images, $data ) {

		global $wpdb;

		$sql     = $wpdb->prepare( "SELECT * FROM " . $wpdb->prefix . "photoblocks
    						WHERE id = %d LIMIT 1",
			$data );
		$gallery = $wpdb->get_row( $sql );

		return json_decode( $gallery->blocks, true );

	}

}