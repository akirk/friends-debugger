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
	function ( $version, $handle, $file ) {
		return filemtime( $file );
	},
	10,
	3
);

add_filter(
	'friends_http_timeout',
	function () {
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
				echo 'Would delete term (id: ', $term->term_id, ' term_taxonomy_id: ', $term->term_taxonomy_id, ').';
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
		function ( $a, $b ) {
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
function friends_debug_activitypub_ingest() {
	if ( ! isset( $_REQUEST['activitypub-ingest'] ) ) {
		return;
	}

	$ingest = wp_unslash( $_REQUEST['activitypub-ingest'] );
	$data = json_decode( $ingest, true );
	$last_error_code = json_last_error();

	if ( $ingest && JSON_ERROR_NONE !== $last_error_code && ! empty( $last_error_code ) ) {
		echo '<div>', esc_html( json_last_error_msg() ), '</div>';
		$data = false;
	}

	?>
	<style>
		pre {
			overflow: auto;
			height: 5em;
			margin: 1em 0;
			padding: .5em;
			border: 1px solid #ccc;
		}
		tt.var {
			position: absolute;
			right: 1em;
		}
		summary {
			cursor: pointer;
		}
	</style>
	<form action="<?php echo esc_attr( self_admin_url( 'admin.php?page=friends&activitypub-ingest' ) ); ?>" method="POST">
		Paste a received Activity JSON here:<br>
		<textarea name="activitypub-ingest" cols="80" rows="3"><?php echo esc_html( $ingest ); ?></textarea>
		<button>Submit &amp; Preview</button>
		<button name="process">Submit &amp; Process</button>
	</form>
	<?php

	if ( empty( $_POST['activitypub-ingest'] ) || ! $data ) {
		exit;
	}
	class Feed_Parser_ActivityPub_Debug extends \Friends\Feed_Parser_ActivityPub {
		public $type, $activity, $user_id, $user_feed, $item;
		protected function process_incoming_activity( $type, $activity, $user_id, $user_feed ) {
			$item = parent::process_incoming_activity( $type, $activity, $user_id, $user_feed );
			$this->type = $type;
			$this->activity = $activity;
			$this->user_id = $user_id;
			$this->user_feed = $user_feed;
			$this->item = $item;
			return false;
		}
	}

	$user = Activitypub\Collection\Users::get_by_various( get_current_user_id() );

	$activity = Activitypub\Activity\Activity::init_from_array( $data );
	$type = \strtolower( $activity->get_type() );

	function pre( $vars ) {
		foreach ( $vars as $k => $v ) {
			if ( is_int( $v ) || ( is_string( $v ) && false === strpos( $v, PHP_EOL ) ) ) {
				echo '<tt><strong>$' . esc_html( $k ) . '</strong> ', esc_html( $v ), '</tt><br>';
			} elseif ( is_null( $v ) ) {
				echo '<tt><strong>$' . esc_html( $k ) . '</strong> NULL</tt><br>';
			} elseif ( is_bool( $v ) ) {
				echo '<tt><strong>$' . esc_html( $k ) . '</strong> ', esc_html( $v ? 'true' : 'false' ), '</tt><br>';
			} else {
				echo '<div><tt class="var"><strong>$' . $k . '</strong></tt>';
				echo '<pre onclick="void( this.style.height = \'auto\'==this.style.height ? \'5em\' : \'auto\' )">';
				echo esc_html( preg_replace( '/::__set_state\\(array/', '', var_export( $v, true ) ) );
				echo '</pre></div>';
			}
		}
	}

	add_action(
		'friends_activitypub_log',
		function ( $message, $objects ) {
			if ( $objects ) {
				echo '<details><summary>', esc_html( $message ), '</summary><pre>';
				echo esc_html( preg_replace( '/::__set_state\\(array/', '', var_export( $objects, true ) ) );
				echo '</pre></details>';
			} else {
				echo esc_html( $message ), '<br>';
			}
		},
		10,
		2
	);
	$friends_feed = \Friends\Friends::get_instance()->feed;
	$parser = new Feed_Parser_ActivityPub_Debug( $friends_feed );
	$item = $parser->handle_received_activity( $data, $user->get__id(), $type, $activity );
	$item = $parser->item;
	pre( compact( 'activity', 'item' ) );
	if ( ! $parser->user_feed ) {
		echo 'no user feed detected.';
		exit;
	}

	$friend_user = $parser->user_feed->get_friend_user();
	pre( compact( 'friend_user' ) );
	$item = apply_filters( 'friends_early_modify_feed_item', $item, $parser->user_feed, $friend_user );
	if ( ! $item || $item->_feed_rule_delete ) {
		echo 'erradicated at friends_early_modify_feed_item';
		exit;
	}

	// Fallback, when no friends plugin is installed.
	$item->post_id     = $item->permalink;
	$item->post_status = 'publish';
	if ( ( ! $item->post_content && ! $item->title ) || ! $item->permalink ) {
		echo 'erradicated at post_content check';
		exit;
	}

	$post_id = null;
	if ( isset( $remote_post_ids[ $item->post_id ] ) ) {
		$post_id = $remote_post_ids[ $item->post_id ];
	}
	if ( is_null( $post_id ) && isset( $remote_post_ids[ $item->permalink ] ) ) {
		$post_id = $remote_post_ids[ $item->permalink ];
	}

	if ( is_null( $post_id ) ) {
		$post_id = \Friends\Feed::url_to_postid( $item->permalink, $friend_user->ID );
	}
	$item->_is_new = is_null( $post_id );
	$item = apply_filters( 'friends_modify_feed_item', $item, $parser->user_feed, $friend_user, $post_id );
	pre( compact( 'item' ) );
	if ( ! $item || $item->_feed_rule_delete ) {
		echo 'erradicated at friends_modify_feed_item';
		exit;
	}
	pre(
		array(
			'type'      => $parser->type,
			'activity'  => $parser->activity,
			'user_id'   => $parser->user_id,
			'user_feed' => $parser->user_feed,
		)
	);

	if ( isset( $_POST['process'] ) ) {
		$new_items = $friends_feed->process_incoming_feed_items( array( $item ), $parser->user_feed );
		pre( compact( 'new_items' ) );
	}

	exit;
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
		if ( isset( $_GET['keyword'] ) ) {
			apply_filters( 'notify_keyword_match_post', null, $post, $_GET['keyword'] );
		} else {
			$feed_url = get_post_meta( $post->ID, 'feed_url', true );
			$user_feed = Friends\User_Feed::get_by_url( $feed_url );
			do_action( 'notify_new_friend_post', $post, $user_feed, false );
		}
	} else {
		$url = add_query_arg( 'preview-email', $_GET['preview-email'], 'admin.php?page=friends&send' );
		if ( isset( $_GET['keyword'] ) ) {
			$url = add_query_arg( 'keyword', $_GET['keyword'], $url );
		}
		echo '<a href="' . esc_attr( $url ) . '">send notification</a>:', PHP_EOL;
	}
	echo '</p>';

	$author      = Friends\User::get_post_author( $post );
	$email_title = $post->post_title;
	$args = array(
		'author' => $author,
		'post'   => $post,
	);

	$template = 'email/new-friend-post';
	if ( isset( $_GET['keyword'] ) ) {
		$template = 'email/keyword-match-post';
		$args['keyword'] = $_GET['keyword'];
	}

	Friends\Friends::template_loader()->get_template_part( 'email/header', null, array( 'email_title' => $email_title ) );
	Friends\Friends::template_loader()->get_template_part(
		$template,
		null,
		$args
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
	function () {
		if ( apply_filters( 'friends_debug', false ) ) {
			?>
		<li class="menu-item"><a href="<?php echo esc_url( self_admin_url( 'admin.php?page=friends&preview-email=' . get_the_ID() ) ); ?>" class="friends-preview-email"><?php esc_html_e( 'Preview Notification E-Mail', 'friends' ); ?></a></li>
			<?php
		}
	}
);

add_action(
	'friends_entry_dropdown_menu',
	function () {
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
		add_action( 'load-toplevel_page_friends', 'friends_debug_activitypub_ingest' );
		add_action( 'load-toplevel_page_friends', 'friends_debug_extract_tags' );
	},
	50
);

add_filter(
	'friends_friend_feed_url',
	function ( $feed_url, $friend_user ) {
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
	function ( $remote_post_ids ) {
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
	function ( $feed, $url ) {
		$feed->enable_cache( false );
	},
	10,
	2
);

add_action(
	'friends_retrieve_friends_error',
	function ( $feed_url, $feed, $friend_user ) {
		// phpcs:ignore // wp_mail( 'debug@example.com', 'friends_retrieve_friends_error', $feed_url . PHP_EOL . print_r( $feed, true ) . PHP_EOL . PHP_EOL . print_r( $friend_user, true ) . PHP_EOL . PHP_EOL );
	},
	10,
	3
);
add_action(
	'friends_retrieved_new_posts',
	function ( $new_posts, $friend_user ) {
		if ( $new_posts ) {
			// phpcs:ignore // wp_mail( 'debug@example.com', 'friends_retrieve_friends_success', print_r( $new_posts, true ) . PHP_EOL . PHP_EOL . print_r( $friend_user, true ) . PHP_EOL . PHP_EOL );
		}
	},
	10,
	2
);

add_action(
	'friends_page_allowed_styles',
	function ( $styles ) {
		$styles[] = 'hide_updates_css';
		return $styles;
	}
);


add_action(
	'friend_post_edit_link',
	function ( $link, $old_link ) {
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
	function ( $postdata ) {
		if ( isset( $_GET['url'] ) && isset( $_GET['step2'] ) ) {
			$postdata['friend_url'] = $_GET['url'];
			$postdata['_wpnonce'] = wp_create_nonce( 'add-friend' );
		}
		return $postdata;
	}
);


add_action(
	'friends_feed_table_header',
	function () {
		?>
		<th><?php esc_html_e( 'MIME Type', 'friends' ); ?></th>
		<?php
	}
);

add_action(
	'friends_feed_table_row',
	function ( $feed, $term_id ) {
		?>
		<td><input type="text" name="feeds[<?php echo esc_attr( $term_id ); ?>][mime-type]" value="<?php echo esc_attr( $feed->get_mime_type() ); ?>" size="20" aria-label="<?php esc_attr_e( 'Feed Type', 'friends' ); ?>" /></td>
		<?php
	},
	10,
	2
);


add_action(
	'friends_feed_list_item',
	function ( $feed, $term_id ) {
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
		function () {
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
