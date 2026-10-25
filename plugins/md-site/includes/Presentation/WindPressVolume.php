<?php
/**
 * The plugin's WindPress volume stylesheets.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Presentation;

/**
 * The WindPress volume: `present` copies `content/windpress/*.css` into the
 * volume directory the WindPress compiler reads (uploads/windpress/data/) and
 * makes the volume's `main.css` import them — the compiler's entry point only
 * reaches a volume file through an import (the de-linker pattern the theme
 * base spec carries, spec 17 §4.4). The next compile ships the rules inside
 * the compiled cache; the plugin never enqueues site CSS itself (AGENTS.md
 * presentation ruling). Today the volume carries the design tokens; the
 * component and template volumes of the page-template tickets join it here.
 *
 * The entry also carries the Tailwind v4 head — the layer declaration and
 * the theme/utilities imports of WindPress's own stub entry — which is what
 * gives the compiler its utility definitions: an entry without it compiles
 * no utility class at all, arbitrary or standard (ticket #58, where 502
 * unique arbitrary-value classes in the LiveCanvas templates computed to
 * nothing because the entry imported only the volumes).
 */
final class WindPressVolume {

	/**
	 * The compiler's entry stylesheet in the volume.
	 */
	private const ENTRY = 'main.css';

	/**
	 * The head the entry must carry, verbatim from WindPress's stub entry for
	 * Tailwind v4 (plugins/windpress/stubs/tailwindcss-v4/main.css): the layer
	 * order, the theme variables and the on-demand utilities. Preflight stays
	 * commented out as in the stub — the volume rules carry the old site's
	 * resets, and a second reset would rebase the replica pages.
	 */
	private const ENTRY_HEAD = '@layer theme, base, components, utilities;' . "\n\n"
		. '@import "tailwindcss/theme.css" layer(theme) theme(static);' . "\n"
		. '/* @import "tailwindcss/preflight.css" layer(base); */' . "\n"
		. '@import "tailwindcss/utilities.css" layer(utilities);';

	/**
	 * The one head line whose absence is #58's failure: without the utilities
	 * import the compiler has no utility definitions, so it builds nothing
	 * for any utility class, arbitrary or standard. install() keys on this
	 * line rather than on the whole head — an entry that carries it (the
	 * WindPress stub seed, a hand-trimmed variant) is left alone instead of
	 * growing a duplicate head.
	 */
	private const ENTRY_HEAD_MARK = '@import "tailwindcss/utilities.css" layer(utilities);';

	/**
	 * Directory of the exported stylesheets, relative to this plugin.
	 */
	private const RELATIVE_DIR = 'content/windpress';

	/**
	 * Copies each exported stylesheet into the volume directory and imports
	 * it from `main.css`; a changed file keeps its previous copy beside it,
	 * so a rollback is one rename away. Idempotent: a second run with
	 * unchanged exports writes nothing.
	 */
	public function install(): void {
		$data_dir = $this->data_dir();
		if ( null === $data_dir ) {
			return;
		}
		$imports = array();
		foreach ( self::files() as $source ) {
			$target    = $data_dir . '/' . basename( $source );
			$imports[] = './' . basename( $source );
			if ( file_exists( $target ) && md5_file( $source ) !== md5_file( $target ) ) {
				copy( $target, $target . '.bak' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- local volume file on disk, not a remote URL.
			}
			if ( ! file_exists( $target ) || md5_file( $source ) !== md5_file( $target ) ) {
				copy( $source, $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- local volume file on disk, not a remote URL.
			}
		}
		$this->import_from_entry( $data_dir . '/' . self::ENTRY, $imports );
	}

	/**
	 * Writes the entry the compiler needs: the Tailwind v4 head (the compiler's
	 * utility definitions — without it no utility class compiles) and the
	 * volume files' imports, each into the `components` layer. Layering the
	 * volumes is load-bearing (#58): the volumes carry the old site's
	 * compiled CSS, utility rules included, and an unlayered rule beats every
	 * layered one — unlayered, the volumes' stale `.grid-cols-1` pinned every
	 * responsive grid to one column while the freshly built `.md:grid-cols-3`
	 * sat unusable in the `utilities` layer. In `components`, declared before
	 * `utilities` by the head, the on-demand utilities win exactly the ties
	 * the old build's own utilities won, and the volumes' custom rules keep
	 * their specificity wins inside their layer.
	 *
	 * The layered form is migrated in place: a bare import of a volume file
	 * is replaced by its layered form, never kept beside it. Otherwise
	 * missing pieces are added and present ones left byte-identical, and a
	 * changed entry keeps its previous copy beside it.
	 *
	 * @param string   $entry   Absolute path of `main.css`.
	 * @param string[] $imports The relative paths to import.
	 */
	private function import_from_entry( string $entry, array $imports ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local volume file on disk, not a remote URL.
		$current = is_readable( $entry ) ? (string) file_get_contents( $entry ) : '';
		$updated = $current;

		if ( ! str_contains( $updated, self::ENTRY_HEAD_MARK ) ) {
			$updated = '' === rtrim( $updated ) ? self::ENTRY_HEAD : self::ENTRY_HEAD . "\n\n" . ltrim( $updated );
		}

		$missing = array();
		foreach ( $imports as $import ) {
			$layered = "@import \"{$import}\" layer(components);";
			if ( ! str_contains( $updated, $layered ) ) {
				$missing[] = $layered;
			}
			// The bare, unlayered form of our own import loses its cascade
			// slot to nothing — replace it, do not keep both.
			$bare    = "@import \"{$import}\";";
			$updated = str_replace( $bare . "\n", '', $updated );
			$updated = str_replace( "\n" . $bare, '', $updated );
			$updated = str_replace( $bare, '', $updated );
		}
		if ( array() !== $missing ) {
			$updated = rtrim( $updated ) . "\n" . implode( "\n", $missing ) . "\n";
		}
		if ( $updated === $current ) {
			return;
		}
		if ( '' !== $current ) {
			copy( $entry, $entry . '.bak' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- local volume file on disk, not a remote URL.
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local volume file on disk, not a remote URL.
		file_put_contents( $entry, rtrim( $updated ) . "\n" );
	}

	/**
	 * The volume files' drift against the exported stylesheets: the read-only
	 * twin of install(), for a deploy to assert that the volume really
	 * carries the checkout's files. A volume file absent, or with a hash the
	 * export does not carry, is one row; the entry's missing head or imports
	 * are one row each. Nothing here writes, and the volume directory is not
	 * created (a site without WindPress has no volume to compare).
	 *
	 * @return list<array{surface: string, target: string, detail: string}> Drift rows, empty when in sync.
	 */
	public function drift(): array {
		$uploads = wp_get_upload_dir();
		if ( empty( $uploads['basedir'] ) ) {
			return array(
				array(
					'surface' => 'windpress volume',
					'target'  => 'uploads',
					'detail'  => 'the uploads directory cannot be resolved',
				),
			);
		}
		$dir = $uploads['basedir'] . '/windpress/data';
		if ( ! is_dir( $dir ) ) {
			return array(
				array(
					'surface' => 'windpress volume',
					'target'  => 'windpress/data',
					'detail'  => 'the volume directory does not exist: present has not installed it',
				),
			);
		}
		$rows    = array();
		$imports = array();
		foreach ( self::files() as $source ) {
			$name      = basename( $source );
			$target    = $dir . '/' . $name;
			$imports[] = './' . $name;
			if ( ! is_readable( $target ) ) {
				$rows[] = array(
					'surface' => 'windpress volume',
					'target'  => $name,
					'detail'  => 'not installed',
				);
				continue;
			}
			if ( md5_file( $source ) !== md5_file( $target ) ) {
				$rows[] = array(
					'surface' => 'windpress volume',
					'target'  => $name,
					'detail'  => 'the volume copy does not hash the same as the export',
				);
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local volume file on disk, not a remote URL.
		$entry = is_readable( $dir . '/' . self::ENTRY ) ? (string) file_get_contents( $dir . '/' . self::ENTRY ) : '';
		if ( ! str_contains( $entry, self::ENTRY_HEAD_MARK ) ) {
			$rows[] = array(
				'surface' => 'windpress volume',
				'target'  => self::ENTRY,
				'detail'  => 'the entry does not carry the Tailwind v4 theme/utilities imports: no utility class compiles',
			);
		}
		foreach ( $imports as $import ) {
			if ( ! str_contains( $entry, "@import \"{$import}\" layer(components);" ) ) {
				$rows[] = array(
					'surface' => 'windpress volume',
					'target'  => self::ENTRY,
					'detail'  => 'the entry does not import ' . $import . ' into the components layer',
				);
			}
		}
		return $rows;
	}

	/**
	 * Absolute path of the exported stylesheets.
	 */
	public static function path(): string {
		return dirname( __DIR__, 2 ) . '/' . self::RELATIVE_DIR;
	}

	/**
	 * Absolute paths of the exported stylesheets.
	 *
	 * @return list<string>
	 */
	public static function files(): array {
		$files = glob( self::path() . '/*.css' );
		return false === $files ? array() : $files;
	}

	/**
	 * The volume directory WindPress reads, null when its path cannot be
	 * resolved (WindPress inactive — nothing will compile, nothing to install).
	 */
	private function data_dir(): ?string {
		$uploads = wp_get_upload_dir();
		if ( empty( $uploads['basedir'] ) ) {
			return null;
		}
		$dir = $uploads['basedir'] . '/windpress/data';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return is_dir( $dir ) ? $dir : null;
	}
}
