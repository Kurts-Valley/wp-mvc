<?php
declare(strict_types=1);

namespace KurtCampbell\WpMvc\Controllers;

/**
 * Contract for controllers that register WordPress hooks or shortcodes.
 */
interface ControllerInterface {
  /**
   * Bind with any WordPress hooks or actions.
   */
  public function register(): void;
}
