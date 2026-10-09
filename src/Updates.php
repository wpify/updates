<?php

namespace Wpify\Updates;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

class Updates {

	private $plugin_file;
	private $plugin_slug;
	private $extra_data;
	private $checker;

	public function __construct( string $plugin_file, string $plugin_slug, array $extra_data = [] ) {
		$this->plugin_file = $plugin_file;
		$this->plugin_slug = $plugin_slug;
		$this->extra_data  = $extra_data;
		if ( did_action( 'init' ) || doing_action( 'init' ) ) {
			$this->init_udates_check();
		} else {
			add_action( 'init', [ $this, 'init_udates_check' ] );
		}
	}

	public function init_udates_check() {
		// Plugins are installed and updated for the whole network, so a network always identifies with its main site.
		$site_url = is_multisite() ? get_site_url( get_main_site_id() ) : site_url();
		$url      = sprintf( 'https://wpify.io/?update_action=get_metadata&update_slug=%s&site_url=%s', $this->plugin_slug, $site_url );
		$url = add_query_arg( $this->extra_data, $url );

		$this->checker = PucFactory::buildUpdateChecker(
			$url,
			$this->plugin_file,
			$this->plugin_slug
		);

		$this->load_textdomain();

		// Keeps the reason with the update info PUC stores, so the plugin list can show it after the check.
		add_filter( 'puc_retain_fields-' . $this->plugin_slug, [ $this, 'retain_fields' ] );
		$this->checker->addResultFilter( [ $this, 'share_metadata' ] );
		add_action( 'in_plugin_update_message-' . plugin_basename( $this->plugin_file ), [ $this, 'update_unavailable_message' ], 10, 2 );
		add_filter( 'upgrader_pre_download', [ $this, 'pre_download' ], 10, 3 );
	}

	private function load_textdomain(): void {
		$mo_file = self::find_translation( dirname( __DIR__ ) . '/languages', 'wpify-updates-', '.mo', determine_locale() );

		if ( $mo_file ) {
			load_textdomain( 'wpify-updates', $mo_file );
		}
	}

	/**
	 * Translation file for the locale, or for another locale of the same language when there is none
	 * (de_AT, de_CH and de_DE_formal use de_DE; the {language}_{LANGUAGE} variant is preferred).
	 *
	 * @param string $dir    Directory with the translations.
	 * @param string $prefix File name before the locale, e.g. 'wpify-updates-'.
	 * @param string $suffix File name after the locale, e.g. '.mo'.
	 * @param string $locale Locale.
	 *
	 * @return string Empty when no translation exists.
	 */
	private static function find_translation( string $dir, string $prefix, string $suffix, string $locale ): string {
		$file = $dir . '/' . $prefix . $locale . $suffix;
		if ( file_exists( $file ) ) {
			return $file;
		}

		$language  = strtolower( strtok( $locale, '_' ) );
		$preferred = $dir . '/' . $prefix . $language . '_' . strtoupper( $language ) . $suffix;
		if ( file_exists( $preferred ) ) {
			return $preferred;
		}

		$files = glob( $dir . '/' . $prefix . $language . '_*' . $suffix );

		return $files ? $files[0] : '';
	}

	public function retain_fields( $fields ) {
		$fields[] = 'update_unavailable';

		return $fields;
	}

	/**
	 * Other packages (e.g. the licence) read their own data from the update
	 * response here instead of sending a request of their own.
	 */
	public function share_metadata( $info, $result = null ) {
		if ( ! is_array( $result ) || 200 !== wp_remote_retrieve_response_code( $result ) ) {
			return $info;
		}

		$data = json_decode( wp_remote_retrieve_body( $result ), true );

		if ( ! is_array( $data ) ) {
			return $info;
		}

		do_action( 'wpify_updates_metadata', $this->plugin_slug, $data );

		return $info;
	}

	/**
	 * WordPress only says the automatic update is unavailable; this adds why.
	 */
	public function update_unavailable_message( $plugin_data = [], $response = null ) {
		if ( ! empty( $response->package ) ) {
			return;
		}

		$update = $this->checker ? $this->checker->getUpdate() : null;
		// PUC stores its state as JSON, so the reason comes back as an object.
		$reason = $update ? (array) $update->update_unavailable : array();

		if ( ! empty( $reason['code'] ) ) {
			echo ' ' . esc_html( $this->get_reason_message( $reason ) );
		}
	}

	/**
	 * WordPress reports a refused download only by its HTTP status. Downloading
	 * the package here (the same single request) lets the reason reach the admin.
	 */
	public function pre_download( $reply, $package, $upgrader ) {
		if ( false !== $reply || ! is_string( $package ) || ! $this->is_own_package( $package ) ) {
			return $reply;
		}

		$upgrader->skin->feedback( 'downloading_package', $package );

		$file = download_url( $package, 300 );

		if ( ! is_wp_error( $file ) ) {
			return $file;
		}

		$data   = $file->get_error_data();
		$body   = is_array( $data ) && ! empty( $data['body'] ) ? json_decode( $data['body'], true ) : null;
		$reason = is_array( $body ) && ! empty( $body['code'] ) ? $this->get_reason_message( $body ) : $file->get_error_message();

		return new \WP_Error( 'download_failed', $upgrader->strings['download_failed'], $reason );
	}

	private function is_own_package( string $package ): bool {
		parse_str( (string) wp_parse_url( $package, PHP_URL_QUERY ), $query );

		return 'download' === ( $query['update_action'] ?? '' ) && $this->plugin_slug === ( $query['update_slug'] ?? '' );
	}

	/**
	 * The update server sends a code and an English message; codes known here are shown in the admin's language.
	 */
	private function get_reason_message( array $reason ): string {
		$domain = $reason['domain'] ?? '';

		switch ( $reason['code'] ) {
			case 'domain-mismatch':
				return $domain
					/* translators: %s: domain the license is activated for */
					? sprintf( __( 'The license is activated for the domain %s, not for this site.', 'wpify-updates' ), $domain )
					: __( 'The license is activated for a different domain.', 'wpify-updates' );
			case 'not-found':
				return __( 'The license activation was not found. It may have been deactivated in your WPify account.', 'wpify-updates' );
			case 'expired':
				return 'on-hold' === ( $reason['subscription_status'] ?? '' )
					? __( 'The subscription is on hold, waiting for payment.', 'wpify-updates' )
					: __( 'The subscription is not active.', 'wpify-updates' );
			case 'no-subscription':
				return __( 'No active subscription was found for this license.', 'wpify-updates' );
			case 'invalid-license':
				return __( 'The license key is not valid.', 'wpify-updates' );
		}

		return (string) ( $reason['message'] ?? '' );
	}
}
