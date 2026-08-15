<?php

/**
 * Unit tests for AppointmentDepositService — deposit session creation,
 * payment callback routing, expiry detection, and timeout release.
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
 * @spec openspec/changes/appointment-booking-08-deposit-payment/specs/appointment-booking/spec.md#req-apt-010
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Pipelinq\Service\AppointmentDepositService;
use OCA\Pipelinq\Service\BookingService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for AppointmentDepositService.
 *
 * The PaymentService seam is duck-typed: a stub class with the
 * `createSession` method is injected via setPaymentService so the test
 * never touches the openconnector dependency.
 */
class AppointmentDepositServiceTest extends TestCase {

	/**
	 * In-memory app config store.
	 *
	 * @var array<string, string>
	 */
	private array $appConfigStore = [];

	/**
	 * Build the service under test with the mocks the tests share.
	 *
	 * @param BookingService|null $bookingService Optional pre-built BookingService mock.
	 *
	 * @return array{0: AppointmentDepositService, 1: BookingService}
	 */
	private function buildService(?BookingService $bookingService = null): array {
		$container = $this->createMock(originalClassName: ContainerInterface::class);

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				return ($this->appConfigStore[$key] ?? $default);
			}
		);

		$urlGenerator = $this->createMock(originalClassName: IURLGenerator::class);
		$urlGenerator->method('linkToOCSRouteAbsolute')
			->willReturn('https://nc.example/index.php/apps/pipelinq/api/appointment-payment-webhook');

		$bookingService = ($bookingService ?? $this->createMock(originalClassName: BookingService::class));
		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		$service = new AppointmentDepositService(
			container: $container,
			appConfig: $appConfig,
			urlGenerator: $urlGenerator,
			bookingService: $bookingService,
			logger: $logger
		);

		return [$service, $bookingService];
	}//end buildService()

	/**
	 * Build a stub PaymentService whose createSession returns a fixed
	 * sessionUrl + id.
	 *
	 * @param string $sessionUrl Session URL the stub returns.
	 * @param string $sessionId Provider reference id.
	 *
	 * @return object
	 */
	private function paymentStub(string $sessionUrl, string $sessionId): object {
		return new class($sessionUrl, $sessionId) {
			/**
			 * The source slug createSession was called with.
			 *
			 * @var string
			 */
			public string $capturedSource = '';

			/**
			 * Last payload createSession was called with.
			 *
			 * @var array<string, mixed>
			 */
			public array $capturedPayload = [];

			/**
			 * Stub constructor capturing the fixture session URL + id.
			 *
			 * @param string $sessionUrl Session URL the stub returns.
			 * @param string $sessionId Provider reference id the stub returns.
			 */
			public function __construct(
				private string $sessionUrl,
				private string $sessionId,
			) {
			}//end __construct()

			/**
			 * Stub createSession honouring openconnector's call shape.
			 *
			 * @param string $source Source slug.
			 * @param array<string, mixed> $payload Payload.
			 *
			 * @return array<string, mixed>
			 */
			public function createSession(string $source, array $payload): array {
				$this->capturedSource = $source;
				$this->capturedPayload = $payload;
				return [
					'id' => $this->sessionId,
					'sessionUrl' => $this->sessionUrl,
					'status' => 'open',
				];
			}//end createSession()
		};
	}//end paymentStub()

	/**
	 * Creating a deposit session returns the session URL + provider
	 * reference and forwards the integer-cent amount as a `nn.nn` major
	 * amount to openconnector.
	 *
	 * @return void
	 */
	public function testCreateDepositSessionReturnsSessionUrl(): void {
		$this->appConfigStore['appointment_payment_source'] = 'mollie-prod';
		[$service] = $this->buildService();

		$stub = $this->paymentStub(sessionUrl: 'https://pay.example/abc', sessionId: 'sess-1');
		$service->setPaymentService(service: $stub);

		$result = $service->createDepositSession(
			bookingId: 'b-1',
			amountCents: 2000,
			currency: 'eur',
			description: 'Booking deposit',
			returnUrl: 'https://nc/portal/b-1'
		);

		$this->assertSame(expected: 'https://pay.example/abc', actual: $result['sessionUrl']);
		$this->assertSame(expected: 'pending', actual: $result['status']);
		$this->assertSame(expected: 'sess-1', actual: $result['providerReference']);
		$this->assertSame(expected: 'mollie-prod', actual: $stub->capturedSource);
		$this->assertSame(expected: '20.00', actual: $stub->capturedPayload['amount']['value']);
		$this->assertSame(expected: 'EUR', actual: $stub->capturedPayload['amount']['currency']);
		$this->assertSame(expected: 'b-1', actual: $stub->capturedPayload['metadata']['bookingId']);
		$this->assertSame(
			expected: 'https://nc.example/index.php/apps/pipelinq/api/appointment-payment-webhook',
			actual: $stub->capturedPayload['webhookUrl']
		);

	}//end testCreateDepositSessionReturnsSessionUrl()

	/**
	 * When the openconnector PaymentService cannot be resolved the
	 * call returns `status=unavailable` with an empty sessionUrl and
	 * never throws (REQ-APT-010 soft-degrade — booking still records).
	 *
	 * @return void
	 */
	public function testCreateDepositSessionReturnsUnavailableWhenPaymentServiceMissing(): void {
		[$service] = $this->buildService();
		// No setPaymentService → container.get('OCA\OpenConnector\Service\PaymentService') will throw.
		$result = $service->createDepositSession(
			bookingId: 'b-2',
			amountCents: 5000
		);

		$this->assertSame(expected: '', actual: $result['sessionUrl']);
		$this->assertSame(expected: 'unavailable', actual: $result['status']);
		$this->assertSame(expected: '', actual: $result['providerReference']);

	}//end testCreateDepositSessionReturnsUnavailableWhenPaymentServiceMissing()

	/**
	 * Configured source slug is required; an empty slug skips the call.
	 *
	 * @return void
	 */
	public function testCreateDepositSessionReturnsUnavailableWhenSourceUnconfigured(): void {
		// No appointment_payment_source in store.
		[$service] = $this->buildService();

		$stub = $this->paymentStub(sessionUrl: 'https://x', sessionId: 'x');
		$service->setPaymentService(service: $stub);

		$result = $service->createDepositSession(
			bookingId: 'b-3',
			amountCents: 1000
		);

		$this->assertSame(expected: 'unavailable', actual: $result['status']);

	}//end testCreateDepositSessionReturnsUnavailableWhenSourceUnconfigured()

	/**
	 * Empty booking id is rejected up-front.
	 *
	 * @return void
	 */
	public function testCreateDepositSessionRejectsEmptyBookingId(): void {
		[$service] = $this->buildService();
		$this->expectException(exception: InvalidArgumentException::class);
		$service->createDepositSession(bookingId: '', amountCents: 1000);

	}//end testCreateDepositSessionRejectsEmptyBookingId()

	/**
	 * Negative / zero amount is rejected up-front.
	 *
	 * @return void
	 */
	public function testCreateDepositSessionRejectsNonPositiveAmount(): void {
		[$service] = $this->buildService();
		$this->expectException(exception: InvalidArgumentException::class);
		$service->createDepositSession(bookingId: 'b-1', amountCents: 0);

	}//end testCreateDepositSessionRejectsNonPositiveAmount()

	/**
	 * Payment success callback drives BookingService::confirmBooking
	 * (which sets `confirmationSentAt` and triggers the email seam).
	 *
	 * @return void
	 */
	public function testHandlePaymentCallbackConfirmsOnPaidStatus(): void {
		$booking = $this->createMock(originalClassName: BookingService::class);
		$booking->expects($this->once())
			->method('confirmBooking')
			->with($this->equalTo(value: 'b-1'),
				$this->stringContains(string: 'Deposit payment confirmed')
			);

		[$service] = $this->buildService(bookingService: $booking);

		$outcome = $service->handlePaymentCallback(bookingId: 'b-1', status: 'paid');
		$this->assertSame(expected: 'confirmed', actual: $outcome);

	}//end testHandlePaymentCallbackConfirmsOnPaidStatus()

	/**
	 * Failed / expired / cancelled callbacks leave the booking in
	 * pending-deposit so the timeout job can release it later.
	 *
	 * @return void
	 */
	public function testHandlePaymentCallbackLeavesBookingPendingOnFailure(): void {
		$booking = $this->createMock(originalClassName: BookingService::class);
		$booking->expects($this->never())->method('confirmBooking');

		[$service] = $this->buildService(bookingService: $booking);

		foreach (['failed', 'expired', 'cancelled', 'unknown'] as $status) {
			$outcome = $service->handlePaymentCallback(bookingId: 'b-1', status: $status);
			$this->assertSame(expected: 'unchanged', actual: $outcome, message: $status);
		}

	}//end testHandlePaymentCallbackLeavesBookingPendingOnFailure()

	/**
	 * A confirmBooking throw in the success path is swallowed and the
	 * outcome is 'unchanged'; we never want a transient OR error to
	 * surface 500 to openconnector.
	 *
	 * @return void
	 */
	public function testHandlePaymentCallbackReturnsUnchangedWhenConfirmThrows(): void {
		$booking = $this->createMock(originalClassName: BookingService::class);
		$booking->method('confirmBooking')->willThrowException(new RuntimeException('OR down'));

		[$service] = $this->buildService(bookingService: $booking);

		$outcome = $service->handlePaymentCallback(bookingId: 'b-x', status: 'paid');
		$this->assertSame(expected: 'unchanged', actual: $outcome);

	}//end testHandlePaymentCallbackReturnsUnchangedWhenConfirmThrows()

	/**
	 * Empty status / bookingId is rejected with InvalidArgumentException.
	 *
	 * @return void
	 */
	public function testHandlePaymentCallbackRejectsEmptyInputs(): void {
		[$service] = $this->buildService();

		$this->expectException(exception: InvalidArgumentException::class);
		$service->handlePaymentCallback(bookingId: '', status: 'paid');

	}//end testHandlePaymentCallbackRejectsEmptyInputs()

	/**
	 * The 15-minute expiry boundary: 14:59 returns false, 15:01 returns true.
	 *
	 * @return void
	 */
	public function testIsDepositExpiredHonoursFifteenMinuteWindow(): void {
		[$service] = $this->buildService();

		$created = '2026-06-15T10:00:00+00:00';
		$createdTs = strtotime($created);

		$this->assertFalse(condition: $service->isDepositExpired(createdAtIso: $created, nowEpoch: ($createdTs + 899)));
		$this->assertTrue(condition: $service->isDepositExpired(createdAtIso: $created, nowEpoch: ($createdTs + 900)));
		$this->assertTrue(condition: $service->isDepositExpired(createdAtIso: $created, nowEpoch: ($createdTs + 3600)));

	}//end testIsDepositExpiredHonoursFifteenMinuteWindow()

	/**
	 * An empty or malformed createdAt is never expired (returns false).
	 *
	 * @return void
	 */
	public function testIsDepositExpiredFalseOnEmptyOrInvalidCreatedAt(): void {
		[$service] = $this->buildService();

		$this->assertFalse(condition: $service->isDepositExpired(createdAtIso: ''));
		$this->assertFalse(condition: $service->isDepositExpired(createdAtIso: 'not-a-date'));

	}//end testIsDepositExpiredFalseOnEmptyOrInvalidCreatedAt()

	/**
	 * Releasing an expired deposit calls BookingService::cancelBooking
	 * with the system actor (REQ-APT-009 scenario 3 — staff/system
	 * cancellations skip the charge).
	 *
	 * @return void
	 */
	public function testReleaseExpiredDepositCancelsAsSystem(): void {
		$booking = $this->createMock(originalClassName: BookingService::class);
		$booking->expects($this->once())
			->method('cancelBooking')
			->with($this->equalTo(value: 'b-1'),
				$this->stringContains(string: 'Deposit not paid'),
				$this->equalTo(value: BookingService::ACTOR_SYSTEM)
			);

		[$service] = $this->buildService(bookingService: $booking);
		$service->releaseExpiredDeposit(bookingId: 'b-1');

	}//end testReleaseExpiredDepositCancelsAsSystem()

	/**
	 * A cancelBooking throw is swallowed (logged) — never propagated to
	 * the cron worker (a single stuck booking can never block the job).
	 *
	 * @return void
	 */
	public function testReleaseExpiredDepositSwallowsCancelExceptions(): void {
		$booking = $this->createMock(originalClassName: BookingService::class);
		$booking->method('cancelBooking')->willThrowException(new RuntimeException('OR down'));

		[$service] = $this->buildService(bookingService: $booking);
		$service->releaseExpiredDeposit(bookingId: 'b-1');
		// No exception thrown — assertion is the absence of one.
		$this->assertTrue(condition: true);

	}//end testReleaseExpiredDepositSwallowsCancelExceptions()

	/**
	 * Empty booking id is a no-op (no call to BookingService).
	 *
	 * @return void
	 */
	public function testReleaseExpiredDepositNoopOnEmptyId(): void {
		$booking = $this->createMock(originalClassName: BookingService::class);
		$booking->expects($this->never())->method('cancelBooking');

		[$service] = $this->buildService(bookingService: $booking);
		$service->releaseExpiredDeposit(bookingId: '');

	}//end testReleaseExpiredDepositNoopOnEmptyId()
}//end class
