<?php
/**
 * Field group B `pattern_copy`: the pattern's own copy (spec 06 §2.2: two of
 * the seventeen retained metafields; 43/146 products carry values today, the
 * rest the content tickets fill).
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

/**
 * The group ACF registers.
 *
 * @return array<string, mixed>
 */
return array(
	'key'          => 'group_md_pattern_copy',
	'title'        => '图案文案 pattern_copy',
	'fields'       => array(
		array(
			'key'   => 'field_md_summary',
			'label' => '摘要 Summary',
			'name'  => 'summary',
			'type'  => 'textarea',
			'rows'  => 4,
		),
		array(
			'key'   => 'field_md_inspiration',
			'label' => '灵感 Inspiration',
			'name'  => 'inspiration',
			'type'  => 'textarea',
			'rows'  => 4,
		),
	),
	'location'     => array(
		array(
			array(
				'param'    => 'post_type',
				'operator' => '==',
				'value'    => 'product',
			),
		),
	),
	'position'     => 'normal',
	'menu_order'   => 1,
	'active'       => true,
	'show_in_rest' => 1,
);
