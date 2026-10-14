<?php
declare(strict_types=1);

namespace KurtCampbell\WpMvc\System;

use KurtCampbell\WpMvc\Views\View;

/**
 * Renders PHP templates under includes/views/emails/ via {@see View}.
 */
final class Email {
  private View $view;

  /**
   * @param View|null $view Optional View instance; defaults to a new View rooted at includes/views/.
   */
  public function __construct( ?View $view = null ) {
    $this->view = $view ?? new View();
  }

  /**
   * Renders a template relative to the emails/ views folder.
   *
   * @param string               $relative_path Path under emails/ (e.g. submission-client.php).
   * @param array<string, mixed> $vars          Variables extracted into the template.
   * @return string Captured HTML output.
   */
  public function render( string $relative_path, array $vars = [] ): string {
    $path = 'emails/' . ltrim( $relative_path, '/' );

    return $this->view->render( $path, $vars );
  }
}
