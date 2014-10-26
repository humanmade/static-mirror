<!DOCTYPE html>
<html>
	<head>
		<title>Static Mirror: <?php echo date( 'c' ) ?></title>
	</head>
	<body>

		<h4>Sites</h4>
		<ul>
			<?php foreach ( $files as $file ) : ?>
				<li>
					<a href="<?php echo esc_attr( $file ) ?>/index.html"><?php echo esc_html( $file ) ?></a>
				</li>
			<?php endforeach ?>
		</ul>

		<hr />

		<h4>Changelog</h4>

		<ul>
			<?php foreach ( $changelog as $change ) : ?>
				<li><?php echo date( 'c', $change['date'] ) ?> - <?php echo esc_html( $change['text'] ) ?></li>
			<?php endforeach ?>
		</ul>
	</body>
</html>
