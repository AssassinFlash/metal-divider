<?php
/**
 * Field group A `pattern_spec`: the pattern's specification facts, the data
 * source of the PDP spec bar (spec 06 §2.2, branch research/spec-data-model:
 * six of the seventeen retained metafields; position is the product edit
 * sidebar, as that spec fixes).
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
	'key'          => 'group_md_pattern_spec',
	'title'        => '图案规格 pattern_spec',
	'fields'       => array(
		array(
			'key'          => 'field_md_open_area',
			'label'        => '镂空率 Open area',
			'name'         => 'open_area',
			'type'         => 'number',
			'instructions' => '镂空率真值。16 个 "On request" 图案留空(迁移不编造数据)。',
			'min'          => 0,
			'max'          => 100,
			'step'         => 0.01,
			'append'       => '%',
			'default'      => '',
		),
		array(
			'key'     => 'field_md_visual_density',
			'label'   => '视觉密度 Visual density',
			'name'    => 'visual_density',
			'type'    => 'radio',
			'choices' => array(
				'open'     => 'Open',
				'balanced' => 'Balanced',
				'dense'    => 'Dense',
				'custom'   => 'Custom',
			),
		),
		array(
			'key'     => 'field_md_repeat_behavior',
			'label'   => '循环行为 Repeat behavior',
			'name'    => 'repeat_behavior',
			'type'    => 'radio',
			'choices' => array(
				'repeating' => 'Repeating',
				'flowing'   => 'Flowing',
			),
		),
		array(
			'key'     => 'field_md_motifs',
			'label'   => '主题词 Motifs',
			'name'    => 'motifs',
			'type'    => 'checkbox',
			'choices' => array(
				'abstract'      => 'Abstract',
				'organic'       => 'Organic',
				'geometric'     => 'Geometric',
				'moroccan'      => 'Moroccan',
				'arabic'        => 'Arabic',
				'landscape'     => 'Landscape',
				'leaf'          => 'Leaf',
				'vine'          => 'Vine',
				'floral'        => 'Floral',
				'asian'         => 'Asian',
				'vintage-retro' => 'Vintage / Retro',
				'garden'        => 'Garden',
				'bamboo'        => 'Bamboo',
				'lattice'       => 'Lattice',
				'perforated'    => 'Perforated',
			),
		),
		array(
			'key'     => 'field_md_best_fit',
			'label'   => '最适用途 Best fit',
			'name'    => 'best_fit',
			'type'    => 'radio',
			'choices' => array(
				'architectural-screening' => 'Architectural screening',
				'decorative-screening'    => 'Decorative screening',
			),
		),
		array(
			'key'     => 'field_md_cad_status',
			'label'   => 'CAD 状态 CAD status',
			'name'    => 'cad_status',
			'type'    => 'radio',
			'choices' => array(
				'gated'   => 'Gated',
				'missing' => 'Missing',
			),
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
	'position'     => 'side',
	'menu_order'   => 0,
	'active'       => true,
	'show_in_rest' => 1,
);
