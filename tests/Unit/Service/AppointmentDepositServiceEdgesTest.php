<?php

/**
 * Edge cases of AppointmentDepositService: the session request, the provider seam and the paid stamp.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\AppointmentDepositService;
use OCA\Pipelinq\Service\BookingService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * A deposit session is asked of the payment provider with the booking's amount; a paid callback stamps the booking.
 *
 * @covers \OCA\Pipelinq\Service\AppointmentDepositService
 * @uses \OCA\Pipelinq\Util\NullablePayloadToArray
 * @uses \OCA\Pipelinq\Util\ObjectToPlainArray
 * @uses \OCA\Pipelinq\Util\SerialisedPayload
 */
class AppointmentDepositServiceEdgesTest extends TestCase {

	/**
	 * App config values.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Container ids to what `get` answers; an id that is not here throws.
	 *
	 * @var array<string, mixed>
	 */
	private array $bound = [];

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The URL generator mock.
	 *
	 * @var IURLGenerator&MockObject
	 */
	private IURLGenerator $urls;

	/**
	 * Set up the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->urls = $this->createMock(IURLGenerator::class);

	}//end setUp()

	/**
	 * The service over the mocks.
	 *
	 * @param BookingService|null $bookings The booking service.
	 *
	 * @return AppointmentDepositService
	 */
	private function service(?BookingService $bookings = null): AppointmentDepositService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): mixed {
				if (array_key_exists($id, $this->bound) === false) {
					throw new RuntimeException('not bound: ' . $id);
				}

				return $this->bound[$id];
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);

		return new AppointmentDepositService(
			$container,
			$appConfig,
			$this->urls,
			($bookings ?? $this->createMock(BookingService::class)),
			$this->logger,
		);

	}//end service()

	/**
	 * A payment provider double that records the sessions asked of it.
	 *
	 * @param mixed $answer    What createSession answers.
	 * @param bool  $throws    Whether it throws instead.
	 *
	 * @return object
	 */
	private function provider(mixed $answer, bool $throws = false): object {
		return new class ($answer, $throws) {
			/** @var array<int, array<int, mixed>> */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param mixed $answer The answer.
			 * @param bool  $throws Whether to throw.
			 */
			public function __construct(private mixed $answer, private bool $throws) {
			}

			/**
			 * Create a payment session.
			 *
			 * @param string               $source  The source slug.
			 * @param array<string, mixed> $payload The payload.
			 *
			 * @return mixed
			 */
			public function createSession(string $source, array $payload): mixed {
				$this->calls[] = [$source, $payload];
				if ($this->throws === true) {
					throw new RuntimeException('provider down');
				}

				return $this->answer;
			}
		};

	}//end provider()

	/**
	 * The session payload carries the decimal amount, the upper-case currency, a capped description and the webhook.
	 *
	 * @return void
	 */
	public function testTheSessionPayloadIsBuiltFromTheBooking(): void {
		$this->config = [AppointmentDepositService::PAYMENT_SOURCE_KEY => 'mollie'];
		$answer = new stdClass();
		$answer->sessionUrl = 'https://pay.test/s/1';
		$answer->id = 'tr_1';
		$provider = $this->provider(answer: $answer);
		$this->urls->method('linkToOCSRouteAbsolute')->with('pipelinq.appointmentPaymentWebhook.callback')->willReturn('https://crm.test/hook');
		$service = $this->service();
		$service->setPaymentService(service: $provider);

		$result = $service->createDepositSession(bookingId: 'b-1', amountCents: 1905, currency: 'eur', description: str_repeat('x', 300), returnUrl: 'https://crm.test/back');

		$this->assertSame(['sessionUrl' => 'https://pay.test/s/1', 'status' => 'pending', 'providerReference' => 'tr_1'], $result);
		[$source, $payload] = $provider->calls[0];
		$this->assertSame('mollie', $source);
		$this->assertSame(['value' => '19.05', 'currency' => 'EUR'], $payload['amount']);
		$this->assertSame(255, strlen($payload['description']));
		$this->assertSame('https://crm.test/back', $payload['returnUrl']);
		$this->assertSame('https://crm.test/hook', $payload['webhookUrl']);
		$this->assertSame(['app' => 'pipelinq', 'kind' => 'appointment-deposit', 'bookingId' => 'b-1'], $payload['metadata']);

	}//end testTheSessionPayloadIsBuiltFromTheBooking()

	/**
	 * Without an absolute URL the webhook is the relative route; a short description and missing answer fields stay.
	 *
	 * @return void
	 */
	public function testTheWebhookFallsBackToTheRelativeRoute(): void {
		$this->config = [AppointmentDepositService::PAYMENT_SOURCE_KEY => 'mollie'];
		$provider = $this->provider(answer: ['other' => 'x']);
		$this->urls->method('linkToOCSRouteAbsolute')->willThrowException(new RuntimeException('no request'));
		$service = $this->service();
		$service->setPaymentService(service: $provider);

		$result = $service->createDepositSession(bookingId: 'b-1', amountCents: 5, description: 'Deposit');

		$this->assertSame(['sessionUrl' => '', 'status' => 'pending', 'providerReference' => ''], $result);
		$payload = $provider->calls[0][1];
		$this->assertSame('/index.php/apps/pipelinq/api/appointment-payment-webhook', $payload['webhookUrl']);
		$this->assertSame(['value' => '0.05', 'currency' => 'EUR'], $payload['amount']);
		$this->assertSame('Deposit', $payload['description']);

	}//end testTheWebhookFallsBackToTheRelativeRoute()

	/**
	 * A provider that throws leaves the session unavailable, logged with the booking and amount.
	 *
	 * @return void
	 */
	public function testAFailingProviderLeavesTheSessionUnavailable(): void {
		$this->config = [AppointmentDepositService::PAYMENT_SOURCE_KEY => 'mollie'];
		$this->logger->expects($this->once())->method('warning')->with('Pipelinq: openconnector createSession failed', ['booking' => 'b-1', 'amount' => 1000]);
		$service = $this->service();
		$service->setPaymentService(service: $this->provider(answer: null, throws: true));

		$this->assertSame(['sessionUrl' => '', 'status' => 'unavailable', 'providerReference' => ''], $service->createDepositSession(bookingId: 'b-1', amountCents: 1000));

	}//end testAFailingProviderLeavesTheSessionUnavailable()

	/**
	 * The provider comes from the container when bound, and an injected one wins.
	 *
	 * @return void
	 */
	public function testThePaymentServiceIsResolvedFromTheContainer(): void {
		$this->assertNull($this->service()->resolvePaymentService());

		$bound = new stdClass();
		$this->bound['OCA\\OpenConnector\\Service\\PaymentService'] = $bound;
		$this->assertSame($bound, $this->service()->resolvePaymentService());

		$this->bound['OCA\\OpenConnector\\Service\\PaymentService'] = 'not an object';
		$this->assertNull($this->service()->resolvePaymentService());

		$injected = new stdClass();
		$service = $this->service();
		$service->setPaymentService(service: $injected);
		$this->assertSame($injected, $service->resolvePaymentService());

	}//end testThePaymentServiceIsResolvedFromTheContainer()

	/**
	 * A paid callback stamps depositPaidAt on the booking and saves it without its metadata.
	 *
	 * @return void
	 */
	public function testAPaidCallbackStampsTheBooking(): void {
		$this->config = ['register' => 'crm', BookingService::BOOKING_SCHEMA_KEY => 'booking'];
		$objects = new class {
			/** @var array<int, array<string, mixed>> */
			public array $saves = [];

			/**
			 * Find a booking.
			 *
			 * @param string $id       The id.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function find(string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$register = $options?->register;
				$schema = $options?->schema;
				return ['@self' => ['id' => $id], 'status' => 'confirmed', 'register' => $register, 'schema' => $schema];
			}

			/**
			 * Save a booking.
			 *
			 * @param array<string, mixed> $object   The object.
			 * @param array<int, string>   $extend   The extend list.
			 * @param mixed                $register The register.
			 * @param mixed                $schema   The schema.
			 * @param string|null          $uuid     The uuid.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): array {
				$extend = $options->extend ?? [];
				$register = $options->target->register;
				$schema = $options->target->schema;
				$uuid = $options->uuid;
				$this->saves[] = ['object' => $object, 'extend' => $extend, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				return $object;
			}
		};
		$this->bound['OCA\\OpenRegister\\Service\\ObjectService'] = $objects;
		$bookings = $this->createMock(BookingService::class);
		$bookings->expects($this->once())->method('confirmBooking')->with('b-1', 'Deposit payment confirmed');

		$this->assertSame('confirmed', $this->service(bookings: $bookings)->handlePaymentCallback(bookingId: 'b-1', status: ' SUCCEEDED '));

		$this->assertCount(1, $objects->saves);
		$save = $objects->saves[0];
		$this->assertSame(['crm', 'booking', 'b-1', []], [$save['register'], $save['schema'], $save['uuid'], $save['extend']]);
		$this->assertArrayNotHasKey('@self', $save['object']);
		$this->assertSame('crm', $save['object']['register']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $save['object']['depositPaidAt']);

	}//end testAPaidCallbackStampsTheBooking()

	/**
	 * A failing read or save of the stamp is logged, and the booking stays confirmed.
	 *
	 * @return void
	 */
	public function testAFailingStampIsLogged(): void {
		$this->config = ['register' => 'crm', BookingService::BOOKING_SCHEMA_KEY => 'booking'];
		$objects = new class {
			/** @var bool */
			public bool $failFind = true;

			/** @var int */
			public int $saveCalls = 0;

			/**
			 * Find a booking, or throw.
			 *
			 * @param string $id       The id.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function find(string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$register = $options?->register;
				$schema = $options?->schema;
				if ($this->failFind === true) {
					throw new RuntimeException('gone');
				}

				return ['status' => 'confirmed'];
			}

			/**
			 * Save a booking, always failing.
			 *
			 * @param array<string, mixed> $object   The object.
			 * @param array<int, string>   $extend   The extend list.
			 * @param mixed                $register The register.
			 * @param mixed                $schema   The schema.
			 * @param string|null          $uuid     The uuid.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): array {
				$extend = $options->extend ?? [];
				$register = $options->target->register;
				$schema = $options->target->schema;
				$uuid = $options->uuid;
				$this->saveCalls++;
				throw new RuntimeException('refused');
			}
		};
		$this->bound['OCA\\OpenRegister\\Service\\ObjectService'] = $objects;
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message, array $context) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);

		$this->assertSame('confirmed', $this->service()->handlePaymentCallback(bookingId: 'b-1', status: 'paid'));
		$objects->failFind = false;
		$this->assertSame('confirmed', $this->service()->handlePaymentCallback(bookingId: 'b-2', status: 'success'));

		$this->assertSame(1, $objects->saveCalls);
		$this->assertSame(
			[
				['Pipelinq: depositPaidAt load failed', ['booking' => 'b-1']],
				['Pipelinq: depositPaidAt save failed', ['booking' => 'b-2']],
			],
			$warnings
		);

	}//end testAFailingStampIsLogged()

	/**
	 * Without the register config, OpenRegister or a readable booking, nothing is stamped.
	 *
	 * @return void
	 */
	public function testNothingIsStampedWithoutAStore(): void {
		$objects = new class {
			/** @var int */
			public int $reads = 0;

			/**
			 * Find nothing.
			 *
			 * @param string $id       The id.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return null
			 */
			public function find(string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): mixed {
				$register = $options?->register;
				$schema = $options?->schema;
				$this->reads++;
				return null;
			}
		};
		$this->bound['OCA\\OpenRegister\\Service\\ObjectService'] = $objects;

		$this->assertSame('confirmed', $this->service()->handlePaymentCallback(bookingId: 'b-1', status: 'paid'));
		$this->assertSame(0, $objects->reads);

		$this->config = ['register' => 'crm', BookingService::BOOKING_SCHEMA_KEY => 'booking'];
		$this->assertSame('confirmed', $this->service()->handlePaymentCallback(bookingId: 'b-1', status: 'paid'));
		$this->assertSame(1, $objects->reads);

		unset($this->bound['OCA\\OpenRegister\\Service\\ObjectService']);
		$this->assertSame('confirmed', $this->service()->handlePaymentCallback(bookingId: 'b-1', status: 'paid'));
		$this->assertSame(1, $objects->reads);

	}//end testNothingIsStampedWithoutAStore()
}//end class
