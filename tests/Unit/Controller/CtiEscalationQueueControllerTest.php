<?php

/**
 * Tests for CtiEscalationQueueController.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\CtiEscalationQueueController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\CtiService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The wrap-up dialog's queue list.
 *
 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-the-agent-chooses-the-escalation-queue-at-wrap-up
 */
class CtiEscalationQueueControllerTest extends TestCase {
	/**
	 * The controller for a signed-in user, privileged or not.
	 *
	 * @param bool $privileged Whether the user may use the CTI surfaces.
	 *
	 * @return CtiEscalationQueueController The controller.
	 */
	private function controller(bool $privileged): CtiEscalationQueueController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('agent-1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$cti = $this->createMock(CtiService::class);
		$cti->method('escalationQueues')->willReturn([['id' => 'vergunningen', 'label' => 'Vergunningen, tweede lijn']]);

		return new CtiEscalationQueueController(
			request: $this->createMock(IRequest::class),
			ctiService: $cti,
			userSession: $session,
			policy: $this->createConfiguredMock(ObjectOwnerAccessPolicy::class, ['isPrivileged' => $privileged]),
		);
	}//end controller()

	/**
	 * An agent gets the queues by id and label.
	 *
	 * @return void
	 */
	public function testAnAgentGetsTheQueues(): void {
		$response = $this->controller(privileged: true)->index();

		self::assertSame(200, $response->getStatus());
		self::assertSame(['queues' => [['id' => 'vergunningen', 'label' => 'Vergunningen, tweede lijn']]], $response->getData());
	}//end testAnAgentGetsTheQueues()

	/**
	 * Someone who may not use the CTI surfaces gets 403.
	 *
	 * @return void
	 */
	public function testOthersAreRefused(): void {
		self::assertSame(403, $this->controller(privileged: false)->index()->getStatus());
	}//end testOthersAreRefused()
}//end class
