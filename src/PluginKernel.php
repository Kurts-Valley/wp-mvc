<?php
declare(strict_types=1);

namespace KurtCampbell\WpMvc;

use KurtCampbell\WpMvc\Controllers\AdminController;
use KurtCampbell\WpMvc\Controllers\ControllerInterface;
use KurtCampbell\WpMvc\Controllers\DashboardAdminController;
use KurtCampbell\WpMvc\Controllers\HookController;
use KurtCampbell\WpMvc\Controllers\IntegrationController;
use KurtCampbell\WpMvc\Controllers\ShortcodeController;
use KurtCampbell\WpMvc\Models\Model;
use KurtCampbell\WpMvc\System\ActivationTask;
use KurtCampbell\WpMvc\System\ActivationTaskInterface;
use KurtCampbell\WpMvc\System\AssetRegistry;

/**
 * Discovers product-plugin controllers/models and registers WordPress lifecycle hooks.
 *
 * Requires {@see PluginContext::boot()} before construction.
 */
final class PluginKernel {
  /**
   * @var array<ControllerInterface>
   */
  private array $controllers = [];

  /**
   * @var array<AdminController>
   */
  private array $admin_pages = [];

  private string $includes_directory;

  private PluginIdentity $identity;

  /**
   * Loads utilities, discovers controllers, and registers core WordPress hooks.
   */
  public function __construct() {
    $this->identity = PluginContext::identity();
    $this->includes_directory = PluginContext::includesDirectory();

    $this->require_discovered_utility_files();

    $this->admin_pages = $this->discover_admin_pages();

    $this->controllers = array_merge(
      $this->discover_shortcode_controllers(),
      $this->discover_hook_controllers(),
      $this->discover_integration_controllers(),
    );

    add_action( 'admin_init', [ $this, 'migrate_models' ], 1 );
    add_action( 'wp_enqueue_scripts', [ AssetRegistry::class, 'register_frontend_assets' ] );
    add_action( 'admin_enqueue_scripts', [ AssetRegistry::class, 'register_frontend_assets' ] );
    add_action( 'admin_enqueue_scripts', [ AssetRegistry::class, 'enqueue_shared_a11y' ], 5 );
  }

  /**
   * Registers discovered shortcode, hook, and integration controllers.
   */
  public function run(): void {
    $this->register_controllers();
  }

  /**
   * Registers all discovered controllers and the admin menu.
   */
  public function register_controllers(): void {
    foreach ( $this->controllers as $controller ) {
      $controller->register();
    }

    $this->register_admin_menu();
  }

  /**
   * Hooks {@see add_plugin_admin_menu()} so submenus run after the parent menu exists.
   */
  private function register_admin_menu(): void {
    add_action( 'admin_menu', [ $this, 'add_plugin_admin_menu' ] );
  }

  /**
   * Creates the top-level menu and registers discovered admin subpages.
   */
  public function add_plugin_admin_menu(): void {
    $dashboard = $this->dashboard_admin_page();
    $menu_callback = $dashboard instanceof DashboardAdminController
      ? [ $dashboard, 'render_page' ]
      : '';

    add_menu_page(
      $this->identity->adminMenuTitle(),
      $this->identity->adminMenuTitle(),
      'manage_options',
      $this->identity->adminParentSlug(),
      $menu_callback,
      $this->identity->adminMenuIcon(),
      $this->identity->adminMenuPosition()
    );

    foreach ( $this->admin_pages as $page ) {
      $page->register();
    }
  }

  /**
   * Returns the dashboard admin page controller when registered.
   */
  private function dashboard_admin_page(): ?AdminController {
    foreach ( $this->admin_pages as $page ) {
      if ( $page instanceof DashboardAdminController ) {
        return $page;
      }
    }

    return null;
  }

  /**
   * Migrate the model tables to the WordPress database.
   */
  public function migrate_models(): void {
    $installed_version = (string) get_option( $this->identity->dbVersionOptionKey(), '' );
    $version_changed = $this->identity->dbVersion() !== $installed_version;
    $tables_missing = $this->managed_tables_missing();

    if ( ! $version_changed && ! $tables_missing ) {
      return;
    }

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    foreach ( $this->discover_models() as $model ) {
      if ( ! $model->is_managed_by_db_delta() ) {
        continue;
      }

      $model->prepare_migrate();
      dbDelta( $model->create_table_sql() );
    }

    $this->create_missing_tables_fallback();

    if ( ! $this->managed_tables_missing() ) {
      update_option( $this->identity->dbVersionOptionKey(), $this->identity->dbVersion() );
      $this->run_model_after_migrate_callbacks();
    }
  }

  /**
   * Whether any plugin-managed model table is absent.
   */
  private function managed_tables_missing(): bool {
    foreach ( $this->discover_models() as $model ) {
      if ( ! $model->is_managed_by_db_delta() ) {
        continue;
      }

      if ( ! $model->table_exists() ) {
        return true;
      }
    }

    return false;
  }

  /**
   * Direct CREATE TABLE fallback when dbDelta fails to create a managed table.
   */
  private function create_missing_tables_fallback(): void {
    global $wpdb;

    foreach ( $this->discover_models() as $model ) {
      if ( ! $model->is_managed_by_db_delta() ) {
        continue;
      }

      if ( $model->table_exists() ) {
        continue;
      }

      // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
      $result = $wpdb->query( $model->create_table_if_not_exists_sql() );

      if ( false === $result && ! empty( $wpdb->last_error ) ) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log(
          sprintf(
            '%s: failed to create table %s: %s',
            $this->identity->slug(),
            $model->get_table_name(),
            $wpdb->last_error
          )
        );
      }
    }
  }

  /**
   * Runs per-model post-migration hooks.
   */
  private function run_model_after_migrate_callbacks(): void {
    foreach ( $this->discover_models() as $model ) {
      if ( ! $model->is_managed_by_db_delta() || ! $model->table_exists() ) {
        continue;
      }

      $model->after_migrate();
    }
  }

  /**
   * Runs migrations and discovered activation tasks.
   */
  public function activate(): void {
    $this->migrate_models();
    $this->run_activation_tasks();
  }

  /**
   * Runs discovered deactivation tasks.
   */
  public function deactivate(): void {
    $this->run_deactivation_tasks();
  }

  /**
   * @return array<ActivationTaskInterface>
   */
  private function discover_activation_tasks(): array {
    $tasks = [];
    $activations_directory = $this->includes_directory . 'activations';
    $task_files = array_values(
      array_unique(
        array_merge(
          glob( $activations_directory . '/*.php' ) ?: [],
          glob( $activations_directory . '/*/*.php' ) ?: []
        )
      )
    );

    sort( $task_files, SORT_STRING );

    foreach ( $task_files as $file ) {
      if ( ! is_string( $file ) || ! is_readable( $file ) ) {
        continue;
      }

      $before_classes = get_declared_classes();
      require_once $file;
      $after_classes = get_declared_classes();
      $new_classes = array_diff( $after_classes, $before_classes );

      foreach ( $new_classes as $class_name ) {
        if ( ! is_subclass_of( $class_name, ActivationTask::class ) ) {
          continue;
        }

        $ref = new \ReflectionClass( $class_name );
        if ( $ref->isAbstract() ) {
          continue;
        }

        $instance = new $class_name();
        if ( $instance instanceof ActivationTaskInterface ) {
          $tasks[] = $instance;
        }
      }
    }

    return $tasks;
  }

  private function run_activation_tasks(): void {
    foreach ( $this->discover_activation_tasks() as $task ) {
      $task->activate();
    }
  }

  private function run_deactivation_tasks(): void {
    foreach ( $this->discover_activation_tasks() as $task ) {
      if ( $task instanceof ActivationTask ) {
        $task->deactivate();
      }
    }
  }

  /**
   * Drops plugin-managed model tables and clears the schema version option.
   */
  public function drop_model_tables(): void {
    global $wpdb;

    foreach ( $this->discover_models() as $model ) {
      if ( ! $model->is_managed_by_db_delta() ) {
        continue;
      }
      $table_name = $model->get_table_name();
      // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
      $wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" );
    }

    delete_option( $this->identity->dbVersionOptionKey() );
  }

  private function require_discovered_utility_files(): void {
    $utilities_directory = $this->includes_directory . 'utilities';
    $files = glob( $utilities_directory . '/*.php' );

    if ( ! is_array( $files ) || [] === $files ) {
      return;
    }

    sort( $files, SORT_STRING );

    foreach ( $files as $file ) {
      require_once $file;
    }
  }

  /**
   * @return array<ShortcodeController>
   */
  private function discover_shortcode_controllers(): array {
    $controllers = [];

    foreach ( ShortcodeController::shortcode_controller_php_paths() as $file ) {
      $before_classes = get_declared_classes();
      require_once $file;
      $after_classes = get_declared_classes();
      $new_classes = array_diff( $after_classes, $before_classes );

      foreach ( $new_classes as $class_name ) {
        if ( ! is_subclass_of( $class_name, ShortcodeController::class ) ) {
          continue;
        }

        $ref = new \ReflectionClass( $class_name );
        if ( $ref->isAbstract() ) {
          continue;
        }

        $instance = new $class_name();
        if ( $instance instanceof ShortcodeController ) {
          $controllers[] = $instance;
        }
      }
    }

    return $controllers;
  }

  /**
   * @return array<AdminController>
   */
  private function discover_admin_pages(): array {
    $pages = [];
    $pages_directory = $this->includes_directory . 'controllers/admin';
    $controller_files = glob( $pages_directory . '/*.php' );

    if ( ! is_array( $controller_files ) ) {
      return $pages;
    }

    foreach ( $controller_files as $file ) {
      $before_classes = get_declared_classes();
      require_once $file;
      $after_classes = get_declared_classes();
      $new_classes = array_diff( $after_classes, $before_classes );

      foreach ( $new_classes as $class_name ) {
        if ( ! is_subclass_of( $class_name, AdminController::class ) ) {
          continue;
        }

        $ref = new \ReflectionClass( $class_name );
        if ( $ref->isAbstract() ) {
          continue;
        }

        $instance = new $class_name();
        if ( $instance instanceof AdminController ) {
          $pages[] = $instance;
        }
      }
    }

    return $pages;
  }

  /**
   * @return array<HookController>
   */
  private function discover_hook_controllers(): array {
    $controllers = [];
    $controllers_directory = $this->includes_directory . 'controllers/hooks';
    $controller_files = glob( $controllers_directory . '/*.php' );

    if ( ! is_array( $controller_files ) ) {
      return $controllers;
    }

    foreach ( $controller_files as $file ) {
      $before_classes = get_declared_classes();
      require_once $file;
      $after_classes = get_declared_classes();
      $new_classes = array_diff( $after_classes, $before_classes );

      foreach ( $new_classes as $class_name ) {
        if ( ! is_subclass_of( $class_name, HookController::class ) ) {
          continue;
        }

        if ( is_subclass_of( $class_name, IntegrationController::class ) ) {
          continue;
        }

        $ref = new \ReflectionClass( $class_name );
        if ( $ref->isAbstract() ) {
          continue;
        }

        $instance = new $class_name();
        if ( $instance instanceof HookController ) {
          $controllers[] = $instance;
        }
      }
    }

    return $controllers;
  }

  /**
   * @return array<IntegrationController>
   */
  private function discover_integration_controllers(): array {
    $controllers = [];
    $controllers_directory = $this->includes_directory . 'controllers/integrations';
    $controller_files = glob( $controllers_directory . '/*.php' );

    if ( ! is_array( $controller_files ) ) {
      return $controllers;
    }

    foreach ( $controller_files as $file ) {
      $before_classes = get_declared_classes();
      require_once $file;
      $after_classes = get_declared_classes();
      $new_classes = array_diff( $after_classes, $before_classes );

      foreach ( $new_classes as $class_name ) {
        if ( ! is_subclass_of( $class_name, IntegrationController::class ) ) {
          continue;
        }

        $ref = new \ReflectionClass( $class_name );
        if ( $ref->isAbstract() ) {
          continue;
        }

        $instance = new $class_name();
        if ( $instance instanceof IntegrationController ) {
          $controllers[] = $instance;
        }
      }
    }

    return $controllers;
  }

  /**
   * @return array<Model>
   */
  private function discover_models(): array {
    static $cached_models = null;
    if ( null !== $cached_models ) {
      return $cached_models;
    }

    $models_directory = $this->includes_directory . 'models';
    $model_files = array_values(
      array_unique(
        array_merge(
          glob( $models_directory . '/*.php' ) ?: [],
          glob( $models_directory . '/*/*.php' ) ?: []
        )
      )
    );

    foreach ( $model_files as $file ) {
      if ( ! is_string( $file ) || ! is_readable( $file ) ) {
        continue;
      }

      require_once $file;
    }

    $models = [];
    $namespace_prefix = $this->identity->modelsNamespace();

    foreach ( get_declared_classes() as $class_name ) {
      if ( ! str_starts_with( $class_name, $namespace_prefix ) ) {
        continue;
      }

      if ( ! is_subclass_of( $class_name, Model::class ) ) {
        continue;
      }

      $reflection = new \ReflectionClass( $class_name );
      if ( $reflection->isAbstract() ) {
        continue;
      }

      $instance = new $class_name();
      if ( $instance instanceof Model ) {
        $models[] = $instance;
      }
    }

    $cached_models = $models;

    return $cached_models;
  }
}
