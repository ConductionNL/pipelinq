<?php

/**
 * Behavioural tests for the ExportAdminAction trait.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller\Support;

use OCA\Pipelinq\Controller\Support\ExportAdminAction;
use OCA\Pipelinq\Kernel\Contracts\ExportAccessPolicyInterface;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Refuses before running, runs for an export admin, logs failures under the host's short name.
 *
 * @covers \OCA\Pipelinq\Controller\Support\ExportAdminAction
 */
class ExportAdminActionTest extends TestCase {

	/**
	 * Build a host for the given user and export-admin flag.
	 *
	 * @param IUser|null      $user    The session user.
	 * @param bool            $isAdmin Whether the policy grants export admin.
	 * @param LoggerInterface $logger  The logger.
	 *
	 * @return ExportAdminActionHost The host.
	 */
	private function host(?IUser $user, bool $isAdmin, LoggerInterface $logger): ExportAdminActionHost {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$policy = $this->createMock(ExportAccessPolicyInterface::class);
		$policy->method('isExportAdmin')->willReturn($isAdmin);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new ExportAdminActionHost($session, $policy, $l10n, $logger);
	}//end host()

	/**
	 * @return void
	 */
	public function testGateThenAction(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('u1');
		$logger = $this->createMock(LoggerInterface::class);
		$ran = false;
		$action = function () use (&$ran): array {
			$ran = true;
			return ['ok' => true];
		};

		$this->assertSame(401, $this->host(null, true, $logger)->act($action)->getStatus());
		$this->assertSame(403, $this->host($user, false, $logger)->act($action)->getStatus());
		$this->assertFalse($ran);
		$this->assertSame(['ok' => true], $this->host($user, true, $logger)->act($action)->getData());
		$this->assertTrue($ran);
	}//end testGateThenAction()

	/**
	 * @return void
	 */
	public function testFailureIsLoggedUnderTheHostName(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('u1');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('ExportAdminActionHost::probe failed');

		$failed = $this->host($user, true, $logger)->act(
			function (): array {
				throw new RuntimeException('boom');
			}
		);
		$this->assertSame(500, $failed->getStatus());
	}//end testFailureIsLoggedUnderTheHostName()
}//end class

/**
 * A named host, so the action log label is a predictable short class name.
 */
final class ExportAdminActionHost {
	use ExportAdminAction;

	/**
	 * @param IUserSession                $userSession The session.
	 * @param ExportAccessPolicyInterface $policy      The policy.
	 * @param IL10N                       $l10n        The translator.
	 * @param LoggerInterface             $logger      The logger.
	 */
	public function __construct(
		private IUserSession $userSession,
		private ExportAccessPolicyInterface $policy,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param callable $action The action.
	 * @return JSONResponse
	 */
	public function act(callable $action): JSONResponse {
		return $this->requireExportAdmin(action: $action, label: 'probe');
	}
}//end class
