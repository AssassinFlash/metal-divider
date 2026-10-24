<?php
/**
 * Field group `inquiry_record`: the inquiry record's own fields.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

/**
 * The group ACF registers on the md_inquiry post type.
 *
 * Every field is a plain carrier: the values are written by the submission
 * handler, and the group exists so the back office reads them as a record
 * (spec 07 §7, branch research/spec-inquiry-chain). The pattern carries a
 * snapshot — name, code, slug — beside the product link, because a product
 * deleted later must not take the record's readability with it.
 *
 * @return array<string, mixed>
 */
return array(
	'key'             => 'group_md_inquiry_record',
	'title'           => '询盘记录 Inquiry record',
	'fields'          => array(
		array(
			'key'          => 'field_md_inquiry_submitted_time',
			'label'        => '提交时间 Submitted (UTC)',
			'name'         => 'submitted_time',
			'type'         => 'text',
			'instructions' => '提交时刻,UTC,格式 Y-m-d H:i:s。',
			'readonly'     => true,
		),
		array(
			'key'   => 'field_md_inquiry_customer_name',
			'label' => '姓名 Name',
			'name'  => 'customer_name',
			'type'  => 'text',
		),
		array(
			'key'   => 'field_md_inquiry_customer_email',
			'label' => '邮箱 Email',
			'name'  => 'customer_email',
			'type'  => 'email',
		),
		array(
			'key'   => 'field_md_inquiry_customer_company',
			'label' => '公司 Company',
			'name'  => 'customer_company',
			'type'  => 'text',
		),
		array(
			'key'   => 'field_md_inquiry_message',
			'label' => '留言 Message',
			'name'  => 'message',
			'type'  => 'textarea',
			'rows'  => 6,
		),
		array(
			'key'           => 'field_md_inquiry_pattern_product',
			'label'         => '图案 Pattern(产品链接)',
			'name'          => 'pattern_product',
			'type'          => 'post_object',
			'post_type'     => array( 'product' ),
			'return_format' => 'id',
			'instructions'  => '询盘发起时的图案产品;产品删除后链接失效,快照字段仍在。',
		),
		array(
			'key'   => 'field_md_inquiry_pattern_name',
			'label' => '图案名 Pattern name(快照)',
			'name'  => 'pattern_name',
			'type'  => 'text',
		),
		array(
			'key'   => 'field_md_inquiry_pattern_code',
			'label' => '图案编码 Pattern code(快照)',
			'name'  => 'pattern_code',
			'type'  => 'text',
		),
		array(
			'key'   => 'field_md_inquiry_pattern_slug',
			'label' => '图案 slug Pattern slug(快照)',
			'name'  => 'pattern_slug',
			'type'  => 'text',
		),
		array(
			'key'   => 'field_md_inquiry_source_page',
			'label' => '来源页 Source page',
			'name'  => 'source_page',
			'type'  => 'text',
		),
		array(
			'key'   => 'field_md_inquiry_source_module',
			'label' => '来源模块 Source module',
			'name'  => 'source_module',
			'type'  => 'text',
		),
		array(
			'key'   => 'field_md_inquiry_source_button',
			'label' => '来源按钮 Source button',
			'name'  => 'source_button',
			'type'  => 'text',
		),
		array(
			'key'   => 'field_md_inquiry_ip_address',
			'label' => 'IP 地址 IP address',
			'name'  => 'ip_address',
			'type'  => 'text',
		),
		array(
			'key'      => 'field_md_inquiry_user_agent',
			'label'    => 'User Agent 摘要',
			'name'     => 'user_agent',
			'type'     => 'textarea',
			'rows'     => 2,
			'readonly' => true,
		),
		array(
			'key'     => 'field_md_inquiry_turnstile_status',
			'label'   => 'Turnstile 验证状态',
			'name'    => 'turnstile_status',
			'type'    => 'select',
			'choices' => array(
				'verified'       => 'verified(已验证)',
				'not_configured' => 'not configured(未配置,标记)',
			),
		),
	),
	'location'        => array(
		array(
			array(
				'param'    => 'post_type',
				'operator' => '==',
				'value'    => 'md_inquiry',
			),
		),
	),
	'position'        => 'normal',
	'placement'       => 'high',
	'show_in_graphql' => false,
	'active'          => true,
	'show_in_rest'    => 0,
);
