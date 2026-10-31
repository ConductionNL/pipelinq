<?php

/**
 * Unit tests for TeamLoadController (GET /api/routing/team-load).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/skill-routing/tasks.md#task-1.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\TeamLoadController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\TeamLoadService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The team load is read by a privileged, signed-in user only.
 */
class TeamLoadControllerTest extends TestCase {

	/**
	 * Build the controller.
	 *
	 * @param string|null $uid        The signed-in user, or null.
	 * @param boolean     $privileged Whether the access policy calls them privileged.
	 * @param array       $rows       What the service answers.
	 *
	 * @return TeamLoadController The controller.
	 */
	private function controller(?string $uid, bool $privileged, array $rows=[]): TeamLoadController {
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$service = $this->createMock(TeamLoadService::class);
		$service->method('getTeamLoad')->willReturn($rows);
		$policy = $this->createMock(ObjectOwnerAccessPolicy::class);
		$policy->method('isPrivileged')->willReturn($privileged);

		return new TeamLoadController(
			request: $this->createMock(IRequest::class),
			teamLoad: $service,
			userSession: $session,
			accessPolicy: $policy,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * A privileged user gets the rows.
	 *
	 * @return void
	 */
	public function testListsTheRows(): void {
		$rows     = [['userId' => 'lotte', 'open' => 9]];
		$response = $this->controller(uid: 'pieter', privileged: true, rows: $rows)->index();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['results' => $rows], actual: $response->getData());
	}//end testListsTheRows()

	/**
	 * No session answers 401, an unprivileged user 403.
	 *
	 * @return void
	 */
	public function testRefusesWithoutSessionOrPrivilege(): void {
		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller(uid: null, privileged: true)->index()->getStatus());
		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $this->controller(uid: 'pieter', privileged: false)->index()->getStatus());
	}//end testRefusesWithoutSessionOrPrivilege()
}//end class
