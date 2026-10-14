<?php
declare(strict_types=1);

namespace KurtCampbell\WpMvc;

use RuntimeException;

/**
 * Holds the booted {@see PluginIdentity} for the active product plugin.
 */
final class PluginContext {
  private static ?PluginIdentity $identity = null;

  /**
   * Boots the framework context once per request with the product plugin identity.
   *
   * @param PluginIdentity $identity Product plugin identity implementation.
   * @return void
   * @throws RuntimeException When boot is called twice with a different identity.
   */
  public static function boot( PluginIdentity $identity ): void {
    if ( null !== self::$identity ) {
      if ( self::$identity::class !== $identity::class
        || self::$identity->pluginFile() !== $identity->pluginFile()
      ) {
        throw new RuntimeException( 'KurtCampbell\\WpMvc\\PluginContext already booted.' );
      }

      return;
    }

    self::$identity = $identity;
  }

  /**
   * Whether {@see boot()} has been called.
   */
  public static function isBooted(): bool {
    return null !== self::$identity;
  }

  /**
   * Returns the booted plugin identity.
   *
   * @throws RuntimeException When context has not been booted.
   */
  public static function identity(): PluginIdentity {
    if ( null === self::$identity ) {
      throw new RuntimeException(
        'KurtCampbell\\WpMvc\\PluginContext not booted. Call PluginContext::boot() from the plugin bootstrap.'
      );
    }

    return self::$identity;
  }

  /**
   * Absolute filesystem path to the product plugin root (trailing slash).
   */
  public static function pluginRoot(): string {
    return plugin_dir_path( self::identity()->pluginFile() );
  }

  /**
   * Absolute filesystem path to includes/ under the product plugin (trailing slash).
   */
  public static function includesDirectory(): string {
    return self::pluginRoot() . 'includes/';
  }

  /**
   * Absolute filesystem path to includes/views/ under the product plugin (trailing slash).
   */
  public static function viewsDirectory(): string {
    return self::includesDirectory() . 'views/';
  }
}
