<?php
/**
 * DeepSeek API client for high-throughput architectural translation.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\I18n;

use RuntimeException;

/**
 * Translates segment batches using deepseek-flash with parallel curl execution,
 * strict JSON schema enforcement, and credential isolation.
 */
class DeepSeekClient {

	public const ENDPOINT = 'https://api.deepseek.com/chat/completions';

	public const MODEL = 'deepseek-flash';

	private const TEMPERATURE = 0.2;

	private const MAX_TOKENS = 8192;

	private const TIMEOUT = 90;

	/**
	 * Cached API key.
	 *
	 * @var string|null
	 */
	private ?string $api_key = null;

	/**
	 * Initializer.
	 *
	 * @param string|null $api_key Optional explicit API key.
	 */
	public function __construct( ?string $api_key = null ) {
		$this->api_key = $api_key;
	}

	/**
	 * Retrieves the API key securely without persistent logging or disk storage.
	 *
	 * @throws RuntimeException If no API key is found.
	 */
	public function get_api_key(): string {
		if ( null !== $this->api_key && '' !== trim( $this->api_key ) ) {
			return trim( $this->api_key );
		}

		// 1. Environment variables.
		$env_key = getenv( 'DEEPSEEK_API_KEY' );
		if ( is_string( $env_key ) && '' !== trim( $env_key ) ) {
			$this->api_key = trim( $env_key );
			return $this->api_key;
		}

		if ( isset( $_ENV['DEEPSEEK_API_KEY'] ) && is_string( $_ENV['DEEPSEEK_API_KEY'] ) && '' !== trim( $_ENV['DEEPSEEK_API_KEY'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$this->api_key = trim( $_ENV['DEEPSEEK_API_KEY'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return $this->api_key;
		}

		// 2. Local config directory (~/.config/metal-divider/deepseek.env).
		$home = getenv( 'HOME' );
		if ( ( ! is_string( $home ) || '' === $home ) && \function_exists( 'posix_getpwuid' ) && \function_exists( 'posix_geteuid' ) ) {
			$pw = \posix_getpwuid( \posix_geteuid() );
			if ( is_array( $pw ) && '' !== $pw['dir'] ) {
				$home = $pw['dir'];
			}
		}

		if ( is_string( $home ) && '' !== $home ) {
			$key = self::read_key_file( $home . '/.config/metal-divider/deepseek.env' );
			if ( '' !== $key ) {
				$this->api_key = $key;
				return $this->api_key;
			}
		}

		// 3. Deployment path (.md-deploy/deepseek.env outside webroot).
		if ( \defined( 'ABSPATH' ) ) {
			$deploy_path = dirname( ABSPATH ) . '/.md-deploy/deepseek.env';
			$key         = self::read_key_file( $deploy_path );
			if ( '' !== $key ) {
				$this->api_key = $key;
				return $this->api_key;
			}
		}

		throw new RuntimeException( 'DEEPSEEK_API_KEY is not configured in environment or config files.' );
	}

	/**
	 * Reads key from env file.
	 *
	 * @param string $path Path to .env file.
	 */
	private static function read_key_file( string $path ): string {
		if ( ! is_readable( $path ) ) {
			return '';
		}

		$lines = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_file -- private credentials file.
		if ( false === $lines ) {
			return '';
		}

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( str_starts_with( $line, '#' ) ) {
				continue;
			}
			if ( str_starts_with( $line, 'export ' ) ) {
				$line = trim( substr( $line, 7 ) );
			}
			if ( ! str_contains( $line, '=' ) ) {
				continue;
			}

			list( $name, $value ) = explode( '=', $line, 2 );
			if ( 'DEEPSEEK_API_KEY' === trim( $name ) ) {
				return trim( $value, " \t\r\n\"'" );
			}
		}

		return '';
	}

	/**
	 * Translates a single batch of segments into the target language.
	 *
	 * @param string             $lang     Target canonical language code.
	 * @param array<int, string> $segments Source segments with placeholders.
	 * @param string             $feedback Optional retry feedback.
	 * @return list<string>
	 * @throws RuntimeException On translation or network error.
	 */
	public function translate_batch( string $lang, array $segments, string $feedback = '' ): array {
		if ( empty( $segments ) ) {
			return array();
		}

		$body = $this->build_request_body( $lang, $segments, $feedback );
		$key  = $this->get_api_key();

		if ( \function_exists( 'wp_remote_post' ) ) {
			$response = \wp_remote_post(
				self::ENDPOINT,
				array(
					'headers' => array(
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . $key,
					),
					'body'    => (string) wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
					'timeout' => self::TIMEOUT,
				)
			);

			if ( is_wp_error( $response ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new RuntimeException( 'DeepSeek HTTP request failed: ' . $response->get_error_message() );
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$raw    = (string) wp_remote_retrieve_body( $response );
		} else {
			// Fallback if wp_remote_post is not available (plain PHP unit test without WP_Mock).
			// phpcs:disable WordPress.WP.AlternativeFunctions,Generic.PHP.DeprecatedFunctions
			$ch = curl_init( self::ENDPOINT );
			curl_setopt_array(
				$ch,
				array(
					CURLOPT_POST           => true,
					CURLOPT_POSTFIELDS     => (string) json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
					CURLOPT_HTTPHEADER     => array(
						'Content-Type: application/json',
						'Authorization: Bearer ' . $key,
					),
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_TIMEOUT        => self::TIMEOUT,
				)
			);
			$raw    = (string) curl_exec( $ch );
			$status = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			curl_close( $ch );
			// phpcs:enable WordPress.WP.AlternativeFunctions,Generic.PHP.DeprecatedFunctions
		}

		return $this->parse_response( $raw, $status, count( $segments ) );
	}

	/**
	 * Translates multiple batches concurrently using curl_multi.
	 *
	 * @param list<array{lang: string, segments: list<string>}> $batches List of batches.
	 * @param int                                               $max_concurrency Maximum concurrent HTTP requests.
	 * @return list<list<string>> Translated results matching $batches order.
	 * @throws RuntimeException On fatal batch failure.
	 */
	public function translate_batches_parallel( array $batches, int $max_concurrency = 10 ): array {
		if ( empty( $batches ) ) {
			return array();
		}

		if ( ! \function_exists( 'curl_multi_init' ) ) {
			// Sequential fallback.
			$results = array();
			foreach ( $batches as $batch ) {
				$results[] = $this->translate_batch( $batch['lang'], $batch['segments'] );
			}
			return $results;
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions,Generic.PHP.DeprecatedFunctions
		$key     = $this->get_api_key();
		$total   = count( $batches );
		$results = array_fill( 0, $total, null );
		$mh      = curl_multi_init();

		$handles = array();
		$cursor  = 0;

		// Add initial batch of handles.
		while ( $cursor < $total && count( $handles ) < $max_concurrency ) { // phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found
			$h                   = $this->create_curl_handle( $batches[ $cursor ], $key );
			$handles[ (int) $h ] = array(
				'handle' => $h,
				'index'  => $cursor,
			);
			curl_multi_add_handle( $mh, $h );
			++$cursor;
		}

		$active = null;
		do {
			$mrc = curl_multi_exec( $mh, $active );
		} while ( CURLM_CALL_MULTI_PERFORM === $mrc );

		while ( $active && CURLM_OK === $mrc ) {
			if ( -1 === curl_multi_select( $mh, 0.5 ) ) {
				usleep( 50000 );
			}

			do {
				$mrc = curl_multi_exec( $mh, $active );
			} while ( CURLM_CALL_MULTI_PERFORM === $mrc );

			while ( true ) {
				$info = curl_multi_info_read( $mh );
				if ( false === $info ) {
					break;
				}
				$h        = $info['handle'];
				$h_id     = (int) $h;
				$idx      = $handles[ $h_id ]['index'];
				$raw      = (string) curl_multi_getcontent( $h );
				$status   = (int) curl_getinfo( $h, CURLINFO_HTTP_CODE );
				$expected = count( $batches[ $idx ]['segments'] );

				try {
					$results[ $idx ] = $this->parse_response( $raw, $status, $expected );
				} catch ( \Throwable $e ) {
					// Single retry on transient failure.
					try {
						$results[ $idx ] = $this->translate_batch( $batches[ $idx ]['lang'], $batches[ $idx ]['segments'], 'Retry after HTTP error: ' . $e->getMessage() );
					} catch ( \Throwable $fatal ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
						throw new RuntimeException( sprintf( 'Batch %d (%s) failed fatally: %s', $idx, $batches[ $idx ]['lang'], $fatal->getMessage() ) );
					}
				}

				curl_multi_remove_handle( $mh, $h );
				curl_close( $h );
				unset( $handles[ $h_id ] );

				if ( $cursor < $total ) {
					$next_h                   = $this->create_curl_handle( $batches[ $cursor ], $key );
					$handles[ (int) $next_h ] = array(
						'handle' => $next_h,
						'index'  => $cursor,
					);
					curl_multi_add_handle( $mh, $next_h );
					++$cursor;
				}
			}
		}

		curl_multi_close( $mh );
		// phpcs:enable WordPress.WP.AlternativeFunctions,Generic.PHP.DeprecatedFunctions

		$clean_results = array();
		foreach ( $results as $res ) {
			$clean_results[] = is_array( $res ) ? $res : array();
		}
		return $clean_results;
	}

	/**
	 * Creates a curl handle for a batch.
	 *
	 * @param array{lang: string, segments: list<string>} $batch Batch data.
	 * @param string                                      $key   API key.
	 * @return \CurlHandle
	 */
	private function create_curl_handle( array $batch, string $key ) {
		$body = $this->build_request_body( $batch['lang'], $batch['segments'] );
		// phpcs:disable WordPress.WP.AlternativeFunctions,Generic.PHP.DeprecatedFunctions
		$ch = curl_init( self::ENDPOINT );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => (string) json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				CURLOPT_HTTPHEADER     => array(
					'Content-Type: application/json',
					'Authorization: Bearer ' . $key,
				),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => self::TIMEOUT,
			)
		);
		// phpcs:enable WordPress.WP.AlternativeFunctions,Generic.PHP.DeprecatedFunctions
		return $ch;
	}

	/**
	 * Builds chat completions request payload.
	 *
	 * @param string             $lang     Target language code.
	 * @param array<int, string> $segments Source segments.
	 * @param string             $feedback Optional retry feedback.
	 * @return array<string, mixed>
	 */
	private function build_request_body( string $lang, array $segments, string $feedback = '' ): array {
		$all_langs   = Languages::all();
		$lang_info   = $all_langs[ $lang ] ?? $all_langs['en'];
		$target_name = $lang_info['english_name'] . ' (' . $lang_info['label'] . ')';

		$system = "You are an expert architectural and industrial B2B localization translator for Metal Divider (metal-divider.com).
Your task is to translate an array of English text segments into {$target_name}.

CRITICAL RULES:
1. Placeholders: You MUST preserve all placeholders (<t1>, </t1>, <t2/>, <k3/>, etc.) exactly verbatim and properly nested! Do not add, omit, or modify any placeholder.
2. Brand names: Preserve \"Metal Divider\", \"MetalDivider\", \"WhatsApp\", \"304\", \"316\" verbatim.
3. Figures & Numbers: Preserve all numeric digits and technical dimensions verbatim. Use Western ASCII digits (0-9) at all times, even in Arabic. Do not convert digits into words or Chinese units (e.g. keep 10,000 as 10,000, not 1万), and keep spelled-out English words (like \"twelve\" or \"two\") translated as words rather than digits when possible.
4. Material: The only material produced is Stainless Steel (304 / 316). Do NOT introduce Corten, aluminum, copper, or mild steel unless present in the English source.
5. Inquiries & Glossary: This is a B2B architectural site for custom architectural metal screens. Use terms for inquiries/quotes (\"Request a Quote\", \"Inquiry\"). NEVER use retail terms like \"cart\", \"shopping bag\", \"checkout\", or \"buy now\". In Simplified Chinese (zh), always translate \"pattern\"/\"patterns\" as \"图案\" (NEVER \"款式\" or \"花型\") and \"inquiry\" as \"询盘\" (NEVER \"订单\").
6. Response format: Return ONLY a valid JSON object with a single key \"translations\" containing the translated array of strings matching the input segments index-for-index.
Example: {\"translations\": [\"translated string 1\", \"translated string 2\"]}";

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$user_content = (string) json_encode( array( 'segments' => $segments ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( '' !== $feedback ) {
			$user_content .= "\n\nCRITICAL FIX: Your previous translation was rejected: " . $feedback . "\nPlease correct this strictly.";
		}

		return array(
			'model'           => self::MODEL,
			'temperature'     => self::TEMPERATURE,
			'max_tokens'      => self::MAX_TOKENS,
			'thinking'        => array( 'type' => 'disabled' ),
			'response_format' => array( 'type' => 'json_object' ),
			'messages'        => array(
				array(
					'role'    => 'system',
					'content' => $system,
				),
				array(
					'role'    => 'user',
					'content' => $user_content,
				),
			),
		);
	}

	/**
	 * Parses and validates DeepSeek JSON response.
	 *
	 * @param string $raw            Raw response string.
	 * @param int    $status         HTTP status code.
	 * @param int    $expected_count Expected segment count.
	 * @return list<string>
	 * @throws RuntimeException On invalid response.
	 */
	private function parse_response( string $raw, int $status, int $expected_count ): array {
		if ( 200 !== $status ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException( sprintf( 'DeepSeek API returned HTTP %d: %s', $status, substr( $raw, 0, 300 ) ) );
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || ! isset( $data['choices'][0]['message']['content'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException( 'Invalid DeepSeek response structure: missing choices content.' );
		}

		$content = (string) $data['choices'][0]['message']['content'];
		$parsed  = json_decode( $content, true );
		if ( ! is_array( $parsed ) || ! isset( $parsed['translations'] ) || ! is_array( $parsed['translations'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException( 'DeepSeek content is not valid JSON with "translations" list.' );
		}

		$translations = $parsed['translations'];
		if ( count( $translations ) !== $expected_count ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException( sprintf( 'Segment count mismatch: expected %d, got %d', $expected_count, count( $translations ) ) );
		}

		$strings = array();
		foreach ( $translations as $tr ) {
			$strings[] = is_scalar( $tr ) ? (string) $tr : '';
		}

		return $strings;
	}
}
