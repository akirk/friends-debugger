<?php
/**
 * Plugin name: Friends-Debugger
 * Plugin author: Alex Kirk
 * Version: 0.1
 *
 * Description: Debug Friends
 */

add_filter( 'friends_show_cached_posts', '__return_true' );
add_filter( 'friends_debug', '__return_true' );
add_filter( 'friends_show_cached_posts', '__return_true' );
add_filter( 'friends_deactivate_plugin_cache', '__return_false' );

function friends_debug_feed_last_log() {
	$term_query = new WP_Term_Query(
		array(
			'taxonomy' => Friend_User_Feed::TAXONOMY,
		)
	);
	$feeds = array();
	foreach ( $term_query->get_terms() as $term ) {
		$user_feed = new Friend_User_Feed( $term, new Friend_User() );

		if ( ! $user_feed->is_active() ) {
			continue;
		}

		foreach ( get_objects_in_term( $term->term_id, Friend_User_Feed::TAXONOMY ) as $user_id ) {
			$userdata = get_user_by( 'ID', $user_id );
			if ( ! $userdata ) {
				continue;
			}
			$feeds[] = new Friend_User_Feed( $term, new Friend_User( $userdata ) );
		}
	}
	?><h1>Feed Log</h1><?php
	if ( empty( $feeds ) ) {
		echo 'No active feeds found.';
		return;
	}
	usort( $feeds, function( $a, $b) {
		return strcmp( $b->get_last_log(), $a->get_last_log() );
	})
	?>
	<table>
		<?php
		foreach ( $feeds as $user_feed ) {
			$friend_user = $user_feed->get_friend_user();
			?>
		<tr>
			<td><a href="<?php self_admin_url( 'admin.php?page=edit-friend&user=' . esc_attr( $friend_user->ID ) ); ?>"><?php echo esc_html( $friend_user->display_name ); ?></a></td>
			<td><?php echo esc_html( $user_feed->get_last_log() ); ?></td>
		</td>
			<?php
		}
		?>
	</table>
	<?php
}

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
	},
	50
);
add_filter(
	'friends_friend_feed_url',
	function( $feed_url, $friend_user ) {
		global $friends_debug_enabled;
		if ( ! $friends_debug_enabled || ! defined( 'WP_ADMIN' ) || ! WP_ADMIN || 'friends-opml' === $_GET['page'] ) {
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
		if ( ! $friends_debug_enabled || ! defined( 'WP_ADMIN' ) || ! WP_ADMIN || empty( $remote_post_ids ) ) {
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
