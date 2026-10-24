<?php
/**
 * Field group C `pattern_provenance`: the internal provenance records (spec 06
 * §2.2: five of the seventeen retained metafields; back-office only —
 * show_in_rest is 0, so the fields never leave through the REST API or any
 * front-end template, that spec §10.7's isolation rule).
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
	'key'          => 'group_md_pattern_provenance',
	'title'        => '来源与许可 pattern_provenance',
	'fields'       => array(
		array(
			'key'     => 'field_md_source_site',
			'label'   => '来源站 Source site',
			'name'    => 'source_site',
			'type'    => 'radio',
			'choices' => array(
				'parasoleil' => 'Parasoleil',
				'dsd'        => 'DSD',
			),
		),
		array(
			'key'   => 'field_md_source_slug',
			'label' => '来源 slug Source slug',
			'name'  => 'source_slug',
			'type'  => 'text',
		),
		array(
			'key'   => 'field_md_source_manifest',
			'label' => '来源 manifest (JSON)',
			'name'  => 'source_manifest',
			'type'  => 'textarea',
			'rows'  => 4,
		),
		array(
			'key'   => 'field_md_openness_inferred_from',
			'label' => '镂空率推断自 Openness inferred from',
			'name'  => 'openness_inferred_from',
			'type'  => 'text',
		),
		array(
			'key'   => 'field_md_openness_confidence',
			'label' => '推断置信度 Confidence',
			'name'  => 'openness_confidence',
			'type'  => 'number',
			'min'   => 0,
			'max'   => 1,
			'step'  => 0.01,
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
	'menu_order'   => 2,
	'active'       => true,
	'show_in_rest' => 0,
);
