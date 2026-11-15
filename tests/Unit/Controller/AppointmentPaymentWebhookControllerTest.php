<?php

/**
 * Unit tests for AppointmentPaymentWebhookController — signature
 * verification + payload routing to AppointmentDepositService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
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

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\AppointmentPaymentWebhookController;
use OCA\Pipelinq\Service\AppointmentDepositService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for AppointmentPaymentWebhookController.
 */
class AppointmentPaymentWebhookControllerTest extends TestCase {

	/**
	 * In-memory app config store.
	 *
	 * @var array<string, string>
	 */
	private array $appConfigStore = [];

	/**
	 * Request mock.
	 *
	 * @var IRequest
	 */
	private IRequest $request;

	/**
	 * App-config mock.
	 *
	 * @var IAppConfig
	 */
	private IAppConfig $appConfig;

	/**
	 * Deposit-service mock.
	 *
	 * @var AppointmentDepositService
	 */
	private AppointmentDepositService $deposit;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(originalClassName: IRequest::class);
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->deposit = $this->createMock(originalClassName: AppointmentDepositService::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				return ($this->appConfigStore[$key] ?? $default);
			}
		);
	}//end setUp()

	/**
	 * Build a controller subclass that returns a fixed raw body so the
	 * test never depends on `php://input`.
	 *
	 * @param string $rawBody Raw body to inject.
	 *
	 * @return AppointmentPaymentWebhookController
	 */
	private function buildController(string $rawBody = ''): AppointmentPaymentWebhookController {
		return new class($this->request, $this->appConfig, $this->deposit, $this->logger, $rawBody) extends AppointmentPaymentWebhookController {
			/**
			 * Raw body to return from readRawBody().
			 *
			 * @var string
			 */
			private string $stubBody;

			/**
			 * Subclass constructor that captures the raw-body override.
			 *
			 * @param IRequest $request The request.
			 * @param IAppConfig $appConfig App config.
			 * @param AppointmentDepositService $deposit Deposit service.
			 * @param LoggerInterface $logger Logger.
			 * @param string $stubBody Raw body fixture.
			 */
			public function __construct(
				IRequest $request,
				IAppConfig $appConfig,
				AppointmentDepositService $deposit,
				LoggerInterface $logger,
				string $stubBody,
			) {
				parent::__construct(
					request: $request,
					appConfig: $appConfig,
					deposit: $deposit,
					logger: $logger
				);
				$this->stubBody = $stubBody;
			}//end __construct()

			/**
			 * Return the captured raw body fixture.
			 *
			 * @return string
			 */
			protected function readRawBody(): string {
				return $this->stubBody;
			}//end readRawBody()
		};
	}//end buildController()

	/**
	 * Missing signature → 422 + never delegates to the deposit service.
	 *
	 * @return void
	 */
	public function testCallbackReturns422WhenSignatureMissing(): void {
		$this->request->method('getHeader')->willReturn('');
		$this->deposit->expects($this->never())->method('handlePaymentCallback');

		$response = $this->buildController(rawBody: '{"bookingId":"b-1","status":"paid"}')->callback();
		$this->assertSame(expected: Http::STATUS_UNPROCESSABLE_ENTITY, actual: $response->getStatus());

	}//end testCallbackReturns422WhenSignatureMissing()

	/**
	 * Invalid signature → 422 + never delegates.
	 *
	 * @return void
	 */
	public function testCallbackReturns422OnInvalidSignature(): void {
		$this->appConfigStore['appointment_payment_webhook_secret'] = 'real-secret';
		$this->request->method('getHeader')->willReturn('totally-wrong-signature');
		$this->deposit->expects($this->never())->method('handlePaymentCallback');

		$response = $this->buildController(rawBody: '{"bookingId":"b-1","status":"paid"}')->callback();
		$this->assertSame(expected: Http::STATUS_UNPROCESSABLE_ENTITY, actual: $response->getStatus());

	}//end testCallbackReturns422OnInvalidSignature()

	/**
	 * Configured-secret empty → 422 (fail-closed). We never permit a
	 * webhook to be processed when the operator forgot to set the secret.
	 *
	 * @return void
	 */
	public function testCallbackReturns422WhenSecretUnconfigured(): void {
		$rawBody = '{"bookingId":"b-1","status":"paid"}';
		$sig = hash_hmac('sha256', $rawBody, 'whatever');
		$this->request->method('getHeader')->willReturn($sig);
		$this->deposit->expects($this->never())->method('handlePaymentCallback');

		$response = $this->buildController(rawBody: $rawBody)->callback();
		$this->assertSame(expected: Http::STATUS_UNPROCESSABLE_ENTITY, actual: $response->getStatus());

	}//end testCallbackReturns422WhenSecretUnconfigured()

	/**
	 * Valid signature + paid status → 200 with outcome=confirmed.
	 *
	 * @return void
	 */
	public function testCallbackAcceptsValidSignatureAndConfirms(): void {
		$secret = 'shared';
		$rawBody = '{"bookingId":"b-1","status":"paid","providerReference":"sess-1"}';
		$sig = hash_hmac('sha256', $rawBody, $secret);
		$this->appConfigStore['appointment_payment_webhook_secret'] = $secret;

		$this->request->method('getHeader')->willReturn($sig);

		$this->deposit->expects($this->once())
			->method('handlePaymentCallback')
			->with($this->equalTo(value: 'b-1'), $this->equalTo(value: 'paid'))
			->willReturn('confirmed');

		$response = $this->buildController(rawBody: $rawBody)->callback();
		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['ok' => true, 'outcome' => 'confirmed'], actual: $response->getData());

	}//end testCallbackAcceptsValidSignatureAndConfirms()

	/**
	 * Malformed JSON → 400.
	 *
	 * @return void
	 */
	public function testCallbackReturns400OnMalformedJson(): void {
		$secret = 'shared';
		$rawBody = 'not-json';
		$sig = hash_hmac('sha256', $rawBody, $secret);
		$this->appConfigStore['appointment_payment_webhook_secret'] = $secret;

		$this->request->method('getHeader')->willReturn($sig);

		$response = $this->buildController(rawBody: $rawBody)->callback();
		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());

	}//end testCallbackReturns400OnMalformedJson()

	/**
	 * Missing bookingId / status → 400.
	 *
	 * @return void
	 */
	public function testCallbackReturns400WhenFieldsMissing(): void {
		$secret = 'shared';
		$rawBody = '{"providerReference":"sess-1"}';
		$sig = hash_hmac('sha256', $rawBody, $secret);
		$this->appConfigStore['appointment_payment_webhook_secret'] = $secret;

		$this->request->method('getHeader')->willReturn($sig);

		$response = $this->buildController(rawBody: $rawBody)->callback();
		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());

	}//end testCallbackReturns400WhenFieldsMissing()

	/**
	 * A deposit-service throw is caught and returned as 200/deferred so
	 * openconnector does not retry indefinitely on a transient OR fault.
	 *
	 * @return void
	 */
	public function testCallbackReturns200WhenDepositHandlerThrows(): void {
		$secret = 'shared';
		$rawBody = '{"bookingId":"b-x","status":"paid"}';
		$sig = hash_hmac('sha256', $rawBody, $secret);
		$this->appConfigStore['appointment_payment_webhook_secret'] = $secret;

		$this->request->method('getHeader')->willReturn($sig);
		$this->deposit->method('handlePaymentCallback')->willThrowException(new RuntimeException('OR down'));

		$response = $this->buildController(rawBody: $rawBody)->callback();
		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$data = $response->getData();
		$this->assertSame(expected: 'deferred', actual: $data['outcome']);

	}//end testCallbackReturns200WhenDepositHandlerThrows()

	/**
	 * Golden: every answer's exact body and log line, and what reaches the deposit service.
	 *
	 * @return void
	 */
	public function testEveryAnswerIsExact(): void {
		$secret = 'shared';
		$this->appConfigStore['appointment_payment_webhook_secret'] = $secret;
		$cases = [
			'bad signature' => ['{"bookingId":"b-1","status":"paid"}', 'wrong', null, [422, ['error' => 'Invalid webhook signature']], [['warning', 'AppointmentPaymentWebhookController: invalid signature', ['ip' => '10.0.0.1']]], []],
			'empty body' => ['', null, null, [422, ['error' => 'Invalid webhook signature']], [['warning', 'AppointmentPaymentWebhookController: invalid signature', ['ip' => '10.0.0.1']]], []],
			'not an object' => ['"paid"', null, null, [400, ['error' => 'Invalid payload']], [], []],
			'blank booking' => ['{"bookingId":"  ","status":"paid"}', null, null, [400, ['error' => 'bookingId and status are required']], [], []],
			'trimmed fields' => ['{"bookingId":" b-2 ","status":" failed "}', null, 'released', [200, ['ok' => true, 'outcome' => 'released']], [], [['b-2', 'failed']]],
			'handler fails' => ['{"bookingId":"b-3","status":"paid"}', null, new \RuntimeException('db down'), [200, ['ok' => true, 'outcome' => 'deferred']], [['warning', 'AppointmentPaymentWebhookController: callback handler failed', ['booking' => 'b-3']]], [['b-3', 'paid']]],
		];
		foreach ($cases as $name => [$body, $signature, $outcome, $answer, $logs, $calls]) {
			$request = $this->createMock(IRequest::class);
			$request->method('getHeader')->willReturn($signature ?? hash_hmac('sha256', $body, $secret));
			$request->method('getRemoteAddress')->willReturn('10.0.0.1');
			$deposit = $this->createMock(AppointmentDepositService::class);
			$seen = [];
			$deposit->method('handlePaymentCallback')->willReturnCallback(
				static function (string $bookingId, string $status) use ($outcome, &$seen): string {
					$seen[] = [$bookingId, $status];
					if ($outcome instanceof \Throwable) {
						throw $outcome;
					}

					return (string)$outcome;
				}
			);
			$lines = [];
			$logger = $this->createMock(LoggerInterface::class);
			$logger->method('warning')->willReturnCallback(
				static function (string $message, array $context = []) use (&$lines): void {
					$lines[] = ['warning', $message, $context];
				}
			);
			$this->request = $request;
			$this->deposit = $deposit;
			$this->logger = $logger;

			$response = $this->buildController(rawBody: $body)->callback();

			$this->assertSame($answer, [$response->getStatus(), $response->getData()], $name);
			$this->assertSame($logs, $lines, $name.' logs');
			$this->assertSame($calls, $seen, $name.' calls');
		}
	}//end testEveryAnswerIsExact()
}//end class
