<?php
declare(strict_types=1);

namespace KurtCampbell\WpMvc\Controllers;

use KurtCampbell\WpMvc\PluginContext;
use KurtCampbell\WpMvc\System\SettingsSchema;

/**
 * Base class for third-party integrations with Integrations admin settings.
 */
abstract class IntegrationController extends HookController {
  /**
   * @var array<int, IntegrationController>|null
   */
  private static $discovered_instances = null;

  /**
   * @var array<string, array{label: string, fields: array<string, array<string, mixed>>}>|null
   */
  private static $admin_schema_map_cache = null;

  /**
   * Stable settings key (e.g. fluent-form). Empty when this integration has no admin settings.
   */
  protected string $settings_key = '';

  /**
   * @var array<string, int|string|bool>
   */
  protected array $default_settings = [];

  /**
   * Admin UI schema. Assign in subclass constructor; {@see __()} not allowed in property defaults.
   *
   * @var array{label: string, fields: array<string, array<string, mixed>>}|null
   */
  protected ?array $settings_schema = null;

  /**
   * Option key for integration settings from {@see PluginIdentity}.
   */
  public static function settings_option_key(): string {
    return PluginContext::identity()->integrationSettingsOptionKey();
  }

  public function get_settings_key(): string {
    return $this->settings_key;
  }

  /**
   * @return array<int, IntegrationController>
   */
  public static function discovered_integration_controllers(): array {
    if ( null !== self::$discovered_instances ) {
      return self::$discovered_instances;
    }

    $controllers = [];
    $directory = PluginContext::includesDirectory() . 'controllers/integrations';
    if ( ! is_dir( $directory ) ) {
      self::$discovered_instances = [];
      return self::$discovered_instances;
    }

    $controller_files = glob( $directory . '/*.php' );
    if ( ! is_array( $controller_files ) ) {
      self::$discovered_instances = [];
      return self::$discovered_instances;
    }

    sort( $controller_files, SORT_STRING );

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
  public static function all_integrations_admin_schema(): array {
    if ( null !== self::$admin_schema_map_cache ) {
      return self::$admin_schema_map_cache;
    }

    $out = [];
    foreach ( self::discovered_integration_controllers() as $controller ) {
      if ( '' === $controller->get_settings_key() ) {
        continue;
      }
      $entry = $controller->settings_schema;
      if (
        null !== $entry
        && isset( $entry['fields'] )
        && is_array( $entry['fields'] )
        && [] !== $entry['fields']
      ) {
        $out[ $controller->get_settings_key() ] = $entry;
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
  public static function get_saved_settings_for_key( string $key ): array {
    $schema = self::all_integrations_admin_schema();
    if ( ! isset( $schema[ $key ]['fields'] ) ) {
      return [];
    }
    $allowed = array_keys( $schema[ $key ]['fields'] );
    $all = self::get_all_saved_settings();
    if ( ! isset( $all[ $key ] ) || ! is_array( $all[ $key ] ) ) {
      return [];
    }
    $out = [];
    foreach ( $allowed as $field_key ) {
      if ( array_key_exists( $field_key, $all[ $key ] ) ) {
        $out[ $field_key ] = $all[ $key ][ $field_key ];
      }
    }
    return $out;
  }

  /**
   * @return array<string, int|string|bool>
   */
  public static function default_settings_for_key( string $key ): array {
    foreach ( self::discovered_integration_controllers() as $controller ) {
      if ( $controller->get_settings_key() === $key ) {
        return $controller->default_settings;
      }
    }
    return [];
  }

  /**
   * @return array<string, mixed>
   */
  public static function merge_settings_for_key( string $key ): array {
    $defaults = self::default_settings_for_key( $key );
    if ( [] === $defaults ) {
      return [];
    }
    $saved = self::get_saved_settings_for_key( $key );
    return array_merge( $defaults, $saved );
  }

  /**
   * @param array<string, string|int|float|bool> $values
   */
  public static function save_settings_for_key( string $key, array $values ): void {
    $all = self::get_all_saved_settings();
    $all[ $key ] = $values;
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
  public static function sanitize_settings_submission( string $key, array $post_slice ): array {
    $schema = self::all_integrations_admin_schema();
    if ( ! isset( $schema[ $key ]['fields'] ) ) {
      return [];
    }
    $defaults = self::default_settings_for_key( $key );
    return SettingsSchema::sanitize_fields( $schema[ $key ]['fields'], $defaults, $post_slice );
  }

  /**
   * @return array<int, string>
   */
  public static function discover_integration_keys_for_admin(): array {
    return array_keys( self::all_integrations_admin_schema() );
  }

  public static function schema_has_field_type( string $key, string $field_type ): bool {
    $schema = self::all_integrations_admin_schema();
    if ( ! isset( $schema[ $key ]['fields'] ) || ! is_array( $schema[ $key ]['fields'] ) ) {
      return false;
    }
    foreach ( $schema[ $key ]['fields'] as $meta ) {
      if ( is_array( $meta ) && isset( $meta['type'] ) && $field_type === (string) $meta['type'] ) {
        return true;
      }
    }
    return false;
  }
}
