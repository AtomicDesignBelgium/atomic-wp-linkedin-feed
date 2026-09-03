<?php
/**
 * Generic due-connection scheduler.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Sync;

use AtomicWPSocialSync\Connections\ConnectionRepository;

final class Scheduler {
	public const HOOK = 'atomic_wp_social_sync_scheduler';

	public function __construct(
		private readonly ConnectionRepository $connections,
		private readonly SyncService $sync_service
	) {}

	public function registerSchedule( array $schedules ): array {
		$schedules['atomic_social_15_minutes'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes (Atomic Social)', 'atomic-wp-social-sync' ),
		);
		return $schedules;
	}

	public function run(): void {
		foreach ( $this->connections->due( time() ) as $connection ) {
			$this->sync_service->syncConnection( $connection->id );
		}
	}

	public static function ensureScheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'atomic_social_15_minutes', self::HOOK );
		}
	}

	public static function clear(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}
}
