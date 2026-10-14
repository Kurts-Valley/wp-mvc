<?php
declare(strict_types=1);

namespace KurtCampbell\WpMvc\System;

use KurtCampbell\WpMvc\PluginContext;

/**
 * Discovers frontend CSS/JS under the product plugin assets/ and registers them with WordPress.
 *
 * Handles: {handlePrefix}-{css|js}-{path-with-dashes}.
 * Product-specific dependency overrides may use {@see dependency_map()} (empty by default).
 */
class AssetRegistry {
  /**
   * @var array<string, string>|null plugin root path keyed by asset type
   */
  private static ?array $assets_root_path = null;

  /**
   * @var array<string, string>|null plugin root URL keyed by asset type
   */
  private static ?array $assets_root_url = null;

  /**
   * Registers all discovered frontend styles and scripts.
   */
  public static function register_frontend_assets(): void {
    foreach ( self::discover_frontend_asset_paths( 'css' ) as $relative_path ) {
      self::register_style( $relative_path );
    }

    foreach ( self::discover_frontend_asset_paths( 'js' ) as $relative_path ) {
      self::register_script( $relative_path );
    }
  }

  /**
   * @return list<string> Paths relative to assets/css/ or assets/js/.
   */
  public static function discover_frontend_asset_paths( string $type ): array {
    if ( 'css' !== $type && 'js' !== $type ) {
      return [];
    }

    $root = self::assets_directory_path( $type );
    if ( ! is_dir( $root ) ) {
      return [];
    }

    $extension = 'css' === $type ? 'css' : 'js';
    $paths = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
    );

    $admin_root = realpath( $root . DIRECTORY_SEPARATOR . 'admin' );
    $sep = DIRECTORY_SEPARATOR;
    $root_real = realpath( $root );
    if ( false === $root_real ) {
      return [];
    }

    foreach ( $iterator as $fileinfo ) {
      if ( ! $fileinfo->isFile() ) {
        continue;
      }
      if ( strcasecmp( $fileinfo->getExtension(), $extension ) !== 0 ) {
        continue;
      }

      $resolved = realpath( $fileinfo->getPathname() );
      if ( false === $resolved ) {
        continue;
      }

      if ( false !== $admin_root ) {
        if ( $resolved === $admin_root || str_starts_with( $resolved, $admin_root . $sep ) ) {
          continue;
        }
      }

      $relative = substr( $resolved, strlen( $root_real ) + 1 );
      $relative = str_replace( DIRECTORY_SEPARATOR, '/', $relative );
      $paths[] = $relative;
    }

    sort( $paths, SORT_STRING );

    return $paths;
  }

  /**
   * @param string $type 'css' or 'js'
   * @param string $relative_path e.g. shortcodes/example.css
   */
  public static function handle_for( string $type, string $relative_path ): string {
    $normalized = str_replace( '\\', '/', $relative_path );
    $normalized = preg_replace( '/\.(css|js)$/i', '', $normalized ) ?? $normalized;
    $slug = str_replace( '/', '-', $normalized );

    return self::handle_prefix() . '-' . $type . '-' . $slug;
  }

  /**
   * Registers and enqueues shared accessibility assets when present on disk.
   */
  public static function enqueue_shared_a11y( bool $include_js = true ): void {
    self::register_frontend_assets();
    self::enqueue( 'css', 'shared/focus-visible.css' );
    if ( $include_js ) {
      self::enqueue( 'js', 'shared/a11y.js' );
    }
  }

  /**
   * Registers and enqueues site-wide keyboard focus styles and contrast helper when present.
   */
  public static function enqueue_global_a11y(): void {
    self::register_frontend_assets();
    self::enqueue( 'css', 'variables.css' );
    self::enqueue( 'css', 'shared/global-focus.css' );
    self::enqueue( 'js', 'shared/a11y.js' );
    self::enqueue( 'js', 'shared/focus-contrast.js' );
  }

  /**
   * Enqueues a registered frontend asset when it exists on disk.
   *
   * @param string $type 'css' or 'js'
   * @param string $relative_path e.g. shortcodes/example.css
   */
  public static function enqueue( string $type, string $relative_path ): void {
    $handle = self::handle_for( $type, $relative_path );
    if ( 'css' === $type ) {
      if ( wp_style_is( $handle, 'registered' ) ) {
        wp_enqueue_style( $handle );
      }
      return;
    }

    if ( wp_script_is( $handle, 'registered' ) ) {
      wp_enqueue_script( $handle );
    }
  }

  /**
   * Clears cached asset roots (for tests).
   */
  public static function reset_roots(): void {
    self::$assets_root_path = null;
    self::$assets_root_url = null;
  }

  private static function register_style( string $relative_path ): void {
    $path = self::assets_directory_path( 'css' ) . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path );
    if ( ! is_readable( $path ) ) {
      return;
    }

    wp_register_style(
      self::handle_for( 'css', $relative_path ),
      self::assets_directory_url( 'css' ) . $relative_path,
      self::style_dependencies( $relative_path ),
      (string) filemtime( $path )
    );
  }

  private static function register_script( string $relative_path ): void {
    $path = self::assets_directory_path( 'js' ) . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path );
    if ( ! is_readable( $path ) ) {
      return;
    }

    wp_register_script(
      self::handle_for( 'js', $relative_path ),
      self::assets_directory_url( 'js' ) . $relative_path,
      self::script_dependencies( $relative_path ),
      (string) filemtime( $path ),
      true
    );
  }

  /**
   * @return list<string>
   */
  private static function style_dependencies( string $relative_path ): array {
    $map = self::dependency_map();
    if ( isset( $map['css'][ $relative_path ] ) ) {
      return $map['css'][ $relative_path ];
    }

    if ( 'variables.css' === $relative_path ) {
      return [];
    }

    if ( 'shared/focus-visible.css' === $relative_path || 'shared/global-focus.css' === $relative_path ) {
      return [ self::handle_for( 'css', 'variables.css' ) ];
    }

    if (
      str_starts_with( $relative_path, 'shortcodes/' )
      || str_starts_with( $relative_path, 'hooks/' )
      || str_starts_with( $relative_path, 'shared/' )
    ) {
      $deps = [ self::handle_for( 'css', 'variables.css' ) ];
      if ( 'shared/focus-visible.css' !== $relative_path && 'shared/global-focus.css' !== $relative_path ) {
        $deps[] = self::handle_for( 'css', 'shared/focus-visible.css' );
      }
      return $deps;
    }

    return [];
  }

  /**
   * @return list<string>
   */
  private static function script_dependencies( string $relative_path ): array {
    $map = self::dependency_map();
    if ( isset( $map['js'][ $relative_path ] ) ) {
      return $map['js'][ $relative_path ];
    }

    if ( 'shared/a11y.js' === $relative_path ) {
      return [];
    }

    if ( 'shared/focus-contrast.js' === $relative_path ) {
      return [ self::handle_for( 'js', 'shared/a11y.js' ) ];
    }

    if ( str_starts_with( $relative_path, 'shortcodes/' ) || str_starts_with( $relative_path, 'hooks/' ) ) {
      $deps = [ 'jquery' ];
      if ( self::asset_exists( 'js', 'shared/a11y.js' ) ) {
        $deps[] = self::handle_for( 'js', 'shared/a11y.js' );
      }
      return $deps;
    }

    return [];
  }

  /**
   * Product plugins may filter this later; framework ships with no hard-coded asset graph.
   *
   * @return array{css: array<string, list<string>>, js: array<string, list<string>>}
   */
  private static function dependency_map(): array {
    return [
      'css' => [],
      'js' => [],
    ];
  }

  private static function assets_directory_path( string $type ): string {
    if ( null === self::$assets_root_path ) {
      self::init_asset_roots();
    }

    return self::$assets_root_path[ $type ] ?? '';
  }

  private static function assets_directory_url( string $type ): string {
    if ( null === self::$assets_root_url ) {
      self::init_asset_roots();
    }

    return self::$assets_root_url[ $type ] ?? '';
  }

  private static function asset_exists( string $type, string $relative_path ): bool {
    $path = self::assets_directory_path( $type ) . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path );

    return is_readable( $path );
  }

  private static function init_asset_roots(): void {
    $plugin_file = PluginContext::identity()->pluginFile();
    $base_path = plugin_dir_path( $plugin_file );
    $base_url = plugin_dir_url( $plugin_file );

    self::$assets_root_path = [
      'css' => $base_path . 'assets/css/',
      'js' => $base_path . 'assets/js/',
    ];
    self::$assets_root_url = [
      'css' => $base_url . 'assets/css/',
      'js' => $base_url . 'assets/js/',
    ];
  }

  private static function handle_prefix(): string {
    return PluginContext::identity()->handlePrefix();
  }
}
