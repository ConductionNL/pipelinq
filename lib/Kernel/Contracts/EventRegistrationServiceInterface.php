<?php

/**
 * Contract of EventRegistrationService
 *
 * The public surface of EventRegistrationService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

use OCA\Pipelinq\Service\Marketing\EventRegistrationOutcome;

/**
 * Contract of EventRegistrationService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EventRegistrationServiceInterface {

	/**
	 * Cancel a registration; a freed place goes to the first person waiting who fits.
	 *
	 * @param string $registrationId The registration id.
	 *
	 * @return EventRegistrationOutcome The cancelled registration and whoever moved up.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
	 */
	public function cancel(string $registrationId): EventRegistrationOutcome;

	/**
	 * Read one event.
	 *
	 * @param string $eventId The event id.
	 *
	 * @return array<string, mixed>|null The event.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
	 */
	public function event(string $eventId): ?array;

	/**
	 * The event a sign-up on this campaign's landing page registers for.
	 *
	 * An event taking registrations wins, the one starting first; otherwise the
	 * campaign's latest event that is not a draft, so a late sign-up can be told
	 * registration has closed. Null when the campaign has no such event.
	 *
	 * @param string $campaignId The campaign id.
	 *
	 * @return array<string, mixed>|null The event.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-a-landing-page-sign-up-registers-for-the-event-req-mev-002
	 */
	public function eventForCampaign(string $campaignId): ?array;

	/**
	 * The events, other than drafts and cancelled ones, that start inside a window.
	 *
	 * @param int $from Unix time the window opens (inclusive).
	 * @param int $to   Unix time the window closes (exclusive).
	 *
	 * @return array<int, array<string, mixed>> The events.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
	 */
	public function eventsStartingBetween(int $from, int $to): array;

	/**
	 * Mark whether a person came; coming adds one `attend` touchpoint to the campaign.
	 *
	 * @param string $registrationId The registration id.
	 * @param bool   $attended       True for attended, false for no-show.
	 *
	 * @return array<string, mixed>|null The registration, or null when it does not exist.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-the-team-keeps-the-attendee-list-and-who-came-req-mev-004
	 */
	public function markAttendance(string $registrationId, bool $attended): ?array;

	/**
	 * Register a person for an event.
	 *
	 * @param array<string, mixed> $event  The event.
	 * @param array<string, mixed> $person `name`, `email`, and optionally `contact`, `lead`,
	 *                                     `source`, `partySize`, `address`, `question`.
	 *
	 * @return EventRegistrationOutcome What happened, and the registration as it stands.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-a-landing-page-sign-up-registers-for-the-event-req-mev-002
	 */
	public function register(array $event, array $person): EventRegistrationOutcome;

	/**
	 * Read one registration.
	 *
	 * @param string $registrationId The registration id.
	 *
	 * @return array<string, mixed>|null The registration.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
	 */
	public function registration(string $registrationId): ?array;

	/**
	 * The registrations of an event with a given status.
	 *
	 * @param string $eventId The event id.
	 * @param string $status  `confirmed`, `waitlist` or `cancelled`.
	 *
	 * @return array<int, array<string, mixed>> The registrations, in the order they came in.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-a-landing-page-sign-up-registers-for-the-event-req-mev-002
	 */
	public function registrationsWithStatus(string $eventId, string $status): array;

	/**
	 * Stamp a time field on a registration (confirmation or reminder sent).
	 *
	 * @param array<string, mixed> $registration The registration.
	 * @param string               $field        `confirmationSentAt` or `reminderSentAt`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
	 */
	public function stamp(array $registration, string $field): void;
}//end interface
