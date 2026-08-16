<?php

/**
 * Test stub for OCA\OpenRegister\Service\Deferral\ListenerDeferralService.
 *
 * The method surface mirrors the real class
 * (openregister/lib/Service/Deferral/ListenerDeferralService.php) exactly —
 * parameter names, order, types and defaults. A stub whose signature drifts
 * from the class it stands in for produces tests that pass against a shape
 * nobody ships.
 *
 * Loaded via Composer's autoload-dev PSR-4 mapping
 * ("OCA\\OpenRegister\\" => "tests/Stubs/"); a no-op when the real openregister
 * app is present (class_exists guard).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs\Service\Deferral
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deferral;

if (class_exists(ListenerDeferralService::class) === false) {
	/**
	 * Stub class for ListenerDeferralService — used only in standalone unit tests.
	 */
	class ListenerDeferralService {

		/**
		 * Kill-switch value that restores synchronous listener execution.
		 *
		 * @var string
		 */
		public const MODE_INLINE = 'inline';

		/**
		 * Default deferral mode.
		 *
		 * @var string
		 */
		public const MODE_BACKGROUND = 'background';

		/**
		 * Default number of entries per enqueued job.
		 *
		 * @var integer
		 */
		public const DEFAULT_CHUNK_SIZE = 100;

		/**
		 * Whether deferral is enabled on this instance.
		 *
		 * @return bool
		 */
		public function isDeferralEnabled(): bool {
			return true;
		}//end isDeferralEnabled()

		/**
		 * Buffer one entry for a job class.
		 *
		 * @param string $jobClass FQCN of the ActorForwardedJob subclass.
		 * @param array<string, mixed> $entry Entry payload.
		 * @param int $chunkSize Maximum entries per enqueued job.
		 * @param string|null $dedupeKey Optional coalescing key.
		 *
		 * @return void
		 */
		public function defer(
			string $jobClass,
			array $entry,
			int $chunkSize = self::DEFAULT_CHUNK_SIZE,
			?string $dedupeKey = null,
		): void {
		}//end defer()

		/**
		 * Flush every remaining buffer to the job list.
		 *
		 * @return void
		 */
		public function flushAll(): void {
		}//end flushAll()
	}
}
