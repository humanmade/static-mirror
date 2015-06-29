<?php

namespace Static_Mirror;

global $current_screen;

$current_screen->post_type = 'static-mirror';

$list_table = new List_Table( array(
	'screen' => $current_screen
) );

$list_table->run();
$list_table->prepare_items();

?>
<div class="wrap">
	<h2 class="page-title">
		Static Mirrors
		<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'static-mirror-create-mirror' ), 'static-mirror-create' ) ); ?>" class="add-new-h2">Create Mirror Now</a>
	</h2>

	<form method="post" action="<?php echo esc_url( add_query_arg( 'page', $_GET['page'], 'tools.php' ) ) ?>">
		<input type="hidden" name="action" value="update-static-mirror" />
		<?php wp_nonce_field( 'static-mirror.update' ) ?>
		<table class="form-table">
			<tbody>
				<tr>
					<th scope="row">
						<label for="static-mirror-urls">Starting URLs</label></th>
					<td>
						<textarea name="static-mirror-urls" id="static-mirror-urls" style="width: 300px; min-height: 100px" class="regular-text"><?php echo esc_textarea( implode("\n", Plugin::get_instance()->get_base_urls() ) ) ?></textarea>
						<p class="description">All the different "sites" you want to create mirrors of.</p>
					</td>
				</tr>
			</tbody>

		</table>

		<p class="submit">
			<input type="submit" name="submit" id="submit" class="button button-primary" value="Save Changes">
		</p>
	</form>

	<?php $list_table->display(); ?>
</div>
