<?php

/**
 * Pipelinq BookingEventPush.
 *
 * What every VEVENT pushed for one confirmed Booking shares.
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * The calendar leaf, the Booking's numeric register/schema ids and UUID, and
 * the event payload without attendees, shared by the per-resource pushes.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
final class BookingEventPush {
	/**
	 * Constructor.
	 *
	 * @param object               $leaf      The calendar leaf.
	 * @param int                  $register  Numeric register id.
	 * @param int                  $schema    Numeric Booking schema id.
	 * @param string               $bookingId Booking UUID.
	 * @param array<string, mixed> $event     The event payload without attendees.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly object $leaf,
		public readonly int $register,
		public readonly int $schema,
		public readonly string $bookingId,
		public readonly array $event,
	) {
	}//end __construct()
}//end class
