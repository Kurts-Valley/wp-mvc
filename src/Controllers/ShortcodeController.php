<?php
declare(strict_types=1);

namespace KurtCampbell\WpMvc\Controllers;

use KurtCampbell\WpMvc\PluginContext;
use KurtCampbell\WpMvc\System\SettingsSchema;

/**
 * Base class for a single shortcode: set {@see $tag} and {@see $callback} then {@see register()}.
 * Site-wide defaults are stored via {@see settings_option_key()}; inline attributes override saved values.
 */
abstract class ShortcodeController extends BaseController {
  /**
   * @var array<int, ShortcodeController>|null
   */
  private static $discovered_instances = null;

  /**
   * @var array<string, array{label: string, fields: array<string, array<string, mixed>>}>|null
   */
  private static $admin_schema_map_cache = null;

  protected string $tag = '';
  protected string $callback = '';

  /**
   * Admin UI schema (label + fields). Null means no Shortcodes admin row.
   * Assign translated arrays in the subclass constructor; {@see __()} is not allowed in property defaults.
   *
   * @var array{label: string, fields: array<string, array<string, mixed>>}|null
   */
  protected ?array $settings_schema = null;

  /**
   * Default shortcode attributes (merged with saved options and inline atts).
   *
   * @var array<string, int|string|bool>
   */
  protected array $default_atts = [];

  /**
   * Option key for shortcode settings from {@see PluginIdentity}.
   */
  public static function settings_option_key(): string {
    return PluginContext::identity()->shortcodeSettingsOptionKey();
  }

  /**
   * Shortcode tag string.
   */
  public function get_tag(): string {
    return $this->tag;
  }

  /**
   * Merge defaults, saved option values, and parsed shortcode attributes (inline wins).
   *
   * @param array<string, mixed> $atts
   * @return array<string, mixed>
   */
  protected function merge_shortcode_atts( array $atts ): array {
    return self::merge_atts_for_tag( $this->tag, $atts );
  }

  /**
   * Absolute paths to every `*.php` under includes/controllers/shortcodes (recursive).
   *
   * Files under shortcodes/partials/ are ordered before other shortcode controller files.
   *
   * @return list<string>
   */
  public static function shortcode_controller_php_paths(): array {
    $directory = PluginContext::includesDirectory() . 'controllers/shortcodes';
    if ( ! is_dir( $directory ) ) {
      return [];
    }

    $paths = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS )
    );

    foreach ( $iterator as $fileinfo ) {
      if ( ! $fileinfo->isFile() ) {
        continue;
      }
      if ( strcasecmp( $fileinfo->getExtension(), 'php' ) !== 0 ) {
        continue;
      }
      $paths[] = $fileinfo->getPathname();
    }

    $partials_root = $directory . DIRECTORY_SEPARATOR . 'partials';
    $partials_real = is_dir( $partials_root ) ? realpath( $partials_root ) : false;

    $partial_paths = [];
    $other_paths = [];
    foreach ( $paths as $path ) {
      $resolved = realpath( $path );
      if ( false !== $partials_real && false !== $resolved ) {
        $sep = DIRECTORY_SEPARATOR;
        if ( $resolved === $partials_real || str_starts_with( $resolved, $partials_real . $sep ) ) {
          $partial_paths[] = $path;
          continue;
        }
      }

      $other_paths[] = $path;
    }

    sort( $partial_paths, SORT_STRING );
    sort( $other_paths, SORT_STRING );

    return array_merge( $partial_paths, $other_paths );
  }

  /**
   * @return array<int, ShortcodeController>
   */
  private static function discovered_shortcode_controllers(): array {
    if ( null !== self::$discovered_instances ) {
      return self::$discovered_instances;
    }

    $controllers = [];
    $controller_files = self::shortcode_controller_php_paths();

    if ( [] === $controller_files ) {
      self::$discovered_instances = [];
      return self::$discovered_instances;
    }

    foreach ( $controller_files as $file ) {
      require_once $file;
      $resolved = realpath( $file );
      if ( false === $resolved ) {
        continue;
      }
      foreach ( get_declared_classes() as $class_name ) {
        if ( ! is_subclass_of( $class_name, self::class, true ) ) {
          continue;
        }
        $ref = new \ReflectionClass( $class_name );
        if ( $ref->isAbstract() || $ref->getFileName() !== $resolved ) {
          continue;
        }
        $controllers[] = new $class_name();
      }
    }

    self::$discovered_instances = $controllers;
    return self::$discovered_instances;
  }

  /**
   * @return array<string, array{label: string, fields: array<string, array<string, mixed>>}>
   */
  public static function all_shortcodes_admin_schema(): array {
    if ( null !== self::$admin_schema_map_cache ) {
      return self::$admin_schema_map_cache;
    }

    $out = [];
    foreach ( self::discovered_shortcode_controllers() as $controller ) {
      $entry = $controller->settings_schema;
      if (
        null !== $entry
        && isset( $entry['fields'] )
        && is_array( $entry['fields'] )
        && [] !== $entry['fields']
      ) {
        $out[ $controller->get_tag() ] = $entry;
      }
    }

    ksort( $out );
    self::$admin_schema_map_cache = $out;
    return self::$admin_schema_map_cache;
  }

  /**
   * @return array<string, mixed>
   */
  public static function get_all_saved_settings(): array {
    $raw = get_option( self::settings_option_key(), [] );
    return is_array( $raw ) ? $raw : [];
  }

  /**
   * @return array<string, string|int|bool>
   */
  public static function get_saved_settings_for_tag( string $tag ): array {
    $schema = self::all_shortcodes_admin_schema();
    if ( ! isset( $schema[ $tag ]['fields'] ) ) {
      return [];
    }
    $allowed = array_keys( $schema[ $tag ]['fields'] );
    $all = self::get_all_saved_settings();
    if ( ! isset( $all[ $tag ] ) || ! is_array( $all[ $tag ] ) ) {
      return [];
    }
    $out = [];
    foreach ( $allowed as $key ) {
      if ( array_key_exists( $key, $all[ $tag ] ) ) {
        $out[ $key ] = $all[ $tag ][ $key ];
      }
    }
    return $out;
  }

  /**
   * @return array<string, int|string|bool>
   */
  public static function code_defaults_for_tag( string $tag ): array {
    foreach ( self::discovered_shortcode_controllers() as $controller ) {
      if ( $controller->get_tag() === $tag ) {
        return $controller->default_atts;
      }
    }
    return [];
  }

  /**
   * @param array<string, mixed> $atts Raw attributes from the shortcode callback.
   * @return array<string, mixed>
   */
  public static function merge_atts_for_tag( string $tag, array $atts ): array {
    $defaults = self::code_defaults_for_tag( $tag );
    if ( [] === $defaults ) {
      return $atts;
    }
    $saved = self::get_saved_settings_for_tag( $tag );
    $merged = array_merge( $defaults, $saved, $atts );
    return shortcode_atts( $defaults, $merged, $tag );
  }

  /**
   * @param array<string, string|int|float|bool> $values
   */
  public static function save_settings_for_tag( string $tag, array $values ): void {
    $all = self::get_all_saved_settings();
    $all[ $tag ] = $values;
    $option_key = self::settings_option_key();
    if ( ! get_option( $option_key, false ) ) {
      add_option( $option_key, $all, '', 'no' );
    } else {
      update_option( $option_key, $all, false );
    }
  }

  /**
   * @param array<string, mixed> $post_slice
   * @return array<string, string|int|float|bool>
   */
  public static function sanitize_settings_submission( string $tag, array $post_slice ): array {
    $schema = self::all_shortcodes_admin_schema();
    if ( ! isset( $schema[ $tag ]['fields'] ) ) {
      return [];
    }
    $defaults = self::code_defaults_for_tag( $tag );
    return SettingsSchema::sanitize_fields( $schema[ $tag ]['fields'], $defaults, $post_slice );
  }

  /**
   * Whether the ID refers to a published WordPress Page.
   */
  public static function is_published_page_id( int $post_id ): bool {
    return SettingsSchema::is_published_page_id( $post_id );
  }

  /**
   * @return list<array{id: int, type: string, title: array{rendered: string}, link: string}>
   */
  public static function search_published_pages_for_picker( string $search, int $limit = 100 ): array {
    $search = trim( $search );
    $limit = max( 1, min( 100, $limit ) );

    $query_args = [
      'post_type' => 'page',
      'post_status' => 'publish',
      'posts_per_page' => $limit,
      'orderby' => 'title',
      'order' => 'ASC',
      'no_found_rows' => true,
      'ignore_sticky_posts' => true,
    ];

    if ( ! empty( $search ) ) {
      $query_args['s'] = $search;
    }

    $query = new \WP_Query( $query_args );

    $out = [];
    foreach ( $query->posts as $post ) {
      if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type ) {
        continue;
      }
      if ( ! self::is_published_page_id( (int) $post->ID ) ) {
        continue;
      }

      $link = get_permalink( $post );
      $out[] = [
        'id' => (int) $post->ID,
        'type' => 'page',
        'title' => [
          'rendered' => get_the_title( $post ),
        ],
        'link' => is_string( $link ) ? $link : '',
      ];
    }

    return $out;
  }

  /**
   * @return list<int>
   */
  public static function published_page_ids_from_setting( string $raw ): array {
    return SettingsSchema::published_page_ids_from_setting( $raw );
  }

  /**
   * @param string|array<int|string> $raw
   */
  public static function sanitize_page_ids_setting( $raw ): string {
    return SettingsSchema::sanitize_page_ids_setting( $raw );
  }

  /**
   * @return list<int>
   */
  public static function parse_page_ids_setting( string $raw ): array {
    return SettingsSchema::parse_page_ids_setting( $raw );
  }

  /**
   * Whether the shortcode schema for a tag includes a field of the given type.
   */
  public static function schema_has_field_type( string $tag, string $field_type ): bool {
    $schema = self::all_shortcodes_admin_schema();
    if ( ! isset( $schema[ $tag ]['fields'] ) || ! is_array( $schema[ $tag ]['fields'] ) ) {
      return false;
    }
    foreach ( $schema[ $tag ]['fields'] as $meta ) {
      if ( is_array( $meta ) && isset( $meta['type'] ) && $field_type === (string) $meta['type'] ) {
        return true;
      }
    }

    return false;
  }

  /**
   * @return array<int, array{id: int, title: string}>
   */
  public static function page_ids_for_picker( string $comma_ids ): array {
    $ids = self::parse_page_ids_setting( $comma_ids );
    if ( empty( $ids ) ) {
      return [];
    }

    $posts = get_posts(
      [
        'post_type' => 'page',
        'post_status' => 'publish',
        'post__in' => $ids,
        'orderby' => 'post__in',
        'posts_per_page' => count( $ids ),
        'no_found_rows' => true,
      ]
    );

    $out = [];
    foreach ( $posts as $post ) {
      if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type ) {
        continue;
      }
      if ( ! self::is_published_page_id( (int) $post->ID ) ) {
        continue;
      }
      $out[] = [
        'id' => (int) $post->ID,
        'title' => get_the_title( $post ),
      ];
    }

    return $out;
  }

  /**
   * @return array<int, string>
   */
  public static function discover_shortcode_tags_for_admin(): array {
    $tags = array_keys( self::all_shortcodes_admin_schema() );
    $tags = array_values(
      array_filter(
        $tags,
        static function ( string $tag ): bool {
          return shortcode_exists( $tag );
        }
      )
    );
    sort( $tags );
    return $tags;
  }

  /**
   * Registers the shortcode with WordPress when tag and callback are configured.
   */
  public function register(): void {
    if ( '' === $this->tag || '' === $this->callback ) {
      return;
    }

    add_shortcode( $this->tag, [ $this, $this->callback ] );
  }
}
