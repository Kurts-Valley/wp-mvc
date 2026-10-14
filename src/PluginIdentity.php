<?php
declare(strict_types=1);

namespace KurtCampbell\WpMvc;

/**
 * Per-plugin identity for the fixed {@see KurtCampbell\WpMvc} framework.
 *
 * Implement in each product plugin. Do not rename this framework namespace.
 */
interface PluginIdentity {
  /**
   * Absolute path to the main plugin PHP file.
   */
  public function pluginFile(): string;

  /**
   * Plugin slug (directory / text-domain style), e.g. my-plugin.
   */
  public function slug(): string;

  /**
   * gettext text domain.
   */
  public function textDomain(): string;

  /**
   * Plugin-owned DB table segment after {$wpdb->prefix}, e.g. mvp_.
   */
  public function tablePrefix(): string;

  /**
   * Asset handle prefix for {@see System\AssetRegistry}.
   */
  public function handlePrefix(): string;

  /**
   * Option key for shortcode admin settings.
   */
  public function shortcodeSettingsOptionKey(): string;

  /**
   * Option key for integration admin settings.
   */
  public function integrationSettingsOptionKey(): string;

  /**
   * Option key storing the installed DB schema version.
   */
  public function dbVersionOptionKey(): string;

  /**
   * Current DB schema version string for migrations.
   */
  public function dbVersion(): string;

  /**
   * Top-level admin menu slug / parent for submenus.
   */
  public function adminParentSlug(): string;

  /**
   * Top-level admin menu title (already translated if needed).
   */
  public function adminMenuTitle(): string;

  /**
   * Dashicon or image URL for the top-level menu.
   */
  public function adminMenuIcon(): string;

  /**
   * Position for add_menu_page.
   */
  public function adminMenuPosition(): int;

  /**
   * PSR-4 namespace prefix for app Model subclasses, with trailing backslash.
   *
   * Example: KurtCampbell\MvcPluginStarter\Models\
   */
  public function modelsNamespace(): string;
}
