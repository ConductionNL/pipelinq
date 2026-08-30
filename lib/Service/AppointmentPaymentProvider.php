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
use OCA\Pipelinq\AppInfo\Application;
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
 */
class AppointmentPaymentProvider {
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
		$this->chargeBookingFee(
			bookingId: $bookingId,
			amount: $amount,
			kind: self::KIND_NO_SHOW,
			stampField: 'noShowFeeChargedAt'
		);
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
		$this->chargeBookingFee(
			bookingId: $bookingId,
			amount: $amount,
			kind: self::KIND_CANCELLATION,
			stampField: 'cancellationFeeChargedAt'
		);
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
	 * @param string $bookingId Booking UUID.
	 * @param float $amount Fee amount (euros, converted to cents).
	 * @param string $kind Charge kind tag (no-show / late-cancellation).
	 * @param string $stampField Booking field to stamp on success.
	 *
	 * @return void
	 */
	private function chargeBookingFee(
		string $bookingId,
		float $amount,
		string $kind,
		string $stampField,
	): void {
		if ($bookingId === '' || $amount <= 0.0) {
			return;
		}

		$cents = (int)round(($amount * 100));
		if ($cents <= 0) {
			return;
		}

		$payment = $this->resolvePaymentService();
		if ($payment === null) {
			$this->logger->info(
				'Pipelinq: openconnector PaymentService unavailable — charge skipped',
				[
					'booking' => $bookingId,
					'kind' => $kind,
				]
			);
			return;
		}

		$booking = $this->loadBooking(bookingId: $bookingId);
		if ($booking === null) {
			return;
		}

		$customerId = (string)($booking['customerId'] ?? '');
		if ($this->hasPaymentMethodOnFile(customerId: $customerId) === false) {
			$this->logger->info(
				'Pipelinq: no payment method on file — charge skipped',
				[
					'booking' => $bookingId,
					'customer' => $customerId,
					'kind' => $kind,
				]
			);
			return;
		}

		$sourceSlug = $this->appConfig->getValueString(
			Application::APP_ID,
			AppointmentDepositService::PAYMENT_SOURCE_KEY,
			''
		);
		if ($sourceSlug === '') {
			$this->logger->warning(
				'Pipelinq: appointment_payment_source not configured — charge skipped',
				[
					'booking' => $bookingId,
					'kind' => $kind,
				]
			);
			return;
		}

		try {
			// @phpstan-ignore-next-line dynamic openconnector seam
			$payment->chargeCustomer(
				$sourceSlug,
				[
					'customerId' => $customerId,
					'amount' => [
						'value' => $this->centsToDecimal(cents: $cents),
						'currency' => 'EUR',
					],
					'description' => sprintf('Pipelinq %s fee — booking %s', $kind, $bookingId),
					'metadata' => [
						'app' => Application::APP_ID,
						'kind' => $kind,
						'bookingId' => $bookingId,
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: openconnector chargeCustomer failed',
				[
					'booking' => $bookingId,
					'kind' => $kind,
					'cents' => $cents,
				]
			);
			return;
		}//end try

		$this->stampBooking(bookingId: $bookingId, field: $stampField, value: $this->nowIso());
	}//end chargeBookingFee()

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
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(
			Application::APP_ID,
			BookingService::BOOKING_SCHEMA_KEY,
			''
		);
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			return null;
		}

		try {
			$found = $objectService->find(
				id: $bookingId,
				register: $register,
				schema: $schema
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: booking lookup failed during fee charge',
				['booking' => $bookingId]
			);
			return null;
		}

		return $this->toArray(object: $found);
	}//end loadBooking()

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

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(
			Application::APP_ID,
			BookingService::CUSTOMER_SCHEMA_KEY,
			''
		);
		if ($register === '' || $schema === '') {
			return false;
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			return false;
		}

		try {
			$customer = $objectService->find(
				id: $customerId,
				register: $register,
				schema: $schema
			);
		} catch (Throwable $e) {
			return false;
		}

		$data = $this->toArray(object: $customer);
		if ($data === null) {
			return false;
		}

		$token = trim((string)($data['paymentMethodToken'] ?? ''));
		return ($token !== '');
	}//end hasPaymentMethodOnFile()

	/**
	 * Stamp a timestamp field on a Booking record.
	 *
	 * @param string $bookingId Booking UUID.
	 * @param string $field Field name (e.g. noShowFeeChargedAt).
	 * @param string $value ISO-8601 timestamp.
	 *
	 * @return void
	 */
	private function stampBooking(string $bookingId, string $field, string $value): void {
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
			return;
		}

		$data = $this->toArray(object: $booking);
		if ($data === null) {
			return;
		}

		$data[$field] = $value;
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
				'Pipelinq: booking stamp save failed',
				[
					'booking' => $bookingId,
					'field' => $field,
				]
			);
		}
	}//end stampBooking()

	/**
	 * Render an integer-cent amount as a `nn.nn` decimal string.
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
	 * Now in ISO-8601 UTC.
	 *
	 * @return string
	 */
	private function nowIso(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
	}//end nowIso()
}//end class
