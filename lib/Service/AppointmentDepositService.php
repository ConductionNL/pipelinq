<?php

/**
 * Pipelinq AppointmentDepositService.
 *
 * Deposit & no-show payment glue for the appointment-booking surface
 * (member 08 of 12). Initiates payment sessions for pending-deposit
 * bookings through openconnector, drives the success/failure callback
 * into BookingService, releases slots that time out at the 15-minute
 * window, and routes no-show / late-cancellation fee charges. All
 * payment transport (PSD2 SCA, 3-D Secure 2) is delegated to
 * openconnector — pipelinq never touches a payment provider directly
 * (ADR-012).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/appointment-booking/spec.md
 * @spec openspec/specs/appointment-booking/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * AppointmentDepositService — deposit + no-show payment orchestrator.
 *
 * Composes BookingService for the state-machine transitions and an
 * openconnector PaymentService seam for the payment transport. The
 * payment provider is resolved on demand from the DI container so the
 * service degrades gracefully when openconnector is unavailable — a
 * pending-deposit booking is still recorded; only the payment session
 * URL is omitted, which the portal surfaces as a static error message.
 *
 * Money is handled in **integer cents** end-to-end to avoid float drift
 * (`euros * 100`, rounded once at the boundary).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
class AppointmentDepositService {
	/**
	 * App-config key for the openconnector payment source slug.
	 *
	 * Operators set this to the source uuid/slug they configured in
	 * openconnector (the source holds the Mollie/Stripe/Adyen
	 * credentials + endpoint).
	 *
	 * @var string
	 */
	public const PAYMENT_SOURCE_KEY = 'appointment_payment_source';

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
	 * Deposit-hold window in seconds (15 minutes per REQ-APT-010 S2).
	 *
	 * @var int
	 */
	public const DEPOSIT_TIMEOUT_SECONDS = 900;

	/**
	 * Payment session status returned to the portal when openconnector
	 * is unavailable. The booking is still created in pending-deposit;
	 * the portal renders the static fallback message (ADR-005).
	 *
	 * @var string
	 */
	public const SESSION_STATUS_UNAVAILABLE = 'unavailable';

	/**
	 * Payment session status when a session URL was returned.
	 *
	 * @var string
	 */
	public const SESSION_STATUS_PENDING = 'pending';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (openconnector lookup).
	 * @param IAppConfig $appConfig App configuration.
	 * @param IURLGenerator $urlGenerator For building the absolute callback URL.
	 * @param BookingService $bookingService Member 04 — transition + status changes.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private IURLGenerator $urlGenerator,
		private BookingService $bookingService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

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
	): array {
		if ($bookingId === '') {
			throw new InvalidArgumentException('bookingId must be a non-empty string');
		}

		if ($amountCents <= 0) {
			throw new InvalidArgumentException('amountCents must be positive');
		}

		$payment = $this->resolvePaymentService();
		if ($payment === null) {
			$this->logger->info(
				'Pipelinq: openconnector PaymentService unavailable — deposit session skipped',
				['booking' => $bookingId]
			);
			return [
				'sessionUrl' => '',
				'status' => self::SESSION_STATUS_UNAVAILABLE,
				'providerReference' => '',
			];
		}

		$sourceSlug = $this->paymentSourceSlug();
		if ($sourceSlug === '') {
			$this->logger->warning(
				'Pipelinq: appointment_payment_source not configured — deposit session skipped',
				['booking' => $bookingId]
			);
			return [
				'sessionUrl' => '',
				'status' => self::SESSION_STATUS_UNAVAILABLE,
				'providerReference' => '',
			];
		}

		$callbackUrl = $this->callbackUrl();

		try {
			// @phpstan-ignore-next-line dynamic openconnector seam
			$result = $payment->createSession(
				$sourceSlug,
				[
					'amount' => [
						'value' => $this->centsToDecimal(cents: $amountCents),
						'currency' => strtoupper($currency),
					],
					'description' => $this->truncate(value: $description, max: 255),
					'returnUrl' => $returnUrl,
					'webhookUrl' => $callbackUrl,
					'metadata' => [
						'app' => Application::APP_ID,
						'kind' => 'appointment-deposit',
						'bookingId' => $bookingId,
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: openconnector createSession failed',
				[
					'booking' => $bookingId,
					'amount' => $amountCents,
				]
			);
			return [
				'sessionUrl' => '',
				'status' => self::SESSION_STATUS_UNAVAILABLE,
				'providerReference' => '',
			];
		}//end try

		$sessionUrl = $this->stringField(result: $result, key: 'sessionUrl');
		$providerReference = $this->stringField(result: $result, key: 'id');

		return [
			'sessionUrl' => $sessionUrl,
			'status' => self::SESSION_STATUS_PENDING,
			'providerReference' => $providerReference,
		];
	}//end createDepositSession()

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
	public function handlePaymentCallback(string $bookingId, string $status): string {
		if ($bookingId === '' || $status === '') {
			throw new InvalidArgumentException('bookingId and status must be non-empty');
		}

		$normalised = strtolower(trim($status));
		if ($normalised === 'paid' || $normalised === 'succeeded' || $normalised === 'success') {
			try {
				$this->bookingService->confirmBooking(
					bookingId: $bookingId,
					reason: 'Deposit payment confirmed'
				);
				$this->markDepositPaid(bookingId: $bookingId);
				return 'confirmed';
			} catch (Throwable $e) {
				$this->logger->warning(
					'Pipelinq: deposit confirm failed',
					[
						'booking' => $bookingId,
						'status' => $normalised,
					]
				);
				return 'unchanged';
			}
		}

		// Failed / expired / cancelled — leave booking in pending-deposit so
		// the timeout job releases the slot at the 15-minute mark.
		$this->logger->info(
			'Pipelinq: deposit payment not confirmed',
			[
				'booking' => $bookingId,
				'status' => $normalised,
			]
		);
		return 'unchanged';
	}//end handlePaymentCallback()

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
	public function isDepositExpired(string $createdAtIso, ?int $nowEpoch = null): bool {
		if ($createdAtIso === '') {
			return false;
		}

		$created = strtotime($createdAtIso);
		if ($created === false) {
			return false;
		}

		$now = ($nowEpoch ?? time());
		return (($now - $created) >= self::DEPOSIT_TIMEOUT_SECONDS);
	}//end isDepositExpired()

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
	public function releaseExpiredDeposit(string $bookingId): void {
		if ($bookingId === '') {
			return;
		}

		try {
			$this->bookingService->cancelBooking(
				bookingId: $bookingId,
				reason: 'Deposit not paid within 15 minutes',
				cancelledBy: BookingService::ACTOR_SYSTEM
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: deposit-timeout release failed',
				['booking' => $bookingId]
			);
		}
	}//end releaseExpiredDeposit()

	/**
	 * Resolve the openconnector PaymentService from the DI container.
	 *
	 * Returns null when openconnector is unavailable — the caller logs
	 * and degrades gracefully (ADR-005: never fail-open on auth, but
	 * payment is a non-auth integration so a soft skip is permitted).
	 *
	 * @return object|null
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function resolvePaymentService(): ?object {
		// Allow tests / wiring to inject a payment service manually.
		if ($this->paymentService !== null) {
			return $this->paymentService;
		}

		try {
			$service = $this->container->get('OCA\\OpenConnector\\Service\\PaymentService');
			if (is_object($service) === true) {
				return $service;
			}
		} catch (Throwable $e) {
			// Openconnector not installed / class not bound; soft skip.
		}

		return null;
	}//end resolvePaymentService()

	/**
	 * Inject the openconnector PaymentService directly (test wiring).
	 *
	 * The production path resolves via the DI container; unit tests
	 * inject a mock here so the test never touches the container.
	 *
	 * @param object|null $service The PaymentService seam.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function setPaymentService(?object $service): void {
		$this->paymentService = $service;
	}//end setPaymentService()

	/**
	 * Optional override for the openconnector PaymentService.
	 *
	 * @var object|null
	 */
	private ?object $paymentService = null;

	/**
	 * The configured payment source slug.
	 *
	 * @return string
	 */
	private function paymentSourceSlug(): string {
		return $this->appConfig->getValueString(
			Application::APP_ID,
			self::PAYMENT_SOURCE_KEY,
			''
		);
	}//end paymentSourceSlug()

	/**
	 * The absolute URL openconnector should call back into.
	 *
	 * @return string
	 */
	private function callbackUrl(): string {
		try {
			return $this->urlGenerator->linkToOCSRouteAbsolute(
				routeName: Application::APP_ID . '.appointmentPaymentWebhook.callback'
			);
		} catch (Throwable $e) {
			// Fall back to a relative path when the URL generator cannot
			// build an absolute URL (no request context); openconnector
			// resolves relative against its own base.
			return '/index.php/apps/' . Application::APP_ID . '/api/appointment-payment-webhook';
		}
	}//end callbackUrl()

	/**
	 * Stamp `depositPaidAt` on the booking record.
	 *
	 * Best-effort: the confirmBooking call already drives the state
	 * machine + email seam, so a failure here is logged but never
	 * propagated (the confirmation has already happened).
	 *
	 * @param string $bookingId Booking UUID.
	 *
	 * @return void
	 */
	private function markDepositPaid(string $bookingId): void {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(
			Application::APP_ID,
			BookingService::BOOKING_SCHEMA_KEY,
			''
		);
		if ($register === '' || $schema === '') {
			return;
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			return;
		}

		try {
			$booking = $objectService->find(
				id: $bookingId,
				register: $register,
				schema: $schema
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: depositPaidAt load failed',
				['booking' => $bookingId]
			);
			return;
		}

		$data = $this->toArray(object: $booking);
		if ($data === null) {
			return;
		}

		$data['depositPaidAt'] = $this->nowIso();
		if (array_key_exists('@self', $data) === true) {
			unset($data['@self']);
		}

		try {
			$objectService->saveObject(
				object: $data,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $bookingId
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: depositPaidAt save failed',
				['booking' => $bookingId]
			);
		}
	}//end markDepositPaid()

	/**
	 * Render an integer-cent amount as a `nn.nn` decimal string.
	 *
	 * Mollie / Stripe expect a string-encoded major-unit amount
	 * (e.g. `"20.00"`), but pipelinq stores cents end-to-end to avoid
	 * float drift. Conversion happens once at the openconnector boundary.
	 *
	 * @param int $cents Amount in integer cents.
	 *
	 * @return string
	 */
	private function centsToDecimal(int $cents): string {
		$sign = '';
		$absolute = $cents;
		if ($cents < 0) {
			$sign = '-';
			$absolute = -$cents;
		}

		$major = intdiv($absolute, 100);
		$minor = ($absolute % 100);
		return sprintf('%s%d.%02d', $sign, $major, $minor);
	}//end centsToDecimal()

	/**
	 * Pull a string field out of a PaymentService result.
	 *
	 * @param mixed $result Raw result (array or object).
	 * @param string $key Field name.
	 *
	 * @return string
	 */
	private function stringField(mixed $result, string $key): string {
		if (is_array($result) === true && isset($result[$key]) === true) {
			return (string)$result[$key];
		}

		if (is_object($result) === true && isset($result->{$key}) === true) {
			return (string)$result->{$key};
		}

		return '';
	}//end stringField()

	/**
	 * Normalise an OpenRegister entity to a plain array.
	 *
	 * @param mixed $object Entity, array, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function toArray(mixed $object): ?array {
		if ($object === null) {
			return null;
		}

		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true) {
			if (method_exists($object, 'jsonSerialize') === true) {
				$serialised = $object->jsonSerialize();
				if (is_array($serialised) === true) {
					return $serialised;
				}
			}

			if (method_exists($object, 'toArray') === true) {
				$arr = $object->toArray();
				if (is_array($arr) === true) {
					return $arr;
				}
			}

			return (array)$object;
		}

		return null;
	}//end toArray()

	/**
	 * Truncate a string to a maximum length.
	 *
	 * @param string $value Raw value.
	 * @param int $max Maximum length.
	 *
	 * @return string
	 */
	private function truncate(string $value, int $max): string {
		if (strlen($value) <= $max) {
			return $value;
		}

		return substr($value, 0, $max);
	}//end truncate()

	/**
	 * Now in ISO-8601 UTC.
	 *
	 * @return string
	 */
	private function nowIso(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
	}//end nowIso()
}//end class
