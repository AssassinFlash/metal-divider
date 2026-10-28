#!/usr/bin/env bash
# Reattach the orphaned pattern images to their products.
#
# Every pattern's processed images are in the library, but 1063 of 1319
# attachments carry `post_parent = 0` and are absent from both
# `_thumbnail_id` and `_product_image_gallery`: the import created the
# attachments and never linked them. Measured on production 2026-10-29:
# 277 images attached across 146 published patterns, 786 recoverable.
#
# The result the visitor sees is an image set a quarter of its real size —
# a product with ten processed images shows one — and, on the patterns
# whose only attached image is a scene, an empty hero plate.
#
# Ordering is by the `--NN--` sequence in the filename, which is the order
# the old site's gallery used. The sequence is not a size ranking: on
# white-sands `--01--` is 400x800 (a portrait `finish preview`, exactly
# what the plate wants) while `--02--` is the 2048-wide accent.
#
# Usage:
#   MD_TARGET=prod bash tools/media/reattach-orphans.sh            # dry run
#   MD_TARGET=prod bash tools/media/reattach-orphans.sh --write    # apply
set -uo pipefail

WRITE=0
[ "${1:-}" = "--write" ] && WRITE=1

PHP_CODE=$(cat <<'PHP'
$q = new WP_Query( array(
	'post_type'      => 'product',
	'post_status'    => 'publish',
	'posts_per_page' => -1,
	'fields'         => 'ids',
) );

$changed = 0;
$added   = 0;
$lines   = array();

foreach ( $q->posts as $pid ) {
	$slug = get_post( $pid )->post_name;

	$thumb = (int) get_post_thumbnail_id( $pid );
	$gallery = array_map( 'intval', (array) get_post_meta( $pid, '_product_image_gallery', true ) );
	$gallery = array_values( array_filter( $gallery ) );

	// Every attachment whose filename starts with this product's slug.
	$cand = get_posts( array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		's'              => $slug,
	) );

	$rows = array();
	foreach ( $cand as $aid ) {
		$file = basename( (string) get_attached_file( $aid ) );
		if ( 0 !== strpos( $file, $slug ) ) {
			continue;
		}
		if ( ! preg_match( '/--(\d+)--/', $file, $m ) ) {
			// Also accept the `-NN-` shape the library actually uses.
			if ( ! preg_match( '/-(\d+)-/', $file, $m ) ) {
				continue;
			}
		}
		$rows[] = array( 'id' => (int) $aid, 'seq' => (int) $m[1], 'file' => $file );
	}
	usort( $rows, function ( $a, $b ) {
		return $a['seq'] <=> $b['seq'] ?: strcmp( $a['file'], $b['file'] );
	} );

	$have  = array_merge( array( $thumb ), $gallery );
	$have  = array_values( array_unique( array_filter( $have ) ) );
	$want  = array();
	$widths = array();
	foreach ( $rows as $r ) {
		if ( in_array( $r['id'], $have, true ) ) {
			continue;
		}
		$meta = wp_get_attachment_metadata( $r['id'] );
		// One file per sequence number: the ladder's other widths share it.
		if ( isset( $widths[ $r['seq'] ] ) ) {
			continue;
		}
		$widths[ $r['seq'] ] = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
		$want[] = $r['id'];
	}

	// Keep the sequence order, with what is already attached first so the
	// current hero and first gallery slot do not move.
	$new_gallery = array_values( array_unique( array_merge( $gallery, $want ) ) );

	if ( $new_gallery === $gallery ) {
		continue;
	}

	$changed++;
	$added += count( $new_gallery ) - count( $gallery );
	$lines[] = sprintf(
		'%s: gallery %d -> %d (+%d) [%s]',
		$slug,
		count( $gallery ),
		count( $new_gallery ),
		count( $new_gallery ) - count( $gallery ),
		implode( ',', $want )
	);

	update_post_meta( $pid, '_product_image_gallery', implode( ',', $new_gallery ) );

	// The attachment belongs to the product it illustrates; the plate
	// resolution and the media screens both read post_parent.
	foreach ( $want as $aid ) {
		wp_update_post( array( 'ID' => $aid, 'post_parent' => $pid ) );
	}
}

echo "products_changed=$changed images_added=$added\n";
foreach ( array_slice( $lines, 0, 15 ) as $l ) {
	echo "  $l\n";
}
PHP
)

run() {
	ssh -o BatchMode=yes -o ConnectTimeout=30 hostinger-metal-divider \
		"cd /home/u388076051/domains/metal-divider.com/public_html && /usr/local/bin/wp eval $(printf '%q' "$1")" 2>&1 | tail -20
}

if [ "$WRITE" -eq 1 ]; then
	echo "=== APPLY ==="
	run "$PHP_CODE"
else
	echo "=== DRY RUN (pass --write to apply) ==="
	# The same walk with the write removed, so the counts are the real ones.
	run "$(printf '%s' "$PHP_CODE" | sed -e 's/^\tupdate_post_meta( \$pid.*$/\t\/\/ dry run: no write/' -e 's/^\tforeach ( \$want as \$aid ) {$/\tforeach ( array() as $aid ) {/')"
fi
