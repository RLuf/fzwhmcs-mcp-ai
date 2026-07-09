<?php
/**
 * Plugin Name:       ImovelSite Provisioner
 * Plugin URI:        https://www.<ROOT_DOMAIN>
 * Description:       API REST de provisionamento de sites de corretores (WHMCS -> red). Namespace imovelsite/v1.
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Webstorage / ImovelSite
 * License:           Proprietary
 * Text Domain:       imovelsite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IMOVELSITE_PROV_VERSION', '1.0.0' );
define( 'IMOVELSITE_PROV_DIR', plugin_dir_path( __FILE__ ) );

// Todos os valores de ambiente vêm de constantes definidas no wp-config.php.
// Sem hardcode: se faltar qualquer uma, o provisionamento falha com erro claro.
foreach ( array( 'IMOVELSITE_TEMPLATE_DIR', 'IMOVELSITE_ACCOUNT_HOME', 'IMOVELSITE_ROOT_DOMAIN' ) as $__c ) {
	if ( ! defined( $__c ) ) {
		define( $__c, '' );
	}
}
unset( $__c );

// Prefixo de banco da conta cPanel (ex.: "conta_"). Derivado do home da conta.
if ( ! defined( 'IMOVELSITE_DB_PREFIX' ) ) {
	define( 'IMOVELSITE_DB_PREFIX', IMOVELSITE_ACCOUNT_HOME ? basename( IMOVELSITE_ACCOUNT_HOME ) . '_' : '' );
}

require_once IMOVELSITE_PROV_DIR . 'includes/class-job-queue.php';
require_once IMOVELSITE_PROV_DIR . 'includes/class-dns-cloudflare.php';
require_once IMOVELSITE_PROV_DIR . 'includes/class-mailbox.php';
require_once IMOVELSITE_PROV_DIR . 'includes/class-provisioner.php';
require_once IMOVELSITE_PROV_DIR . 'includes/class-rest-controller.php';

/** Application Passwords sao obrigatorias para a autenticacao WHMCS -> WP (Basic sobre HTTPS). */
add_filter( 'wp_is_application_passwords_available', '__return_true' );

register_activation_hook( __FILE__, function () {
	Imovelsite_Job_Queue::install_table();

	add_role( 'imovelsite_service', 'ImovelSite Service', array(
		'read'                 => true,
		'imovelsite_provision' => true,
	) );
	// Garante a capability mesmo se o role ja existia sem ela.
	$role = get_role( 'imovelsite_service' );
	if ( $role && ! $role->has_cap( 'imovelsite_provision' ) ) {
		$role->add_cap( 'imovelsite_provision' );
	}
	$admin = get_role( 'administrator' );
	if ( $admin ) {
		$admin->add_cap( 'imovelsite_provision' );
	}
} );

add_action( 'rest_api_init', function () {
	$controller = new Imovelsite_Rest_Controller();
	$controller->register_routes();
} );

add_action( 'imovelsite_run_job', function ( $job_id ) {
	// exec() e desabilitado no PHP web (disable_functions) de proposito: o provisionamento
	// so roda em CLI. No web o evento apenas se encerra — o cron de sistema processa a
	// tabela diretamente via imovelsite_process_pending() (sem corrida com o WP-Cron web).
	if ( 'cli' !== PHP_SAPI || ! function_exists( 'exec' ) ) {
		return;
	}
	$provisioner = new Imovelsite_Provisioner();
	$provisioner->run_job( (int) $job_id );
}, 10, 1 );

/**
 * Processa todos os jobs pendentes da fila. Chamado pelo cron de sistema em CLI:
 *   wp eval 'imovelsite_process_pending();'
 */
function imovelsite_process_pending() {
	if ( 'cli' !== PHP_SAPI || ! function_exists( 'exec' ) ) {
		return;
	}
	global $wpdb;
	$table       = Imovelsite_Job_Queue::table();
	$ids         = $wpdb->get_col( "SELECT id FROM {$table} WHERE status = 'pending' ORDER BY id ASC LIMIT 5" );
	$provisioner = new Imovelsite_Provisioner();
	foreach ( $ids as $id ) {
		$provisioner->run_job( (int) $id );
	}
}
