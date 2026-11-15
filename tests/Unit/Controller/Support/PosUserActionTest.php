<?php

/**
 * Behavioural tests for the PosUserAction trait.
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

use OCA\Pipelinq\Controller\Support\PosControllerResponses;
use OCA\Pipelinq\Controller\Support\PosJsonAction;
use OCA\Pipelinq\Controller\Support\PosUserAction;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The action receives the session user's id; without a session it does not run.
 *
 * @covers \OCA\Pipelinq\Controller\Support\PosUserAction
 */
class PosUserActionTest extends TestCase {

	/**
	 * Build a host for the given session user.
	 *
	 * @param IUser|null $user The session user.
	 *
	 * @return PosUserActionHost The host.
	 */
	private function host(?IUser $user): PosUserActionHost {
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new PosUserActionHost($session, $l10n, $this->createStub(LoggerInterface::class));
	}//end host()

	/**
	 * @return void
	 */
	public function testRunAsUserPassesTheUidOrRefuses(): void {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('u1');
		$ran = false;
		$action = function (string $uid) use (&$ran): array {
			$ran = true;
			return ['uid' => $uid];
		};

		$this->assertSame(401, $this->host(null)->act($action)->getStatus());
		$this->assertFalse($ran);
		$this->assertSame(['uid' => 'u1'], $this->host($user)->act($action)->getData());
	}//end testRunAsUserPassesTheUidOrRefuses()
}//end class

/**
 * A host with the POS response plumbing and run().
 */
final class PosUserActionHost {
	use PosControllerResponses;
	use PosJsonAction;
	use PosUserAction;

	/**
	 * @param IUserSession    $userSession The session.
	 * @param IL10N           $l10n        The translator.
	 * @param LoggerInterface $logger      The logger.
	 */
	public function __construct(
		private IUserSession $userSession,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param callable $action The action.
	 * @return JSONResponse
	 */
	public function act(callable $action): JSONResponse {
		return $this->runAsUser(action: $action, label: 'act');
	}
}//end class
