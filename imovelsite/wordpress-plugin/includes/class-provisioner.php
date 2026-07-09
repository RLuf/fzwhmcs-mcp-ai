<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provisionamento local do site do corretor — port do provision-corretor.sh.
 * Roda como o usuario imovelsitecom (UAPI sem --user, wp-cli local). Sem SSH, sem root.
 */
class Imovelsite_Provisioner {

	const SLUG_REGEX = '/^[a-z0-9]{3,20}$/';

	private $wp_cli = '/usr/local/bin/wp';
	private $php    = '/usr/local/bin/php';

	public static function docroot( $slug ) {
		return IMOVELSITE_ACCOUNT_HOME . '/' . $slug;
	}

	public static function site_exists( $slug ) {
		return file_exists( self::docroot( $slug ) . '/wp-config.php' );
	}

	/** Executa wp-cli no site do corretor. Retorna [status, output]. */
	private function wp( $slug, $args ) {
		$cmd = sprintf(
			'%s -d memory_limit=512M %s %s --path=%s 2>&1',
			escapeshellarg( $this->php ),
			escapeshellarg( $this->wp_cli ),
			$args, // ja montado com escapeshellarg por quem chama
			escapeshellarg( self::docroot( $slug ) )
		);
		$out    = array();
		$status = 0;
		exec( $cmd, $out, $status );
		return array( $status, implode( "\n", $out ) );
	}

	private function uapi( $module, $func, array $params = array() ) {
		$cmd = sprintf( 'uapi --output=json %s %s', escapeshellarg( $module ), escapeshellarg( $func ) );
		foreach ( $params as $k => $v ) {
			$cmd .= ' ' . escapeshellarg( $k . '=' . $v );
		}
		$out = array();
		exec( $cmd . ' 2>&1', $out );
		$json = json_decode( implode( "\n", $out ), true );
		return is_array( $json ) ? $json : array();
	}

	/** Entrada assincrona (hook imovelsite_run_job). */
	public function run_job( $job_id ) {
		$job = Imovelsite_Job_Queue::get( $job_id );
		if ( ! $job || 'pending' !== $job['status'] ) {
			return;
		}
		Imovelsite_Job_Queue::update( $job_id, 'running' );
		$payload = json_decode( $job['payload'], true );

		try {
			$result = $this->provision(
				$job['slug'],
				$payload['email'],
				isset( $payload['display_name'] ) ? $payload['display_name'] : '',
				! empty( $payload['create_mailbox'] )
			);
			if ( is_wp_error( $result ) ) {
				Imovelsite_Job_Queue::update( $job_id, 'error', array( 'error' => $result->get_error_message() ) );
				return;
			}
			Imovelsite_Job_Queue::update( $job_id, 'done', $result );
		} catch ( \Throwable $e ) {
			Imovelsite_Job_Queue::update( $job_id, 'error', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Cria o site completo. Retorna array de credenciais ou WP_Error.
	 */
	public function provision( $slug, $email, $display_name = '', $create_mailbox = true ) {
		if ( ! preg_match( self::SLUG_REGEX, $slug ) ) {
			return new WP_Error( 'invalid_slug', 'Slug invalido: ' . $slug );
		}
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', 'E-mail invalido.' );
		}
		if ( self::site_exists( $slug ) ) {
			return new WP_Error( 'already_exists', 'ja existe: ' . self::docroot( $slug ) );
		}

		$domain     = $slug . '.' . IMOVELSITE_ROOT_DOMAIN;
		$url        = 'https://' . $domain;
		$docroot    = self::docroot( $slug );
		$title      = $display_name ?: 'Imoveis ' . $slug;
		// Prefixo de banco exigido pelo cPanel para a conta (sem root/fallback).
		$db_name    = IMOVELSITE_DB_PREFIX . substr( $slug, 0, 8 );
		$db_pass    = wp_generate_password( 20, false );
		$admin_pass = wp_generate_password( 16, false );

		// 0. DNS Cloudflare (proxied = SSL de borda imediato).
		$dns     = new Imovelsite_Dns_Cloudflare();
		$dns_msg = $dns->ensure_a_record( $slug );

		// 1. Subdominio (vhost + docroot).
		$this->uapi( 'SubDomain', 'addsubdomain', array(
			'domain'     => $slug,
			'rootdomain' => IMOVELSITE_ROOT_DOMAIN,
			'dir'        => $slug,
		) );
		if ( ! is_dir( $docroot ) ) {
			return new WP_Error( 'vhost_failed', 'vhost nao criou docroot ' . $docroot );
		}

		// 2. Banco + usuario (UAPI da propria conta).
		$this->uapi( 'Mysql', 'create_database', array( 'name' => $db_name ) );
		$this->uapi( 'Mysql', 'create_user', array( 'name' => $db_name, 'password' => $db_pass ) );
		$this->uapi( 'Mysql', 'set_privileges_on_database', array(
			'user'       => $db_name,
			'database'   => $db_name,
			'privileges' => 'ALL',
		) );

		// 3. WordPress pt_BR.
		list( $st, $out ) = $this->wp( $slug, 'core download --locale=pt_BR --force' );
		if ( 0 !== $st ) {
			return new WP_Error( 'wp_download', 'core download falhou: ' . $out );
		}
		list( $st, $out ) = $this->wp( $slug, sprintf(
			'config create --dbname=%s --dbuser=%s --dbpass=%s --locale=pt_BR',
			escapeshellarg( $db_name ), escapeshellarg( $db_name ), escapeshellarg( $db_pass )
		) );
		if ( 0 !== $st ) {
			return new WP_Error( 'wp_config', 'config create falhou: ' . $out );
		}
		list( $st, $out ) = $this->wp( $slug, sprintf(
			'core install --url=%s --title=%s --admin_user=%s --admin_password=%s --admin_email=%s --skip-email',
			escapeshellarg( $url ), escapeshellarg( $title ), escapeshellarg( $slug ),
			escapeshellarg( $admin_pass ), escapeshellarg( $email )
		) );
		if ( 0 !== $st ) {
			return new WP_Error( 'wp_install', 'core install falhou: ' . $out );
		}

		// 4. Tema proimovel + plugins essenciais + painel do corretor.
		$theme_src = IMOVELSITE_TEMPLATE_DIR . '/proimovel';
		if ( is_dir( $theme_src ) ) {
			exec( sprintf( 'cp -a %s %s 2>&1', escapeshellarg( $theme_src ), escapeshellarg( $docroot . '/wp-content/themes/' ) ) );
			$this->wp( $slug, 'theme activate proimovel' );
		}
		$this->wp( $slug, 'plugin install advanced-custom-fields creame-whatsapp-me wordpress-seo --activate' );

		$mu_src = IMOVELSITE_TEMPLATE_DIR . '/painel-corretor.php';
		if ( file_exists( $mu_src ) ) {
			if ( ! is_dir( $docroot . '/wp-content/mu-plugins' ) ) {
				mkdir( $docroot . '/wp-content/mu-plugins', 0755, true );
			}
			copy( $mu_src, $docroot . '/wp-content/mu-plugins/painel-corretor.php' );
		}
		$this->wp( $slug, 'option update is_site_corretor 1' );
		foreach ( array( 'Casas', 'Apartamento', 'Fazenda' ) as $cat ) {
			$this->wp( $slug, 'term create category ' . escapeshellarg( $cat ) );
		}
		$this->wp( $slug, "eval 'do_action(\"init\");'" );
		$this->wp( $slug, 'user set-role ' . escapeshellarg( $slug ) . ' corretor' );

		// 5. Permalinks + .htaccess (sem isso os imoveis dao 404).
		$this->wp( $slug, "rewrite structure '/%postname%/'" );
		file_put_contents( $docroot . '/.htaccess', "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteBase /\nRewriteRule ^index\\.php$ - [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule . /index.php [L]\n</IfModule>\n# END WordPress\n" );
		$this->wp( $slug, 'post delete 1 2 3 --force' );

		// 6. Caixa de e-mail exclusiva.
		$mailbox = null;
		if ( $create_mailbox ) {
			$mb  = new Imovelsite_Mailbox();
			$res = $mb->create( $slug );
			if ( ! is_wp_error( $res ) ) {
				$mailbox = $res;
			}
		}

		return array(
			'domain'     => $domain,
			'url'        => $url,
			'admin_user' => $slug,
			'admin_pass' => $admin_pass,
			'panel_url'  => $url . '/wp-admin',
			'dns'        => $dns_msg,
			'mailbox'    => $mailbox,
		);
	}

	/** Suspensao: .htaccess 503 + trava de login da caixa. */
	public function suspend( $slug ) {
		$docroot = self::docroot( $slug );
		if ( ! is_dir( $docroot ) ) {
			return new WP_Error( 'not_found', 'not_found' );
		}
		if ( file_exists( $docroot . '/.htaccess' ) && ! file_exists( $docroot . '/.htaccess.imovelsite-bkp' ) ) {
			copy( $docroot . '/.htaccess', $docroot . '/.htaccess.imovelsite-bkp' );
		}
		file_put_contents( $docroot . '/.imovelsite-suspended', gmdate( 'c' ) );
		file_put_contents( $docroot . '/.htaccess', "ErrorDocument 503 \"Site temporariamente suspenso. Entre em contato com o suporte ImovelSite.\"\nRewriteEngine On\nRewriteRule .* - [R=503,L]\n" );
		( new Imovelsite_Mailbox() )->suspend( $slug );
		return true;
	}

	public function unsuspend( $slug ) {
		$docroot = self::docroot( $slug );
		if ( ! is_dir( $docroot ) ) {
			return new WP_Error( 'not_found', 'not_found' );
		}
		if ( file_exists( $docroot . '/.htaccess.imovelsite-bkp' ) ) {
			copy( $docroot . '/.htaccess.imovelsite-bkp', $docroot . '/.htaccess' );
			unlink( $docroot . '/.htaccess.imovelsite-bkp' );
		}
		if ( file_exists( $docroot . '/.imovelsite-suspended' ) ) {
			unlink( $docroot . '/.imovelsite-suspended' );
		}
		( new Imovelsite_Mailbox() )->unsuspend( $slug );
		return true;
	}

	/** Arquiva: suspende, renomeia docroot e remove DNS. Purge e manual. */
	public function archive( $slug ) {
		$docroot = self::docroot( $slug );
		if ( ! is_dir( $docroot ) ) {
			return new WP_Error( 'not_found', 'not_found' );
		}
		$this->suspend( $slug );
		$target = $docroot . '-archived-' . gmdate( 'Ymd-His' );
		rename( $docroot, $target );
		( new Imovelsite_Dns_Cloudflare() )->delete_a_record( $slug );
		return $target;
	}

	/** Mapeia dominio proprio do cliente como principal (subdominio vira alias). */
	public function map_domain( $slug, $domain ) {
		$docroot = self::docroot( $slug );
		if ( ! self::site_exists( $slug ) ) {
			return new WP_Error( 'not_found', 'not_found' );
		}
		$domain = strtolower( preg_replace( '/[^a-z0-9.\-]/i', '', $domain ) );

		// Addon domain apontando pro mesmo docroot (subdominio interno tecnico).
		$sub = preg_replace( '/[^a-z0-9]/', '', explode( '.', $domain )[0] ) . 'map';
		$res = $this->uapi( 'AddonDomain', 'addaddondomain', array(
			'newdomain' => $domain,
			'subdomain' => $sub,
			'dir'       => $slug,
		) );
		$uapi_ok = isset( $res['result']['status'] ) && 1 === (int) $res['result']['status'];

		// WordPress passa a responder pelo dominio proprio.
		$this->wp( $slug, 'option update home ' . escapeshellarg( 'https://' . $domain ) );
		$this->wp( $slug, 'option update siteurl ' . escapeshellarg( 'https://' . $domain ) );

		return array(
			'domain'           => $domain,
			'addon_domain_ok'  => $uapi_ok,
			'dns_instructions' => 'Aponte o dominio ' . $domain . ' com registro A para ' .
				( defined( 'IMOVELSITE_SERVER_IP' ) ? IMOVELSITE_SERVER_IP : '(IP do servidor)' ) .
				' (ou CNAME para ' . $slug . '.' . IMOVELSITE_ROOT_DOMAIN . '). SSL via AutoSSL/Cloudflare apos propagacao.',
		);
	}
}
