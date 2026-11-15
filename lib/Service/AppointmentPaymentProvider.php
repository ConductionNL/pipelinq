<?php

/**
 * Pipelinq AppointmentPaymentProvider.
 *
 * Implements the BookingService payment seam (member 04, REQ-APT-009 /
 * REQ-APT-011) by routing no-show and late-cancellation fee charges
 * through openconnector. The adapter is intentionally thin: BookingService
 * decides whether and how much to charge (policy, window, deposit
 * forfeit); this class queues the charge with openconnector after
 * verifying a payment method is on file, then stamps the timestamp on
 * the Booking record on success.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Service\Support\CentsAsDecimal;
use OCA\Pipelinq\Util\NullablePayloadToArray;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * AppointmentPaymentProvider — BookingService payment seam implementation.
 *
 * Wired into BookingService via {@see BookingService::setPaymentProvider}
 * during {@see \OCA\Pipelinq\AppInfo\Application::boot}. The provider
 * exposes the two methods BookingService duck-types against:
 *   - chargeNoShowFee(string $bookingId, float $amount): void
 *   - chargeCancellationFee(string $bookingId, float $amount): void
 *
 * Money arrives as a float (BookingService keeps the legacy seam
 * signature) and is converted to integer cents inside this class so the
 * openconnector boundary always sees a `nn.nn` decimal string.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/appointment-booking/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The total is the sum of small private steps; each method stays under the method thresholds.
 */
class AppointmentPaymentProvider {
	use NullablePayloadToArray;
	use CentsAsDecimal;

	/**
	 * Charge kind logged on the Booking record.
	 *
	 * @var string
	 */
	public const KIND_NO_SHOW = 'no-show';

	/**
	 * Charge kind logged on the Booking record.
	 *
	 * @var string
	 */
	public const KIND_CANCELLATION = 'late-cancellation';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (openconnector + OR lookup).
	 * @param IAppConfig $appConfig App configuration.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Queue a no-show fee charge through openconnector.
	 *
	 * No-op when:
	 *   - the booking cannot be loaded,
	 *   - no payment method is on file (REQ-APT-011 scenario 2),
	 *   - the openconnector PaymentService is unavailable,
	 *   - the amount is non-positive.
	 *
	 * Stamps `noShowFeeChargedAt` on the Booking on success.
	 *
	 * @param string $bookingId Booking UUID.
	 * @param float $amount Fee amount (euros).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function chargeNoShowFee(string $bookingId, float $amount): void {
		$fee = new BookingFee(bookingId: $bookingId, amount: $amount, kind: self::KIND_NO_SHOW, stampField: 'noShowFeeChargedAt');
		$this->chargeBookingFee(fee: $fee);
	}//end chargeNoShowFee()

	/**
	 * Queue a late-cancellation fee charge through openconnector.
	 *
	 * Same semantics as {@see chargeNoShowFee} but stamps
	 * `cancellationFeeChargedAt`.
	 *
	 * @param string $bookingId Booking UUID.
	 * @param float $amount Fee amount (euros).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function chargeCancellationFee(string $bookingId, float $amount): void {
		$fee = new BookingFee(bookingId: $bookingId, amount: $amount, kind: self::KIND_CANCELLATION, stampField: 'cancellationFeeChargedAt');
		$this->chargeBookingFee(fee: $fee);
	}//end chargeCancellationFee()

	/**
	 * Inject an explicit openconnector PaymentService (test wiring).
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
	 * Shared body for the two public charge paths.
	 *
	 * @param BookingFee $fee The fee to charge.
	 *
	 * @return void
	 */
	private function chargeBookingFee(BookingFee $fee): void {
		$prepared = $this->prepareCharge(fee: $fee);
		if ($prepared === null) {
			return;
		}

		if ($this->submitCharge(prepared: $prepared) === false) {
			return;
		}

		$this->stampBooking(fee: $fee, value: $this->nowIso());
	}//end chargeBookingFee()

	/**
	 * Run the pre-charge checks and gather what the charge needs.
	 *
	 * @param BookingFee $fee The fee to charge.
	 *
	 * @return array{payment: object, sourceSlug: string, charge: array<string, mixed>, cents: int}|null Null when the charge is skipped.
	 */
	private function prepareCharge(BookingFee $fee): array|null {
		if ($fee->cents() <= 0) {
			return null;
		}

		$payment = $this->resolvePaymentServiceForCharge(bookingId: $fee->bookingId, kind: $fee->kind);
		if ($payment === null) {
			return null;
		}

		return $this->prepareCustomerCharge(payment: $payment, fee: $fee);
	}//end prepareCharge()

	/**
	 * Check the booking's customer and payment source and gather the charge.
	 *
	 * @param object     $payment The openconnector PaymentService.
	 * @param BookingFee $fee     The fee to charge.
	 *
	 * @return array{payment: object, sourceSlug: string, charge: array<string, mixed>, cents: int}|null Null when the charge is skipped.
	 */
	private function prepareCustomerCharge(object $payment, BookingFee $fee): array|null {
		$customerId = $this->resolveChargeableCustomer(bookingId: $fee->bookingId, kind: $fee->kind);
		if ($customerId === null) {
			return null;
		}

		$sourceSlug = $this->resolvePaymentSource(bookingId: $fee->bookingId, kind: $fee->kind);
		if ($sourceSlug === '') {
			return null;
		}

		return [
			'payment' => $payment,
			'sourceSlug' => $sourceSlug,
			'charge' => $this->buildChargePayload(customerId: $customerId, fee: $fee),
			'cents' => $fee->cents(),
		];
	}//end prepareCustomerCharge()

	/**
	 * Resolve the openconnector PaymentService, logging when it is unavailable.
	 *
	 * @param string $bookingId Booking UUID.
	 * @param string $kind Charge kind tag.
	 *
	 * @return object|null
	 */
	private function resolvePaymentServiceForCharge(string $bookingId, string $kind): object|null {
		$payment = $this->resolvePaymentService();
		if ($payment === null) {
			$this->logger->info(
				'Pipelinq: openconnector PaymentService unavailable — charge skipped',
				['booking' => $bookingId, 'kind' => $kind]
			);
		}

		return $payment;
	}//end resolvePaymentServiceForCharge()

	/**
	 * Load the booking's customer id when that customer has a payment method on file.
	 *
	 * @param string $bookingId Booking UUID.
	 * @param string $kind Charge kind tag.
	 *
	 * @return string|null The customer id, or null when the charge must be skipped.
	 */
	private function resolveChargeableCustomer(string $bookingId, string $kind): string|null {
		$booking = $this->loadBooking(bookingId: $bookingId);
		if ($booking === null) {
			return null;
		}

		$customerId = (string)($booking['customerId'] ?? '');
		if ($this->hasPaymentMethodOnFile(customerId: $customerId) === false) {
			$this->logger->info(
				'Pipelinq: no payment method on file — charge skipped',
				['booking' => $bookingId, 'customer' => $customerId, 'kind' => $kind]
			);
			return null;
		}

		return $customerId;
	}//end resolveChargeableCustomer()

	/**
	 * Read the configured openconnector payment source slug, logging when unset.
	 *
	 * @param string $bookingId Booking UUID.
	 * @param string $kind Charge kind tag.
	 *
	 * @return string The source slug, or '' when not configured.
	 */
	private function resolvePaymentSource(string $bookingId, string $kind): string {
		$sourceSlug = $this->appConfig->getValueString(AppIdentity::APP_ID, AppointmentDepositService::PAYMENT_SOURCE_KEY, '');
		if ($sourceSlug === '') {
			$this->logger->warning(
				'Pipelinq: appointment_payment_source not configured — charge skipped',
				['booking' => $bookingId, 'kind' => $kind]
			);
		}

		return $sourceSlug;
	}//end resolvePaymentSource()

	/**
	 * Build the chargeCustomer payload for a booking fee.
	 *
	 * @param string     $customerId Customer UUID.
	 * @param BookingFee $fee        The fee to charge.
	 *
	 * @return array<string, mixed>
	 */
	private function buildChargePayload(string $customerId, BookingFee $fee): array {
		return [
			'customerId' => $customerId,
			'amount' => [
				'value' => $this->centsToDecimal(cents: $fee->cents()),
				'currency' => 'EUR',
			],
			'description' => sprintf('Pipelinq %s fee — booking %s', $fee->kind, $fee->bookingId),
			'metadata' => [
				'app' => AppIdentity::APP_ID,
				'kind' => $fee->kind,
				'bookingId' => $fee->bookingId,
			],
		];
	}//end buildChargePayload()

	/**
	 * Queue the charge with openconnector.
	 *
	 * @param array{payment: object, sourceSlug: string, charge: array<string, mixed>, cents: int} $prepared The
	 *   PaymentService, the payment source slug, the chargeCustomer payload and the cents (logged on failure).
	 *
	 * @return bool True when the charge was queued.
	 */
	private function submitCharge(array $prepared): bool {
		$charge = $prepared['charge'];
		try {
			// @phpstan-ignore-next-line dynamic openconnector seam
			$prepared['payment']->chargeCustomer($prepared['sourceSlug'], $charge);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: openconnector chargeCustomer failed',
				['booking' => $charge['metadata']['bookingId'], 'kind' => $charge['metadata']['kind'], 'cents' => $prepared['cents']]
			);
			return false;
		}//end try

		return true;
	}//end submitCharge()

	/**
	 * Resolve openconnector PaymentService from the DI container.
	 *
	 * @return object|null
	 */
	private function resolvePaymentService(): ?object {
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
			// Openconnector not installed.
		}

		return null;
	}//end resolvePaymentService()

	/**
	 * Load a Booking record by id (or null when unavailable).
	 *
	 * @param string $bookingId Booking UUID.
	 *
	 * @return array<string, mixed>|null
	 */
	private function loadBooking(string $bookingId): ?array {
		$store = $this->resolveObjectStore(schemaKey: BookingService::BOOKING_SCHEMA_KEY);
		if ($store === null) {
			return null;
		}

		try {
			$found = $store['service']->find(id: $bookingId, register: $store['register'], schema: $store['schema']);
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: booking lookup failed during fee charge', ['booking' => $bookingId]);
			return null;
		}

		return $this->toArray(object: $found);
	}//end loadBooking()

	/**
	 * Resolve the register, the schema behind a config key and the ObjectService.
	 *
	 * @param string $schemaKey App config key holding the schema id.
	 *
	 * @return array{service: object, register: string, schema: string}|null Null when unconfigured or OpenRegister is unavailable.
	 */
	private function resolveObjectStore(string $schemaKey): array|null {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, $schemaKey, '');
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			return null;
		}

		return ['service' => $objectService, 'register' => $register, 'schema' => $schema];
	}//end resolveObjectStore()

	/**
	 * Find a record in a resolved object store as an array (null when the lookup fails).
	 *
	 * @param array{service: object, register: string, schema: string} $store The resolved object store.
	 * @param string $objectId Record UUID.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findRecord(array $store, string $objectId): array|null {
		try {
			$found = $store['service']->find(id: $objectId, register: $store['register'], schema: $store['schema']);
		} catch (Throwable $e) {
			return null;
		}

		return $this->toArray(object: $found);
	}//end findRecord()

	/**
	 * True when the customer has a payment method on file.
	 *
	 * Looks up the customer mirror record (denormalised in the pipelinq
	 * register, see BookingService::CUSTOMER_SCHEMA_KEY). The mirror
	 * stores `paymentMethodToken` when the customer added a payment
	 * method during a previous booking.
	 *
	 * @param string $customerId Customer UUID.
	 *
	 * @return bool
	 */
	private function hasPaymentMethodOnFile(string $customerId): bool {
		if ($customerId === '') {
			return false;
		}

		$store = $this->resolveObjectStore(schemaKey: BookingService::CUSTOMER_SCHEMA_KEY);
		if ($store === null) {
			return false;
		}

		$data = $this->findRecord(store: $store, objectId: $customerId);
		if ($data === null) {
			return false;
		}

		$token = trim((string)($data['paymentMethodToken'] ?? ''));
		return ($token !== '');
	}//end hasPaymentMethodOnFile()

	/**
	 * Stamp the fee's timestamp field on its Booking record.
	 *
	 * @param BookingFee $fee   The charged fee; its stamp field is set.
	 * @param string     $value ISO-8601 timestamp.
	 *
	 * @return void
	 */
	private function stampBooking(BookingFee $fee, string $value): void {
		$store = $this->resolveObjectStore(schemaKey: BookingService::BOOKING_SCHEMA_KEY);
		if ($store === null) {
			return;
		}

		$data = $this->findRecord(store: $store, objectId: $fee->bookingId);
		if ($data === null) {
			return;
		}

		$data[$fee->stampField] = $value;
		if (array_key_exists('@self', $data) === true) {
			unset($data['@self']);
		}

		$this->saveStampedBooking(fee: $fee, stamped: ['store' => $store, 'data' => $data]);
	}//end stampBooking()

	/**
	 * Save a stamped Booking record, logging a failed save.
	 *
	 * @param BookingFee           $fee     The charged fee.
	 * @param array<string, mixed> $stamped Keys: store (the resolved object store: service, register, schema),
	 *   data (the stamped Booking data).
	 *
	 * @return void
	 */
	private function saveStampedBooking(BookingFee $fee, array $stamped): void {
		$store = $stamped['store'];
		try {
			$store['service']->saveObject(
				object: $stamped['data'],
				extend: [],
				register: $store['register'],
				schema: $store['schema'],
				uuid: $fee->bookingId
			);
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: booking stamp save failed', ['booking' => $fee->bookingId, 'field' => $fee->stampField]);
		}
	}//end saveStampedBooking()

	/**
	 * Now in ISO-8601 UTC.
	 *
	 * @return string
	 */
	private function nowIso(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
	}//end nowIso()
}//end class

