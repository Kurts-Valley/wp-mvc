<?php
declare(strict_types=1);

namespace KurtCampbell\WpMvc\Controllers;

/**
 * Base class for internal WordPress hook registrars (actions and filters).
 */
abstract class HookController extends BaseController {
  /**
   * Registers WordPress actions and filters for this hook controller.
   */
  abstract public function register(): void;
}
