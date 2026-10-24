<?php
/**
 * Field group D `series_meta`: the series-level facts, carried on the
 * product_cat term (spec 06 §4: four fields on the taxonomy; `family` folded
 * into the term's display name, the five-material list dropped as bad data —
 * owner ruling 2026-10-24 revision 6).
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
	'key'          => 'group_md_series_meta',
	'title'        => '系列属性 series_meta',
	'fields'       => array(
		array(
			'key'     => 'field_md_accent_color',
			'label'   => '强调色 Accent color',
			'name'    => 'accent_color',
			'type'    => 'color_picker',
			'default' => '#c9a962',
		),
		array(
			'key'     => 'field_md_difficulty_level',
			'label'   => '难度 Difficulty',
			'name'    => 'difficulty_level',
			'type'    => 'radio',
			'choices' => array(
				'simple'   => 'Simple',
				'moderate' => 'Moderate',
				'complex'  => 'Complex',
			),
		),
		array(
			'key'   => 'field_md_typical_applications',
			'label' => '典型应用 Typical applications',
			'name'  => 'typical_applications',
			'type'  => 'textarea',
			'rows'  => 4,
		),
		array(
			'key'   => 'field_md_bottom_content',
			'label' => '页底内容 Bottom content',
			'name'  => 'bottom_content',
			'type'  => 'wysiwyg',
		),
	),
	'location'     => array(
		array(
			array(
				'param'    => 'taxonomy',
				'operator' => '==',
				'value'    => 'product_cat',
			),
		),
	),
	'position'     => 'normal',
	'active'       => true,
	'show_in_rest' => 1,
);
