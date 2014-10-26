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

		$permalink = $wp_upload_dir['baseurl'] . get_post_meta( $post->ID, '_dir_rel', true ) . 'index.html';
		?>
		<strong><a href="<?php echo esc_url( $permalink ) ?>"><?php echo esc_html( $post->post_title ) ?></a></strong>

		<?php
	}

	protected function column_changelog( $post ) {

		echo $this->get_changelog_html( get_post_meta( $post->ID, '_changelog', true ) );
		$wp_upload_dir = wp_upload_dir();
		$permalink = $wp_upload_dir['baseurl'] . get_post_meta( $post->ID, '_dir_rel', true ) . 'index.html';
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
			$message .= '<li>' . date( "g:ia", $change['date'] ) . ' - ' . $change['text'] . '</li>';
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