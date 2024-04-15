<?php

namespace Friends;

class Debug_Stream_Connector extends \WP_Stream\Connector {
	/**
	 * Connector slug
	 *
	 * @var string
	 */
	public $name = 'friends';

	/**
	 * Actions registered for this connector
	 *
	 * @var array
	 */
	public $actions = array(
		'activitypub_safe_remote_post_response',
		'activitypub_inbox',
		'friends_activitypub_log',
		'friends_feed_parser_activitypub_announce',
		'mastodon_api_reblog',
	);

	/**
	 * Return translated connector label
	 *
	 * @return string
	 */
	public function get_label() {
		return __( 'Friends', 'friends' );
	}

	/**
	 * Return translated context labels
	 *
	 * @return array
	 */
	public function get_context_labels() {
		return array();
	}

	/**
	 * Return translated action labels
	 *
	 * @return array
	 */
	public function get_action_labels() {
		return array(
			// 'created' => __( 'Created', 'friends' ),
			// 'updated' => __( 'Updated', 'friends' ),
		);
	}

	public function callback_activitypub_safe_remote_post_response( $response, $url, $body, $user_id ) {
		$body = json_decode( $body, true );
		unset( $body['@context'] );
		$action = 'activitypub-outgoing';

		$this->log(
			sprintf(
				// translators: %s is a URL
				__( 'HTTP POST to %s', 'friends' ),
				$url
			),
			array(
				'body'     => json_encode( $body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'response' => json_encode( $response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			),
			null,
			'http-post',
			$action,
			$user_id
		);
	}
	public function callback_activitypub_inbox( $activity, $user_id, $type ) {
		if ( 'delete' === $type ) {
			return;
		}
		$data = array();
		foreach ( array( 'object', 'to' ) as $key ) {
			if ( isset( $activity[ $key ] ) && ! is_scalar( $activity[ $key ] ) ) {
				if ( is_array( $activity[ $key ] ) && count( $activity[ $key ] ) >= 1 ) {
					if ( 1 === count( $activity[ $key ] ) ) {
						$activity[ $key ] = reset( $activity[ $key ] );
					} else {
						$data[ $key ] = $activity[ $key ];
						foreach ( $data[ $key ] as $k => $v ) {
							if ( ! is_scalar( $v ) ) {
								$data[ $key ][ $k ] = json_encode( $v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
							}
						}
					}
					unset( $activity[ $key ] );
				} else {
					$activity[ $key ] = json_encode( $activity[ $key ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				}
			}
		}
		unset( $activity['@context'], $activity['signature'] );
		$data['activity'] = $activity;
		$this->log(
			sprintf(
				__( 'ActivityPub %1$s by %2$s', 'friends' ),
				$type,
				$activity['actor']
			),
			$data,
			null,
			'activitypub-incoming',
			$type,
			$user_id
		);
	}

	public function callback_friends_activitypub_log( $message, $objects = array() ) {
		$log_objects = array();
		foreach ( $objects as $key => $object ) {
			$log_objects[ $key ] = json_encode( $object, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		$this->log(
			$message,
			array(
				'objects' => $log_objects,
			),
			null,
			'log',
			'log'
		);
	}

	public function callback_mastodon_api_reblog( $post ) {
		$this->log(
			sprintf(
			// translators: %s is a URL
				__( 'ActivityPub announce %s', 'friends' ),
				$post->post_title
			),
			array(
				'post' => $post,
			),
			null,
			'mastodon-apps',
			'reblog'
		);
	}
	public function callback_friends_feed_parser_activitypub_announce( $url, $user_id ) {
		$this->log(
			sprintf(
			// translators: %s is a URL
				__( 'ActivityPub announce %s', 'friends' ),
				$url
			),
			array(
				'url' => $url,
			),
			null,
			'activitypub-outgoing',
			'accounce',
			$user_id
		);
	}
}
