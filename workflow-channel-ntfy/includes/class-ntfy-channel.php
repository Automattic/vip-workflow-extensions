<?php
/**
 * Notification channel for ntfy.sh.
 *
 * Supports multiple ntfy topics for routing different events.
 *
 * @package WorkflowNtfy
 * @see https://docs.ntfy.sh/publish/
 */

declare( strict_types=1 );

namespace WorkflowNtfy;

use VIPWorkflows\Notifications\NotificationChannel;
use VIPWorkflows\Notifications\Notification;
use WP_Error;

/**
 * Push notification channel for ntfy.sh.
 *
 * Each instance represents one ntfy topic/destination.
 */
class NtfyChannel extends NotificationChannel {

	/**
	 * Option name for storing ntfy destinations.
	 */
	public const DESTINATIONS_OPTION = 'workflow_ntfy_destinations';

	/**
	 * Default ntfy server.
	 */
	private const DEFAULT_SERVER = 'https://ntfy.sh';

	/**
	 * Unique ID for this destination.
	 *
	 * @var string
	 */
	private string $destination_id;

	/**
	 * Destination configuration.
	 *
	 * @var array
	 */
	private array $destination_config;

	/**
	 * Constructor.
	 *
	 * @param string $destination_id     Unique destination ID.
	 * @param array  $destination_config Destination config (name, topic, server).
	 */
	public function __construct( string $destination_id = 'default', array $destination_config = [] ) {
		$this->destination_id     = $destination_id;
		$this->destination_config = $destination_config;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'ntfy-' . $this->destination_id;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return $this->destination_config['name'] ?? __( 'ntfy.sh', 'workflow-ntfy' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Send push notifications via ntfy.sh to phones and desktops.', 'workflow-ntfy' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_icon(): string {
		return 'bell';
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_configured(): bool {
		// Consider configured if there's a topic (allows testing before enabling).
		return ! empty( $this->get_topic() );
	}

	/**
	 * Check if the channel is enabled for sending.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return ! empty( $this->destination_config['enabled'] ) && $this->is_configured();
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param  Notification $notification Notification to publish to the topic.
	 * @return bool True when ntfy accepted the message.
	 */
	public function send( Notification $notification ): bool {
		if ( ! $this->is_enabled() ) {
			return false;
		}

		return $this->do_send( $notification );
	}

	/**
	 * Actually send the notification to ntfy.
	 *
	 * @param Notification $notification Notification to send.
	 * @return bool Success.
	 */
	private function do_send( Notification $notification ): bool {
		$server = $this->destination_config['server'] ?? self::DEFAULT_SERVER;
		$topic  = $this->get_topic();
		$url    = trailingslashit( $server ) . $topic;

		// Build message body.
		$body   = $notification->message;
		$fields = $notification->get_fields();
		if ( ! empty( $fields ) ) {
			$body .= "\n\n";
			foreach ( $fields as $field ) {
				$body .= sprintf( "%s: %s\n", $field['title'], $field['value'] );
			}
		}

		$headers = [
			'Title'    => 'VIP Workflows: ' . ucfirst( $notification->type ),
			'Priority' => (string) $this->map_priority( $notification->severity ),
			'Tags'     => $this->get_tags( $notification ),
		];

		// Add click action.
		$click_url = admin_url( 'admin.php?page=vip-workflows' );
		if ( $notification->post_id ) {
			$edit_link = get_edit_post_link( $notification->post_id, 'raw' );
			$click_url = empty( $edit_link ) ? $click_url : $edit_link;
		}
		$headers['Click'] = $click_url;

		$response = wp_remote_post(
			$url,
			[
				// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- push delivery to a third-party ntfy server, sent off the request path.
				'timeout' => 15,
				'headers' => $headers,
				'body'    => $body,
			] 
		);

		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- a failed push has no other channel to report itself on.
			error_log( 'Workflow ntfy error: ' . $response->get_error_message() );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}

	/**
	 * {@inheritdoc}
	 */
	public function test_connection() {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'not_configured', __( 'Topic not set.', 'workflow-ntfy' ) );
		}

		$notification           = new Notification();
		$notification->type     = 'test';
		$notification->severity = 'info';
		$notification->title    = __( 'Test Message', 'workflow-ntfy' );
		$notification->message  = sprintf(
			/* translators: %s: destination name */
			__( 'VIP Workflows → %s is working!', 'workflow-ntfy' ),
			$this->get_name()
		);
		$notification->icon     = '✅';

		// Bypass is_enabled() check for tests - use do_send() directly.
		$success = $this->do_send( $notification );

		return $success ? true : new WP_Error( 'send_failed', __( 'Failed to send test message.', 'workflow-ntfy' ) );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param  array $input Raw settings.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( array $input ): array {
		return $input;
	}

	/**
	 * {@inheritdoc}
	 * Override to return destination config.
	 */
	public function get_settings(): array {
		return $this->destination_config;
	}

	/**
	 * {@inheritdoc}
	 * Override to save to destinations array.
	 *
	 * @param  array $settings Settings for this destination.
	 * @return bool True when the destination list was written.
	 */
	public function update_settings( array $settings ): bool {
		$destinations = self::get_destinations();

		// Find and update this destination.
		foreach ( $destinations as &$dest ) {
			if ( $dest['id'] === $this->destination_id ) {
				$dest = array_merge(
					$dest,
					[
						'name'    => $settings['name'] ?? $dest['name'],
						'topic'   => $settings['topic'] ?? $dest['topic'],
						'server'  => $settings['server'] ?? $dest['server'],
						'enabled' => $settings['enabled'] ?? $dest['enabled'],
					] 
				);
				$this->destination_config = $dest;
				break;
			}
		}

		return self::save_destinations( $destinations );
	}

	/**
	 * Get the ntfy topic.
	 *
	 * @return string Topic name.
	 */
	private function get_topic(): string {
		// Use custom topic if set.
		if ( ! empty( $this->destination_config['topic'] ) ) {
			return $this->destination_config['topic'];
		}

		// Auto-generate topic based on destination ID.
		return 'vipwf-' . $this->destination_id . '-' . substr( md5( get_site_url() ), 0, 8 );
	}

	/**
	 * Map severity to ntfy priority (1-5).
	 *
	 * @param string $severity Notification severity.
	 * @return int Priority.
	 */
	private function map_priority( string $severity ): int {
		return match ( $severity ) {
			'urgent', 'critical' => 5,
			'high', 'warning'    => 4,
			'low'                => 2,
			'min'                => 1,
			default              => 3,
		};
	}

	/**
	 * Get ntfy tags for a notification.
	 *
	 * @param Notification $notification The notification.
	 * @return string Comma-separated tags.
	 */
	private function get_tags( Notification $notification ): string {
		$tags = [ 'workflow' ];

		// Keyed on the ids the dispatcher fires, which is what it stamps onto
		// Notification::$type — `sla.breached`, not the `sla_breach` the retired
		// System Events matrix spoke.
		$tags[] = match ( $notification->type ) {
			'sla.breached' => 'rotating_light',
			'sla.warning'  => 'clock3',
			'goal.at_risk' => 'chart_with_downwards_trend',
			'published'    => 'white_check_mark',
			'transition'   => 'arrow_right',
			'test'         => 'test_tube',
			default        => 'bell',
		};

		return implode( ',', $tags );
	}

	// =========================================================================
	// Static methods for managing destinations
	// =========================================================================

	/**
	 * Get all configured ntfy destinations.
	 *
	 * @return array Array of destination configs.
	 */
	public static function get_destinations(): array {
		$destinations = get_option( self::DESTINATIONS_OPTION, [] );
		$needs_save   = false;

		// If no destinations, create a default one.
		if ( empty( $destinations ) ) {
			$destinations = [
				[
					'id'      => 'default',
					'name'    => __( 'ntfy (Default)', 'workflow-ntfy' ),
					'topic'   => self::generate_default_topic( 'default' ),
					'server'  => self::DEFAULT_SERVER,
					'enabled' => true,
				],
			];
			$needs_save = true;
		} else {
			// Ensure all destinations have topics (backfill for existing).
			foreach ( $destinations as &$dest ) {
				if ( empty( $dest['topic'] ) ) {
					$dest['topic'] = self::generate_default_topic( $dest['id'] );
					$needs_save    = true;
				}
			}
		}

		if ( $needs_save ) {
			update_option( self::DESTINATIONS_OPTION, $destinations );
		}

		return $destinations;
	}

	/**
	 * Save ntfy destinations.
	 *
	 * @param array $destinations Array of destination configs.
	 * @return bool
	 */
	public static function save_destinations( array $destinations ): bool {
		// Sanitize each destination.
		$sanitized = array_map(
			function ( $dest ) {
				$id    = sanitize_key( $dest['id'] ?? wp_generate_uuid4() );
				$topic = sanitize_text_field( $dest['topic'] ?? '' );

				// Generate default topic if empty.
				if ( empty( $topic ) ) {
					$topic = self::generate_default_topic( $id );
				}

				$server = esc_url_raw( $dest['server'] ?? self::DEFAULT_SERVER );

				return [
					'id'      => $id,
					'name'    => sanitize_text_field( $dest['name'] ?? 'ntfy' ),
					'topic'   => $topic,
					'server'  => empty( $server ) ? self::DEFAULT_SERVER : $server,
					'enabled' => ! empty( $dest['enabled'] ),
				];
			},
			$destinations 
		);

		return update_option( self::DESTINATIONS_OPTION, $sanitized );
	}

	/**
	 * Generate a default topic name for a destination.
	 *
	 * @param string $destination_id Destination ID.
	 * @return string Generated topic name.
	 */
	public static function generate_default_topic( string $destination_id ): string {
		return 'vipwf-' . $destination_id . '-' . substr( md5( get_site_url() ), 0, 8 );
	}

	/**
	 * Create channel instances for all configured destinations.
	 *
	 * @return NtfyChannel[]
	 */
	public static function create_channel_instances(): array {
		$destinations = self::get_destinations();
		$channels     = [];

		foreach ( $destinations as $dest ) {
			$channels[] = new self( $dest['id'], $dest );
		}

		return $channels;
	}
}
