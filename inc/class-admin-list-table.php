<?php

namespace Static_Mirror;

require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-posts-list-table.php';

class List_Table extends \WP_Posts_List_Table {

	public function prepare_items() {
		global $avail_post_stati, $wp_query, $per_page, $mode;

		$avail_post_stati = wp_edit_posts_query( array(
			'post_status' => 'private',
			'post_type' => 'static-mirror'
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
				<?php $this->display_rows_or_placeholder(); ?>
			</tbody>
		</table>
		<?php
		$this->display_tablenav( 'bottom' );
	}

	public function bulk_actions( $which = '' ) {
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
			'date' => 'Date',
			'changelog' => 'Changelog',
			'info' => 'Snapshot',
		);

		$this->_column_headers = array( $columns, array(), array() );

		return $this->_column_headers;
	}

	protected function column_info( $post ) {

		$wp_upload_dir = wp_upload_dir();

		$permalink = $wp_upload_dir['baseurl'] . get_post_meta( $post->ID, '_dir_rel', true ) . 'index.html';
		?>
		<strong><a href="<?php echo esc_url( $permalink ) ?>"><?php echo esc_html( $post->post_title ) ?></a></strong>

		<?php
	}

	protected function column_date( $post ) {

		$t_time = get_the_time( __( 'Y/m/d g:i:s A' ) );
		$m_time = $post->post_date;
		$time = get_post_time( 'G', true, $post );

		$time_diff = time() - $time;

		if ( $time_diff > 0 && $time_diff < DAY_IN_SECONDS ) {
			$h_time = sprintf( __( '%s ago' ), human_time_diff( $time ) );
		} else {
			$date_format = get_option( 'date_format', 'Y/m/d' ) . ' @ ' . get_option( 'time_format', 'H:i' );
			$h_time = mysql2date( __( $date_format ), $m_time );
		}

		/** This filter is documented in wp-admin/includes/class-wp-posts-list-table.php */
		echo '<abbr title="' . $t_time . '">' . $h_time . '</abbr>';

		$wp_upload_dir = wp_upload_dir();
		$permalink = $wp_upload_dir['baseurl'] . get_post_meta( $post->ID, '_dir_rel', true ) . 'index.html';
		echo $this->row_actions( array(
			'view' => '<a href="' . esc_url( $permalink ) . '">View</a>'
		) );

	}

	protected function column_changelog( $post ) {

		echo implode( ', ', array_map( 'esc_html', get_post_meta( $post->ID, '_changelog', true ) ) );
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