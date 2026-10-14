<?php
declare(strict_types=1);

namespace KurtCampbell\WpMvc\Models;

use KurtCampbell\WpMvc\PluginContext;

/**
 * Base class for plugin-owned database tables (wpdb, schema, CRUD helpers).
 */
abstract class Model {
  protected \wpdb $db;

  /**
   * Override to hardcode a prefix; empty string uses {@see PluginIdentity::tablePrefix()}.
   */
  protected string $plugin_prefix = '';

  protected string $table = '';

  /**
   * When false, {@see \KurtCampbell\WpMvc\PluginKernel::migrate_models()} skips this model.
   * Override {@see prepare_migrate()} or {@see after_migrate()} for per-table migration hooks.
   * Use for tables created outside the plugin (e.g. imported legacy data).
   */
  protected bool $managed_by_db_delta = true;

  /**
   * @param \wpdb|null $db Optional database instance; defaults to the global $wpdb.
   */
  public function __construct( ?\wpdb $db = null ) {
    global $wpdb;

    if ( null !== $db ) {
      $this->db = $db;
      return;
    }

    $this->db = $wpdb;
  }

  /**
   * Full prefixed table name for this model.
   */
  protected function table_name(): string {
    return $this->db->prefix . $this->resolved_plugin_prefix() . $this->table;
  }

  /**
   * Public accessor for migrations and raw SQL outside the model.
   */
  public function get_table_name(): string {
    return $this->table_name();
  }

  /**
   * Whether the managed table exists in the database.
   */
  public function table_exists(): bool {
    $table = $this->table_name();
    if ( '' === $table ) {
      return false;
    }

    $found = $this->db->get_var(
      $this->db->prepare( 'SHOW TABLES LIKE %s', $table )
    );

    return is_string( $found ) && $found === $table;
  }

  /**
   * Whether this table is owned by plugin migrations ({@see dbDelta}).
   */
  public function is_managed_by_db_delta(): bool {
    return $this->managed_by_db_delta;
  }

  /**
   * Optional hook before migrations run dbDelta for this model.
   */
  public function prepare_migrate(): void {
  }

  /**
   * Optional hook after this model's table exists (indexes dbDelta cannot create, etc.).
   */
  public function after_migrate(): void {
  }

  /**
   * Column definitions, primary key, and index keys for {@see create_table_sql()}.
   *
   * @return array{
   *   columns: array<string, string>,
   *   primary?: array<int, string>,
   *   keys?: array<string, array<int, string>>,
   *   unique?: array<string, array<int, string>>
   * }
   */
  abstract protected function schema(): array;

  /**
   * Builds a CREATE TABLE statement for use with dbDelta.
   */
  public function create_table_sql(): string {
    $schema = $this->schema();
    $columns = $schema['columns'] ?? [];
    $primary = $schema['primary'] ?? [];
    $keys = $schema['keys'] ?? [];
    $charset_collate = $this->db->get_charset_collate();
    $parts = [];

    foreach ( $columns as $name => $definition ) {
      $parts[] = sprintf( '`%s` %s', $name, $definition );
    }

    if ( ! empty( $primary ) ) {
      $quoted_primary = array_map(
        static function ( string $column ): string {
          return '`' . $column . '`';
        },
        $primary
      );
      $parts[] = 'PRIMARY KEY  (' . implode( ',', $quoted_primary ) . ')';
    }

    $unique = $schema['unique'] ?? [];
    foreach ( $unique as $name => $key_columns ) {
      $quoted_columns = array_map(
        static function ( string $column ): string {
          return '`' . $column . '`';
        },
        $key_columns
      );
      $parts[] = sprintf( 'UNIQUE KEY `%s` (%s)', $name, implode( ',', $quoted_columns ) );
    }

    foreach ( $keys as $name => $key_columns ) {
      $quoted_columns = array_map(
        static function ( string $column ): string {
          return '`' . $column . '`';
        },
        $key_columns
      );
      $parts[] = sprintf( 'KEY `%s` (%s)', $name, implode( ',', $quoted_columns ) );
    }

    return sprintf(
      "CREATE TABLE %s (\n%s\n) %s;",
      $this->table_name(),
      implode( ",\n", $parts ),
      $charset_collate
    );
  }

  /**
   * CREATE TABLE IF NOT EXISTS variant for direct $wpdb->query fallback.
   */
  public function create_table_if_not_exists_sql(): string {
    return str_replace( 'CREATE TABLE ', 'CREATE TABLE IF NOT EXISTS ', $this->create_table_sql() );
  }

  /**
   * @param mixed ...$args Placeholders for $wpdb->prepare().
   */
  protected function prepare( string $query, ...$args ): string {
    return $this->db->prepare( $query, ...$args );
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  protected function get_results( string $query ): array {
    return $this->db->get_results( $query, ARRAY_A );
  }

  /**
   * @return array<string, mixed>|null
   */
  protected function get_row( string $query ): ?array {
    $row = $this->db->get_row( $query, ARRAY_A );

    return is_array( $row ) ? $row : null;
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  public function get_all(): array {
    $table = $this->table_name();
    $query = $this->prepare( "SELECT * FROM `{$table}`" );

    return $this->get_results( $query );
  }

  /**
   * @param array<string, mixed> $data   Column => value.
   * @param array<int, string>   $format Optional $wpdb->insert formats.
   * @return int New row id (insert_id), or 0 if insert failed.
   */
  protected function insert( array $data, array $format = [] ): int {
    $this->db->insert( $this->table_name(), $data, $format );

    return (int) $this->db->insert_id;
  }

  /**
   * @param array<string, mixed> $data
   * @param array<string, mixed> $where
   * @return int Rows affected.
   */
  protected function update( array $data, array $where, array $format = [], array $where_format = [] ): int {
    return (int) $this->db->update( $this->table_name(), $data, $where, $format, $where_format );
  }

  /**
   * @param array<string, mixed> $where
   * @return int Rows deleted.
   */
  protected function delete( array $where, array $where_format = [] ): int {
    return (int) $this->db->delete( $this->table_name(), $where, $where_format );
  }

  /**
   * Resolves the plugin table prefix from the subclass or identity.
   */
  private function resolved_plugin_prefix(): string {
    if ( '' !== $this->plugin_prefix ) {
      return $this->plugin_prefix;
    }

    return PluginContext::identity()->tablePrefix();
  }
}
