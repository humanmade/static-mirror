<?php

namespace Static_Mirror;


class Admin {

	static $instance;

	public function get_instance() {

		if ( ! self::$instance ) {
			$class = get_called_class();
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * Add the Tools page page
	 */
	public function add_tools_page() {
		add_submenu_page( 'tools.php', 'Static Mirrors', 'Static Mirror', 'activate_plugins', 'static-mirror-tools-page', array( $this, 'output_tools_page' ) );
	}

	public function output_tools_page() {

		global $current_screen;

		$current_screen->post_type = 'static-mirror';
		
		$list_table = new List_Table( array(
			'screen' => $current_screen
		) );

		$list_table->prepare_items();

		?>
		<div class="wrap">
			<h2 class="page-title">Static Mirrors</h2>
			<?php $list_table->display(); ?>
		</div>
		<?php


	}
}