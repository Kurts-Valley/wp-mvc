<?php
declare(strict_types=1);

namespace KurtCampbell\WpMvc\System;

/**
 * Shared sanitization for shortcode and hook integration admin settings fields.
 */
final class SettingsSchema {
  /**
   * @param array<string, array<string, mixed>> $fields
   * @param array<string, int|string|bool>      $defaults
   * @param array<string, mixed>                $post_slice
   * @return array<string, string|int|float|bool>
   */
  public static function sanitize_fields( array $fields, array $defaults, array $post_slice ): array {
    $out = [];

    foreach ( $fields as $key => $meta ) {
      if ( ! is_string( $key ) || ! is_array( $meta ) ) {
        continue;
      }

      $type = isset( $meta['type'] ) ? (string) $meta['type'] : 'text';
      if ( 'int' === $type ) {
        $min = isset( $meta['min'] ) ? (int) $meta['min'] : PHP_INT_MIN;
        $max = isset( $meta['max'] ) ? (int) $meta['max'] : PHP_INT_MAX;
        $fallback = isset( $defaults[ $key ] ) ? (int) $defaults[ $key ] : 0;
        $raw = array_key_exists( $key, $post_slice ) ? $post_slice[ $key ] : $fallback;
        $out[ $key ] = min( $max, max( $min, (int) $raw ) );
      } elseif ( 'checkbox' === $type ) {
        // Unchecked boxes are omitted from POST; treat absence as off, not the code default.
        $out[ $key ] = array_key_exists( $key, $post_slice )
          ? wp_validate_boolean( $post_slice[ $key ] )
          : false;
      } elseif ( 'float' === $type ) {
        $min = isset( $meta['min'] ) ? (float) $meta['min'] : -INF;
        $max = isset( $meta['max'] ) ? (float) $meta['max'] : INF;
        $fallback = isset( $defaults[ $key ] ) ? (float) $defaults[ $key ] : 0.0;
        $raw = array_key_exists( $key, $post_slice ) ? $post_slice[ $key ] : $fallback;
        $val = (float) $raw;
        $out[ $key ] = min( $max, max( $min, $val ) );
      } elseif ( 'key' === $type ) {
        $fallback_raw = isset( $defaults[ $key ] ) ? (string) $defaults[ $key ] : '';
        $fallback = sanitize_key( $fallback_raw );
        $raw = array_key_exists( $key, $post_slice ) ? $post_slice[ $key ] : $fallback_raw;
        $sanitized = sanitize_key( (string) $raw );
        $out[ $key ] = '' !== $sanitized ? $sanitized : $fallback;
      } elseif ( 'select' === $type ) {
        $options = isset( $meta['options'] ) && is_array( $meta['options'] ) ? $meta['options'] : [];
        $allowed = array_map( 'strval', array_keys( $options ) );
        $fallback_raw = isset( $defaults[ $key ] ) ? (string) $defaults[ $key ] : '';
        $fallback = in_array( $fallback_raw, $allowed, true ) ? $fallback_raw : ( $allowed[0] ?? '' );
        $raw = array_key_exists( $key, $post_slice ) ? (string) $post_slice[ $key ] : $fallback;
        if ( ! in_array( $raw, $allowed, true ) ) {
          $raw = $fallback;
        }
        $out[ $key ] = ctype_digit( $raw ) ? (int) $raw : $raw;
      } elseif ( 'textarea' === $type ) {
        $fallback = isset( $defaults[ $key ] ) ? (string) $defaults[ $key ] : '';
        $raw = array_key_exists( $key, $post_slice ) ? $post_slice[ $key ] : $fallback;
        $out[ $key ] = wp_kses_post( (string) $raw );
      } elseif ( 'page_ids' === $type ) {
        $fallback = isset( $defaults[ $key ] ) ? (string) $defaults[ $key ] : '';
        $raw = array_key_exists( $key, $post_slice ) ? $post_slice[ $key ] : $fallback;
        $out[ $key ] = self::sanitize_page_ids_setting( $raw );
      } elseif ( 'email' === $type ) {
        $fallback = isset( $defaults[ $key ] ) ? (string) $defaults[ $key ] : '';
        $raw = array_key_exists( $key, $post_slice ) ? $post_slice[ $key ] : $fallback;
        $out[ $key ] = self::sanitize_email_setting( (string) $raw );
      } elseif ( 'email_list' === $type ) {
        $fallback = isset( $defaults[ $key ] ) ? (string) $defaults[ $key ] : '';
        $raw = array_key_exists( $key, $post_slice ) ? $post_slice[ $key ] : $fallback;
        $out[ $key ] = self::sanitize_email_list_setting( (string) $raw );
      } else {
        $fallback = isset( $defaults[ $key ] ) ? (string) $defaults[ $key ] : '';
        $raw = array_key_exists( $key, $post_slice ) ? $post_slice[ $key ] : $fallback;
        $out[ $key ] = sanitize_text_field( (string) $raw );
      }
    }

    return $out;
  }

  /**
   * Whether the ID refers to a published WordPress Page (not a post or custom post type).
   */
  public static function is_published_page_id( int $post_id ): bool {
    if ( $post_id <= 0 ) {
      return false;
    }

    $page = get_page( $post_id );

    return $page instanceof \WP_Post && 'publish' === $page->post_status;
  }

  /**
   * Normalizes a page ID list to a comma-separated string of published page IDs.
   *
   * @param string|array<int|string> $raw Comma-separated IDs or list from POST.
   */
  public static function sanitize_page_ids_setting( $raw ): string {
    $ids = [];
    if ( is_array( $raw ) ) {
      foreach ( $raw as $item ) {
        $ids[] = absint( $item );
      }
    } else {
      foreach ( preg_split( '/\s*,\s*/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY ) as $part ) {
        $ids[] = absint( $part );
      }
    }

    $valid = [];
    foreach ( array_unique( array_filter( $ids ) ) as $id ) {
      if ( self::is_published_page_id( $id ) ) {
        $valid[] = $id;
      }
    }

    return implode( ',', $valid );
  }

  /**
   * @return list<int>
   */
  public static function parse_page_ids_setting( string $raw ): array {
    $ids = [];
    foreach ( preg_split( '/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY ) as $part ) {
      $id = absint( $part );
      if ( $id > 0 ) {
        $ids[] = $id;
      }
    }

    return array_values( array_unique( $ids ) );
  }

  /**
   * Parsed page IDs limited to published Pages ({@see is_published_page_id()}).
   *
   * @return list<int>
   */
  public static function published_page_ids_from_setting( string $raw ): array {
    $ids = self::parse_page_ids_setting( $raw );

    return array_values(
      array_filter(
        $ids,
        static function ( int $id ): bool {
          return self::is_published_page_id( $id );
        }
      )
    );
  }

  /**
   * Normalizes a single email setting (bare address or "Name <email@domain.com>").
   */
  public static function sanitize_email_setting( string $raw ): string {
    $raw = trim( sanitize_text_field( $raw ) );
    if ( '' === $raw ) {
      return '';
    }

    $parsed = self::parse_email_setting( $raw );
    if ( '' === $parsed['email'] ) {
      return '';
    }

    if ( '' !== $parsed['name'] ) {
      return $parsed['name'] . ' <' . $parsed['email'] . '>';
    }

    return $parsed['email'];
  }

  /**
   * @return array{email: string, name: string}
   */
  public static function parse_email_setting( string $raw ): array {
    $raw = trim( $raw );
    if ( '' === $raw ) {
      return [
        'email' => '',
        'name' => '',
      ];
    }

    if ( preg_match( '/^(.+?)\s*<([^>]+)>$/', $raw, $matches ) ) {
      $email = sanitize_email( trim( $matches[2] ) );
      $name = trim( $matches[1], " \t\"'" );

      return [
        'email' => is_email( $email ) ? $email : '',
        'name' => '' !== $name ? $name : '',
      ];
    }

    $email = sanitize_email( $raw );

    return [
      'email' => is_email( $email ) ? $email : '',
      'name' => '',
    ];
  }

  /**
   * Normalizes a comma-separated email list to validated addresses.
   */
  public static function sanitize_email_list_setting( string $raw ): string {
    $emails = self::parse_email_list_setting( $raw );

    return implode( ', ', $emails );
  }

  /**
   * @return list<string>
   */
  public static function parse_email_list_setting( string $raw ): array {
    $valid = [];
    foreach ( preg_split( '/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY ) as $part ) {
      $parsed = self::parse_email_setting( (string) $part );
      if ( '' !== $parsed['email'] && is_email( $parsed['email'] ) ) {
        $valid[] = $parsed['email'];
      }
    }

    return array_values( array_unique( $valid ) );
  }
}
