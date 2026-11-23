<?php

/**
 * Pipelinq BookingReschedule.
 *
 * One reschedule in progress: the original booking, where it moves to and
 * the moment the move is recorded.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * A booking being moved to a new start; used only inside BookingService.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
final class BookingReschedule {
	/**
	 * Constructor.
	 *
	 * @param string               $bookingId    The original Booking UUID.
	 * @param array<string, mixed> $original     The original booking as loaded.
	 * @param string               $newStartAt   The new ISO-8601 start.
	 * @param int                  $deltaSeconds How far the booking moves.
	 * @param string               $changedAt    The moment recorded on both status histories.
	 */
	public function __construct(
		public readonly string $bookingId,
		public readonly array $original,
		public readonly string $newStartAt,
		public readonly int $deltaSeconds,
		public readonly string $changedAt,
	) {
	}//end __construct()
}//end class
