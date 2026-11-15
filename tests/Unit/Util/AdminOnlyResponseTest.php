<?php

/**
 * Behavioural tests for the AdminOnlyResponse trait.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Util
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

namespace OCA\Pipelinq\Tests\Unit\Util;

use OCA\Pipelinq\Util\AdminOnlyResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * No user is 401, a non-admin is 403, an admin passes.
 *
 * @covers \OCA\Pipelinq\Util\AdminOnlyResponse
 */
class AdminOnlyResponseTest extends TestCase {

	/**
	 * Build a host for the given user and admin flag.
	 *
	 * @param IUser|null $user    The session user.
	 * @param bool       $isAdmin Whether the user is an admin.
	 *
	 * @return object The host.
	 */
	private function host(?IUser $user, bool $isAdmin): object {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($isAdmin);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new class($session, $groups, $l10n) {
			use AdminOnlyResponse;

			/**
			 * @param IUserSession  $userSession  The session.
			 * @param IGroupManager $groupManager The groups.
			 * @param IL10N         $l10n         The translator.
			 */
			public function __construct(
				private IUserSession $userSession,
				private IGroupManager $groupManager,
				private IL10N $l10n,
			) {
			}

			/**
			 * @return JSONResponse|null
			 */
			public function gate(): ?JSONResponse {
				return $this->requireAdmin();
			}
		};
	}//end host()

	/**
	 * @return void
	 */
	public function testGate(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('u1');

		$anonymous = $this->host(null, false)->gate();
		$this->assertSame(401, $anonymous->getStatus());
		$this->assertSame(['error' => 'Authentication required'], $anonymous->getData());

		$member = $this->host($user, false)->gate();
		$this->assertSame(403, $member->getStatus());
		$this->assertSame(['error' => 'Admin privileges required'], $member->getData());

		$this->assertNull($this->host($user, true)->gate());
	}//end testGate()
}//end class
