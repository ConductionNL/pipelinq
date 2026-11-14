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
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\AppointmentDepositServiceInterface;
use OCA\Pipelinq\Util\NullablePayloadToArray;
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
class AppointmentDepositService implements AppointmentDepositServiceInterface {
	use NullablePayloadToArray;

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
		$target = $this->sessionTarget(bookingId: $bookingId, amountCents: $amountCents);
		if ($target === null) {
			return $this->unavailableSession();
		}

		$amount = $this->sessionAmount(amountCents: $amountCents, currency: $currency);
		$payload = $this->sessionPayload(bookingId: $bookingId, amount: $amount, description: $description, returnUrl: $returnUrl);
		return $this->requestSession(target: $target, payload: $payload, amountCents: $amountCents);
	}//end createDepositSession()

	/**
	 * Reject an empty booking id or a non-positive deposit amount.
	 *
	 * @param string $bookingId   Booking UUID.
	 * @param int    $amountCents Deposit amount in integer cents.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the bookingId is empty or amountCents <= 0.
	 */
	private function assertDepositInput(string $bookingId, int $amountCents): void {
		if ($bookingId === '') {
			throw new InvalidArgumentException('bookingId must be a non-empty string');
		}

		if ($amountCents <= 0) {
			throw new InvalidArgumentException('amountCents must be positive');
		}
	}//end assertDepositInput()

	/**
	 * Validate the input, then resolve the payment service and source slug, logging why a session is skipped.
	 *
	 * @param string $bookingId   Booking UUID.
	 * @param int    $amountCents Deposit amount in integer cents.
	 *
	 * @return array{payment: object, source: string}|null Null when the session must be skipped.
	 *
	 * @throws InvalidArgumentException When the bookingId is empty or amountCents <= 0.
	 */
	private function sessionTarget(string $bookingId, int $amountCents): array|null {
		$this->assertDepositInput(bookingId: $bookingId, amountCents: $amountCents);
		$payment = $this->paymentServiceFor(bookingId: $bookingId);
		if ($payment === null) {
			return null;
		}

		$sourceSlug = $this->paymentSourceSlug();
		if ($sourceSlug === '') {
			$this->logger->warning('Pipelinq: appointment_payment_source not configured — deposit session skipped', ['booking' => $bookingId]);
			return null;
		}

		return ['payment' => $payment, 'source' => $sourceSlug];
	}//end sessionTarget()

	/**
	 * Resolve the payment service, logging when it is unavailable.
	 *
	 * @param string $bookingId Booking UUID.
	 *
	 * @return object|null
	 */
	private function paymentServiceFor(string $bookingId): object|null {
		$payment = $this->resolvePaymentService();
		if ($payment === null) {
			$this->logger->info(
				'Pipelinq: openconnector PaymentService unavailable — deposit session skipped',
				['booking' => $bookingId]
			);
		}

		return $payment;
	}//end paymentServiceFor()

	/**
	 * The session result returned when no payment session could be created.
	 *
	 * @return array{sessionUrl: string, status: string, providerReference: string}
	 */
	private function unavailableSession(): array {
		return [
			'sessionUrl' => '',
			'status' => self::SESSION_STATUS_UNAVAILABLE,
			'providerReference' => '',
		];
	}//end unavailableSession()

	/**
	 * Build the amount block of the payment session.
	 *
	 * @param int    $amountCents Deposit amount in integer cents.
	 * @param string $currency    ISO-4217 currency code.
	 *
	 * @return array{value: string, currency: string}
	 */
	private function sessionAmount(int $amountCents, string $currency): array {
		return [
			'value' => $this->centsToDecimal(cents: $amountCents),
			'currency' => strtoupper($currency),
		];
	}//end sessionAmount()

	/**
	 * Build the createSession payload for a deposit booking.
	 *
	 * @param string               $bookingId   Booking UUID.
	 * @param array<string, mixed> $amount      The amount block.
	 * @param string               $description Short description for the payment session.
	 * @param string               $returnUrl   The portal URL the customer returns to.
	 *
	 * @return array<string, mixed>
	 */
	private function sessionPayload(string $bookingId, array $amount, string $description, string $returnUrl): array {
		$callbackUrl = $this->callbackUrl();
		return [
			'amount' => $amount,
			'description' => $this->truncate(value: $description, max: 255),
			'returnUrl' => $returnUrl,
			'webhookUrl' => $callbackUrl,
			'metadata' => [
				'app' => AppIdentity::APP_ID,
				'kind' => 'appointment-deposit',
				'bookingId' => $bookingId,
			],
		];
	}//end sessionPayload()

	/**
	 * Ask the payment service for a session and shape the result.
	 *
	 * @param array{payment: object, source: string} $target      The payment service and source slug.
	 * @param array<string, mixed>                   $payload     The createSession payload.
	 * @param int                                    $amountCents Deposit amount in integer cents.
	 *
	 * @return array{sessionUrl: string, status: string, providerReference: string}
	 */
	private function requestSession(array $target, array $payload, int $amountCents): array {
		try {
			// @phpstan-ignore-next-line dynamic openconnector seam
			$result = $target['payment']->createSession($target['source'], $payload);
		} catch (Throwable $e) {
			return $this->sessionFailed(bookingId: $payload['metadata']['bookingId'], amountCents: $amountCents);
		}

		return [
			'sessionUrl' => $this->stringField(result: $result, key: 'sessionUrl'),
			'status' => self::SESSION_STATUS_PENDING,
			'providerReference' => $this->stringField(result: $result, key: 'id'),
		];
	}//end requestSession()

	/**
	 * Log a failed createSession call and return the unavailable result.
	 *
	 * @param string $bookingId   Booking UUID.
	 * @param int    $amountCents Deposit amount in integer cents.
	 *
	 * @return array{sessionUrl: string, status: string, providerReference: string}
	 */
	private function sessionFailed(string $bookingId, int $amountCents): array {
		$this->logger->warning(
			'Pipelinq: openconnector createSession failed',
			[
				'booking' => $bookingId,
				'amount' => $amountCents,
			]
		);
		return $this->unavailableSession();
	}//end sessionFailed()

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
		if ($this->isPaidStatus(normalised: $normalised) === true) {
			return $this->confirmPaidDeposit(bookingId: $bookingId, normalised: $normalised);
		}

		// Failed / expired / cancelled — leave booking in pending-deposit so
		// the timeout job releases the slot at the 15-minute mark.
		$this->logUnconfirmedDeposit(bookingId: $bookingId, normalised: $normalised);
		return 'unchanged';
	}//end handlePaymentCallback()

	/**
	 * True when the normalised payment status means the deposit was paid.
	 *
	 * @param string $normalised Lower-cased, trimmed payment status.
	 *
	 * @return bool
	 */
	private function isPaidStatus(string $normalised): bool {
		return ($normalised === 'paid' || $normalised === 'succeeded' || $normalised === 'success');
	}//end isPaidStatus()

	/**
	 * Confirm the booking and stamp the deposit as paid.
	 *
	 * @param string $bookingId  Booking UUID.
	 * @param string $normalised Lower-cased, trimmed payment status.
	 *
	 * @return string 'confirmed', or 'unchanged' when confirming failed.
	 */
	private function confirmPaidDeposit(string $bookingId, string $normalised): string {
		try {
			$this->bookingService->confirmBooking(bookingId: $bookingId, reason: 'Deposit payment confirmed');
			$this->markDepositPaid(bookingId: $bookingId);
			return 'confirmed';
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: deposit confirm failed', ['booking' => $bookingId, 'status' => $normalised]);
			return 'unchanged';
		}
	}//end confirmPaidDeposit()

	/**
	 * Log a payment callback whose status did not confirm the deposit.
	 *
	 * @param string $bookingId  Booking UUID.
	 * @param string $normalised Lower-cased, trimmed payment status.
	 *
	 * @return void
	 */
	private function logUnconfirmedDeposit(string $bookingId, string $normalised): void {
		$this->logger->info(
			'Pipelinq: deposit payment not confirmed',
			[
				'booking' => $bookingId,
				'status' => $normalised,
			]
		);
	}//end logUnconfirmedDeposit()

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
			// NOT REPOINTED, AND NOT REPOINTABLE (verified 2026-09-10). The name
			// `openconnector` is retired and its successor is `integriq`, but
			// `OCA\Integriq\Service\PaymentService` does not exist, and neither did
			// the old spelling: `git log -S PaymentService` over integriq's 3,960
			// commits, which span the whole openconnector era because the repo was
			// renamed rather than forked, returns nothing at all. The connector has
			// never published a payment service under either name.
			//
			// Swapping the namespace here would produce a `get()` that misses
			// exactly as it misses now, lands in the same catch below, and returns
			// the same null, on a diff that reads to the next person as a fix
			// already applied. That is worse than the stale name, which at least
			// still says out loud that the integration is unfinished.
			//
			// @stale-fleet-app-id exclude integriq publishes no PaymentService under
			// either name; git log -S over its full 3,960-commit history, which
			// covers the openconnector era, finds the class was never added. The
			// deposit flow needs an integriq-side payment surface before any name
			// written here resolves.
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
			AppIdentity::APP_ID,
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
				routeName: AppIdentity::APP_ID . '.appointmentPaymentWebhook.callback'
			);
		} catch (Throwable $e) {
			// Fall back to a relative path when the URL generator cannot
			// build an absolute URL (no request context); openconnector
			// resolves relative against its own base.
			return '/index.php/apps/' . AppIdentity::APP_ID . '/api/appointment-payment-webhook';
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
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, BookingService::BOOKING_SCHEMA_KEY, '');
		if ($register === '' || $schema === '') {
			return;
		}

		$objectService = $this->depositObjectService();
		if ($objectService === null) {
			return;
		}

		$this->stampDepositPaid(objectService: $objectService, bookingId: $bookingId, register: $register, schema: $schema);
	}//end markDepositPaid()

	/**
	 * Resolve the OpenRegister ObjectService, or null when it is unavailable.
	 *
	 * @return mixed
	 */
	private function depositObjectService(): mixed {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			return null;
		}
	}//end depositObjectService()

	/**
	 * Load the booking, set `depositPaidAt` and save it back, logging a failed save.
	 *
	 * @param mixed  $objectService The OpenRegister ObjectService.
	 * @param string $bookingId     Booking UUID.
	 * @param string $register      Register id.
	 * @param string $schema        Booking schema id.
	 *
	 * @return void
	 */
	private function stampDepositPaid(mixed $objectService, string $bookingId, string $register, string $schema): void {
		$data = $this->loadStampedBooking(objectService: $objectService, bookingId: $bookingId, register: $register, schema: $schema);
		if ($data === null) {
			return;
		}

		try {
			$objectService->saveObject(object: $data, extend: [], register: $register, schema: $schema, uuid: $bookingId);
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: depositPaidAt save failed', ['booking' => $bookingId]);
		}
	}//end stampDepositPaid()

	/**
	 * Load the booking as an array with `depositPaidAt` stamped, logging a failed load.
	 *
	 * @param mixed  $objectService The OpenRegister ObjectService.
	 * @param string $bookingId     Booking UUID.
	 * @param string $register      Register id.
	 * @param string $schema        Booking schema id.
	 *
	 * @return array<string, mixed>|null Null when the booking cannot be loaded.
	 */
	private function loadStampedBooking(mixed $objectService, string $bookingId, string $register, string $schema): array|null {
		try {
			$booking = $objectService->find(id: $bookingId, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: depositPaidAt load failed', ['booking' => $bookingId]);
			return null;
		}

		$data = $this->toArray(object: $booking);
		if ($data === null) {
			return null;
		}

		return $this->withDepositPaidAt(data: $data);
	}//end loadStampedBooking()

	/**
	 * Stamp `depositPaidAt` on the booking data and drop its `@self` block.
	 *
	 * @param array<string, mixed> $data The booking data.
	 *
	 * @return array<string, mixed>
	 */
	private function withDepositPaidAt(array $data): array {
		$data['depositPaidAt'] = $this->nowIso();
		if (array_key_exists('@self', $data) === true) {
			unset($data['@self']);
		}

		return $data;
	}//end withDepositPaidAt()

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
