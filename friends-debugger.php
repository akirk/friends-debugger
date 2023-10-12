<?php
/**
 * Plugin name: Friends Debugger
 * Plugin author: Alex Kirk
 * Plugin URI: https://github.com/akirk/friends-debugger
 * Version: 0.2
 * Requires Plugins: friends
 *
 * Description: Activates a debug mode for the Friends plugin and outputs some debug data.
 *
 * License: GPL2
 * Text Domain: friends
 *
 * @package Friends_Debugger
 */

use Friends\Feed_Parser_ActivityPub;

add_filter( 'friends_show_cached_posts', '__return_true' );
add_filter( 'friends_debug', '__return_true' );
add_filter( 'friends_show_cached_posts', '__return_true' );
add_filter( 'friends_deactivate_plugin_cache', '__return_false' );
add_filter(
	'friends_debug_enqueue',
	function( $version, $handle, $file ) {
		return filemtime( $file );
	},
	10,
	3
);

add_filter(
	'friends_http_timeout',
	function() {
		return 5;
	}
);

/**
 * Feed debug log display
 */
function friends_debug_feed_last_log() {
	global $wpdb;
	$term_query = new \WP_Term_Query(
		array(
			'taxonomy'   => Friends\User_Feed::TAXONOMY,
			'hide_empty' => false,
		)
	);

	$feeds = array();
	echo '<form method="post">';
	$has_multiple_associations = false;
	wp_nonce_field( 'delete-assoc' );
	if ( ! empty( $_POST ) ) {
		if ( wp_verify_nonce( $_POST['_wpnonce'], 'delete-assoc' ) ) {
			if ( ! empty( $_POST['delete_assoc'] ) ) {
				foreach ( $_POST['delete_assoc'] as $term_taxonomy_id => $object_id ) {
					$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->term_relationships WHERE term_taxonomy_id = %d  AND object_id = %d LIMIT 1", $term_taxonomy_id, $object_id ) );
					echo 'Deleted association between ', $term_taxonomy_id, ' and ', $object_id, '<br/>';
				}
			}
		}
	}
	foreach ( $term_query->get_terms() as $term ) {
		$user_feed = new Friends\User_Feed( $term );
		$users_for_feed = $user_feed->get_all_friend_users();
		$objects_for_feed = get_objects_in_term( $term->term_id, Friends\User_Feed::TAXONOMY );
		if ( empty( $users_for_feed ) ) {
			echo 'Unassociated feed <a href="', esc_url( $term->name ), '" target="_blank">', esc_html( $term->name ), '</a>. ';
			if ( isset( $_GET['delete'] ) ) {
				wp_delete_term( $term->term_id, Friends\User_Feed::TAXONOMY );
				echo 'Term deleted.';
			} else {
				echo 'Would delete term.';
			}
			echo '<br/>', PHP_EOL;
			continue;
		}

		if ( count( $users_for_feed ) > 1 ) {
			$has_multiple_associations = true;
			echo 'Feed <a href="', esc_url( $term->name ), '" target="_blank">', esc_html( $term->name ), '</a> attached to multiple users: ';
			foreach ( $users_for_feed as $user ) {
				echo '<input type="checkbox" name="delete_assoc[', esc_attr( $term->term_taxonomy_id ), ']" value="', esc_attr( $user->ID ), '"/> ';
				echo '<a href="', self_admin_url( 'admin.php?page=edit-friend&user=' . esc_attr( $user->user_login ) ), '">', esc_html( $user->display_name ), '</a> ';
			}
			echo '<br/>', PHP_EOL;
		}
		if ( ! $user_feed->is_active() ) {
			continue;
		}
		if ( count( $objects_for_feed ) !== count( $users_for_feed ) ) {
			foreach ( $objects_for_feed as $i => $object_id ) {
				foreach ( $users_for_feed as $user ) {
					if ( $user->get_object_id() === $object_id ) {
						unset( $objects_for_feed[ $i ] );
						continue 2;
					}
				}
			}
			foreach ( $objects_for_feed as $i => $object_id ) {
				if ( 1 === count( $users_for_feed ) ) {
					if ( $object_id === $users_for_feed[0]->get_object_id() ) {
						continue;
					}
					echo 'Leftover feed <a href="', esc_url( $term->name ), '" target="_blank">', esc_html( $term->name ), '</a> for user id ', $object_id, '. ';
					if ( isset( $_GET['relink'] ) ) {
						wp_delete_object_term_relationships( $object_id, Friends\User_Feed::TAXONOMY );
						wp_set_object_terms( $object_id, $user_feed->get_id(), Friends\User_Feed::POST_TAXONOMY );
						echo 'Term relinked.';
					} elseif ( isset( $_GET['delete'] ) ) {
						wp_delete_object_term_relationships( $object_id, Friends\User_Feed::TAXONOMY );
						echo 'Term deleted.';
					} else {
						echo 'Would delete relationship. Existing user ';
						?><a href="<?php echo self_admin_url( 'admin.php?page=edit-friend&user=' . esc_attr( $users_for_feed[0]->user_login ) ); ?>"><?php echo esc_html( $users_for_feed[0]->display_name ); ?></a> (<?php echo esc_html( $users_for_feed[0]->ID ); ?>)
						<?php

					}
				} else {
					echo 'Feed <a href="', esc_url( $term->name ), '" target="_blank">', esc_html( $term->name ), '</a> attached to unknown user id ', $object_id, '. ';
					echo ' Already attached to ';
					foreach ( $users_for_feed as $user ) {
						if ( $user->get_object_id() === $object_id ) {
							continue;
						}
						echo '<a href="', self_admin_url( 'admin.php?page=edit-friend&user=' . esc_attr( $user->user_login ) ), '">', esc_html( $user->display_name ), '</a> ';
					}

					if ( isset( $_GET['delete'] ) ) {
						wp_remove_object_terms( $object_id, array( $term->term_id ), Friends\User_Feed::TAXONOMY );
						echo 'Removed association.';
					} else {
						echo 'Would remove association.';
					}
				}
				echo '<br/>', PHP_EOL;
				continue;
			}
		}
		if ( is_multisite() && ! is_user_member_of_blog( $user_feed->get_friend_user()->ID, get_current_blog_id() ) ) {
			continue;
		}
		$feeds[ $term->term_id ] = $user_feed;
	}
	if ( $has_multiple_associations ) {
		echo '<button>Delete multiple associations</button>';
	}
	echo '</form>';
	?>
	<h1>Feed Log</h1>
	<?php
	if ( empty( $feeds ) ) {
		echo 'No active feeds found.';
		return;
	}
	uasort(
		$feeds,
		function( $a, $b ) {
			return strcmp( $b->get_last_log(), $a->get_last_log() );
		}
	);

	?>
	Current time: <?php echo gmdate( 'r' ); ?>
	<table>
		<?php
		foreach ( $feeds as $user_feed ) {
			$friend_user = $user_feed->get_friend_user();
			if ( empty( $friend_user->display_name ) ) {
				var_dump( $friend_user );
				exit;
			}
			?>
		<tr>
			<td>
				<a href="<?php echo esc_url( $user_feed->get_url() ); ?>" target="_blank"><?php echo esc_html( $user_feed->get_title() ? $user_feed->get_title() : 'Untitled' ); ?></a> by
				<a href="<?php echo self_admin_url( 'admin.php?page=edit-friend&user=' . esc_attr( $friend_user->user_login ) ); ?>"><?php echo esc_html( $friend_user->display_name ); ?></a>
			</td>
			<td>
			<?php
			echo esc_html( $user_feed->get_last_log() );
			echo ' ';
			echo wp_kses_post(
				sprintf(
				// translators: %s is a date.
					__( 'Will be fetched again at %s.', 'friends' ),
					esc_html( $user_feed->get_next_poll() )
				)
			);
			?>
			</td>
		</tr>
			<?php
		}
		?>
	</table>

	<h1>Due next</h1>
	<table>
	<?php
	foreach ( Friends\User_Feed::get_all_due() as $user_feed ) {
		unset( $feeds[ $user_feed->get_id() ] );

		$friend_user = $user_feed->get_friend_user();
		if ( empty( $friend_user->display_name ) ) {
			var_dump( $user_feed );
			continue;
		}
		?>
	<tr>
		<td>
			<a href="<?php echo esc_url( $user_feed->get_url() ); ?>" target="_blank"><?php echo esc_html( $user_feed->get_title() ? $user_feed->get_title() : 'Untitled' ); ?></a> by
			<a href="<?php echo self_admin_url( 'admin.php?page=edit-friend&user=' . esc_attr( $friend_user->user_login ) ); ?>"><?php echo esc_html( $friend_user->display_name ); ?></a>
		</td>
		<td>
		<?php
		echo esc_html( $user_feed->get_last_log() );
		echo ' ';
		echo wp_kses_post(
			sprintf(
			// translators: %s is a date.
				__( 'Will be fetched again at %s.', 'friends' ),
				esc_html( $user_feed->get_next_poll() )
			)
		);
		?>
		</td>
	</tr>
		<?php
	}

	?>
	</table>

	<h1>Not due</h1>

	<table>
	<?php
	foreach ( $feeds as $user_feed ) {
		if ( $user_feed->get_parser() === Feed_Parser_ActivityPub::SLUG ) {
			continue;
		}
		if ( $user_feed->get_next_poll() > gmdate( 'Y-m-d H:i:s', time() ) ) {
			continue;
		}
		$friend_user = $user_feed->get_friend_user();
		if ( empty( $friend_user->display_name ) ) {
			var_dump( $user_feed );
			continue;
		}
		?>
	<tr>
		<td>
			<a href="<?php echo esc_url( $user_feed->get_url() ); ?>" target="_blank"><?php echo esc_html( $user_feed->get_title() ? $user_feed->get_title() : 'Untitled' ); ?></a> by
			<a href="<?php echo self_admin_url( 'admin.php?page=edit-friend&user=' . esc_attr( $friend_user->user_login ) ); ?>"><?php echo esc_html( $friend_user->display_name ); ?></a>
		</td>
		<td>
		<?php
		echo esc_html( $user_feed->get_last_log() );
		echo ' ';
		echo wp_kses_post(
			sprintf(
			// translators: %s is a date.
				__( 'Will be fetched again at %s.', 'friends' ),
				esc_html( $user_feed->get_next_poll() )
			)
		);
		?>
		</td>
	</tr>
		<?php
	}

	?>
	</table>
	<?php
}

/**
 * Preview notification email
 */
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
		echo '<a href="' . esc_attr( 'admin.php?page=friends&send&preview-email=' . $_GET['preview-email'] ) . '">send notification</a>:', PHP_EOL;
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

/**
 * Display extracted tags from a post
 */
function friends_debug_extract_tags() {
	if ( ! isset( $_GET['check-feed-modifications'] ) || ! is_numeric( $_GET['check-feed-modifications'] ) ) {
		return;
	}

	$post = get_post( $_GET['check-feed-modifications'] );
	$item = new Friends\Feed_Item(
		array(
			'post_content' => $post->post_content,
		)
	);
	$item = apply_filters( 'friends_modify_feed_item', $item, null, Friends\User::get_post_author( $post ), $post->ID );
	echo '<pre>';
	var_dump( $item );
	exit;
}


add_action(
	'friends_entry_dropdown_menu',
	function() {
		if ( apply_filters( 'friends_debug', false ) ) {
			?>
		<li class="menu-item"><a href="<?php echo esc_url( self_admin_url( 'admin.php?page=friends&preview-email=' . get_the_ID() ) ); ?>" class="friends-preview-email"><?php esc_html_e( 'Preview Notification E-Mail', 'friends' ); ?></a></li>
			<?php
		}
	}
);

add_action(
	'friends_entry_dropdown_menu',
	function() {
		if ( apply_filters( 'friends_debug', false ) ) {
			?>
		<li class="menu-item"><a href="<?php echo esc_url( self_admin_url( 'admin.php?page=friends&check-feed-modifications=' . get_the_ID() ) ); ?>" class="friends-check-feed-modifications"><?php esc_html_e( 'Extract Tags', 'friends' ); ?></a></li>
			<?php
		}
	}
);


add_action(
	'admin_menu',
	function () {
		if ( '' === menu_page_url( 'friends', false ) ) {
			// Don't add menu when no Friends menu is shown.
			return;
		}
		add_submenu_page(
			'friends',
			'Debug: Feed Log',
			'Debug: Feed Log',
			'edit_private_posts',
			'friends-last-log',
			'friends_debug_feed_last_log'
		);

		add_action( 'load-toplevel_page_friends', 'friends_debug_preview_email' );
		add_action( 'load-toplevel_page_friends', 'friends_debug_extract_tags' );
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
		// phpcs:ignore // wp_mail( 'debug@example.com', 'friends_retrieve_friends_error', $feed_url . PHP_EOL . print_r( $feed, true ) . PHP_EOL . PHP_EOL . print_r( $friend_user, true ) . PHP_EOL . PHP_EOL );
	},
	10,
	3
);
add_action(
	'friends_retrieved_new_posts',
	function( $new_posts, $friend_user ) {
		if ( $new_posts ) {
			// phpcs:ignore // wp_mail( 'debug@example.com', 'friends_retrieve_friends_success', print_r( $new_posts, true ) . PHP_EOL . PHP_EOL . print_r( $friend_user, true ) . PHP_EOL . PHP_EOL );
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

add_action(
	'friends_add_friend_postdata',
	function( $postdata ) {
		if ( isset( $_GET['url'] ) && isset( $_GET['step2'] ) ) {
			$postdata['friend_url'] = $_GET['url'];
			$postdata['_wpnonce'] = wp_create_nonce( 'add-friend' );
		}
		return $postdata;
	}
);


add_action(
	'friends_feed_table_header',
	function() {
		?>
		<th><?php esc_html_e( 'MIME Type', 'friends' ); ?></th>
		<?php
	}
);

add_action(
	'friends_feed_table_row',
	function( $feed, $term_id ) {
		?>
		<td><input type="text" name="feeds[<?php echo esc_attr( $term_id ); ?>][mime-type]" value="<?php echo esc_attr( $feed->get_mime_type() ); ?>" size="20" aria-label="<?php esc_attr_e( 'Feed Type', 'friends' ); ?>" /></td>
		<?php
	},
	10,
	2
);


add_action(
	'friends_feed_list_item',
	function( $feed, $term_id ) {
		?>
		<tr>
			<th><?php esc_html_e( 'MIME Type', 'friends' ); ?></th>
		<td><input type="text" name="feeds[<?php echo esc_attr( $term_id ); ?>][mime-type]" value="<?php echo esc_attr( 'new' === $term_id ? '' : $feed->get_mime_type() ); ?>" size="20" aria-label="<?php esc_attr_e( 'Feed Type', 'friends' ); ?>" /></td>
		</tr>
		<?php
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

function friends_debugger_register_stream_connector( $classes ) {
	require plugin_dir_path( __FILE__ ) . '/class-debug-stream-connector.php';

	$class_name = '\Friends\Debug_Stream_Connector';

	if ( ! class_exists( $class_name ) ) {
		return;
	}

	wp_stream_get_instance();
	$class = new $class_name();

	if ( ! method_exists( $class, 'is_dependency_satisfied' ) ) {
		return;
	}

	if ( $class->is_dependency_satisfied() ) {
		$classes[] = $class;
	}

	return $classes;
}
add_filter( 'wp_stream_connectors', 'friends_debugger_register_stream_connector' );
