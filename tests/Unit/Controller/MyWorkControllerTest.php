<?php

/**
 * Unit tests for the Pipelinq MyWorkController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-kpi-summary-tiles-on-my-work-req-mw-090
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Pipelinq\Controller\MyWorkController;
use OCA\Pipelinq\Service\WorklistService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * GET /api/my-work/week-stats: always the session user, the scope passed on,
 * and static error envelopes.
 *
 * @covers \OCA\Pipelinq\Controller\MyWorkController
 */
class MyWorkControllerTest extends TestCase {

	/**
	 * Mock request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * Mock service.
	 *
	 * @var WorklistService&MockObject
	 */
	private WorklistService $service;

	/**
	 * Mock user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request     = $this->createMock(IRequest::class);
		$this->service     = $this->createMock(WorklistService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger      = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The controller with a signed-in user, or none.
	 *
	 * @param string|null $uid   The user, or null for no session.
	 * @param string      $scope The `scope` parameter.
	 *
	 * @return MyWorkController
	 */
	private function controller(?string $uid, string $scope='mine'): MyWorkController {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $key === 'scope' ? $scope : $default
		);

		return new MyWorkController($this->request, $this->service, $this->userSession, $this->logger);
	}//end controller()

	/**
	 * The figures of the session user come back as they are.
	 *
	 * @return void
	 */
	public function testReturnsTheSessionUsersWeek(): void {
		$figures = ['answered' => 14, 'answeredToday' => 4, 'scope' => 'mine'];
		$this->service->expects($this->once())->method('getWeekStats')
			->with('pieter', 'mine')->willReturn($figures);

		$response = $this->controller(uid: 'pieter')->weekStats();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($figures, $response->getData());
	}//end testReturnsTheSessionUsersWeek()

	/**
	 * Without a session the service is not asked.
	 *
	 * @return void
	 */
	public function testRefusesWithoutAUser(): void {
		$this->service->expects($this->never())->method('getWeekStats');

		$response = $this->controller(uid: null)->weekStats();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Unauthorized'], $response->getData());
	}//end testRefusesWithoutAUser()

	/**
	 * An unknown scope is a 400.
	 *
	 * @return void
	 */
	public function testAnUnknownScopeIsABadRequest(): void {
		$this->service->expects($this->once())->method('getWeekStats')
			->with('pieter', 'team')->willThrowException(new InvalidArgumentException('Unknown scope'));

		$response = $this->controller(uid: 'pieter', scope: 'team')->weekStats();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'Invalid scope'], $response->getData());
	}//end testAnUnknownScopeIsABadRequest()

	/**
	 * A failure is logged and answered with a static message.
	 *
	 * @return void
	 */
	public function testAFailureIsAStaticServerError(): void {
		$this->service->method('getWeekStats')->willThrowException(new RuntimeException('secret detail'));
		$this->logger->expects($this->once())->method('warning')
			->with('[MyWorkController] weekStats failed', ['error' => 'secret detail']);

		$response = $this->controller(uid: 'pieter')->weekStats();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['message' => 'Week figures unavailable'], $response->getData());
	}//end testAFailureIsAStaticServerError()
}//end class
