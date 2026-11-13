<?php

/**
 * Contract of AppointmentDepositService
 *
 * The public surface of AppointmentDepositService that other components call, so they depend
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

use InvalidArgumentException;

/**
 * Contract of AppointmentDepositService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AppointmentDepositServiceInterface {

	/**
	 * App-config key for the openconnector webhook shared secret.
	 *
	 * Used by {@see AppointmentPaymentWebhookController} to verify
	 * inbound payment callbacks (HMAC-SHA256 over the raw body).
	 *
	 * @var string
	 */
	public const WEBHOOK_SECRET_KEY = 'appointment_payment_webhook_secret';

	/**
	 * Create a payment session for a pending-deposit booking.
	 *
	 * Returns a structured result the PortalController forwards to the
	 * frontend. The booking remains in `pending-deposit` regardless of
	 * the openconnector outcome — the 15-minute timeout (run by
	 * {@see \OCA\Pipelinq\BackgroundJob\AppointmentDepositTimeoutJob})
	 * releases the slot if payment never completes.
	 *
	 * @param string $bookingId Booking UUID.
	 * @param int $amountCents Deposit amount in integer cents.
	 * @param string $currency ISO-4217 currency code (default EUR).
	 * @param string $description Short description for the payment session.
	 * @param string $returnUrl The portal URL the customer returns to.
	 *
	 * @return array{sessionUrl: string, status: string, providerReference: string}
	 *
	 * @throws InvalidArgumentException When the bookingId is empty or amountCents <= 0.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function createDepositSession(
		string $bookingId,
		int $amountCents,
		string $currency = 'EUR',
		string $description = '',
		string $returnUrl = '',
	): array;

	/**
	 * Process an inbound payment callback from openconnector.
	 *
	 * The webhook controller verifies the HMAC signature, decodes the
	 * payload, and forwards the {bookingId, status} pair here. A `paid`
	 * outcome confirms the booking via BookingService::confirmBooking
	 * (which sets `confirmationSentAt` and dispatches the email seam);
	 * any other status leaves the booking in pending-deposit so the
	 * timeout job can later release the slot.
	 *
	 * @param string $bookingId Booking UUID.
	 * @param string $status Payment status reported by openconnector.
	 *
	 * @return string The new booking status (or 'unchanged' on no-op).
	 *
	 * @throws InvalidArgumentException When inputs are empty.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function handlePaymentCallback(string $bookingId, string $status): string;

	/**
	 * True when a `pending-deposit` booking has exceeded the 15-minute hold.
	 *
	 * @param string $createdAtIso ISO-8601 timestamp when the booking was created.
	 * @param int $nowEpoch Optional injected "now" (seconds since epoch) for tests.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function isDepositExpired(string $createdAtIso, ?int $nowEpoch = null): bool;

	/**
	 * Release a timed-out pending-deposit booking.
	 *
	 * Wraps BookingService::cancelBooking with a system actor so the
	 * cancellation never charges (REQ-APT-009 scenario 3 — staff/system
	 * cancellations skip the charge) and the slot is freed.
	 *
	 * @param string $bookingId Booking UUID.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function releaseExpiredDeposit(string $bookingId): void;
}//end interface
