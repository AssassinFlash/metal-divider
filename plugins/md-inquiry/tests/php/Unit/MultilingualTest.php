<?php
/**
 * Tests for multilingual WPML integration across Form, Pattern, ThankYou, Columns, and Multibyte truncation.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry\Tests\Unit;

use MetalDivider\Inquiry\Admin\Columns;
use MetalDivider\Inquiry\Config;
use MetalDivider\Inquiry\Form;
use MetalDivider\Inquiry\FormState;
use MetalDivider\Inquiry\Mail\Emails;
use MetalDivider\Inquiry\Mail\Queue;
use MetalDivider\Inquiry\Pattern;
use MetalDivider\Inquiry\Request;
use MetalDivider\Inquiry\Tests\TestCase;
use MetalDivider\Inquiry\ThankYou;
use MetalDivider\Inquiry\Turnstile;
use MetalDivider\Inquiry\Validator;
use WP_Mock;

/**
 * Validates multilingual integration behavior.
 *
 * @covers \MetalDivider\Inquiry\Admin\Columns
 * @covers \MetalDivider\Inquiry\Form
 * @covers \MetalDivider\Inquiry\Mail\Emails
 * @covers \MetalDivider\Inquiry\Mail\Queue
 * @covers \MetalDivider\Inquiry\Pattern
 * @covers \MetalDivider\Inquiry\Request
 * @covers \MetalDivider\Inquiry\ThankYou
 * @covers \MetalDivider\Inquiry\Validator
 */
final class MultilingualTest extends TestCase {

	/**
	 * Admin columns declare 'inquiry_locale' (Language) and format locale to uppercase.
	 */
	public function test_admin_columns_declare_and_render_language_column(): void {
		$this->mock_passthroughs();
		$columns = new Columns();

		$cols = $columns->columns(
			array(
				'cb'    => '<input>',
				'title' => 'Inquiry',
				'date'  => 'Date',
			)
		);
		$this->assertArrayHasKey( 'inquiry_locale', $cols );
		$this->assertSame( 'Language', $cols['inquiry_locale'] );

		// Cell rendering.
		WP_Mock::userFunction( 'get_post_meta' )->with( 42, 'inquiry_locale', true )->andReturn( 'zh' );
		WP_Mock::userFunction( 'get_post_meta' )->with( 43, 'inquiry_locale', true )->andReturn( 'pt-br' );
		WP_Mock::userFunction( 'get_post_meta' )->with( 44, 'inquiry_locale', true )->andReturn( '' );

		\ob_start();
		$columns->render( 'inquiry_locale', 42 );
		$out_zh = \ob_get_clean();
		$this->assertSame( 'ZH', $out_zh );

		\ob_start();
		$columns->render( 'inquiry_locale', 43 );
		$out_pt = \ob_get_clean();
		$this->assertSame( 'PT-BR', $out_pt );

		\ob_start();
		$columns->render( 'inquiry_locale', 44 );
		$out_empty = (string) \ob_get_clean();
		$this->assertStringContainsString( '—', $out_empty, 'Empty locale renders dash' );
	}

	/**
	 * Multibyte UTF-8 truncation in Validator, Request, and Queue does not split characters.
	 */
	public function test_multibyte_utf8_truncation(): void {
		$this->mock_passthroughs();
		$validator = new Validator();
		$long_zh   = \str_repeat( '建筑屏风', 40 ); // 160 multibyte characters (each 3 bytes).
		$res       = $validator->validate( array( 'name' => $long_zh ), false );
		$this->assertSame( Validator::NAME_MAX, \mb_strlen( $res['values']['name'], 'UTF-8' ) );
		$this->assertSame( \mb_substr( $long_zh, 0, Validator::NAME_MAX, 'UTF-8' ), $res['values']['name'] );

		// Request::user_agent_summary() truncation at 300 chars.
		$ua_multi                   = \str_repeat( '建筑屏风', 100 ); // 400 multi-byte characters
		$_SERVER['HTTP_USER_AGENT'] = $ua_multi;
		$ua_summary                 = Request::user_agent_summary();
		$this->assertSame( Request::UA_MAX, \mb_strlen( $ua_summary, 'UTF-8' ) );
		$this->assertSame( \mb_substr( $ua_multi, 0, Request::UA_MAX - 1, 'UTF-8' ) . '…', $ua_summary );

		// Mail\Queue::substr500() truncation at 500 chars via reflection.
		$long_msg   = \str_repeat( '金属隔断', 150 ); // 600 characters
		$ref_substr = new \ReflectionMethod( Queue::class, 'substr500' );
		$ref_substr->setAccessible( true );
		$cut_500 = $ref_substr->invoke( null, $long_msg );
		$this->assertSame( 500, \mb_strlen( $cut_500, 'UTF-8' ) );
		$this->assertSame( \mb_substr( $long_msg, 0, 499, 'UTF-8' ) . '…', $cut_500 );
	}

	/**
	 * Form renders localized data-language, hidden locale input, and translated option labels while preserving English option values.
	 */
	public function test_form_renders_localized_attributes_and_options(): void {
		$this->mock_passthroughs();
		WP_Mock::userFunction( 'wp_create_nonce' )->andReturn( 'test-nonce' );
		WP_Mock::userFunction( 'wp_nonce_field' )->zeroOrMoreTimes()->andReturn( '<input type="hidden" name="md_inquiry_nonce" value="nonce">' );
		WP_Mock::userFunction( 'get_the_ID' )->andReturn( 0 );
		WP_Mock::userFunction( 'get_post' )->andReturn( null );
		WP_Mock::userFunction( 'wp_enqueue_script' )->zeroOrMoreTimes()->andReturn( null );

		// Set WPML language to 'zh-hans' -> normalized to 'zh'.
		WP_Mock::onFilter( 'wpml_current_language' )->withAnyArgs()->reply( 'zh-hans' );

		$config = new Config(
			array(
				'MD_INQUIRY_TURNSTILE_SITEKEY' => 'turnstile-sitekey-test',
				'MD_INQUIRY_TURNSTILE_SECRET'  => 'turnstile-secret-test',
			)
		);
		$form   = new Form( $config, new Pattern(), new FormState() );

		$html = $form->render( '' );

		// Hidden locale field carries 'zh'.
		$this->assertStringContainsString( 'name="md_inquiry_locale"', $html );
		$this->assertStringContainsString( 'value="zh"', $html );

		// Turnstile has data-language="zh-cn".
		$this->assertStringContainsString( 'data-language="zh-cn"', $html );

		// Select options have English values and Chinese display labels.
		$this->assertStringContainsString( '<option value="Facade screening">建筑幕墙屏风</option>', $html );
		$this->assertStringContainsString( '<option value="Single zone">单一区域</option>', $html );
		$this->assertStringContainsString( '<option value="United States">美国</option>', $html );
		$this->assertStringContainsString( '<option value="10-50 sqm">10-50 平方米</option>', $html );
	}

	/**
	 * Form renders Turnstile data-language="pt-br" when locale is 'pt-br'.
	 */
	public function test_form_renders_pt_br_turnstile_language(): void {
		$this->mock_passthroughs();
		WP_Mock::userFunction( 'wp_create_nonce' )->andReturn( 'test-nonce' );
		WP_Mock::userFunction( 'wp_nonce_field' )->zeroOrMoreTimes()->andReturn( '<input type="hidden" name="md_inquiry_nonce" value="nonce">' );
		WP_Mock::userFunction( 'get_the_ID' )->andReturn( 0 );
		WP_Mock::userFunction( 'get_post' )->andReturn( null );
		WP_Mock::userFunction( 'wp_enqueue_script' )->zeroOrMoreTimes()->andReturn( null );

		WP_Mock::onFilter( 'wpml_current_language' )->withAnyArgs()->reply( 'pt-br' );

		$config = new Config(
			array(
				'MD_INQUIRY_TURNSTILE_SITEKEY' => 'turnstile-sitekey-test',
				'MD_INQUIRY_TURNSTILE_SECRET'  => 'turnstile-secret-test',
			)
		);
		$form   = new Form( $config, new Pattern(), new FormState() );

		$html = $form->render( '' );

		$this->assertStringContainsString( 'name="md_inquiry_locale"', $html );
		$this->assertStringContainsString( 'value="pt-br"', $html );
		$this->assertStringContainsString( 'data-language="pt-br"', $html );
		$this->assertStringContainsString( '<option value="Facade screening">Brises e fachadas</option>', $html );
	}

	/**
	 * ThankYou::url() maps page ID through wpml_object_id when page exists.
	 */
	public function test_thank_you_url_maps_through_wpml_when_page_exists(): void {
		$this->mock_passthroughs();
		WP_Mock::userFunction( 'wp_hash' )->andReturn( 'the-key' );
		WP_Mock::userFunction( 'add_query_arg' )->andReturnUsing(
			static function ( array $args, string $url ): string {
				return $url . '?md_inquiry=' . $args['md_inquiry'] . '&key=' . $args['key'];
			}
		);

		WP_Mock::onFilter( 'wpml_current_language' )->withAnyArgs()->reply( 'de' );

		$page = new \WP_Post(
			(object) array(
				'ID'        => 100,
				'post_type' => 'page',
			)
		);
		WP_Mock::userFunction( 'get_page_by_path' )->with( ThankYou::PATH )->andReturn( $page );
		WP_Mock::onFilter( 'wpml_object_id' )->with( 100, 'page', true, 'de' )->reply( 200 );
		WP_Mock::userFunction( 'get_permalink' )->with( 200 )->andReturn( 'https://metal-divider.com/de/danke/' );

		$url_de = ThankYou::url( 123 );
		$this->assertSame( 'https://metal-divider.com/de/danke/?md_inquiry=123&key=the-key', $url_de );
	}

	/**
	 * ThankYou::url() maps fallback URL through wpml_permalink when page does not exist.
	 */
	public function test_thank_you_url_maps_through_wpml_fallback(): void {
		$this->mock_passthroughs();
		WP_Mock::userFunction( 'wp_hash' )->andReturn( 'the-key' );
		WP_Mock::userFunction( 'add_query_arg' )->andReturnUsing(
			static function ( array $args, string $url ): string {
				return $url . '?md_inquiry=' . $args['md_inquiry'] . '&key=' . $args['key'];
			}
		);

		WP_Mock::onFilter( 'wpml_current_language' )->withAnyArgs()->reply( 'de' );

		WP_Mock::userFunction( 'get_page_by_path' )->with( ThankYou::PATH )->andReturn( null );
		WP_Mock::userFunction( 'home_url' )->with( '/inquiry/thank-you/' )->andReturn( 'https://metal-divider.com/inquiry/thank-you/' );
		WP_Mock::onFilter( 'wpml_permalink' )->with( 'https://metal-divider.com/inquiry/thank-you/', 'de' )->reply( 'https://metal-divider.com/de/inquiry/thank-you/' );

		$url_fallback_de = ThankYou::url( 123 );
		$this->assertSame( 'https://metal-divider.com/de/inquiry/thank-you/?md_inquiry=123&key=the-key', $url_fallback_de );
	}

	/**
	 * Pattern::resolve() resolves localized name and URL from current_id while keeping canonical post_id as en_id.
	 */
	public function test_pattern_resolve_cross_language(): void {
		$this->mock_passthroughs();

		WP_Mock::onFilter( 'wpml_current_language' )->withAnyArgs()->reply( 'zh-hans' );

		$product            = new \stdClass();
		$product->ID        = 42;
		$product->post_type = 'product';
		$product->post_name = 'apiary';

		WP_Mock::userFunction( 'get_posts' )->andReturn( array( $product ) );

		// Current ID in zh-hans is 142, canonical EN ID is 42.
		WP_Mock::onFilter( 'wpml_object_id' )->with( 42, 'product', true, 'zh-hans' )->reply( 142 );
		WP_Mock::onFilter( 'wpml_object_id' )->with( 42, 'product', true, 'en' )->reply( 42 );

		WP_Mock::userFunction( 'get_the_title' )->with( 142 )->andReturn( '蜂巢 | 不锈钢建筑屏风' );
		WP_Mock::userFunction( 'get_the_title' )->with( 42 )->andReturn( 'Apiary | Architectural Screen Pattern' );

		WP_Mock::userFunction( 'get_permalink' )->with( 142 )->andReturn( 'https://metal-divider.com/zh/pattern/apiary/' );
		WP_Mock::userFunction( 'get_post_meta' )->andReturn( 'APIARY' );
		WP_Mock::userFunction( 'get_the_post_thumbnail_url' )->andReturn( 'https://metal-divider.com/wp-content/uploads/apiary.jpg' );

		$pattern = ( new Pattern() )->resolve( 'apiary' );

		$this->assertNotNull( $pattern );
		$this->assertSame( 42, $pattern['post_id'], 'post_id must be the canonical English product ID' );
		$this->assertSame( '蜂巢', $pattern['name'], 'name is the cleaned localized Chinese title' );
		$this->assertSame( 'Apiary', $pattern['name_en'] ?? '', 'name_en is the cleaned English title' );
		$this->assertSame( 'apiary', $pattern['canonical_slug'] ?? '' );
		$this->assertSame( 'https://metal-divider.com/zh/pattern/apiary/', $pattern['url'] );
	}

	/**
	 * Emails::internal() prefixes subject with [DE] and includes Language: de in body.
	 */
	public function test_emails_internal_with_non_en_locale(): void {
		$this->mock_passthroughs();
		WP_Mock::userFunction( 'get_option' )->zeroOrMoreTimes()->andReturn( '' );

		$record = array(
			'name'            => 'Hans Schmidt',
			'email'           => 'hans@studio.de',
			'company'         => 'Schmidt Architektur',
			'phone'           => '',
			'country'         => 'Germany',
			'scope'           => 'Single zone',
			'route'           => 'Facade screening',
			'quantity'        => '10-50 sqm',
			'confirm'         => '1',
			'message'         => 'Anfrage für Fassade.',
			'pattern_slug'    => 'apiary',
			'source_page'     => 'pattern-pdp',
			'source_module'   => 'hero',
			'source_button'   => 'request_quote',
			'locale'          => 'de',
			'pattern_name'    => 'Bienenstock',
			'pattern_name_en' => 'Apiary',
			'pattern_code'    => 'APIARY',
			'pattern_url'     => 'https://metal-divider.com/pattern/apiary',
			'pattern_product' => 42,
			'submitted_time'  => '2026-11-10 12:00:00',
			'verification'    => 'verified',
			'edit_url'        => 'https://metal-divider.com/wp-admin/post.php?post=50&action=edit',
			'quote_intent'    => '',
		);

		$config = new Config( array() );
		$emails = new Emails( $config );
		$mail   = $emails->internal( $record );

		$this->assertStringStartsWith( '[DE] New inquiry: Apiary — Hans Schmidt', $mail['subject'] );
		$this->assertStringContainsString( 'Language: de', $mail['body'] );
		$this->assertStringContainsString( 'Hans Schmidt', $mail['body'] );
	}

	/**
	 * Emails::confirmation() composes localized confirmation mail for non-EN locales.
	 */
	public function test_emails_confirmation_with_non_en_locale(): void {
		$this->mock_passthroughs();
		WP_Mock::userFunction( 'get_option' )->zeroOrMoreTimes()->andReturn( '' );

		$record = array(
			'name'            => '陈女士',
			'email'           => 'chen@design.cn',
			'company'         => '陈氏设计',
			'phone'           => '',
			'country'         => 'China',
			'scope'           => 'Single zone',
			'route'           => 'Facade screening',
			'quantity'        => '10-50 sqm',
			'confirm'         => '1',
			'message'         => '咨询 304 不锈钢屏风。',
			'pattern_slug'    => 'apiary',
			'source_page'     => 'pattern-pdp',
			'source_module'   => 'hero',
			'source_button'   => 'request_quote',
			'locale'          => 'zh',
			'pattern_name'    => '蜂巢',
			'pattern_name_en' => 'Apiary',
			'pattern_code'    => 'APIARY',
			'pattern_url'     => 'https://metal-divider.com/zh/pattern/apiary/',
			'pattern_product' => 42,
			'submitted_time'  => '2026-11-10 12:00:00',
			'verification'    => 'verified',
			'edit_url'        => 'https://metal-divider.com/wp-admin/post.php?post=50&action=edit',
			'quote_intent'    => '',
		);

		$config = new Config( array() );
		$emails = new Emails( $config );
		$mail   = $emails->confirmation( $record );

		$this->assertSame( '我们已收到您关于图案 蜂巢 的询盘', $mail['subject'] );
		$this->assertStringContainsString( '陈女士', $mail['body'] );
		$this->assertStringContainsString( '蜂巢', $mail['body'] );
		$this->assertStringContainsString( '304', $mail['body'] );
		$this->assertStringContainsString( '316', $mail['body'] );
		$this->assertContains( 'Content-Type: text/plain; charset=UTF-8', $mail['headers'] );
	}
}
