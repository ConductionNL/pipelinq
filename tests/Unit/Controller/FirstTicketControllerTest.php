<?php

/**
 * Tests for FirstTicketController.
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\FirstTicketController;
use OCA\Pipelinq\Service\FirstTicketService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Only the caller's own first ticket; found or not, said plainly.
 *
 * @covers \OCA\Pipelinq\Controller\FirstTicketController
 */
class FirstTicketControllerTest extends TestCase {
	/**
	 * The controller for a user (null: nobody signed in).
	 *
	 * @param string|null $uid The user.
	 * @param array<string, mixed>|null $row What the service answers.
	 *
	 * @return FirstTicketController
	 */
	private function controller(?string $uid, ?array $row): FirstTicketController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$service = $this->createMock(FirstTicketService::class);
		$service->method('firstFor')->willReturnCallback(fn (string $userId) => ($userId === 'pieter' ? $row : null));

		return new FirstTicketController($this->createMock(IRequest::class), $service, $session);
	}//end controller()

	/**
	 * The session user's first ticket, marked found.
	 *
	 * @return void
	 */
	public function testAnswersTheSessionUsersFirstTicket(): void {
		$data = $this->controller('pieter', ['id' => 'T1', 'title' => 'Afvalpas'])->show()->getData();

		$this->assertSame(['found' => true, 'id' => 'T1', 'title' => 'Afvalpas'], $data);
	}//end testAnswersTheSessionUsersFirstTicket()

	/**
	 * No ticket: found false. Nobody signed in: 401.
	 *
	 * @return void
	 */
	public function testNoTicketAndNoUser(): void {
		$this->assertSame(['found' => false], $this->controller('pieter', null)->show()->getData());
		$this->assertSame(401, $this->controller(null, null)->show()->getStatus());
	}//end testNoTicketAndNoUser()
}//end class
