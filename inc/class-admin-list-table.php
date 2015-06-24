<?php

namespace Static_Mirror;

require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-posts-list-table.php';

class List_Table extends \WP_Posts_List_Table {

	/**
	 * Constructor
	 *
	 * @param array $args An associative array of arguments.
	 */
	public function __construct( $args = array() ) {

		// Call WP_Posts_List_Table constructor
		parent::__construct();

		/**
		 * Add admin JS and CSS
		 *
		 * NOTE: Don't use add_action( 'admin_print_styles', ... ) because
		 * class is instasialised in the templates/admin-tools-page.php
		 * and all header/setup actions have already been run
		 */
		$this->register_admin_js_css();
	}

	/**
	 * Register admin JS and CSS
	 */
	public function register_admin_js_css() {
		// JS
		wp_enqueue_script( 'jquery-ui-datepicker' );
		wp_enqueue_script( 'static-mirror-jquery-date-picker', SM_PLUGIN_URL . 'js/admin.js' );

		// CSS
		wp_enqueue_style( 'jquery-ui-datepicker', 'http://ajax.googleapis.com/ajax/libs/jqueryui/1.8.18/themes/smoothness/jquery-ui.css' );
	}

	public function prepare_items() {
		global $avail_post_stati, $wp_query, $per_page, $mode;

		/**
		 * Remove the permissions check for the query as we want anyone who can view
		 * this page to be able to view the static mirrors on it.
		 *
		 * Add in date filtering based on pickers
		 */
		add_action( 'parse_query', function( $q ) {

			$date = $this->date_posted();

			$q->set( 'perm', '' );
			$q->set( 'author', '' );
			$q->set( 'date_query', array(
				array(
					'after'  => $date['from'],
					'before' => $date['to'],
				),
				'inclusive' => true
			) );
		} );

		$avail_post_stati = wp_edit_posts_query( array(
			'post_status' => 'private',
			'post_type'   => 'static-mirror',
		) );

		$this->hierarchical_display = ( is_post_type_hierarchical( $this->screen->post_type ) && 'menu_order title' == $wp_query->query['orderby'] );

		$total_items = $this->hierarchical_display ? $wp_query->post_count : $wp_query->found_posts;

		$post_type = $this->screen->post_type;
		$per_page = $this->get_items_per_page( 'edit_' . $post_type . '_per_page' );

		/** This filter is documented in wp-admin/includes/post.php */
 		$per_page = apply_filters( 'edit_posts_per_page', $per_page, $post_type );

		if ( $this->hierarchical_display )
			$total_pages = ceil( $total_items / $per_page );
		else
			$total_pages = $wp_query->max_num_pages;

		if ( ! empty( $_REQUEST['mode'] ) ) {
			$mode = $_REQUEST['mode'] == 'excerpt' ? 'excerpt' : 'list';
			set_user_setting ( 'posts_list_mode', $mode );
		} else {
			$mode = get_user_setting ( 'posts_list_mode', 'list' );
		}

		$this->is_trash = isset( $_REQUEST['post_status'] ) && $_REQUEST['post_status'] == 'trash';

		$this->set_pagination_args( array(
			'total_items' => $total_items,
			'total_pages' => $total_pages,
			'per_page' => $per_page
		) );
	}

	/**
	 * Display the table
	 *
	 * @since 3.1.0
	 * @access public
	 */
	public function display() {
		$singular = $this->_args['singular'];
		$this->display_tablenav( 'top' );
		?>
		<table class="wp-list-table <?php echo implode( ' ', $this->get_table_classes() ); ?>">
			<thead>
			<tr>
				<?php $this->print_column_headers(); ?>
			</tr>
			</thead>

			<tfoot>
			<tr>
				<?php $this->print_column_headers( false ); ?>
			</tr>
			</tfoot>

			<tbody id="the-list"<?php
				if ( $singular ) {
					echo " data-wp-lists='list:$singular'";
				} ?>>
				<?php $this->display_in_progress_row() ?>
				<?php $this->display_rows_or_placeholder(); ?>
			</tbody>
		</table>
		<?php
		$this->display_tablenav( 'bottom' );
	}

	public function bulk_actions( $which = '' ) {
	}

	/**
	 * Don't display a view switcher
	 *
	 * @param string $current_mode
	 */
	protected function view_switcher( $current_mode ) {
	}

	/**
	 * Add extra markup in the toolbars before or after the table list
	 * Date filters, to show Static Mirrors for a specific period of time
	 *
	 * Add to both top and bottom of the table list
	 *
	 * @param string $which Identifies the place to add a toolbar
	 *                      before (top) or after (bottom) the table list
	 */
	protected function extra_tablenav( $which ) {
		?>
		<div class="alignleft actions">
			<?php
			if ( ! is_singular() ) {

				// Date picker fields for the date range filtering
				$this->date_picker_range( $which );

				/**
				 * Fires before the Filter button on the Posts and Pages list tables.
				 *
				 * The Filter button allows sorting by date and/or category on the
				 * Posts list table, and sorting by date on the Pages list table.
				 *
				 * @since 2.1.0
				 */
				do_action( 'restrict_manage_posts' );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Displays date range input fields
	 *
	 * @param string $which Identifies the place the date range fields
	 *                      are being displayed at: before (top) or after (bottom)
	 *                      the table list
	 */
	protected function date_picker_range( $which ) {

		$date = $this->date_posted();
		?>
		<h3><?php esc_html_e( 'Filter by date range' ); ?></h3>
		<form method="post" action="<?php echo esc_url( add_query_arg( 'page', $_GET['page'], 'tools.php' ) ); ?>">
			<input type="hidden" name="action" value="filter-date-range" />
			<?php wp_nonce_field( 'static-mirror.filter-date-range' ); ?>

			<label for="date-from-<?php echo esc_attr( $which ); ?>"><?php esc_html_e( 'Date from:' ); ?></label>
			<input id="date-from-<?php echo esc_attr( $which ); ?>" class="datepicker date-from"
			       type="text" name="date-from" value="<?php echo esc_attr( $date['from'] ); ?>" />
			<label for="date-to-<?php echo esc_attr( $which ); ?>"><?php esc_html_e( 'Date to:' ); ?></label>
			<input id="date-to-<?php echo esc_attr( $which ); ?>" class="datepicker date-to"
			       type="text" name="date-to" value="<?php echo esc_attr( $date['to'] ); ?>" />

			<?php
			submit_button( __( 'Filter' ), 'button', 'filter', false );
			submit_button( __( 'Clear Filter' ), 'button', 'clear-filter', false );
			?>
		</form>

		<?php
	}

	/**
	 * Grab dates picked from filter and sets logic based on whether the form
	 * was to filter or clear the filter.
	 *
	 * @return array Dates selected from and to
	 */
	protected function date_posted() {

		$date['from'] = isset( $_POST['date-from'] ) && isset( $_POST['filter'] ) && ! isset( $_POST['clear-filter'] ) ? $_POST['date-from'] : '';
		$date['to']   = isset( $_POST['date-to'] ) && isset( $_POST['filter'] ) && ! isset( $_POST['clear-filter'] ) ? $_POST['date-to'] : '';

		return $date;
	}

	/**
	 * Message to be displayed when there are no items
	 *
	 * @since 3.1.0
	 * @access public
	 */
	public function no_items() {
		_e( 'No items found.' );
	}

	/**
	 * Get a list of all, hidden and sortable columns, with filter applied
	 *
	 * @since 3.1.0
	 * @access protected
	 *
	 * @return array
	 */
	public function get_column_info() {

		$columns = array(
			'changelog' => 'Changelog',
			'info' => 'Snapshot',
		);

		$this->_column_headers = array( $columns, array(), array() );

		return $this->_column_headers;
	}

	protected function column_info( $post ) {

		$wp_upload_dir = wp_upload_dir();

		$permalink = dirname( $wp_upload_dir['baseurl'] ) . get_post_meta( $post->ID, '_dir_rel', true ) . 'index.html';
		?>
		<strong><a href="<?php echo esc_url( $permalink ) ?>"><?php echo esc_html( $post->post_title ) ?></a></strong>

		<?php
	}

	protected function column_changelog( $post ) {

		echo $this->get_changelog_html( get_post_meta( $post->ID, '_changelog', true ) );
		$wp_upload_dir = wp_upload_dir();
		$permalink = dirname( $wp_upload_dir['baseurl'] ) . get_post_meta( $post->ID, '_dir_rel', true ) . 'index.html';
		echo $this->row_actions( array(
			'view' => '<a href="' . esc_url( $permalink ) . '">View</a>'
		) );
	}

	/**
	 * Generates content for a single row of the table
	 *
	 * @since 3.1.0
	 * @access public
	 *
	 * @param object $item The current item
	 */
	public function single_row( $item, $level = 0 ) {
		static $row_class = '';
		$row_class = ( $row_class == '' ? ' class="alternate"' : '' );

		echo '<tr' . $row_class . '>';
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	public function display_in_progress_row() {

		if ( ! get_option( 'static_mirror_next_changelog' ) && ! get_option( 'static_mirror_in_progress' ) ) {
			return;
		}

		list( $columns, $hidden ) = $this->get_column_info();

		$message = '';

		if ( $changelog = get_option( 'static_mirror_next_changelog' ) ) {
			$next = wp_next_scheduled( 'static_mirror_create_mirror' );

			if ( $next < time() ) {
				$message .= sprintf( "Static Mirror is queued but in the past (%d seconds ago), please make sure WP Cron is functioning.", time() - $next );
			} else {
				$message .= "Static Mirror queued in " . ( $next - time() ) . ' seconds. ';
			}

			$message .= $this->get_changelog_html( $changelog );
		}

		if ( $in_progress = get_option( 'static_mirror_in_progress' ) ) {

			$message .= "Static Mirror is running. Started " . ( time() - $in_progress['time'] ) . ' seconds ago. ';

			$message .= $this->get_changelog_html( $in_progress['changelog'] );
		}
		?>
		<tr style="background-color: #999; text-align: center;">
			<td style="color: #fff;" colspan="<?php echo count( $columns ) ?>">
				<?php echo $message ?>
			</td>
		</tr>
		<?php
	}

	protected function get_changelog_html( $changelog ) {

		$message = '<ul style="text-align: left">';
		foreach ( $changelog as $change ) {
			$message .= sprintf(
				'<li>%s - %s</li>',
				esc_html( date_i18n( "g:ia", $change['date'], true ) ),
				esc_html( $change['text'] )
			);
		}
		$message .= '</ul>';

		return $message;
	}

	/**
	 * Generates the columns for a single row of the table
	 *
	 * @since 3.1.0
	 * @access protected
	 *
	 * @param object $item The current item
	 */
	public function single_row_columns( $item ) {
		list( $columns, $hidden ) = $this->get_column_info();

		foreach ( $columns as $column_name => $column_display_name ) {
			$class = "class='$column_name column-$column_name'";

			$style = '';
			if ( in_array( $column_name, $hidden ) )
				$style = ' style="display:none;"';

			$attributes = "$class$style";

			if ( 'cb' == $column_name ) {
				echo '<th scope="row" class="check-column">';
				echo $this->column_cb( $item );
				echo '</th>';
			}
			elseif ( method_exists( $this, 'column_' . $column_name ) ) {
				echo "<td $attributes>";
				echo call_user_func( array( $this, 'column_' . $column_name ), $item );
				echo "</td>";
			}
			else {
				echo "<td $attributes>";
				echo $this->column_default( $item, $column_name );
				echo "</td>";
			}
		}
	}
}
