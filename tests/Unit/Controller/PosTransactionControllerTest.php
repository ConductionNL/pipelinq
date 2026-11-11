<?php

/**
 * Unit tests for PosTransactionController.
 *
 * Every transaction action runs for the signed-in user and passes that user
 * to the service, which owns the money rules; the controller maps the
 * service's refusals to 404, 403 and 422 and hides unexpected errors.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\PosTransactionController;
use OCA\Pipelinq\Service\PosTransactionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for PosTransactionController.
 *
 * @covers \OCA\Pipelinq\Controller\PosTransactionController
 * @uses   \OCA\Pipelinq\Controller\PosResponses
 */
class PosTransactionControllerTest extends TestCase {

	/**
	 * Mock request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock transaction service.
	 *
	 * @var PosTransactionService&MockObject
	 */
	private PosTransactionService&MockObject $service;

	/**
	 * Mock session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Controller under test.
	 *
	 * @var PosTransactionController
	 */
	private PosTransactionController $controller;

	/**
	 * Wire the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(originalClassName: IRequest::class);
		$this->service = $this->createMock(originalClassName: PosTransactionService::class);
		$this->session = $this->createMock(originalClassName: IUserSession::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->controller = new PosTransactionController(
			request: $this->request,
			service: $this->service,
			userSession: $this->session,
			l10n: $l10n,
			logger: $this->logger
		);
	}//end setUp()

	/**
	 * Sign in as the given user.
	 *
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function signIn(string $uid): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * Without a session no transaction is touched.
	 *
	 * @return void
	 */
	public function testEveryActionRequiresASession(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->service->expects(self::never())->method(self::anything());

		foreach (['confirm', 'settle', 'refund', 'park', 'resume'] as $action) {
			self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller->$action('t1')->getStatus());
		}

		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller->taxReport()->getStatus());
	}//end testEveryActionRequiresASession()

	/**
	 * Each lifecycle action runs for the caller and returns the transaction.
	 *
	 * @return void
	 */
	public function testLifecycleActionsRunForTheCaller(): void {
		$this->signIn(uid: 'cashier');
		$this->service->expects(self::once())->method('confirmTransaction')->with('t1', 'cashier')->willReturn(['status' => 'confirmed']);
		$this->service->expects(self::once())->method('settleTransaction')->with('t1', 'cashier')->willReturn(['status' => 'settled']);
		$this->service->expects(self::once())->method('parkTransaction')->with('t1', 'cashier')->willReturn(['status' => 'parked']);
		$this->service->expects(self::once())->method('resumeTransaction')->with('t1', 'cashier')->willReturn(['status' => 'open']);

		self::assertSame(expected: ['transaction' => ['status' => 'confirmed']], actual: $this->controller->confirm(id: 't1')->getData());
		self::assertSame(expected: ['transaction' => ['status' => 'settled']], actual: $this->controller->settle(id: 't1')->getData());
		self::assertSame(expected: ['transaction' => ['status' => 'parked']], actual: $this->controller->park(id: 't1')->getData());
		self::assertSame(expected: ['transaction' => ['status' => 'open']], actual: $this->controller->resume(id: 't1')->getData());
	}//end testLifecycleActionsRunForTheCaller()

	/**
	 * A refund carries the reason from the request.
	 *
	 * @return void
	 */
	public function testRefundCarriesTheReason(): void {
		$this->signIn(uid: 'manager');
		$this->request->method('getParam')->with('reason', '')->willReturn('Damaged item');
		$this->service->expects(self::once())->method('refundTransaction')
			->with('t1', 'Damaged item', 'manager')
			->willReturn(['status' => 'refunded']);

		self::assertSame(
			expected: ['transaction' => ['status' => 'refunded']],
			actual: $this->controller->refund(id: 't1')->getData()
		);
	}//end testRefundCarriesTheReason()

	/**
	 * The tax report filters on a status only when one is given.
	 *
	 * @return void
	 */
	public function testTaxReportFiltersOnAGivenStatus(): void {
		$this->signIn(uid: 'manager');
		$this->request->method('getParam')->with('status', '')->willReturnOnConsecutiveCalls('settled', '');
		$this->service->expects(self::exactly(count: 2))->method('taxReport')
			->willReturnCallback(
				static fn (?string $status, string $userId): array => ['status' => $status, 'user' => $userId]
			);

		self::assertSame(
			expected: ['report' => ['status' => 'settled', 'user' => 'manager']],
			actual: $this->controller->taxReport()->getData()
		);
		self::assertSame(
			expected: ['report' => ['status' => null, 'user' => 'manager']],
			actual: $this->controller->taxReport()->getData()
		);
	}//end testTaxReportFiltersOnAGivenStatus()

	/**
	 * Refusals map to their status; an unexpected error is logged and hidden.
	 *
	 * @return void
	 */
	public function testServiceRefusalsMapToStatuses(): void {
		$this->signIn(uid: 'cashier');
		$this->service->method('confirmTransaction')->willThrowException(new OCSNotFoundException('Transaction not found'));
		$this->service->method('refundTransaction')->willThrowException(new OCSForbiddenException('Not your transaction'));
		$this->service->method('settleTransaction')->willThrowException(new OCSBadRequestException('Tenders do not cover the total'));
		$this->service->method('parkTransaction')->willThrowException(new RuntimeException('lock timeout'));
		$this->logger->expects(self::once())->method('error')
			->with('PosTransactionController::park failed', ['exception' => 'lock timeout']);

		self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $this->controller->confirm(id: 't1')->getStatus());
		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $this->controller->refund(id: 't1')->getStatus());

		$settle = $this->controller->settle(id: 't1');
		self::assertSame(expected: Http::STATUS_UNPROCESSABLE_ENTITY, actual: $settle->getStatus());
		self::assertSame(expected: ['error' => 'Tenders do not cover the total'], actual: $settle->getData());

		$park = $this->controller->park(id: 't1');
		self::assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $park->getStatus());
		self::assertSame(expected: ['error' => 'An unexpected error occurred'], actual: $park->getData());
	}//end testServiceRefusalsMapToStatuses()
}//end class
