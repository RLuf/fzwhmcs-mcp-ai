<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Caixa de e-mail SEU_NOME@imovelsite.com.br via UAPI cPanel (conta local, sem --user:
 * o PHP roda como o proprio usuario imovelsitecom).
 */
class Imovelsite_Mailbox {

	const QUOTA_MB = 1024;

	/**
	 * Cria a caixa. Retorna array com dados (inclui a senha em claro UMA vez) ou WP_Error.
	 */
	public function create( $slug ) {
		$password = wp_generate_password( 16, false );
		$address  = $slug . '@' . IMOVELSITE_ROOT_DOMAIN;

		$cmd = sprintf(
			'uapi --output=json Email add_pop email=%s password=%s domain=%s quota=%d 2>&1',
			escapeshellarg( $slug ),
			escapeshellarg( $password ),
			escapeshellarg( IMOVELSITE_ROOT_DOMAIN ),
			self::QUOTA_MB
		);
		$out    = array();
		$status = 0;
		exec( $cmd, $out, $status );
		$json = json_decode( implode( "\n", $out ), true );

		$ok = isset( $json['result']['status'] ) && 1 === (int) $json['result']['status'];
		if ( ! $ok ) {
			$errs = isset( $json['result']['errors'] ) ? implode( '; ', (array) $json['result']['errors'] ) : 'uapi falhou';
			// Caixa ja existente nao e fatal: segue sem trocar a senha.
			if ( false !== stripos( $errs, 'already exists' ) || false !== stripos( $errs, 'ja existe' ) ) {
				return array(
					'address'     => $address,
					'password'    => '',
					'existing'    => true,
					'webmail_url' => 'https://' . IMOVELSITE_ROOT_DOMAIN . ':2096',
					'imap_host'   => 'mail.' . IMOVELSITE_ROOT_DOMAIN,
					'smtp_host'   => 'mail.' . IMOVELSITE_ROOT_DOMAIN,
				);
			}
			return new WP_Error( 'mailbox_failed', 'Falha ao criar caixa de e-mail: ' . $errs );
		}

		return array(
			'address'     => $address,
			'password'    => $password,
			'existing'    => false,
			'webmail_url' => 'https://' . IMOVELSITE_ROOT_DOMAIN . ':2096',
			'imap_host'   => 'mail.' . IMOVELSITE_ROOT_DOMAIN,
			'smtp_host'   => 'mail.' . IMOVELSITE_ROOT_DOMAIN,
			'imap_port'   => 993,
			'smtp_port'   => 465,
		);
	}

	public function suspend( $slug ) {
		$cmd = sprintf(
			'uapi --output=json Email suspend_login email=%s 2>&1',
			escapeshellarg( $slug . '@' . IMOVELSITE_ROOT_DOMAIN )
		);
		exec( $cmd );
	}

	public function unsuspend( $slug ) {
		$cmd = sprintf(
			'uapi --output=json Email unsuspend_login email=%s 2>&1',
			escapeshellarg( $slug . '@' . IMOVELSITE_ROOT_DOMAIN )
		);
		exec( $cmd );
	}
}
