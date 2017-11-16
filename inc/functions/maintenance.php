<?php
defined( 'ABSPATH' ) || die( 'Cheatin\' uh?' );

/**
 * If some plugin toyed too much with our metas, we could have attachments with more than 1 "status" meta.
 * This deletes duplicates and keep the good one.
 *
 * @since  1.7
 * @author Grégory Viguier
 */
function imagify_delete_duplicate_status() {
	global $wpdb;

	/**
	 * Get posts that have more than 1 post meta '_imagify_status'.
	 * While we're at it, also get the post meta '_imagify_data' to decide wich status is the good one.
	 */
	$mime_types  = Imagify_DB::get_mime_types();
	$statuses    = Imagify_DB::get_post_statuses();
	$nodata_join = Imagify_DB::get_required_wp_metadata_join_clause();
	$results     = $wpdb->get_results( // WPCS: unprepared SQL ok.
		"
		SELECT
			p.ID,
			GROUP_CONCAT( mt1.meta_id SEPARATOR ',' ) AS status_ids,
			mt2.meta_value AS data
		FROM $wpdb->posts AS p
			$nodata_join
		INNER JOIN $wpdb->postmeta AS mt1
			ON ( p.ID = mt1.post_id AND mt1.meta_key = '_imagify_status' )
		INNER JOIN $wpdb->postmeta AS mt2
			ON ( p.ID = mt2.post_id AND mt2.meta_key = '_imagify_data' )
		WHERE
			p.post_mime_type IN ( $mime_types )
			AND p.post_type = 'attachment'
			AND p.post_status IN ( $statuses )
		GROUP BY p.ID
		HAVING COUNT( DISTINCT mt1.meta_value ) > 1
		ORDER BY p.ID ASC"
	);

	if ( ! $results ) {
		return;
	}

	foreach ( $results as $i => $result ) {
		if ( empty( $result->data ) ) {
			continue;
		}

		$data = maybe_unserialize( $result->data );

		if ( empty( $data['sizes']['full'] ) ) {
			continue;
		}

		// The full size contains the data required to select the good status.
		$data = $data['sizes']['full'];

		if ( ! empty( $data['success'] ) ) {
			$status = 'success';
		} elseif ( ! empty( $data['error'] ) && false !== strpos( $data['error'], 'This image is already compressed' ) ) {
			$status = 'already_optimized';
		} else {
			$status = 'error';
		}

		// Meta IDs (status meta).
		$status_ids = explode( ',', $result->status_ids );
		$status_ids = array_map( 'absint', $status_ids );
		// Keep the last meta ID.
		$status_id  = array_pop( $status_ids );

		// Delete the duplicates.
		if ( $status_ids ) {
			foreach ( $status_ids as $meta_id ) {
				delete_metadata_by_mid( 'post', $meta_id );
			}
		}

		// Update the last meta.
		update_metadata_by_mid( 'post', $status_id, $status );
	}
}
