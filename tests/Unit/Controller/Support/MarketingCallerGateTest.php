<?php

/**
 * Behavioural tests for the MarketingCallerGate trait.
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

use OCA\Pipelinq\Controller\Support\MarketingCallerGate;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The gate answers 401/403 or the uid from the host's session and policy; the 404 is generic.
 *
 * @covers \OCA\Pipelinq\Controller\Support\MarketingCallerGate
 */
class MarketingCallerGateTest extends TestCase {

	/**
	 * Build a host for the given user and privilege.
	 *
	 * @param IUser|null $user       The session user.
	 * @param bool       $privileged Whether the policy grants the CRM capability.
	 *
	 * @return object The host.
	 */
	private function host(?IUser $user, bool $privileged): object {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$policy = $this->createMock(ObjectOwnerAccessPolicy::class);
		$policy->method('isPrivileged')->willReturn($privileged);

		return new class($session, $policy) {
			use MarketingCallerGate;

			/**
			 * @param IUserSession            $userSession The session.
			 * @param ObjectOwnerAccessPolicy $policy      The policy.
			 */
			public function __construct(
				private IUserSession $userSession,
				private ObjectOwnerAccessPolicy $policy,
			) {
			}

			/**
			 * @return string|JSONResponse
			 */
			public function caller(): string|JSONResponse {
				return $this->crmGate()->privilegedUserId();
			}

			/**
			 * @return JSONResponse
			 */
			public function missing(): JSONResponse {
				return $this->notFound();
			}
		};
	}//end host()

	/**
	 * @return void
	 */
	public function testGateAndNotFound(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('u1');

		$this->assertSame(401, $this->host(null, false)->caller()->getStatus());
		$this->assertSame(403, $this->host($user, false)->caller()->getStatus());
		$this->assertSame('u1', $this->host($user, true)->caller());

		$missing = $this->host(null, false)->missing();
		$this->assertSame(404, $missing->getStatus());
		$this->assertSame(['error' => 'Not found'], $missing->getData());
	}//end testGateAndNotFound()
}//end class
