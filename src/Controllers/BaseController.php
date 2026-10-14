<?php
declare(strict_types=1);

namespace KurtCampbell\WpMvc\Controllers;

use KurtCampbell\WpMvc\Views\View;

/**
 * Shared view rendering and capability checks for all controllers.
 */
abstract class BaseController implements ControllerInterface {
  protected View $view;

  /**
   * Creates the shared {@see View} for template rendering.
   */
  public function __construct() {
    $this->view = new View();
  }

  /**
   * Renders a PHP template path relative to includes/views/.
   *
   * @param array<string, mixed> $vars Variables extracted into the template scope.
   * @return string
   */
  protected function render( string $view, array $vars = [] ): string {
    return $this->view->render( $view, $vars );
  }

  /**
   * @param string $capability WordPress capability key.
   * @return bool
   */
  protected function can( $capability ): bool {
    return current_user_can( $capability );
  }
}
