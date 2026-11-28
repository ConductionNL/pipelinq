<?php

/**
 * PosRefundController: confirming and rejecting a refund for the signed-in
 * cashier, and how service refusals map onto HTTP answers.
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

use OCA\Pipelinq\Controller\PosRefundController;
use OCA\Pipelinq\Kernel\Contracts\PosRefundServiceInterface;
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
 * @covers \OCA\Pipelinq\Controller\PosRefundController
 * @uses   \OCA\Pipelinq\Controller\PosResponses
 */
class PosRefundControllerTest extends TestCase {

	/**
	 * Refund service double.
	 *
	 * @var PosRefundServiceInterface&MockObject
	 */
	private PosRefundServiceInterface&MockObject $service;

	/**
	 * Logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = $this->createMock(PosRefundServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The controller for a session user, with the request parameters given.
	 *
	 * @param string|null           $uid    The session user, or null for none.
	 * @param array<string, string> $params The request parameters.
	 *
	 * @return PosRefundController
	 */
	private function controller(?string $uid = 'cashier', array $params = []): PosRefundController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new PosRefundController($request, $this->service, $session, $l10n, $this->logger);
	}//end controller()

	/**
	 * The cashier confirms the refund, and the answer carries it.
	 *
	 * @return void
	 */
	public function testConfirmConfirmsForTheSignedInCashier(): void {
		$this->service->expects($this->once())->method('confirmRefund')->with(id: 'rf-1', userId: 'cashier')
			->willReturn(['id' => 'rf-1', 'status' => 'confirmed']);

		$response = $this->controller()->confirm('rf-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['refund' => ['id' => 'rf-1', 'status' => 'confirmed']], $response->getData());
	}//end testConfirmConfirmsForTheSignedInCashier()

	/**
	 * A rejection passes the reason from the request; without one it is empty.
	 *
	 * @return void
	 */
	public function testRejectPassesTheReason(): void {
		$this->service->expects($this->exactly(2))->method('rejectRefund')
			->willReturnCallback(static fn (string $id, string $reason, string $userId): array => ['id' => $id, 'reason' => $reason, 'by' => $userId]);

		$this->assertSame(
			['refund' => ['id' => 'rf-2', 'reason' => 'Wrong amount', 'by' => 'cashier']],
			$this->controller(params: ['reason' => 'Wrong amount'])->reject('rf-2')->getData()
		);
		$this->assertSame(
			['refund' => ['id' => 'rf-2', 'reason' => '', 'by' => 'cashier']],
			$this->controller()->reject('rf-2')->getData()
		);
	}//end testRejectPassesTheReason()

	/**
	 * Without a session nothing is confirmed or rejected.
	 *
	 * @return void
	 */
	public function testWithoutASessionTheAnswerIs401(): void {
		$this->service->expects($this->never())->method('confirmRefund');
		$this->service->expects($this->never())->method('rejectRefund');

		foreach ([$this->controller(uid: null)->confirm('rf-1'), $this->controller(uid: null)->reject('rf-1')] as $response) {
			$this->assertSame(401, $response->getStatus());
			$this->assertSame(['error' => 'Authentication required'], $response->getData());
		}
	}//end testWithoutASessionTheAnswerIs401()

	/**
	 * Not found, forbidden and invalid answer 404, 403 and 422 with the service's message.
	 *
	 * @return void
	 */
	public function testServiceRefusalsMapOntoTheirStatus(): void {
		$cases = [
			[new OCSNotFoundException('No such refund'), 404],
			[new OCSForbiddenException('Not your till'), 403],
			[new OCSBadRequestException('Already confirmed'), 422],
		];
		foreach ($cases as [$refusal, $status]) {
			$service = $this->createMock(PosRefundServiceInterface::class);
			$service->method('confirmRefund')->willThrowException($refusal);
			$this->service = $service;

			$response = $this->controller()->confirm('rf-1');

			$this->assertSame($status, $response->getStatus());
			$this->assertSame(['error' => $refusal->getMessage()], $response->getData());
		}
	}//end testServiceRefusalsMapOntoTheirStatus()

	/**
	 * Anything else answers 500 without its message, and is logged under the action.
	 *
	 * @return void
	 */
	public function testAnUnexpectedFailureIs500AndLogged(): void {
		$this->service->method('rejectRefund')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('error')->with('PosRefundController::reject failed', ['exception' => 'db down']);

		$response = $this->controller()->reject('rf-1');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'An unexpected error occurred'], $response->getData());
	}//end testAnUnexpectedFailureIs500AndLogged()
}//end class
