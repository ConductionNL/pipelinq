<?php

/**
 * Unit tests for the Pipelinq MyWorkTeamController.
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-role-based-content-req-mw-180
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\MyWorkTeamController;
use OCA\Pipelinq\Kernel\Contracts\WorklistServiceInterface;
use OCA\Pipelinq\Service\Worklist\WorkScope;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * GET /api/my-work/team: the scopes to switch between and the team with its open work.
 */
class MyWorkTeamControllerTest extends TestCase {

	/**
	 * A controller for the given user and scope resolver.
	 *
	 * @param string|null              $uid       The signed-in user, or null for none.
	 * @param WorkScope                $workScope The scope resolver.
	 * @param WorklistServiceInterface $worklist  The worklist.
	 *
	 * @return MyWorkTeamController
	 */
	private function controller(?string $uid, WorkScope $workScope, WorklistServiceInterface $worklist): MyWorkTeamController {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new MyWorkTeamController(
			request: $this->createMock(originalClassName: IRequest::class),
			workScope: $workScope,
			worklistService: $worklist,
			userSession: $session,
		);
	}//end controller()

	/**
	 * A scope resolver with fixed answers.
	 *
	 * @param array<int, string>    $available The scopes the user has.
	 * @param array<string, string> $team      Display name per member.
	 *
	 * @return WorkScope
	 */
	private function scope(array $available, array $team = []): WorkScope {
		$scope = $this->createMock(originalClassName: WorkScope::class);
		$scope->method('available')->willReturn($available);
		$scope->method('team')->willReturn($team);
		return $scope;
	}//end scope()

	/**
	 * An agent with only their own work gets no scopes, so the switch stays hidden, and no team.
	 *
	 * @return void
	 */
	public function testAnAgentGetsNoSwitch(): void {
		$worklist = $this->createMock(originalClassName: WorklistServiceInterface::class);
		$worklist->expects($this->never())->method('items');

		$response = $this->controller(uid: 'alice', workScope: $this->scope(available: ['mine']), worklist: $worklist)->show();

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertSame(expected: ['scopes' => [], 'members' => []], actual: $response->getData());
	}//end testAnAgentGetsNoSwitch()

	/**
	 * A manager gets My items and Team, and each member with their open items.
	 *
	 * @return void
	 */
	public function testAManagerGetsTheTeamWithOpenCounts(): void {
		$worklist = $this->createMock(originalClassName: WorklistServiceInterface::class);
		$worklist->expects($this->once())->method('items')
			->with('manager', ['scope' => 'team'])
			->willReturn(
				[
					'items' => [
						['kind' => 'request', 'record' => ['assignee' => 'bob']],
						['kind' => 'lead', 'record' => ['assignee' => 'bob']],
						['kind' => 'task', 'record' => ['assigneeUserId' => 'manager']],
					],
					'total' => 3,
				]
			);

		$response = $this->controller(
			uid: 'manager',
			workScope: $this->scope(available: ['mine', 'team'], team: ['bob' => 'Bob de Vries', 'carol' => 'Carol', 'manager' => 'Maria']),
			worklist: $worklist
		)->show();

		$this->assertSame(
			expected: [
				'scopes' => [
					['value' => 'mine', 'label' => 'My items'],
					['value' => 'team', 'label' => 'Team'],
				],
				'members' => [
					['value' => 'bob', 'label' => 'Bob de Vries', 'count' => 2],
					['value' => 'carol', 'label' => 'Carol', 'count' => 0],
					['value' => 'manager', 'label' => 'Maria', 'count' => 1],
				],
			],
			actual: $response->getData()
		);
	}//end testAManagerGetsTheTeamWithOpenCounts()

	/**
	 * An administrator who manages no team gets the organisation scope and no members.
	 *
	 * @return void
	 */
	public function testAnAdministratorGetsTheOrganisation(): void {
		$worklist = $this->createMock(originalClassName: WorklistServiceInterface::class);
		$worklist->expects($this->never())->method('items');

		$response = $this->controller(uid: 'admin', workScope: $this->scope(available: ['mine', 'organisation']), worklist: $worklist)->show();

		$this->assertSame(
			expected: ['scopes' => [['value' => 'mine', 'label' => 'My items'], ['value' => 'organisation', 'label' => 'Organisation']], 'members' => []],
			actual: $response->getData()
		);
	}//end testAnAdministratorGetsTheOrganisation()

	/**
	 * Without a session nothing is read; an unreachable OpenRegister is a 500 without detail.
	 *
	 * @return void
	 */
	public function testNoSessionAndAnUnreachableRegister(): void {
		$worklist = $this->createMock(originalClassName: WorklistServiceInterface::class);
		$worklist->method('items')->willThrowException(new RuntimeException(message: 'db down'));
		$scope = $this->scope(available: ['mine', 'team'], team: ['bob' => 'Bob']);

		$this->assertSame(expected: 401, actual: $this->controller(uid: null, workScope: $scope, worklist: $worklist)->show()->getStatus());

		$failed = $this->controller(uid: 'manager', workScope: $scope, worklist: $worklist)->show();
		$this->assertSame(expected: 500, actual: $failed->getStatus());
		$this->assertSame(expected: ['message' => 'Team unavailable'], actual: $failed->getData());
	}//end testNoSessionAndAnUnreachableRegister()
}//end class
