<?php

/**
 * Pipelinq EventRegistrationOutcome.
 *
 * What one registration or cancellation did: the registration as it now stands,
 * what happened to it, and the person who moved up from the waiting list.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-a-landing-page-sign-up-registers-for-the-event-req-mev-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

/**
 * The result of one registration call.
 *
 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-a-landing-page-sign-up-registers-for-the-event-req-mev-002
 */
final class EventRegistrationOutcome {

	/**
	 * A new registration with a place.
	 */
	public const REGISTERED = 'registered';

	/**
	 * A new registration on the waiting list.
	 */
	public const WAITLISTED = 'waitlisted';

	/**
	 * The same person was already registered; nothing was written.
	 */
	public const DUPLICATE = 'duplicate';

	/**
	 * The event does not take registrations; nothing was written.
	 */
	public const CLOSED = 'closed';

	/**
	 * The registration was cancelled.
	 */
	public const CANCELLED = 'cancelled';

	/**
	 * Nothing was done: no such registration or event, or the write failed.
	 */
	public const NONE = 'none';

	/**
	 * Constructor.
	 *
	 * @param string                    $status       One of the constants above.
	 * @param array<string, mixed>|null $registration The registration as it now stands.
	 * @param array<string, mixed>|null $promoted     The registration that moved up from the waiting list.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-a-landing-page-sign-up-registers-for-the-event-req-mev-002
	 */
	public function __construct(
		public readonly string $status,
		public readonly ?array $registration = null,
		public readonly ?array $promoted = null,
	) {
	}//end __construct()
}//end class
