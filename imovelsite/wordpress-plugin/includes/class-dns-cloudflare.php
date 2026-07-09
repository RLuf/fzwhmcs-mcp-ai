<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DNS Cloudflare — A record proxied do subdominio (SSL na borda imediato).
 * Token via constante IMOVELSITE_CF_TOKEN no wp-config.php (nunca no banco).
 */
class Imovelsite_Dns_Cloudflare {

	private $token;
	private $zone;
	private $server_ip;

	public function __construct() {
		// Todos os valores vem de constantes do wp-config.php — nada de infra no codigo.
		$this->token     = defined( 'IMOVELSITE_CF_TOKEN' ) ? IMOVELSITE_CF_TOKEN : '';
		$this->zone      = defined( 'IMOVELSITE_CF_ZONE' ) ? IMOVELSITE_CF_ZONE : '';
		$this->server_ip = defined( 'IMOVELSITE_SERVER_IP' ) ? IMOVELSITE_SERVER_IP : '';
	}

	public function available() {
		return '' !== $this->token && '' !== $this->zone && '' !== $this->server_ip;
	}

	private function request( $method, $path, array $body = null ) {
		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->token,
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$resp = wp_remote_request( 'https://api.cloudflare.com/client/v4' . $path, $args );
		if ( is_wp_error( $resp ) ) {
			return array( 'success' => false, 'errors' => array( $resp->get_error_message() ) );
		}
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		return is_array( $json ) ? $json : array( 'success' => false, 'errors' => array( 'resposta invalida' ) );
	}

	private function find_record_id( $fqdn ) {
		$res = $this->request( 'GET', "/zones/{$this->zone}/dns_records?name=" . rawurlencode( $fqdn ) );
		if ( ! empty( $res['result'][0]['id'] ) ) {
			return $res['result'][0]['id'];
		}
		return '';
	}

	/** Cria ou atualiza o A record proxied. Retorna string de status p/ log. */
	public function ensure_a_record( $slug ) {
		if ( ! $this->available() ) {
			return 'dns=sem-token';
		}
		$fqdn = $slug . '.' . IMOVELSITE_ROOT_DOMAIN;
		$body = array(
			'type'    => 'A',
			'name'    => $slug,
			'content' => $this->server_ip,
			'ttl'     => 300,
			'proxied' => true,
			'comment' => 'corretor imovelsite (provisioner)',
		);
		$rec = $this->find_record_id( $fqdn );
		if ( $rec ) {
			$res = $this->request( 'PATCH', "/zones/{$this->zone}/dns_records/{$rec}", $body );
			return ! empty( $res['success'] ) ? 'dns=atualizado(proxied)' : 'dns=erro-patch';
		}
		$res = $this->request( 'POST', "/zones/{$this->zone}/dns_records", $body );
		return ! empty( $res['success'] ) ? 'dns=criado(proxied)' : 'dns=erro-post';
	}

	public function delete_a_record( $slug ) {
		if ( ! $this->available() ) {
			return 'dns=sem-token';
		}
		$fqdn = $slug . '.' . IMOVELSITE_ROOT_DOMAIN;
		$rec  = $this->find_record_id( $fqdn );
		if ( ! $rec ) {
			return 'dns=inexistente';
		}
		$res = $this->request( 'DELETE', "/zones/{$this->zone}/dns_records/{$rec}" );
		return ! empty( $res['success'] ) ? 'dns=removido' : 'dns=erro-delete';
	}
}
