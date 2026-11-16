<?php

/**
 * Behavioural tests for the PrivilegedAppointmentCaller trait.
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

use OCA\Pipelinq\Controller\Support\PrivilegedAppointmentCaller;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * No session is 401, an unprivileged caller 403, a privileged caller their uid.
 *
 * @covers \OCA\Pipelinq\Controller\Support\PrivilegedAppointmentCaller
 */
class PrivilegedAppointmentCallerTest extends TestCase {

	/**
	 * The uid answer for the given user and privilege.
	 *
	 * @param IUser|null $user       The session user.
	 * @param bool       $privileged Whether the policy grants the CRM capability.
	 *
	 * @return string|JSONResponse
	 */
	private function answer(?IUser $user, bool $privileged): string|JSONResponse {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$policy = $this->createMock(ObjectOwnerAccessPolicy::class);
		$policy->method('isPrivileged')->willReturn($privileged);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$host = new class($session, $policy, $l10n) {
			use PrivilegedAppointmentCaller;

			/**
			 * @param IUserSession            $userSession  The session.
			 * @param ObjectOwnerAccessPolicy $accessPolicy The policy.
			 * @param IL10N                   $l10n         The translator.
			 */
			public function __construct(
				private IUserSession $userSession,
				private ObjectOwnerAccessPolicy $accessPolicy,
				private IL10N $l10n,
			) {
			}

			/**
			 * @return string|JSONResponse
			 */
			public function uid(): string|JSONResponse {
				return $this->privilegedUid();
			}
		};

		return $host->uid();
	}//end answer()

	/**
	 * @return void
	 */
	public function testRefusalsAndUid(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('u1');

		$anonymous = $this->answer(null, true);
		$this->assertSame(401, $anonymous->getStatus());
		$this->assertSame(['error' => 'Authentication required'], $anonymous->getData());

		$member = $this->answer($user, false);
		$this->assertSame(403, $member->getStatus());
		$this->assertSame(['error' => 'Forbidden'], $member->getData());

		$this->assertSame('u1', $this->answer($user, true));
	}//end testRefusalsAndUid()
}//end class
