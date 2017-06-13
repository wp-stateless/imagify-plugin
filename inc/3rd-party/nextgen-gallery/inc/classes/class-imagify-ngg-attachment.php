<?php
defined( 'ABSPATH' ) || die( 'Cheatin\' uh?' );

/**
 * Imagify NextGen Gallery attachment class.
 *
 * @since 1.5
 * @author Jonathan Buttigieg
 */
class Imagify_NGG_Attachment extends Imagify_Abstract_Attachment {

	/**
	 * Class version.
	 *
	 * @var string
	 */
	const VERSION = '1.0.1';

	/**
	 * The image object.
	 *
	 * @since 1.5
	 *
	 * @var    object A nggImage object.
	 * @access public
	 */
	public $image;

	/**
	 * The image id.
	 *
	 * @since 1.5
	 *
	 * @var    int
	 * @access public
	 */
	public $id;

	/**
	 * The attachment SQL data row.
	 *
	 * @since 1.5
	 *
	 * @var    array
	 * @access public
	 */
	public $row;

	/**
	 * The constructor.
	 *
	 * @since 1.5
	 * @author Jonathan Buttigieg
	 *
	 * @param int|object $id An image attachment ID.
	 * @return void
	 */
	public function __construct( $id ) {
		if ( is_object( $id ) ) {
			$this->image = $id;
			$this->id    = $id->pid;
		} else {
			$this->image = nggdb::find_image( (int) $id );
			$this->id    = $this->image->pid;
		}

		$this->row = $this->get_row();

		// Load nggAdmin classe.
		$ngg_admin_functions_path = WP_PLUGIN_DIR . '/' . NGGFOLDER . '/products/photocrati_nextgen/modules/ngglegacy/admin/functions.php';

		if ( ! class_exists( 'nggAdmin' ) && file_exists( $ngg_admin_functions_path ) ) {
			require_once( $ngg_admin_functions_path );
		}
	}

	/**
	 * Get the attachment backup filepath.
	 *
	 * @since 1.5
	 * @author Jonathan Buttigieg
	 *
	 * @access public
	 * @return string|bool The path. False on failure.
	 */
	public function get_backup_path() {
		$backup_path = $this->get_original_path() . '_backup';

		if ( file_exists( $backup_path ) ) {
			return $backup_path;
		}

		return false;
	}

	/**
	 * Get the attachment optimization data.
	 *
	 * @since 1.5
	 * @author Jonathan Buttigieg
	 *
	 * @access public
	 * @return array|bool
	 */
	public function get_data() {
		$row = $this->row ? $this->row : $this->get_row();
		return isset( $row['data'] ) ? maybe_unserialize( $row['data'] ) : false;
	}

	/**
	 * Get the attachment optimization level.
	 *
	 * @since 1.5
	 * @author Jonathan Buttigieg
	 *
	 * @access public
	 * @return int|bool
	 */
	public function get_optimization_level() {
		$row = $this->row ? $this->row : $this->get_row();
		return isset( $row['optimization_level'] ) ? (int) $row['optimization_level'] : false;
	}

	/**
	 * Get the attachment SQL data row.
	 *
	 * @since 1.5
	 * @author Jonathan Buttigieg
	 *
	 * @access public
	 * @return array
	 */
	public function get_row() {
		return imagify_ngg_db()->get( $this->id );
	}

	/**
	 * Get the attachment optimization status (success or error).
	 *
	 * @since 1.5
	 * @author Jonathan Buttigieg
	 *
	 * @access public
	 * @return string|bool
	 */
	public function get_status() {
		$row = $this->row ? $this->row : $this->get_row();
		return isset( $row['status'] ) ? $row['status'] : false;
	}

	/**
	 * Get the original attachment path.
	 *
	 * @since 1.5
	 * @author Jonathan Buttigieg
	 *
	 * @access public
	 * @return string
	 */
	public function get_original_path() {
		return $this->image->imagePath;
	}

	/**
	 * Get the original attachment URL.
	 *
	 * @since 1.5
	 * @author Jonathan Buttigieg
	 *
	 * @access public
	 * @return string
	 */
	public function get_original_url() {
		return $this->image->imageURL;
	}

	/**
	 * Update the metadata size of the attachment.
	 *
	 * @since 1.5
	 *
	 * @access public
	 * @return void
	 */
	public function update_metadata_size() {
		$size = @getimagesize( $this->get_original_path() );

		if ( isset( $size[0], $size[1] ) ) {
			$metadata                   = $this->image->meta_data;
			$metadata['width']          = $size[0];
			$metadata['height']         = $size[1];
			$metadata['full']['width']  = $size[0];
			$metadata['full']['height'] = $size[1];

			nggdb::update_image_meta( $this->id , $metadata );
		}
	}

	/**
	 * Fills statistics data with values from $data array.
	 *
	 * @since 1.5
	 * @since 1.6.6 Not static anymore.
	 * @author Jonathan Buttigieg
	 * @access public
	 *
	 * @param  array  $data      The statistics data.
	 * @param  object $response  The API response.
	 * @param  int    $id        The attachment ID.
	 * @param  int    $url       The attachment URL.
	 * @param  string $size      The attachment size key.
	 * @return bool|array        False if the original size has an error or an array contains the data for other result.
	 */
	public function fill_data( $data, $response, $id, $url, $size = 'full' ) {
		$data['sizes'] = ! empty( $data['sizes'] ) && is_array( $data['sizes'] ) ? $data['sizes'] : array();

		if ( is_wp_error( $response ) ) {
			$error        = $response->get_error_message();
			$error_status = 'error';

			$data['sizes'][ $size ] = array(
				'success' => false,
				'error'   => $error,
			);

			// Update the error status for the original size.
			if ( 'full' === $size ) {
				if ( false !== strpos( $error, 'This image is already compressed' ) ) {
					$error_status = 'already_optimized';
				}

				imagify_ngg_db()->update( $id, array(
					'pid'    => $id,
					'status' => $error_status,
					'data'   => serialize( $data ),
				) );

				return false;
			}
		} else {
			$response = (object) array_merge( array(
				'original_size' => 0,
				'new_size'      => 0,
				'percent'       => 0,
			), (array) $response );

			$data['sizes'][ $size ] = array(
				'success'        => true,
				'file_url'       => $url,
				'original_size'  => $response->original_size,
				'optimized_size' => $response->new_size,
				'percent'        => $response->percent,
			);

			$data['stats']['original_size']  += $response->original_size;
			$data['stats']['optimized_size'] += $response->new_size;
			$data['stats']['percent']         = round( ( ( $data['stats']['original_size'] - $data['stats']['optimized_size'] ) / $data['stats']['original_size'] ) * 100, 2 );
		} // End if().

		return $data;
	}

	/**
	 * Optimize all sizes with Imagify.
	 *
	 * @since 1.5
	 * @author Jonathan Buttigieg
	 *
	 * @access public
	 * @param  int   $optimization_level  The optimization level (2=ultra, 1=aggressive, 0=normal).
	 * @param  array $metadata            The attachment meta data (not used here).
	 * @return array $data                The optimization data.
	 */
	public function optimize( $optimization_level = null, $metadata = array() ) {
		$optimization_level = is_null( $optimization_level ) ? (int) get_imagify_option( 'optimization_level', 1 ) : (int) $optimization_level;

		$id   = $this->id;
		$data = array(
			'stats' => array(
				'original_size'  => 0,
				'optimized_size' => 0,
				'percent'        => 0,
			),
		);

		// To avoid issue with "original_size" at 0 in "_imagify_data".
		if ( 0 === $this->get_stats_data( 'original_size' ) ) {
			delete_post_meta( $id, '_imagify_data' );
			delete_post_meta( $id, '_imagify_status' );
			delete_post_meta( $id, '_imagify_optimization_level' );
		}

		// Get file path & URL for original image.
		$attachment_path          = $this->get_original_path();
		$attachment_url           = $this->get_original_url();
		$attachment_original_size = $this->get_original_size( false );

		// Check if the full size is already optimized.
		if ( $this->is_optimized() && ( $this->get_optimization_level() === $optimization_level ) ) {
			return;
		}

		/**
		 * Fires before optimizing an attachment.
		 *
		 * @since 1.5
		 *
		 * @param int $id The attachment ID
		*/
		do_action( 'before_imagify_ngg_optimize_attachment', $id );

		set_transient( 'imagify-ngg-async-in-progress-' . $id, true, 10 * MINUTE_IN_SECONDS );

		// Get the resize values for the original size.
		$resized         = false;
		$do_resize       = get_imagify_option( 'resize_larger', false );
		$resize_width    = get_imagify_option( 'resize_larger_w' );
		$attachment_size = @getimagesize( $attachment_path );

		if ( $do_resize && isset( $attachment_size[0] ) && $resize_width < $attachment_size[0] ) {
			$resized_attachment_path = $this->resize( $attachment_path, $attachment_size, $resize_width );

			if ( ! is_wp_error( $resized_attachment_path ) ) {
				$filesystem = imagify_get_filesystem();

				if ( get_imagify_option( 'backup', false ) ) {
					$backup_path      = get_imagify_attachment_backup_path( $attachment_path );
					$backup_path_info = pathinfo( $backup_path );

					wp_mkdir_p( $backup_path_info['dirname'] );

					// TO DO - check and send a error message if the backup can't be created.
					$filesystem->copy( $attachment_path, $backup_path, true );
					imagify_chmod_file( $backup_path );
				}

				$filesystem->move( $resized_attachment_path, $attachment_path, true );
				imagify_chmod_file( $attachment_path );

				// If resized temp file still exists, delete it.
				if ( $filesystem->exists( $resized_attachment_path ) ) {
					$filesystem->delete( $resized_attachment_path );
				}

				$resized = true;
			}
		}

		// Optimize the original size.
		$response = do_imagify( $attachment_path, array(
			'optimization_level' => $optimization_level,
			'context'            => 'NGG',
			'resized'            => $resized,
			'original_size'      => $attachment_original_size,
		) );
		$data     = $this->fill_data( $data, $response, $id, $attachment_url );

		if ( ! $data ) {
			delete_transient( 'imagify-ngg-async-in-progress-' . $id );
			return;
		}

		// Save the optimization level.
		imagify_ngg_db()->update( $id, array(
			'pid'                => $id,
			'optimization_level' => $optimization_level,
		) );

		// If we resized the original with success, we have to update the attachment metadata.
		// If not, WordPress keeps the old attachment size.
		if ( $do_resize && $resized ) {
			$this->update_metadata_size();
		}

		// Optimize thumbnails.
		$data = $this->optimize_thumbnails( $optimization_level, $data );

		// Save the status to success.
		imagify_ngg_db()->update( $id, array(
			'pid'    => $id,
			'status' => 'success',
		) );

		/**
		 * Fires after optimizing an attachment.
		 *
		 * @since 1.5
		 *
		 * @param int    $id    The attachment ID.
		 * @param array  $data  The optimization data.
		*/
		do_action( 'after_imagify_ngg_optimize_attachment', $id, $data );

		delete_transient( 'imagify-ngg-async-in-progress-' . $id );

		return $data;
	}

	/**
	 * Optimize all thumbnails of an image.
	 *
	 * @since 1.5
	 * @author Jonathan Buttigieg
	 *
	 * @access public
	 * @param  int   $optimization_level  The optimization level (2=ultra, 1=aggressive, 0=normal).
	 * @param  array $data                The optimization data.
	 * @return array $data                The optimization data.
	 */
	public function optimize_thumbnails( $optimization_level = null, $data = array() ) {
		$id      = $this->id;
		$storage = C_Gallery_Storage::get_instance();
		$sizes   = $storage->get_image_sizes();
		$data    = $data ? $data : $this->get_data();

		// Stop if the original image has an error.
		if ( $this->has_error() ) {
			return $data;
		}

		$optimization_level = is_null( $optimization_level ) ? (int) get_imagify_option( 'optimization_level', 1 ) : (int) $optimization_level;

		/**
		 * Fires before optimizing all thumbnails.
		 *
		 * @since 1.5
		 *
		 * @param int $id The image ID.
		*/
		do_action( 'before_imagify_ngg_optimize_thumbnails', $id );

		if ( $sizes ) {
			foreach ( $sizes as $size_key ) {
				if ( 'full' === $size_key || isset( $data['sizes'][ $size_key ]['success'] ) ) {
					continue;
				}

				$thumbnail_path = $storage->get_image_abspath( $this->image, $size_key );
				$thumbnail_url  = $storage->get_image_url( $this->image, $size_key );

				// Optimize the thumbnail size.
				$response = do_imagify( $thumbnail_path, array(
					'backup'             => false,
					'optimization_level' => $optimization_level,
					'context'            => 'wp',
				) );
				$data     = $this->fill_data( $data, $response, $id, $thumbnail_url, $size_key );

				/**
				* Filter the optimization data of a specific thumbnail.
				*
				* @since 1.5
				*
				* @param  array  $data            The statistics data.
				* @param  object $response        The API response.
				* @param  int    $id              The attachment ID.
				* @param  string $thumbnail_path  The attachment path.
				* @param  string $thumbnail_url   The attachment URL.
				* @param  string $size_key        The attachment size key.
				* @param  bool   $is_aggressive   The optimization level.
				* @return array  $data            The new optimization data.
				*/
				$data = apply_filters( 'imagify_fill_ngg_thumbnail_data', $data, $response, $id, $thumbnail_path, $thumbnail_url, $size_key, $optimization_level );
			}

			imagify_ngg_db()->update( $id, array(
				'pid'  => $id,
				'data' => serialize( $data ),
			) );
		} // End if().

		/**
		 * Fires after optimizing all thumbnails.
		 *
		 * @since 1.5
		 *
		 * @param int   $id    The image ID.
		 * @param array $data  The optimization data.
		*/
		do_action( 'after_imagify_ngg_optimize_thumbnails', $id, $data );

		return $data;
	}

	/**
	 * Process an attachment restoration from the backup file.
	 *
	 * @since 1.5
	 * @author Jonathan Buttigieg
	 *
	 * @access public
	 * @return void
	 */
	public function restore() {
		// Stop the process if there is no backup file to restore.
		if ( ! $this->has_backup() ) {
			return;
		}

		$id              = $this->id;
		$backup_path     = $this->get_backup_path();
		$attachment_path = $this->get_original_path();

		/**
		 * Fires before restoring an attachment.
		 *
		 * @since 1.5
		 *
		 * @param int $id The attachment ID.
		*/
		do_action( 'before_imagify_ngg_restore_attachment', $id );

		// Create the original image from the backup.
		C_Gallery_Storage::get_instance()->recover_image( $id );

		// Remove old optimization data.
		imagify_ngg_db()->delete( $id );

		/**
		 * Fires after restoring an attachment.
		 *
		 * @since 1.5
		 *
		 * @param int $id The attachment ID.
		*/
		do_action( 'after_imagify_ngg_restore_attachment', $id );
	}
}
