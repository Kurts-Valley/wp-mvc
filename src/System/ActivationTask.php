<?php
declare(strict_types=1);

namespace KurtCampbell\WpMvc\System;

/**
 * Optional work to run when the plugin is activated.
 */
interface ActivationTaskInterface {
  public function activate(): void;
}

/**
 * Base class for discovered activation tasks under includes/activations/.
 */
abstract class ActivationTask implements ActivationTaskInterface {
  /**
   * Optional cleanup when the plugin is deactivated.
   */
  public function deactivate(): void {
  }
}
