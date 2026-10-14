<?php
declare(strict_types=1);

namespace KurtCampbell\WpMvc\Controllers;

use KurtCampbell\WpMvc\PluginContext;

/**
 * Single admin submenu page: registers the menu and renders one PHP template with prepared variables.
 */
abstract class AdminController extends BaseController {
  protected string $parent_slug = '';
  protected string $page_title = '';
  protected string $menu_title = '';
  protected string $capability = 'manage_options';
  protected string $menu_slug = '';
  protected string $template = '';

  /**
   * Adds a submenu page under {@see $parent_slug} pointing to {@see render_page()}.
   */
  public function register(): void {
    add_submenu_page(
      $this->resolved_parent_slug(),
      $this->page_title,
      $this->menu_title,
      $this->capability,
      $this->menu_slug,
      [ $this, 'render_page' ]
    );
  }

  /**
   * Entry point for admin pages. Subclasses override {@see prepare_page()} to process
   * the request and return variables for the view (empty array if none).
   */
  final public function render_page(): void {
    $identity = PluginContext::identity();

    if ( ! $this->can( $this->capability ) ) {
      wp_die(
        esc_html__( 'You are not allowed to access this page.', $identity->textDomain() )
      );
    }

    $vars = $this->prepare_page();
    echo $this->render( $this->template, $vars );
  }

  /**
   * Process POST/actions and build variables for the template. Default: no variables.
   *
   * @return array<string, mixed>
   */
  protected function prepare_page(): array {
    return [];
  }

  /**
   * Parent menu slug from the subclass or {@see PluginIdentity::adminParentSlug()}.
   */
  protected function resolved_parent_slug(): string {
    if ( '' !== $this->parent_slug ) {
      return $this->parent_slug;
    }

    return PluginContext::identity()->adminParentSlug();
  }
}
