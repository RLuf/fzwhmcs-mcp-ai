<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rotas REST imovelsite/v1 — toda rota exige a capability imovelsite_provision
 * (usuario de servico + Application Password, Basic sobre HTTPS).
 */
class Imovelsite_Rest_Controller {

	const NS = 'imovelsite/v1';

	public function permission( $request ) {
		if ( ! current_user_can( 'imovelsite_provision' ) ) {
			return new WP_Error( 'rest_forbidden', 'Provisionamento nao autorizado.', array( 'status' => 401 ) );
		}
		return true;
	}

	private function slug_arg() {
		return array(
			'required'          => true,
			'type'              => 'string',
			'validate_callback' => function ( $v ) {
				return (bool) preg_match( '/^[a-z0-9]{3,20}$/', $v );
			},
			'sanitize_callback' => function ( $v ) {
				return strtolower( trim( $v ) );
			},
		);
	}

	public function register_routes() {
		register_rest_route( self::NS, '/ping', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'ping' ),
			'permission_callback' => array( $this, 'permission' ),
		) );

		register_rest_route( self::NS, '/sites', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'create_site' ),
			'permission_callback' => array( $this, 'permission' ),
			'args'                => array(
				'slug'            => $this->slug_arg(),
				'email'           => array(
					'required'          => true,
					'type'              => 'string',
					'validate_callback' => 'is_email',
					'sanitize_callback' => 'sanitize_email',
				),
				'display_name'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'idempotency_key' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
				'create_mailbox'  => array( 'type' => 'boolean', 'default' => true ),
			),
		) );

		register_rest_route( self::NS, '/sites/(?P<slug>[a-z0-9]{3,20})/status', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'site_status' ),
			'permission_callback' => array( $this, 'permission' ),
		) );

		register_rest_route( self::NS, '/sites/(?P<slug>[a-z0-9]{3,20})/suspend', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'suspend' ),
			'permission_callback' => array( $this, 'permission' ),
		) );

		register_rest_route( self::NS, '/sites/(?P<slug>[a-z0-9]{3,20})/unsuspend', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'unsuspend' ),
			'permission_callback' => array( $this, 'permission' ),
		) );

		register_rest_route( self::NS, '/sites/(?P<slug>[a-z0-9]{3,20})', array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => array( $this, 'delete_site' ),
			'permission_callback' => array( $this, 'permission' ),
		) );

		register_rest_route( self::NS, '/sites/(?P<slug>[a-z0-9]{3,20})/domain', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'map_domain' ),
			'permission_callback' => array( $this, 'permission' ),
			'args'                => array(
				'domain' => array(
					'required'          => true,
					'type'              => 'string',
					'validate_callback' => function ( $v ) {
						return (bool) preg_match( '/^[a-z0-9][a-z0-9.\-]{2,60}\.[a-z]{2,10}$/i', $v );
					},
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( self::NS, '/sites/(?P<slug>[a-z0-9]{3,20})/sso', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'sso' ),
			'permission_callback' => array( $this, 'permission' ),
		) );
	}

	public function ping() {
		return rest_ensure_response( array(
			'ok'      => true,
			'service' => 'imovelsite-provisioner',
			'version' => IMOVELSITE_PROV_VERSION,
			'time'    => gmdate( 'c' ),
		) );
	}

	public function create_site( WP_REST_Request $req ) {
		$key = $req['idempotency_key'];

		$existing = Imovelsite_Job_Queue::find_by_key( $key );
		if ( $existing ) {
			return new WP_REST_Response( $this->job_view( $existing ), 200 );
		}
		if ( Imovelsite_Provisioner::site_exists( $req['slug'] ) ) {
			return new WP_Error( 'already_exists', 'Site ja existe para o slug ' . $req['slug'], array( 'status' => 409 ) );
		}

		$job_id = Imovelsite_Job_Queue::create( $key, $req['slug'], 'provision', array(
			'email'          => $req['email'],
			'display_name'   => $req['display_name'],
			'create_mailbox' => (bool) $req['create_mailbox'],
		) );
		if ( ! $job_id ) {
			return new WP_Error( 'queue_failed', 'Falha ao enfileirar o job.', array( 'status' => 500 ) );
		}

		wp_schedule_single_event( time(), 'imovelsite_run_job', array( $job_id ) );
		spawn_cron();

		return new WP_REST_Response( array( 'job_id' => $job_id, 'slug' => $req['slug'], 'status' => 'pending' ), 202 );
	}

	public function site_status( WP_REST_Request $req ) {
		$slug = $req['slug'];
		$job  = Imovelsite_Job_Queue::latest_for_slug( $slug );

		if ( ! $job ) {
			if ( Imovelsite_Provisioner::site_exists( $slug ) ) {
				return rest_ensure_response( array(
					'status' => 'done',
					'domain' => $slug . '.' . IMOVELSITE_ROOT_DOMAIN,
					'note'   => 'site existente (provisionado fora da fila)',
				) );
			}
			return new WP_REST_Response( array( 'status' => 'not_found' ), 404 );
		}
		return rest_ensure_response( $this->job_view( $job ) );
	}

	public function suspend( WP_REST_Request $req ) {
		$res = ( new Imovelsite_Provisioner() )->suspend( $req['slug'] );
		if ( is_wp_error( $res ) ) {
			return new WP_REST_Response( array( 'status' => 'not_found' ), 404 );
		}
		return rest_ensure_response( array( 'ok' => true, 'action' => 'suspend', 'slug' => $req['slug'] ) );
	}

	public function unsuspend( WP_REST_Request $req ) {
		$res = ( new Imovelsite_Provisioner() )->unsuspend( $req['slug'] );
		if ( is_wp_error( $res ) ) {
			return new WP_REST_Response( array( 'status' => 'not_found' ), 404 );
		}
		return rest_ensure_response( array( 'ok' => true, 'action' => 'unsuspend', 'slug' => $req['slug'] ) );
	}

	public function delete_site( WP_REST_Request $req ) {
		$mode = $req->get_param( 'mode' ) ?: 'archive';
		if ( 'archive' !== $mode ) {
			return new WP_Error( 'not_implemented', 'Somente mode=archive e suportado; purge e manual.', array( 'status' => 501 ) );
		}
		$res = ( new Imovelsite_Provisioner() )->archive( $req['slug'] );
		if ( is_wp_error( $res ) ) {
			return new WP_REST_Response( array( 'status' => 'not_found' ), 404 );
		}
		return rest_ensure_response( array( 'ok' => true, 'action' => 'archive', 'archived_to' => basename( $res ) ) );
	}

	public function map_domain( WP_REST_Request $req ) {
		$res = ( new Imovelsite_Provisioner() )->map_domain( $req['slug'], $req['domain'] );
		if ( is_wp_error( $res ) ) {
			$code = 'not_found' === $res->get_error_code() ? 404 : 500;
			return new WP_REST_Response( array( 'status' => $res->get_error_code(), 'error' => $res->get_error_message() ), $code );
		}
		return rest_ensure_response( array_merge( array( 'ok' => true ), $res ) );
	}

	public function sso( WP_REST_Request $req ) {
		if ( ! Imovelsite_Provisioner::site_exists( $req['slug'] ) ) {
			return new WP_REST_Response( array( 'status' => 'not_found' ), 404 );
		}
		return rest_ensure_response( array(
			'url' => 'https://' . $req['slug'] . '.' . IMOVELSITE_ROOT_DOMAIN . '/wp-admin',
		) );
	}

	/** Visao segura do job (senhas so aparecem quando done — o WHMCS as grava criptografadas). */
	private function job_view( array $job ) {
		$view = array(
			'job_id' => (int) $job['id'],
			'slug'   => $job['slug'],
			'status' => $job['status'],
		);
		if ( 'done' === $job['status'] && ! empty( $job['result'] ) ) {
			$result = json_decode( $job['result'], true );
			if ( is_array( $result ) ) {
				$view = array_merge( $view, $result );
			}
		}
		if ( 'error' === $job['status'] && ! empty( $job['result'] ) ) {
			$result        = json_decode( $job['result'], true );
			$view['error'] = isset( $result['error'] ) ? $result['error'] : 'erro desconhecido';
		}
		return $view;
	}
}
