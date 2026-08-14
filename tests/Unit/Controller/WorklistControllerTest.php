<?php

/**
 * Unit tests for the Pipelinq WorklistController.
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
 * @spec openspec/specs/dashboard/spec.md#requirement-my-work-widget
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\WorklistController;
use OCA\Pipelinq\Service\WorklistService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Asserts the /api/worklist/mine surface — happy path, limit handling,
 * missing auth, server failure — returns the documented HTTP status
 * codes and static error envelopes (no `getMessage()` leak).
 */
class WorklistControllerTest extends TestCase {
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
	 * Set up the test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(WorklistService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	/**
	 * Build a controller with the standard mocks.
	 *
	 * @return WorklistController
	 */
	private function buildController(): WorklistController {
		return new WorklistController(
			request: $this->request,
			worklistService: $this->service,
			userSession: $this->userSession,
			logger: $this->logger,
		);
	}

	/**
	 * Put an authenticated user with the given UID on the session.
	 *
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function authenticateAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	/**
	 * GET /api/worklist/mine returns 200 with the documented envelope and
	 * scopes the query to the session user (no limit -> full list).
	 *
	 * @return void
	 */
	public function testMineReturnsOkWithEnvelope(): void {
		$this->authenticateAs(uid: 'alice');
		$this->request->method('getParam')->willReturn('');

		$envelope = [
			'items' => [
				[
					'entityType' => 'lead',
					'id' => 'lead-1',
					'title' => 'Big deal',
					'stageOrStatus' => 'Proposal',
					'priority' => 'high',
					'dueDate' => '2026-07-01',
					'isOverdue' => true,
					'routeName' => 'LeadDetail',
				],
			],
			'total' => 1,
			'leadCount' => 1,
			'requestCount' => 0,
		];

		$this->service->expects($this->once())
			->method('getMine')
			->with('alice', null)
			->willReturn($envelope);

		$response = $this->buildController()->mine();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(1, $data['total']);
		$this->assertSame('lead-1', $data['items'][0]['id']);
	}

	/**
	 * `?limit=` is parsed and forwarded to the service (widget top-5).
	 *
	 * @return void
	 */
	public function testMineForwardsLimitToService(): void {
		$this->authenticateAs(uid: 'alice');
		$this->request->method('getParam')->willReturn('5');

		$this->service->expects($this->once())
			->method('getMine')
			->with('alice', 5)
			->willReturn(['items' => [], 'total' => 0, 'leadCount' => 0, 'requestCount' => 0]);

		$response = $this->buildController()->mine();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	/**
	 * Unauthenticated request returns 401 with a static message.
	 *
	 * @return void
	 */
	public function testMineReturnsUnauthorizedWithoutSession(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->buildController()->mine();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('Unauthorized', $response->getData()['message']);
	}

	/**
	 * A non-numeric limit returns 400 without touching the service.
	 *
	 * @return void
	 */
	public function testMineRejectsNonNumericLimit(): void {
		$this->authenticateAs(uid: 'alice');
		$this->request->method('getParam')->willReturn('abc');

		$this->service->expects($this->never())->method('getMine');

		$response = $this->buildController()->mine();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid limit', $response->getData()['message']);
	}

	/**
	 * A zero / negative limit returns 400 without touching the service.
	 *
	 * @return void
	 */
	public function testMineRejectsNonPositiveLimit(): void {
		$this->authenticateAs(uid: 'alice');
		$this->request->method('getParam')->willReturn('0');

		$this->service->expects($this->never())->method('getMine');

		$response = $this->buildController()->mine();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid limit', $response->getData()['message']);
	}

	/**
	 * Backend failure returns 500 with `Worklist unavailable`, never the
	 * underlying exception text.
	 *
	 * @return void
	 */
	public function testMineReturnsServerErrorOnFailure(): void {
		$this->authenticateAs(uid: 'alice');
		$this->request->method('getParam')->willReturn('');

		$this->service->method('getMine')->willThrowException(new \RuntimeException('boom'));

		$response = $this->buildController()->mine();
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$payload = $response->getData();
		$this->assertSame('Worklist unavailable', $payload['message']);
		$this->assertStringNotContainsString('boom', $payload['message']);
	}
}
