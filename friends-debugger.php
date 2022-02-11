<?php
/**
 * Plugin name: Friends Debugger
 * Plugin author: Alex Kirk
 * Plugin URI: https://github.com/akirk/friends-debugger
 * Version: 0.1
 *
 * Description: Activates a debug mode for the Friends plugin and outputs some debug data.
 *
 * License: GPL2
 * Text Domain: friends
 *
 * @package Friends_Send_To_E_Reader
 */

add_filter( 'friends_show_cached_posts', '__return_true' );
add_filter( 'friends_debug', '__return_true' );
add_filter( 'friends_show_cached_posts', '__return_true' );
add_filter( 'friends_deactivate_plugin_cache', '__return_false' );

function friends_debug_feed_last_log() {
	$term_query = new \WP_Term_Query(
		array(
			'taxonomy' => Friends\User_Feed::TAXONOMY,
		)
	);
	$feeds = array();
	foreach ( $term_query->get_terms() as $term ) {
		$user_feed = new Friends\User_Feed( $term, new Friends\User() );

		if ( ! $user_feed->is_active() ) {
			continue;
		}

		foreach ( get_objects_in_term( $term->term_id, Friends\User_Feed::TAXONOMY ) as $user_id ) {
			$userdata = get_user_by( 'ID', $user_id );
			if ( ! $userdata ) {
				continue;
			}
			$feeds[] = new Friends\User_Feed( $term, new Friends\User( $userdata ) );
		}
	}
	?><h1>Feed Log</h1>
	<?php
	if ( empty( $feeds ) ) {
		echo 'No active feeds found.';
		return;
	}
	usort(
		$feeds,
		function( $a, $b ) {
			return strcmp( $b->get_last_log(), $a->get_last_log() );
		}
	);

	?>
	Current time: <?php echo date( 'r' ); ?>
	<table>
		<?php
		foreach ( $feeds as $user_feed ) {
			$friend_user = $user_feed->get_friend_user();
			?>
		<tr>
			<td>
				<a href="<?php echo esc_url( $user_feed->get_url() ); ?>" target="_blank"><?php echo esc_html( $user_feed->get_title() ); ?></a> by
				<a href="<?php echo self_admin_url( 'admin.php?page=edit-friend&user=' . esc_attr( $friend_user->ID ) ); ?>"><?php echo esc_html( $friend_user->display_name ); ?></a>
			</td>
			<td><?php echo esc_html( $user_feed->get_last_log() ); ?></td>
		</td>
			<?php
		}
		?>
	</table>
	<?php
}

function friends_debug_preview_email() {
	if ( ! isset( $_GET['preview-email'] ) || ! is_numeric( $_GET['preview-email'] ) ) {
		return;
	}

	$post = get_post( $_GET['preview-email'] );
	echo '<p>';
	if ( isset( $_GET['send'] ) ) {
		echo 'sent:', PHP_EOL;
		do_action( 'notify_new_friend_post', $post );
	} else {
		echo '<a href="' . esc_attr( 'admin.php?page=friends-settings&send&preview-email=' . $_GET['preview-email'] ) . '">send notification</a>:', PHP_EOL;
	}
	echo '</p>';

	$author      = new Friends\User( $post->post_author );
	$email_title = $post->post_title;

	Friends\Friends::template_loader()->get_template_part( 'email/header', null, array( 'email_title' => $email_title ) );
	Friends\Friends::template_loader()->get_template_part(
		'email/new-friend-post',
		null,
		array(
			'author' => $author,
			'post'   => $post,
		)
	);
	Friends\Friends::template_loader()->get_template_part( 'email/footer' );
	exit;
}


add_action(
	'friends_entry_dropdown_menu',
	function() {
		if ( apply_filters( 'friends_debug', false ) ) {
			?>
		<li class="menu-item"><a href="<?php echo esc_url( self_admin_url( 'admin.php?page=friends-settings&preview-email=' . get_the_ID() ) ); ?>" class="friends-preview-email"><?php esc_html_e( 'Preview Notification E-Mail', 'friends' ); ?></a></li>
			<?php
		}
	}
);


add_action(
	'admin_menu',
	function () {
		if ( '' === menu_page_url( 'friends-settings', false ) ) {
			// Don't add menu when no Friends menu is shown.
			return;
		}
		add_submenu_page(
			'friends-settings',
			'Debug: Feed Log',
			'Debug: Feed Log',
			'administrator',
			'friends-last-log',
			'friends_debug_feed_last_log'
		);

		add_action( 'load-toplevel_page_friends-settings', 'friends_debug_preview_email' );

	},
	50
);

add_filter(
	'friends_friend_feed_url',
	function( $feed_url, $friend_user ) {
		global $friends_debug_enabled;
		if ( ! $friends_debug_enabled || ! defined( '\WP_ADMIN' ) || ! \WP_ADMIN || 'friends-opml' === $_GET['page'] ) {
			return $feed_url;
		}
		echo nl2br( "Refreshing <a href=\"{$feed_url}\">{$friend_user->user_login}</a>\n" );
		return $feed_url;
	},
	10,
	2
);

add_filter(
	'friends_remote_post_ids',
	function( $remote_post_ids ) {
		global $friends_debug_enabled;
		if ( ! $friends_debug_enabled || ! defined( '\WP_ADMIN' ) || ! \WP_ADMIN || empty( $remote_post_ids ) ) {
			return;
		}
		echo 'Remote Post Ids: <pre>';
		print_r( $remote_post_ids );
		echo '</pre>';
	}
);

add_filter(
	'wp_feed_options',
	function( $feed, $url ) {
		$feed->enable_cache( false );
	},
	10,
	2
);

add_action(
	'friends_retrieve_friends_error',
	function( $feed_url, $feed, $friend_user ) {
		// wp_mail( 'debug@example.com', 'friends_retrieve_friends_error', $feed_url . PHP_EOL . print_r( $feed, true ) . PHP_EOL . PHP_EOL . print_r( $friend_user, true ) . PHP_EOL . PHP_EOL );
	},
	10,
	3
);
add_action(
	'friends_retrieved_new_posts',
	function( $new_posts, $friend_user ) {
		if ( $new_posts ) {
			// wp_mail( 'debug@example.com', 'friends_retrieve_friends_success', print_r( $new_posts, true ) . PHP_EOL . PHP_EOL . print_r( $friend_user, true ) . PHP_EOL . PHP_EOL );
		}
	},
	10,
	2
);

add_action(
	'friends_page_allowed_styles',
	function( $styles ) {
		$styles[] = 'hide_updates_css';
		return $styles;
	}
);


add_action(
	'friend_post_edit_link',
	function( $link, $old_link ) {
		if ( ! $link ) {
			return $old_link;
		}
		return $link;
	},
	10,
	2
);



if ( isset( $_GET['cleanfriends'] ) ) {
	add_action(
		'plugins_loaded',
		function() {
			foreach ( wp_load_alloptions() as $name => $value ) {
				if ( 'friends_' === substr( $name, 0, 8 ) ) {
					delete_option( $name );
				}
			}
		}
	);
}
