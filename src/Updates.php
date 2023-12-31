<?php

namespace Wpify\Updates;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

class Updates {

	private $plugin_file;
	private $plugin_slug;
	private $extra_data;

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
		$url = sprintf( 'https://wpify.io/?update_action=get_metadata&update_slug=%s&site_url=%s', $this->plugin_slug, site_url() );
		$url = add_query_arg( $this->extra_data, $url );

		PucFactory::buildUpdateChecker(
			$url,
			$this->plugin_file,
			$this->plugin_slug
		);
	}
}