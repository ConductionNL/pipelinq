<?php

/**
 * Contract of AppointmentEmailService
 *
 * The public surface of AppointmentEmailService that other components call, so they depend
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
 * Contract of AppointmentEmailService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AppointmentEmailServiceInterface {

	/**
	 * Why the last send was refused by the opt-out check, or null when it was not.
	 *
	 * @return array{code:string,reason:string}|null The refusal.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
	 */
	public function lastRefusal(): ?array;

	/**
	 * Send the 24-hour reminder for a booking (called by ReminderDispatchJob).
	 *
	 * @param string $bookingId The Booking UUID.
	 *
	 * @return bool True when the reminder was accepted for delivery.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function sendReminder(string $bookingId): bool;
}//end interface
