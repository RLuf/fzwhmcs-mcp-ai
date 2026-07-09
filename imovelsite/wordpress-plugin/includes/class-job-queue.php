<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fila de jobs de provisionamento — tabela {prefix}imovelsite_jobs.
 * Idempotencia por idempotency_key (UNIQUE): repetir o POST devolve o job existente.
 */
class Imovelsite_Job_Queue {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'imovelsite_jobs';
	}

	public static function install_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		dbDelta( "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			idempotency_key VARCHAR(64) NOT NULL DEFAULT '',
			slug VARCHAR(32) NOT NULL DEFAULT '',
			action VARCHAR(20) NOT NULL DEFAULT 'provision',
			status VARCHAR(12) NOT NULL DEFAULT 'pending',
			payload LONGTEXT NULL,
			result LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY slug (slug),
			KEY status (status)
		) {$charset};" );
	}

	public static function find_by_key( $key ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE idempotency_key = %s", $key ), ARRAY_A );
	}

	public static function latest_for_slug( $slug, $action = 'provision' ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s AND action = %s ORDER BY id DESC LIMIT 1", $slug, $action ),
			ARRAY_A
		);
	}

	public static function create( $key, $slug, $action, array $payload ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$ok  = $wpdb->insert( self::table(), array(
			'idempotency_key' => $key,
			'slug'            => $slug,
			'action'          => $action,
			'status'          => 'pending',
			'payload'         => wp_json_encode( $payload ),
			'created_at'      => $now,
			'updated_at'      => $now,
		) );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
	}

	public static function update( $id, $status, array $result = null ) {
		global $wpdb;
		$data = array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql' ),
		);
		if ( null !== $result ) {
			$data['result'] = wp_json_encode( $result );
		}
		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
	}

	/** Mescla novos campos ao result existente sem perder os anteriores. */
	public static function merge_result( $id, array $fields, $status = null ) {
		$job    = self::get( $id );
		$result = $job && $job['result'] ? json_decode( $job['result'], true ) : array();
		if ( ! is_array( $result ) ) {
			$result = array();
		}
		self::update( $id, $status ?: $job['status'], array_merge( $result, $fields ) );
	}
}
