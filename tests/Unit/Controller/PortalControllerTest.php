<?php

/**
 * Unit tests for PortalController — public booking surface (member 05).
 *
 * Mocks BookingService, OpenRegister ObjectService, the URL generator and the
 * signing-key plumbing. No live Nextcloud server is required.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/appointment-booking-05-portal-controller/specs/appointment-booking/spec.md#req-apt-005
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\PortalController;
use OCA\Pipelinq\Service\AppointmentDepositService;
use OCA\Pipelinq\Service\BookingService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PortalController.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Aggregates every collaborator
 *  the controller takes — necessary surface area for a public booking entrypoint.
 */
class PortalControllerTest extends TestCase {

	/**
	 * In-memory app-config key/value store driven by the mock IAppConfig.
	 *
	 * @var array<string, string>
	 */
	private array $appConfigStore = [];

	/**
	 * Build a controller wired to mocked collaborators.
	 *
	 * Returns a tuple `[controller, bookingService, objectService, request]`
	 * so each test can program the mocks it cares about.
	 *
	 * @param array<string, mixed> $serviceStub Optional service row returned by ObjectService::find for service ids.
	 * @param array<string, mixed> $bookingStub Optional booking row returned by ObjectService::find for booking ids.
	 * @param array<int, mixed> $findAllStub Optional findAll(results) for /services.
	 * @param int $time Fixed timestamp for ITimeFactory.
	 * @param array<string, mixed> $requestParams Pre-populated request params.
	 *
	 * @return array{
	 *   0: PortalController,
	 *   1: BookingService,
	 *   2: object,
	 *   3: IRequest,
	 *   4: AppointmentDepositService
	 * }
	 */
	private function build(
		array $serviceStub = [],
		array $bookingStub = [],
		array $findAllStub = [],
		int $time = 1700000000,
		array $requestParams = [],
	): array {
		// Reset the in-memory app-config store between builds.
		$this->appConfigStore = [
			'register' => 'reg-1',
			'service_schema' => 'svc-schema',
			'booking_schema' => 'bk-schema',
			'contact_schema' => 'ct-schema',
		];

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($requestParams) {
				return ($requestParams[$key] ?? $default ?? '');
			}
		);

		$booking = $this->createMock(BookingService::class);
		$deposit = $this->createMock(AppointmentDepositService::class);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') {
				return ($this->appConfigStore[$key] ?? $default);
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) {
				$this->appConfigStore[$key] = $value;
				return true;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$objectService = new class($serviceStub, $bookingStub, $findAllStub) {

			public int $saveCalls = 0;

			public ?array $lastSaved = null;

			public function __construct(
				private array $serviceStub,
				private array $bookingStub,
				private array $findAllStub,
			) {
			}//end __construct()

			public function find(string $id, string $register, string $schema): array {
				if ($schema === 'svc-schema') {
					if ($this->serviceStub === []) {
						throw new \RuntimeException('not found');
					}

					return $this->serviceStub;
				}

				if ($schema === 'bk-schema') {
					if ($this->bookingStub === []) {
						throw new \RuntimeException('not found');
					}

					return $this->bookingStub;
				}

				throw new \RuntimeException('schema unknown');
			}//end find()

			public function findAll(array $config): array {
				return ['results' => $this->findAllStub];
			}//end findAll()

			public function saveObject(
				array $object,
				array $extend,
				string $register,
				string $schema,
				?string $uuid = null,
			): array {
				$this->saveCalls++;
				$this->lastSaved = $object;
				return ['@self' => ['id' => 'ct-new'], 'email' => $object['email'] ?? ''];
			}//end saveObject()
		};

		$container->method('get')->willReturn($objectService);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static function (string $path) {
				return ('https://example.test' . $path);
			}
		);

		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn($time);

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturn(str_repeat('A', 64));

		$logger = $this->createMock(LoggerInterface::class);

		$controller = new PortalController(
			$request,
			$booking,
			$deposit,
			$appConfig,
			$container,
			$appManager,
			$urlGenerator,
			$timeFactory,
			$secureRandom,
			$logger
		);

		return [$controller, $booking, $objectService, $request, $deposit];
	}//end build()

	/**
	 * GET /portal/services returns 200 with the bookable services list.
	 *
	 * @return void
	 */
	public function testServicesReturnsBookableList(): void {
		$findAll = [
			[
				'@self' => ['id' => 'svc-1'],
				'name' => 'Knipbeurt',
				'description' => 'Hair cut',
				'durationMinutes' => 30,
				'price' => 25.0,
				'currency' => 'EUR',
				'bookableOnline' => true,
				'requiresDeposit' => false,
			],
			[
				'@self' => ['id' => 'svc-2'],
				'name' => 'Internal-only',
				'bookableOnline' => false,
			],
		];

		[$controller] = $this->build(findAllStub: $findAll);
		$response = $controller->services();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertCount(1, $data['services']);
		$this->assertSame('svc-1', $data['services'][0]['id']);
		$this->assertSame('Knipbeurt', $data['services'][0]['name']);
	}//end testServicesReturnsBookableList()

	/**
	 * GET /portal/availability returns 200 with slots for a valid date.
	 *
	 * @return void
	 */
	public function testAvailabilityReturnsSlots(): void {
		[$controller, $booking] = $this->build(
			requestParams: [
				'serviceId' => 'svc-1',
				'date' => '2026-06-10',
			]
		);

		$booking->expects($this->once())
			->method('getAvailableSlots')
			->with('svc-1', '2026-06-10')
			->willReturn(
				[
					[
						'startTime' => '2026-06-10T09:00:00+00:00',
						'endTime' => '2026-06-10T09:30:00+00:00',
						'durationMinutes' => 30,
						'resourceId' => 'res-1',
					],
				]
			);

		$response = $controller->availability();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertCount(1, $data['slots']);
		$this->assertSame('res-1', $data['slots'][0]['resourceId']);
	}//end testAvailabilityReturnsSlots()

	/**
	 * GET /portal/availability returns 400 when params are missing.
	 *
	 * @return void
	 */
	public function testAvailabilityRejectsMissingParams(): void {
		[$controller] = $this->build(requestParams: ['serviceId' => 'svc-1']);
		$response = $controller->availability();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testAvailabilityRejectsMissingParams()

	/**
	 * POST /portal/book returns 200 and delegates to BookingService.
	 *
	 * @return void
	 */
	public function testBookCreatesBookingForValidInput(): void {
		$service = [
			'@self' => ['id' => 'svc-1'],
			'name' => 'Knipbeurt',
			'durationMinutes' => 30,
			'bookableOnline' => true,
		];

		[$controller, $booking] = $this->build(
			serviceStub: $service,
			bookingStub: [
				'@self' => ['id' => 'bk-1'],
				'customerId' => 'ct-new',
			],
			findAllStub: [],
			requestParams: [
				'customerName' => 'Annie',
				'email' => 'annie@example.com',
				'phone' => '+31600000000',
				'serviceId' => 'svc-1',
				'startAt' => '2026-06-10T09:00:00+00:00',
			]
		);

		$booking->expects($this->once())
			->method('createBooking')
			->willReturn('bk-1');

		$response = $controller->book();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('bk-1', $data['bookingId']);
		$this->assertArrayHasKey('reschedule', $data['manageLinks']);
		$this->assertArrayHasKey('cancel', $data['manageLinks']);
		$this->assertStringContainsString('token=', $data['manageLinks']['reschedule']);
	}//end testBookCreatesBookingForValidInput()

	/**
	 * POST /portal/book on a deposit-required Service opens a payment session
	 * and forwards the customer to the hosted checkout URL (REQ-APT-010).
	 *
	 * Regression guard for the money bug where `paymentRedirect` was hardcoded
	 * null and the deposit service was never invoked, so a deposit booking was
	 * created in `pending-deposit` but the customer was never charged.
	 *
	 * @return void
	 */
	public function testBookDepositServiceOpensPaymentSession(): void {
		$service = [
			'@self' => ['id' => 'svc-1'],
			'name' => 'Massage',
			'durationMinutes' => 60,
			'bookableOnline' => true,
			'requiresDeposit' => true,
			'depositAmount' => 20.0,
			'currency' => 'EUR',
		];

		[$controller, $booking, , , $deposit] = $this->build(
			serviceStub: $service,
			bookingStub: [
				'@self' => ['id' => 'bk-dep'],
				'customerId' => 'ct-new',
			],
			requestParams: [
				'customerName' => 'Bram',
				'email' => 'bram@example.com',
				'phone' => '+31600000001',
				'serviceId' => 'svc-1',
				'startAt' => '2026-06-10T09:00:00+00:00',
			]
		);

		$booking->expects($this->once())
			->method('createBooking')
			->willReturn('bk-dep');

		// The controller MUST invoke the deposit orchestrator with the deposit
		// amount converted to integer cents (20.00 EUR -> 2000 cents).
		$deposit->expects($this->once())
			->method('createDepositSession')
			->with(
				'bk-dep',
				2000,
				'EUR',
				$this->stringContains('Massage'),
				$this->stringContains('bk-dep')
			)
			->willReturn(
				[
					'sessionUrl' => 'https://pay.example.test/checkout/abc123',
					'status' => 'pending',
					'providerReference' => 'tr_abc123',
				]
			);

		$response = $controller->book();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('bk-dep', $data['bookingId']);
		$this->assertSame('https://pay.example.test/checkout/abc123', $data['paymentRedirect']);
	}//end testBookDepositServiceOpensPaymentSession()

	/**
	 * POST /portal/book on a non-deposit Service returns a null paymentRedirect
	 * and never opens a payment session.
	 *
	 * @return void
	 */
	public function testBookNonDepositReturnsNullPaymentRedirect(): void {
		$service = [
			'@self' => ['id' => 'svc-1'],
			'name' => 'Knipbeurt',
			'durationMinutes' => 30,
			'bookableOnline' => true,
			'requiresDeposit' => false,
		];

		[$controller, $booking, , , $deposit] = $this->build(
			serviceStub: $service,
			bookingStub: [
				'@self' => ['id' => 'bk-1'],
				'customerId' => 'ct-new',
			],
			requestParams: [
				'customerName' => 'Annie',
				'email' => 'annie@example.com',
				'phone' => '+31600000000',
				'serviceId' => 'svc-1',
				'startAt' => '2026-06-10T09:00:00+00:00',
			]
		);

		$booking->expects($this->once())->method('createBooking')->willReturn('bk-1');
		$deposit->expects($this->never())->method('createDepositSession');

		$response = $controller->book();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertNull($response->getData()['paymentRedirect']);
	}//end testBookNonDepositReturnsNullPaymentRedirect()

	/**
	 * POST /portal/book returns 400 on a malformed email (REQ-APT-005 scenario 3).
	 *
	 * @return void
	 */
	public function testBookRejectsInvalidEmail(): void {
		[$controller, $booking] = $this->build(
			requestParams: [
				'customerName' => 'Annie',
				'email' => 'not-an-email',
				'phone' => '+31600000000',
				'serviceId' => 'svc-1',
				'startAt' => '2026-06-10T09:00:00+00:00',
			]
		);

		$booking->expects($this->never())->method('createBooking');

		$response = $controller->book();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('Invalid email', $data['error']);
		// Static message — never includes the submitted value.
		$this->assertStringNotContainsString('not-an-email', json_encode($data));
	}//end testBookRejectsInvalidEmail()

	/**
	 * POST /portal/book returns 400 when a required field is missing.
	 *
	 * @return void
	 */
	public function testBookRejectsMissingFields(): void {
		[$controller] = $this->build(
			requestParams: [
				'customerName' => 'Annie',
				'email' => 'annie@example.com',
			]
		);

		$response = $controller->book();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testBookRejectsMissingFields()

	/**
	 * GET /portal/booking/{id} returns 404 when missing.
	 *
	 * @return void
	 */
	public function testGetBookingReturns404WhenMissing(): void {
		[$controller] = $this->build();
		$response = $controller->getBooking('missing');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testGetBookingReturns404WhenMissing()

	/**
	 * GET /portal/booking/{id} returns the public projection of a booking.
	 *
	 * @return void
	 */
	public function testGetBookingReturnsPublicProjection(): void {
		[$controller] = $this->build(
			bookingStub: [
				'@self' => ['id' => 'bk-1'],
				'serviceId' => 'svc-1',
				'startAt' => '2026-06-10T09:00:00+00:00',
				'endAt' => '2026-06-10T09:30:00+00:00',
				'status' => 'confirmed',
				'internalNotes' => 'staff-only',
				'statusHistory' => [['status' => 'pending-deposit']],
			]
		);

		$response = $controller->getBooking('bk-1');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('bk-1', $data['id']);
		$this->assertSame('confirmed', $data['status']);
		// Public projection MUST strip internalNotes and statusHistory.
		$this->assertArrayNotHasKey('internalNotes', $data);
		$this->assertArrayNotHasKey('statusHistory', $data);
	}//end testGetBookingReturnsPublicProjection()

	/**
	 * POST /portal/reschedule with valid signed link delegates to BookingService.
	 *
	 * @return void
	 */
	public function testRescheduleWithValidLink(): void {
		[$controller, $booking] = $this->build(time: 1700000000);

		// Mint a signature with the controller's own helper to avoid replicating
		// the HMAC computation in the test.
		$token = $controller->signLink(bookingId: 'bk-1', action: 'reschedule', customerId: 'ct-1');

		// Re-build the controller with a request that carries that token.
		[$controller, $booking] = $this->build(
			time: 1700000000,
			requestParams: [
				'token' => $token,
				'newStartAt' => '2026-06-11T10:00:00+00:00',
			]
		);

		$booking->expects($this->once())
			->method('rescheduleBooking')
			->with('bk-1', '2026-06-11T10:00:00+00:00')
			->willReturn('bk-2');

		$response = $controller->reschedule();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('bk-2', $response->getData()['bookingId']);
	}//end testRescheduleWithValidLink()

	/**
	 * POST /portal/reschedule returns 410 on an invalid signature.
	 *
	 * @return void
	 */
	public function testRescheduleRejectsInvalidSignature(): void {
		[$controller, $booking] = $this->build(
			requestParams: [
				'token' => 'definitely.not-a-valid-token',
				'newStartAt' => '2026-06-11T10:00:00+00:00',
			]
		);

		$booking->expects($this->never())->method('rescheduleBooking');

		$response = $controller->reschedule();
		$this->assertSame(Http::STATUS_GONE, $response->getStatus());
	}//end testRescheduleRejectsInvalidSignature()

	/**
	 * POST /portal/reschedule returns 410 on an expired signature.
	 *
	 * @return void
	 */
	public function testRescheduleRejectsExpiredSignature(): void {
		// Issue the token at t = 1_000_000_000, then validate at t = 31 days later
		// (past the 30-day TTL). Both builds use the same mocked CSPRNG output,
		// so the lazily-minted signing key is identical and signatures align.
		$issuedAt = 1000000000;
		$expiredAt = ($issuedAt + (31 * 24 * 3600));

		[$controllerIssuer] = $this->build(time: $issuedAt);
		$expiredToken = $controllerIssuer->signLink(bookingId: 'bk-1', action: 'reschedule');

		[$controller, $booking] = $this->build(
			time: $expiredAt,
			requestParams: [
				'token' => $expiredToken,
				'newStartAt' => '2026-06-11T10:00:00+00:00',
			]
		);
		$booking->expects($this->never())->method('rescheduleBooking');

		$response = $controller->reschedule();
		$this->assertSame(Http::STATUS_GONE, $response->getStatus());
	}//end testRescheduleRejectsExpiredSignature()

	/**
	 * POST /portal/cancel with a valid signed link delegates to BookingService.
	 *
	 * @return void
	 */
	public function testCancelWithValidLink(): void {
		[$controllerIssuer] = $this->build(time: 1700000000);
		$token = $controllerIssuer->signLink(
			bookingId: 'bk-1',
			action: 'cancel',
			customerId: 'ct-1'
		);

		[$controller, $booking] = $this->build(
			time: 1700000000,
			requestParams: [
				'token' => $token,
				'reason' => 'Plans changed',
			]
		);

		$booking->expects($this->once())
			->method('cancelBooking')
			->with('bk-1', 'Plans changed', 'ct-1');

		$response = $controller->cancel();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['cancelled']);
	}//end testCancelWithValidLink()

	/**
	 * POST /portal/cancel returns 410 when the token action is wrong (e.g. a
	 * leaked reschedule token cannot be replayed as a cancel).
	 *
	 * @return void
	 */
	public function testCancelRejectsWrongActionToken(): void {
		[$controllerIssuer] = $this->build(time: 1700000000);
		$rescheduleToken = $controllerIssuer->signLink(bookingId: 'bk-1', action: 'reschedule');

		[$controller, $booking] = $this->build(
			time: 1700000000,
			requestParams: ['token' => $rescheduleToken]
		);
		$booking->expects($this->never())->method('cancelBooking');

		$response = $controller->cancel();
		$this->assertSame(Http::STATUS_GONE, $response->getStatus());
	}//end testCancelRejectsWrongActionToken()
}//end class
