<?php

/**
 * Unit tests for RapportageController.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/lead-management/specs/lead-management/spec.md#REQ-LM-006
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\RapportageController;
use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\RapportageService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the RapportageController endpoint surface.
 */
class RapportageControllerTest extends TestCase {

	/**
	 * Mock request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * Mock service.
	 *
	 * @var RapportageService&MockObject
	 */
	private RapportageService $service;

	/**
	 * Mock user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * Set up the test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(RapportageService::class);
		$this->userSession = $this->createMock(IUserSession::class);

	}//end setUp()

	/**
	 * Authenticated request returns 200 with all four data sections.
	 *
	 * @return void
	 */
	public function testGetPipelineStatsReturnsAllSections(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->request->method('getParam')->willReturn('');

		$this->service->method('getStageValues')->willReturn([['stage' => 'Nieuw', 'count' => 1, 'totalValue' => 0.0, 'weightedValue' => 0.0]]);
		$this->service->method('getSourcePerformance')->willReturn([]);
		$this->service->method('getAgingBuckets')->willReturn([]);
		$this->service->method('getWinLossAnalysis')->willReturn([
			'wonCount' => 0,
			'lostCount' => 0,
			'winRate' => 0.0,
			'avgWonValue' => 0.0,
			'avgLostValue' => 0.0,
			'avgDaysToClose' => 0.0,
		]);

		$controller = new RapportageController($this->request, $this->service, new CrmPrivilegeCheck($this->userSession, $this->createConfiguredMock(ObjectOwnerAccessPolicy::class, ['isPrivileged' => true, 'mayAccess' => true])));
		$response = $controller->getPipelineStats();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertArrayHasKey('stageValues', $data);
		$this->assertArrayHasKey('sourcePerformance', $data);
		$this->assertArrayHasKey('agingBuckets', $data);
		$this->assertArrayHasKey('winLoss', $data);

	}//end testGetPipelineStatsReturnsAllSections()

	/**
	 * Unauthenticated request returns 401 with a static message.
	 *
	 * @return void
	 */
	public function testGetPipelineStatsReturnsUnauthorizedWithoutSession(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$controller = new RapportageController($this->request, $this->service, new CrmPrivilegeCheck($this->userSession, $this->createConfiguredMock(ObjectOwnerAccessPolicy::class, ['isPrivileged' => true, 'mayAccess' => true])));
		$response = $controller->getPipelineStats();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testGetPipelineStatsReturnsUnauthorizedWithoutSession()

	/**
	 * Aggregation failure returns 500 with a static error message and
	 * never leaks the underlying exception text.
	 *
	 * @return void
	 */
	public function testGetPipelineStatsReturnsServerErrorOnFailure(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParam')->willReturn('');

		$this->service->method('getStageValues')->willThrowException(new \RuntimeException('boom'));

		$controller = new RapportageController($this->request, $this->service, new CrmPrivilegeCheck($this->userSession, $this->createConfiguredMock(ObjectOwnerAccessPolicy::class, ['isPrivileged' => true, 'mayAccess' => true])));
		$response = $controller->getPipelineStats();
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('Operation failed', $data['message']);

	}//end testGetPipelineStatsReturnsServerErrorOnFailure()

	/**
	 * Golden: empty filters reach the service as null, given ones as is, in this call order.
	 *
	 * @return void
	 */
	public function testFiltersReachTheServiceExactly(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		foreach ([[[], [null, null, null]], [['pipelineId' => 'p-1', 'dateFrom' => '2026-01-01', 'dateTo' => '2026-06-30'], ['p-1', '2026-01-01', '2026-06-30']]] as [$params, [$pipeline, $from, $to]]) {
			$request = $this->createMock(IRequest::class);
			$request->method('getParam')->willReturnCallback(
				static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
			);
			$calls = [];
			$service = $this->createMock(RapportageService::class);
			$service->method('getStageValues')->willReturnCallback(static function (?string $pipelineId) use (&$calls): array {
				$calls[] = ['stageValues', $pipelineId];
				return ['s'];
			});
			$service->method('getSourcePerformance')->willReturnCallback(static function (?string $dateFrom, ?string $dateTo) use (&$calls): array {
				$calls[] = ['sourcePerformance', $dateFrom, $dateTo];
				return ['p'];
			});
			$service->method('getAgingBuckets')->willReturnCallback(static function () use (&$calls): array {
				$calls[] = ['agingBuckets'];
				return ['a'];
			});
			$service->method('getWinLossAnalysis')->willReturnCallback(static function (?string $dateFrom, ?string $dateTo) use (&$calls): array {
				$calls[] = ['winLoss', $dateFrom, $dateTo];
				return ['w'];
			});

			$controller = new RapportageController($request, $service, new CrmPrivilegeCheck($this->userSession, $this->createConfiguredMock(ObjectOwnerAccessPolicy::class, ['isPrivileged' => true, 'mayAccess' => true])));
			$response = $controller->getPipelineStats();

			$this->assertSame(['stageValues' => ['s'], 'sourcePerformance' => ['p'], 'agingBuckets' => ['a'], 'winLoss' => ['w']], $response->getData());
			$this->assertSame([['stageValues', $pipeline], ['sourcePerformance', $from, $to], ['agingBuckets'], ['winLoss', $from, $to]], $calls);
		}
	}//end testFiltersReachTheServiceExactly()
}//end class
