<?php

/**
 * PosPaymentController: what the provider configuration endpoints and the
 * payment methods answer when the payment service refuses or fails, and how a
 * refund reason that is not text is read.
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\PosPaymentController;
use OCA\Pipelinq\Kernel\Contracts\PosPaymentServiceInterface;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Pipelinq\Controller\PosPaymentController
 */
class PosPaymentControllerFailureTest extends TestCase {

	/**
	 * Payment service double.
	 *
	 * @var PosPaymentServiceInterface&MockObject
	 */
	private PosPaymentServiceInterface&MockObject $service;

	/**
	 * Logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Request double.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = $this->createMock(PosPaymentServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->request = $this->createMock(IRequest::class);
	}//end setUp()

	/**
	 * The controller for a signed-in cashier.
	 *
	 * @return PosPaymentController
	 */
	private function controller(): PosPaymentController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('cashier');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new PosPaymentController($this->request, $this->service, $session, $l10n, $this->logger);
	}//end controller()

	/**
	 * Each provider endpoint with a name, the service method behind it.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function providerEndpoints(): array {
		return [
			'show' => ['show', 'getProviderConfig'],
			'update' => ['update', 'updateProvider'],
			'test' => ['test', 'testConnection'],
		];
	}//end providerEndpoints()

	/**
	 * A refused provider request answers 422 with the service's reason, and is not logged.
	 *
	 * @param string $action  The controller method.
	 * @param string $service The service method.
	 *
	 * @return void
	 *
	 * @dataProvider providerEndpoints
	 */
	public function testARefusedProviderRequestIs422(string $action, string $service): void {
		$this->request->method('getParams')->willReturn(['apiKey' => 'x']);
		$this->service->method($service)->willThrowException(new OCSBadRequestException('Unknown provider: acme'));
		$this->logger->expects($this->never())->method('error');

		$response = $this->controller()->{$action}('acme');

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(['error' => 'Unknown provider: acme'], $response->getData());
	}//end testARefusedProviderRequestIs422()

	/**
	 * A failing provider request answers 500, logged with the exception class only.
	 *
	 * @param string $action  The controller method.
	 * @param string $service The service method.
	 *
	 * @return void
	 *
	 * @dataProvider providerEndpoints
	 */
	public function testAFailingProviderRequestIs500(string $action, string $service): void {
		$this->request->method('getParams')->willReturn([]);
		$this->service->method($service)->willThrowException(new RuntimeException('secret key sk_live_123 rejected'));
		$this->logger->expects($this->once())->method('error')->with('PosPaymentController::' . $action . ' failed', ['class' => RuntimeException::class]);

		$response = $this->controller()->{$action}('mollie');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'An unexpected error occurred'], $response->getData());
	}//end testAFailingProviderRequestIs500()

	/**
	 * Listing providers and payment methods answers 500 when the service fails.
	 *
	 * @return void
	 */
	public function testListingFailuresAre500(): void {
		$this->service->method('listProviders')->willThrowException(new RuntimeException('db down'));
		$this->service->method('listPaymentMethods')->willThrowException(new RuntimeException('db down'));
		$labels = [];
		$this->logger->expects($this->exactly(2))->method('error')->willReturnCallback(
			static function (string $message) use (&$labels): void {
				$labels[] = $message;
			}
		);

		$this->assertSame(500, $this->controller()->index()->getStatus());
		$this->assertSame(500, $this->controller()->methods()->getStatus());
		$this->assertSame(['PosPaymentController::index failed', 'PosPaymentController::methods failed'], $labels);
	}//end testListingFailuresAre500()

	/**
	 * An update hands the whole request body to the provider.
	 *
	 * @return void
	 */
	public function testUpdatePassesTheRequestBody(): void {
		$this->request->method('getParams')->willReturn(['apiKey' => 'test_abc', 'enabled' => true]);
		$this->service->expects($this->once())->method('updateProvider')->with(name: 'mollie', data: ['apiKey' => 'test_abc', 'enabled' => true])
			->willReturn(['name' => 'mollie', 'enabled' => true]);

		$this->assertSame(['provider' => ['name' => 'mollie', 'enabled' => true]], $this->controller()->update('mollie')->getData());
	}//end testUpdatePassesTheRequestBody()

	/**
	 * A refund reason that is not text is read as none; a text one is trimmed.
	 *
	 * @return void
	 */
	public function testARefundReasonIsReadAsTrimmedText(): void {
		$reasons = [];
		$this->service->method('refundPayment')->willReturnCallback(
			static function (string $transactionId, string $reason, string $userId) use (&$reasons): array {
				$reasons[] = [$transactionId, $reason, $userId];
				return ['status' => 'refunded'];
			}
		);
		$this->request->method('getParam')->with('reason', '')->willReturnOnConsecutiveCalls(['not', 'text'], '  damaged  ');

		$this->assertSame(['status' => 'refunded'], $this->controller()->refund('tx-1')->getData());
		$this->controller()->refund('tx-2');

		$this->assertSame([['tx-1', '', 'cashier'], ['tx-2', 'damaged', 'cashier']], $reasons);
	}//end testARefundReasonIsReadAsTrimmedText()
}//end class
