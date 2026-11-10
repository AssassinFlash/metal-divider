<?php
/**
 * Programmatic WPML, String Translation, WCML, and ACFML setup engine.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\I18n;

/**
 * Automates the complete 9-language multilingual configuration with zero clicks,
 * strictly enforcing directory URLs, URL slug copying, and CSP-compliant cookies.
 */
final class Setup {

	/**
	 * Expected WPML active languages.
	 */
	public const WPML_LANGS = array( 'en', 'zh-hans', 'es', 'pt-br', 'de', 'fr', 'ar', 'id', 'vi' );

	/**
	 * Applies the complete WPML configuration sequence.
	 *
	 * @return array{ok: bool, steps: list<string>, errors: list<string>}
	 */
	public static function apply(): array {
		$steps  = array();
		$errors = array();

		// 1. Verify required plugins.
		$required_plugins = array(
			'sitepress-multilingual-cms/sitepress.php' => 'WPML Multilingual CMS',
			'wpml-string-translation/plugin.php'       => 'WPML String Translation',
			'woocommerce-multilingual/wpml-woocommerce.php' => 'WooCommerce Multilingual',
			'acfml/wpml-acf.php'                       => 'ACFML',
		);

		if ( \function_exists( 'is_plugin_active' ) ) {
			foreach ( $required_plugins as $plugin_file => $plugin_name ) {
				if ( ! \is_plugin_active( $plugin_file ) ) {
					$errors[] = sprintf( 'Required plugin %s (%s) is not active.', $plugin_name, $plugin_file );
				}
			}
		}

		if ( ! empty( $errors ) ) {
			return array(
				'ok'     => false,
				'steps'  => $steps,
				'errors' => $errors,
			);
		}

		// 2. Prepare admin screen in CLI environment for upgrade hooks.
		self::prepare_screen();

		// 3. WPML Setup wizard instance execution.
		if ( \function_exists( 'wpml_get_setup_instance' ) ) {
			try {
				$setup = \wpml_get_setup_instance();
				if ( is_object( $setup ) ) {
					$is_setup_complete = \function_exists( 'icl_get_setting' ) && (bool) \icl_get_setting( 'setup_complete' );
					$default_language  = \function_exists( 'icl_get_setting' ) ? (string) \icl_get_setting( 'default_language' ) : '';
					if ( ( ! $is_setup_complete || 'en' !== $default_language ) && method_exists( $setup, 'finish_step1' ) ) {
						$setup->finish_step1( 'en' );
						$steps[] = 'WPML setup step 1 finished (en)';
					}

					if ( method_exists( $setup, 'finish_step2' ) ) {
						$setup->finish_step2( self::WPML_LANGS );
						$steps[] = 'WPML setup step 2 finished (active languages)';
					}

					if ( method_exists( $setup, 'finish_step3' ) ) {
						$setup->finish_step3();
						$steps[] = 'WPML setup step 3 finished';
					}

					// 4. Configure LanguageRepository for display code & hreflang tags.
					if ( class_exists( '\WPML\LanguageEditor\Adapter\LanguageRepository' ) ) {
						$repo = new \WPML\LanguageEditor\Adapter\LanguageRepository();
						if ( method_exists( $repo, 'setDisplayCode' ) ) {
							$repo->setDisplayCode( 'zh-hans', 'zh' );
							$steps[] = 'Mapped zh-hans display code to zh';
						}
						if ( method_exists( $repo, 'setTag' ) ) {
							$repo->setTag( 'zh-hans', 'zh-Hans' );
							$repo->setTag( 'pt-br', 'pt-BR' );
							$steps[] = 'Configured language tags for zh-Hans and pt-BR';
						}
					}

					$active_model = '\WPML\Core\Component\Languages\ActiveLanguagesReadModel';
					if ( class_exists( $active_model ) && is_callable( array( $active_model, 'invalidate' ) ) ) {
						call_user_func( array( $active_model, 'invalidate' ) );
					}

					if ( method_exists( $setup, 'refresh_active_lang_cache' ) ) {
						$setup->refresh_active_lang_cache( 'en', true );
					}
				}
			} catch ( \Throwable $e ) {
				$errors[] = 'WPML setup steps exception: ' . $e->getMessage();
			}
		}

		// 5. Configure WPML settings.
		if ( \function_exists( 'icl_set_setting' ) && \function_exists( 'icl_save_settings' ) ) {
			\icl_set_setting( 'language_negotiation_type', 1 );
			\icl_set_setting(
				'urls',
				array(
					'directory_for_default_language' => 0,
					'show_on_root'                   => '',
					'root_html_file_path'            => '',
					'root_page'                      => 0,
					'hide_language_switchers'        => 1,
				)
			);
			\icl_set_setting(
				'seo',
				array(
					'head_langs'                  => 1,
					'canonicalization_duplicates' => 1,
					'head_langs_priority'         => 1,
				)
			);
			\icl_set_setting( 'icl_lso_link_empty', 0 );
			\icl_set_setting( 'automatic_redirect', 0 );
			\icl_set_setting( 'browser_lang_redirect', 0 );
			\icl_set_setting( 'remember_language', 0 );
			\icl_set_setting( 'sync_page_ordering', 1 );
			\icl_set_setting( 'sync_page_parent', 1 );
			\icl_set_setting( 'sync_post_taxonomies', 1 );
			\icl_set_setting( 'sync_post_status', 1 );
			\icl_set_setting( 'translated_document_status', 1 );
			\icl_set_setting( 'translated_document_page_url', 'Copy' );
			\icl_save_settings();
			$steps[] = 'Configured WPML URL negotiation and translation sync settings';
		}

		// 6. TM Wizard options.
		$opt_class = '\WPML\Setup\Option';
		if ( class_exists( $opt_class ) ) {
			try {
				if ( is_callable( array( $opt_class, 'setOriginalLang' ) ) ) {
					call_user_func( array( $opt_class, 'setOriginalLang' ), 'en' );
				}
				if ( is_callable( array( $opt_class, 'setTranslationLangs' ) ) ) {
					call_user_func( array( $opt_class, 'setTranslationLangs' ), array( 'zh-hans', 'es', 'pt-br', 'de', 'fr', 'ar', 'id', 'vi' ) );
				}
				if ( is_callable( array( $opt_class, 'setTranslationMode' ) ) ) {
					call_user_func( array( $opt_class, 'setTranslationMode' ), array( 'myself' ) );
				}
				if ( is_callable( array( $opt_class, 'setTMAllowed' ) ) ) {
					call_user_func( array( $opt_class, 'setTMAllowed' ), true );
				}
				if ( is_callable( array( $opt_class, 'setTranslateEverything' ) ) ) {
					call_user_func( array( $opt_class, 'setTranslateEverything' ), false );
				}
				if ( is_callable( array( $opt_class, 'setAiSkipped' ) ) ) {
					call_user_func( array( $opt_class, 'setAiSkipped' ), true );
				}
			} catch ( \Throwable $e ) {
				$errors[] = 'WPML Option configuration error: ' . $e->getMessage();
			}
		}

		if ( \function_exists( 'wpml_get_setup_instance' ) ) {
			$setup             = \wpml_get_setup_instance();
			$is_setup_complete = \function_exists( 'icl_get_setting' ) && (bool) \icl_get_setting( 'setup_complete' );
			if ( is_object( $setup ) && ! $is_setup_complete && method_exists( $setup, 'finish_installation' ) ) {
				$setup->finish_installation();
				$steps[] = 'Finished WPML installation wizard';
			}
		}

		// CRITICAL: Force setup_complete, default_language, and CSP-safe cookie/redirect settings unconditionally.
		if ( \function_exists( 'icl_set_setting' ) && \function_exists( 'icl_save_settings' ) ) {
			\icl_set_setting( 'default_language', 'en' );
			\icl_set_setting( 'existing_content_language_verified', 1 );
			\icl_set_setting( 'setup_complete', 1 );
			\icl_set_setting( 'store_frontend_cookie', 0 );
			\icl_set_setting( 'browser_lang_redirect', 0 );
			\icl_save_settings();
			$steps[] = 'Enforced setup_complete=1, default_language=en, store_frontend_cookie=0, and browser_lang_redirect=0';
		}

		if ( \function_exists( 'update_option' ) ) {
			\update_option( 'WPML_TM_Wizard_For_Manager_Complete', true );
			\update_option( 'WPML_TM_Wizard_For_Admin_Complete', true );
			\delete_option( 'wpml_notices' );
			$steps[] = 'Marked WPML TM wizard options complete and deleted notices';
		}

		// 7. WCML configuration.
		if ( \function_exists( 'get_option' ) && \function_exists( 'update_option' ) ) {
			$wcml_settings = \get_option( '_wcml_settings', array() );
			if ( ! is_array( $wcml_settings ) ) {
				$wcml_settings = array();
			}
			$wcml_updates = array(
				'enable_multi_currency'   => 0,
				'display_custom_prices'   => 0,
				'trnsl_interface'         => 1,
				'set_up'                  => 1,
				'set_up_wizard_run'       => 1,
				'set_up_wizard_splash'    => 1,
				'url_translation_set_up'  => 1,
				'sync_taxonomies_checked' => 1,
			);
			\update_option( '_wcml_settings', array_merge( $wcml_settings, $wcml_updates ) );
			if ( \function_exists( 'delete_transient' ) ) {
				\delete_transient( '_wcml_activation_redirect' );
			}
			$steps[] = 'Configured WCML settings and cleared activation redirect';
		}

		// 8. ACFML notices.
		if ( \function_exists( 'delete_option' ) ) {
			\delete_option( 'acfml_activation_notice_pending' );
			$steps[] = 'Cleared ACFML pending notices';
		}

		// 9. Force-load wpml-config.xml.
		if ( \function_exists( 'wpml_tm_load_blog_tm' ) ) {
			\wpml_tm_load_blog_tm();
		}
		$cfg_class = '\WPML_Config';
		if ( class_exists( $cfg_class ) ) {
			if ( property_exists( $cfg_class, 'has_run' ) ) {
				$cfg_class::$has_run = false;
			}
			if ( is_callable( array( $cfg_class, 'load_config_run' ) ) ) {
				call_user_func( array( $cfg_class, 'load_config_run' ) );
				$steps[] = 'Reloaded wpml-config.xml';
			}
		}

		// 10. Backfill any icl_translations source rows seeded before default language was set.
		global $wpdb;
		if ( isset( $wpdb ) && $wpdb instanceof \wpdb && '' !== $wpdb->prefix ) {
			$icl_table = $wpdb->prefix . 'icl_translations';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$updated_rows = (int) $wpdb->query( "UPDATE `{$icl_table}` SET language_code = 'en' WHERE ( language_code = '' OR language_code IS NULL ) AND source_language_code IS NULL" );
			if ( $updated_rows > 0 ) {
				if ( \function_exists( 'wp_cache_flush' ) ) {
					\wp_cache_flush();
				}
				$steps[] = sprintf( 'Backfilled %d unassigned source rows in icl_translations to en', $updated_rows );
			}
		}

		// 11. Flush rewrite rules.
		if ( \function_exists( 'flush_rewrite_rules' ) ) {
			\flush_rewrite_rules( false );
			$steps[] = 'Flushed rewrite rules';
		}

		return array(
			'ok'     => empty( $errors ),
			'steps'  => $steps,
			'errors' => $errors,
		);
	}

	/**
	 * Checks for configuration drift against desired state.
	 *
	 * @return array{ok: bool, issues: list<string>, details: array<string, mixed>}
	 */
	public static function check(): array {
		$issues  = array();
		$details = array();

		// Check setup_complete.
		$setup_complete            = \function_exists( 'icl_get_setting' ) ? \icl_get_setting( 'setup_complete' ) : null;
		$details['setup_complete'] = $setup_complete;
		if ( 1 !== (int) $setup_complete ) {
			$issues[] = sprintf( 'setup_complete is %s (expected 1).', is_scalar( $setup_complete ) ? (string) $setup_complete : 'null' );
		}

		// Check default_language.
		$default_language            = \function_exists( 'icl_get_setting' ) ? \icl_get_setting( 'default_language' ) : null;
		$details['default_language'] = $default_language;
		if ( 'en' !== $default_language ) {
			$issues[] = sprintf( 'default_language is %s (expected "en").', is_scalar( $default_language ) ? (string) $default_language : 'null' );
		}

		// Check negotiation type.
		$type                                 = \function_exists( 'icl_get_setting' ) ? \icl_get_setting( 'language_negotiation_type' ) : null;
		$details['language_negotiation_type'] = $type;
		if ( 1 !== (int) $type ) {
			$issues[] = sprintf( 'language_negotiation_type is %s (expected 1 for directory URLs).', is_scalar( $type ) ? (string) $type : 'null' );
		}

		// Check CSP cookie setting.
		$cookie                           = \function_exists( 'icl_get_setting' ) ? \icl_get_setting( 'store_frontend_cookie' ) : null;
		$details['store_frontend_cookie'] = $cookie;
		if ( 0 !== (int) $cookie ) {
			$issues[] = sprintf( 'store_frontend_cookie is %s (expected 0 for strict CSP).', is_scalar( $cookie ) ? (string) $cookie : 'null' );
		}

		// Check browser redirect.
		$redirect                         = \function_exists( 'icl_get_setting' ) ? \icl_get_setting( 'browser_lang_redirect' ) : null;
		$details['browser_lang_redirect'] = $redirect;
		if ( 0 !== (int) $redirect ) {
			$issues[] = sprintf( 'browser_lang_redirect is %s (expected 0).', is_scalar( $redirect ) ? (string) $redirect : 'null' );
		}

		// Check URL copy mode.
		$url_copy                                = \function_exists( 'icl_get_setting' ) ? \icl_get_setting( 'translated_document_page_url' ) : null;
		$details['translated_document_page_url'] = $url_copy;
		if ( 'Copy' !== $url_copy ) {
			$issues[] = sprintf( 'translated_document_page_url is %s (expected "Copy").', is_scalar( $url_copy ) ? (string) $url_copy : 'null' );
		}

		// Check head_langs SEO setting.
		$seo            = \function_exists( 'icl_get_setting' ) ? \icl_get_setting( 'seo' ) : null;
		$head_langs     = is_array( $seo ) && isset( $seo['head_langs'] ) ? (int) $seo['head_langs'] : 0;
		$details['seo'] = $seo;
		if ( 1 !== $head_langs ) {
			$issues[] = sprintf( 'seo.head_langs is %d (expected 1).', $head_langs );
		}

		// Check active languages.
		$read_model = '\WPML\Core\Component\Languages\ActiveLanguagesReadModel';
		if ( class_exists( $read_model ) && is_callable( array( $read_model, 'get_active_languages' ) ) ) {
			$active_langs                = array_keys( (array) call_user_func( array( $read_model, 'get_active_languages' ) ) );
			$details['active_languages'] = $active_langs;
			$missing                     = array_diff( self::WPML_LANGS, $active_langs );
			if ( ! empty( $missing ) ) {
				$issues[] = 'Missing active languages: ' . implode( ', ', $missing );
			}
		}

		// Check unassigned language_code rows in icl_translations.
		global $wpdb;
		if ( isset( $wpdb ) && $wpdb instanceof \wpdb && '' !== $wpdb->prefix ) {
			$icl_table = $wpdb->prefix . 'icl_translations';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$empty_lang_rows                     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$icl_table}` WHERE language_code = '' OR language_code IS NULL" );
			$details['empty_language_code_rows'] = $empty_lang_rows;
			if ( $empty_lang_rows > 0 ) {
				$issues[] = sprintf( '%d row(s) in icl_translations have empty language_code.', $empty_lang_rows );
			}
		}

		return array(
			'ok'      => empty( $issues ),
			'issues'  => $issues,
			'details' => $details,
		);
	}

	/**
	 * Prepares the current screen in WP-CLI.
	 */
	private static function prepare_screen(): void {
		if ( ! \function_exists( 'set_current_screen' ) && \defined( 'ABSPATH' ) ) {
			$screen_file = ABSPATH . 'wp-admin/includes/screen.php';
			if ( is_readable( $screen_file ) ) {
				require_once $screen_file;
			}
		}

		if ( \function_exists( 'set_current_screen' ) ) {
			\set_current_screen( 'dashboard' );
		}
	}
}
