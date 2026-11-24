<?php

/**
 * Contract of EventEmailService
 *
 * The public surface of EventEmailService that other components call, so they depend
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

/**
 * Contract of EventEmailService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EventEmailServiceInterface {

	/**
	 * Tell someone who signed up that registration has closed.
	 *
	 * @param array<string, mixed> $event  The event.
	 * @param array<string, mixed> $person `name` and `email`.
	 *
	 * @return bool True when the mail was accepted for delivery.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-a-landing-page-sign-up-registers-for-the-event-req-mev-002
	 */
	public function sendClosed(array $event, array $person): bool;

	/**
	 * Confirm a registration: a place gets a calendar file, the waiting list does not.
	 *
	 * @param array<string, mixed> $event        The event.
	 * @param array<string, mixed> $registration The registration.
	 *
	 * @return bool True when the mail was accepted for delivery.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
	 */
	public function sendConfirmation(array $event, array $registration): bool;

	/**
	 * Tell someone who was waiting that they have a place now.
	 *
	 * @param array<string, mixed> $event        The event.
	 * @param array<string, mixed> $registration The registration that moved up.
	 *
	 * @return bool True when the mail was accepted for delivery.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
	 */
	public function sendPlaceFreed(array $event, array $registration): bool;

	/**
	 * Remind a registered person the day before, with the join link of an online event.
	 *
	 * @param array<string, mixed> $event        The event.
	 * @param array<string, mixed> $registration The registration.
	 *
	 * @return bool True when the mail was accepted for delivery.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
	 */
	public function sendReminder(array $event, array $registration): bool;

	/**
	 * The registration id a cancel token was signed for, when it is genuine and unexpired.
	 *
	 * @param string $token The token.
	 *
	 * @return string|null The registration id, or null.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
	 */
	public function verifyCancelToken(string $token): ?string;

	/**
	 * Tell someone with a place, or on the waiting list, that the event is cancelled.
	 *
	 * @param array<string, mixed> $event        The cancelled event.
	 * @param array<string, mixed> $registration The registration.
	 *
	 * @return bool True when the mail was accepted for delivery.
	 *
	 * @spec openspec/changes/marketing-events/tasks.md#3.1
	 */
	public function sendEventCancelled(array $event, array $registration): bool;
}//end interface
