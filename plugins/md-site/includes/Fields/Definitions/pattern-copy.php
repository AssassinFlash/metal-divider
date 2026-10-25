<?php
/**
 * Field group B `pattern_copy`: the pattern's own copy (spec 06 §2.2: two of
 * the seventeen retained metafields; 43/146 products carry values today, the
 * rest the content tickets fill).
 *
 * `notable_effect` is the third: the old site's PDP rendered it as the
 * design-intent block's second paragraph (146/146 products carry it, and it
 * is byte-identical to the old `design_intent.body[1]`), so it is retained
 * product data, not one of the seventeen dropped metafields.
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
		array(
			'key'          => 'field_md_notable_effect',
			'label'        => '显著效果 Notable effect',
			'name'         => 'notable_effect',
			'type'         => 'text',
			'instructions' => 'PDP 设计意图第二段的短句(旧站 metafield notable_effect,146/146 有值)。留空则第二段不渲染。',
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
