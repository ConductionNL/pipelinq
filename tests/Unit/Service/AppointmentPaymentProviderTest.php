<?php

/**
 * Unit tests for AppointmentPaymentProvider — BookingService payment
 * seam (member 04 → member 08) covering no-show + late-cancellation
 * fee charging with payment-method-on-file gating (REQ-APT-011A).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/appointment-booking-08-deposit-payment/specs/appointment-booking/spec.md#req-apt-011a
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\AppointmentPaymentProvider;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for AppointmentPaymentProvider.
 */
class AppointmentPaymentProviderTest extends TestCase {

	/**
	 * In-memory app config store.
	 *
	 * @var array<string, string>
	 */
	private array $appConfigStore = [
		'register' => 'pipelinq',
		'booking_schema' => 'booking',
		'contact_schema' => 'contact',
		'appointment_payment_source' => 'mollie-prod',
	];

	/**
	 * Build a provider with overridable mocks.
	 *
	 * @param ObjectServiceInterface|null $objectService Optional OR ObjectService mock.
	 * @param mixed $paymentStub Optional payment seam stub.
	 *
	 * @return AppointmentPaymentProvider
	 */
	private function buildProvider(
		?ObjectServiceInterface $objectService = null,
		mixed $paymentStub = null,
	): AppointmentPaymentProvider {
		$objectService = ($objectService ?? $this->createMock(originalClassName: ObjectServiceInterface::class));

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objectService) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}

				throw new RuntimeException(sprintf('No binding for %s', $id));
			}
		);

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				return ($this->appConfigStore[$key] ?? $default);
			}
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		$provider = new AppointmentPaymentProvider(
			container: $container,
			appConfig: $appConfig,
			logger: $logger
		);
		if ($paymentStub !== null) {
			$provider->setPaymentService(service: $paymentStub);
		}

		return $provider;
	}//end buildProvider()

	/**
	 * Build a stub PaymentService that captures the chargeCustomer call.
	 *
	 * @return object
	 */
	private function paymentStub(): object {
		return new class {
			/**
			 * Number of times chargeCustomer was invoked.
			 *
			 * @var integer
			 */
			public int $calls = 0;

			/**
			 * Last source slug chargeCustomer was called with.
			 *
			 * @var string
			 */
			public string $source = '';

			/**
			 * Last payload chargeCustomer was called with.
			 *
			 * @var array<string, mixed>
			 */
			public array $payload = [];

			/**
			 * Stub chargeCustomer.
			 *
			 * @param string $source Source slug.
			 * @param array<string, mixed> $payload Payload.
			 *
			 * @return void
			 */
			public function chargeCustomer(string $source, array $payload): void {
				$this->calls++;
				$this->source = $source;
				$this->payload = $payload;
			}//end chargeCustomer()
		};
	}//end paymentStub()

	/**
	 * No-show fee is charged when a payment method is on file and stamps
	 * `noShowFeeChargedAt` on the booking record.
	 *
	 * @return void
	 */
	public function testChargeNoShowFeeQueuesChargeWhenPaymentMethodOnFile(): void {
		$captured = null;
		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('find')->willReturnCallback(
			function (string $id): array {
				if ($id === 'b-1') {
					return [
						'@self' => ['id' => 'b-1'],
						'customerId' => 'cust-1',
						'status' => 'no-show',
					];
				}

				if ($id === 'cust-1') {
					return [
						'@self' => ['id' => 'cust-1'],
						'paymentMethodToken' => 'pm_token_123',
					];
				}

				return [];
			}
		);

		$object->method('saveObject')->willReturnCallback(
			function (
				array|object $payload,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use (&$captured): array {
				$captured = $payload;
				if (is_array($payload) === true) {
					return $payload;
				}

				return (array)$payload;
			}
		);

		$stub = $this->paymentStub();
		$provider = $this->buildProvider(objectService: $object, paymentStub: $stub);

		$provider->chargeNoShowFee(bookingId: 'b-1', amount: 25.0);

		$this->assertSame(expected: 1, actual: $stub->calls);
		$this->assertSame(expected: 'mollie-prod', actual: $stub->source);
		$this->assertSame(expected: 'cust-1', actual: $stub->payload['customerId']);
		$this->assertSame(expected: '25.00', actual: $stub->payload['amount']['value']);
		$this->assertSame(expected: 'EUR', actual: $stub->payload['amount']['currency']);
		$this->assertSame(expected: 'no-show', actual: $stub->payload['metadata']['kind']);
		$this->assertSame(expected: 'b-1', actual: $stub->payload['metadata']['bookingId']);
		$this->assertIsArray(actual: $captured);
		$this->assertArrayHasKey(key: 'noShowFeeChargedAt', array: $captured);
		$this->assertNotEmpty(actual: $captured['noShowFeeChargedAt']);

	}//end testChargeNoShowFeeQueuesChargeWhenPaymentMethodOnFile()

	/**
	 * No payment method on file → no chargeCustomer call (REQ-APT-011
	 * scenario 2). The booking is not stamped.
	 *
	 * @return void
	 */
	public function testChargeNoShowFeeSkippedWhenNoPaymentMethod(): void {
		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('find')->willReturnCallback(
			function (string $id): array {
				if ($id === 'b-2') {
					return ['@self' => ['id' => 'b-2'], 'customerId' => 'cust-2'];
				}

				// No paymentMethodToken on the customer mirror.
				return ['@self' => ['id' => 'cust-2']];
			}
		);

		$object->expects($this->never())->method('saveObject');

		$stub = $this->paymentStub();
		$provider = $this->buildProvider(objectService: $object, paymentStub: $stub);

		$provider->chargeNoShowFee(bookingId: 'b-2', amount: 25.0);

		$this->assertSame(expected: 0, actual: $stub->calls);

	}//end testChargeNoShowFeeSkippedWhenNoPaymentMethod()

	/**
	 * Cancellation fee path uses the same gating + stamps a different field.
	 *
	 * @return void
	 */
	public function testChargeCancellationFeeStampsCancellationField(): void {
		$captured = null;
		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('find')->willReturnCallback(
			function (string $id): array {
				if ($id === 'b-3') {
					return ['@self' => ['id' => 'b-3'], 'customerId' => 'cust-3'];
				}

				return ['@self' => ['id' => 'cust-3'], 'paymentMethodToken' => 'pm_x'];
			}
		);

		$object->method('saveObject')->willReturnCallback(
			function (
				array|object $payload,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use (&$captured): array {
				$captured = $payload;
				if (is_array($payload) === true) {
					return $payload;
				}

				return (array)$payload;
			}
		);

		$stub = $this->paymentStub();
		$provider = $this->buildProvider(objectService: $object, paymentStub: $stub);

		$provider->chargeCancellationFee(bookingId: 'b-3', amount: 50.0);

		$this->assertSame(expected: 1, actual: $stub->calls);
		$this->assertSame(expected: '50.00', actual: $stub->payload['amount']['value']);
		$this->assertSame(expected: 'late-cancellation', actual: $stub->payload['metadata']['kind']);
		$this->assertIsArray(actual: $captured);
		$this->assertArrayHasKey(key: 'cancellationFeeChargedAt', array: $captured);
		$this->assertArrayNotHasKey(key: 'noShowFeeChargedAt', array: $captured);

	}//end testChargeCancellationFeeStampsCancellationField()

	/**
	 * Zero / negative amounts are silently no-ops.
	 *
	 * @return void
	 */
	public function testChargeBookingFeeNoopOnNonPositiveAmount(): void {
		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->expects($this->never())->method('find');

		$stub = $this->paymentStub();
		$provider = $this->buildProvider(objectService: $object, paymentStub: $stub);

		$provider->chargeNoShowFee(bookingId: 'b-1', amount: 0.0);
		$provider->chargeCancellationFee(bookingId: 'b-1', amount: -5.0);

		$this->assertSame(expected: 0, actual: $stub->calls);

	}//end testChargeBookingFeeNoopOnNonPositiveAmount()

	/**
	 * Empty booking id is silently no-op (defence-in-depth — the seam
	 * caller in BookingService already guards this, but we don't trust
	 * it twice).
	 *
	 * @return void
	 */
	public function testChargeBookingFeeNoopOnEmptyBookingId(): void {
		$stub = $this->paymentStub();
		$provider = $this->buildProvider(paymentStub: $stub);

		$provider->chargeNoShowFee(bookingId: '', amount: 10.0);
		$provider->chargeCancellationFee(bookingId: '', amount: 10.0);

		$this->assertSame(expected: 0, actual: $stub->calls);

	}//end testChargeBookingFeeNoopOnEmptyBookingId()

	/**
	 * Source slug unconfigured → no chargeCustomer call (configuration
	 * error, not a runtime fault — logged + skipped).
	 *
	 * @return void
	 */
	public function testChargeBookingFeeSkippedWhenSourceUnconfigured(): void {
		$this->appConfigStore['appointment_payment_source'] = '';

		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('find')->willReturnCallback(
			function (string $id): array {
				if ($id === 'b-4') {
					return ['@self' => ['id' => 'b-4'], 'customerId' => 'cust-4'];
				}

				return ['@self' => ['id' => 'cust-4'], 'paymentMethodToken' => 'pm_x'];
			}
		);

		$stub = $this->paymentStub();
		$provider = $this->buildProvider(objectService: $object, paymentStub: $stub);

		$provider->chargeNoShowFee(bookingId: 'b-4', amount: 25.0);
		$this->assertSame(expected: 0, actual: $stub->calls);

	}//end testChargeBookingFeeSkippedWhenSourceUnconfigured()

	/**
	 * Openconnector PaymentService unavailable -> soft skip; no throw.
	 *
	 * @return void
	 */
	public function testChargeBookingFeeNoopWhenPaymentServiceUnavailable(): void {
		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->expects($this->never())->method('find');

		// No paymentStub → resolvePaymentService returns null.
		$provider = $this->buildProvider(objectService: $object);

		$provider->chargeNoShowFee(bookingId: 'b-1', amount: 10.0);
		$this->assertTrue(condition: true);

	}//end testChargeBookingFeeNoopWhenPaymentServiceUnavailable()

	/**
	 * Float→cents rounding handles half-cent values safely (REQ-APT-011A
	 * money handling: half-cent rounds to nearest integer cent).
	 *
	 * @return void
	 */
	public function testChargeBookingFeeRoundsHalfCentValues(): void {
		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('find')->willReturnCallback(
			function (string $id): array {
				if ($id === 'b-5') {
					return ['@self' => ['id' => 'b-5'], 'customerId' => 'cust-5'];
				}

				return ['@self' => ['id' => 'cust-5'], 'paymentMethodToken' => 'pm_x'];
			}
		);

		$stub = $this->paymentStub();
		$provider = $this->buildProvider(objectService: $object, paymentStub: $stub);

		// 12.345 EUR -> 1234.5 cents -> round(int) -> 1235 cents -> "12.35"
		$provider->chargeNoShowFee(bookingId: 'b-5', amount: 12.345);
		$this->assertSame(expected: '12.35', actual: $stub->payload['amount']['value']);

	}//end testChargeBookingFeeRoundsHalfCentValues()
}//end class
