<?php

/**
 * EmailSyncController: when the email matching service fails, every endpoint
 * answers 500 with a general message and logs the failure under its action.
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

use OCA\Pipelinq\Controller\EmailSyncController;
use OCA\Pipelinq\Kernel\Contracts\EmailMatchServiceInterface;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Pipelinq\Controller\EmailSyncController
 */
class EmailSyncControllerFailureTest extends TestCase {

	/**
	 * Each endpoint, the service method it calls, and the label it logs under.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function endpoints(): array {
		return [
			'settings' => ['getSettings', 'getSettings', 'EmailSyncController::getSettings failed'],
			'trigger' => ['trigger', 'runForUser', 'EmailSyncController::trigger failed'],
			'status' => ['getStatus', 'getStatus', 'EmailSyncController::getStatus failed'],
		];
	}//end endpoints()

	/**
	 * A failing service answers 500 with a general message, for the signed-in user, logged.
	 *
	 * @param string $action  The controller method.
	 * @param string $service The service method it calls.
	 * @param string $label   The log message.
	 *
	 * @return void
	 *
	 * @dataProvider endpoints
	 */
	public function testAFailingServiceAnswers500AndIsLogged(string $action, string $service, string $label): void {
		$failure = new RuntimeException('imap unreachable');
		$matcher = $this->createMock(EmailMatchServiceInterface::class);
		$matcher->expects($this->once())->method($service)->with(userId: 'agent')->willThrowException($failure);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('agent');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with($label, ['exception' => $failure]);

		$controller = new EmailSyncController(request: $this->createMock(IRequest::class), emailMatchService: $matcher, userSession: $session, l10n: $l10n, logger: $logger);
		$response = $controller->{$action}();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'An unexpected error occurred'], $response->getData());
	}//end testAFailingServiceAnswers500AndIsLogged()
}//end class
