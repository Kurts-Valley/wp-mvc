<?php
declare(strict_types=1);

namespace KurtCampbell\WpMvc\Views;

use KurtCampbell\WpMvc\PluginContext;

/**
 * Loads PHP templates from the product plugin views directory with scoped variables.
 */
class View {
  private string $views_directory;

  /**
   * @param string|null $views_directory Absolute path to the views root; default from {@see PluginContext}.
   */
  public function __construct( ?string $views_directory = null ) {
    if ( null !== $views_directory ) {
      $this->views_directory = trailingslashit( $views_directory );
      return;
    }

    $this->views_directory = trailingslashit( PluginContext::viewsDirectory() );
  }

  /**
   * Renders a PHP template under the views root and returns the captured output.
   *
   * @param string               $relative_path Path relative to views, e.g. shortcodes/example.php.
   * @param array<string, mixed> $vars          Variables available in the template.
   * @return string
   */
  public function render( string $relative_path, array $vars = [] ): string {
    $path = $this->views_directory . ltrim( $relative_path, '/' );
    if ( ! is_readable( $path ) ) {
      return '';
    }

    extract( $vars, EXTR_SKIP );
    ob_start();
    include $path;

    return (string) ob_get_clean();
  }
}
