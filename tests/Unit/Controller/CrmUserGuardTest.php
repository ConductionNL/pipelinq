<?php

/**
 * Unit tests for CrmUserGuard.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\CrmCallerGate;
use OCA\Pipelinq\Controller\CrmUserGuard;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * CrmUserGuard and CrmCallerGate admit signed-in and CRM-privileged callers.
 */
class CrmUserGuardTest extends TestCase {

	/**
	 * A session for a uid, or no session.
	 *
	 * @param string|null $uid The signed-in uid.
	 *
	 * @return IUserSession
	 */
	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
			return $session;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()

	/**
	 * A policy that grants privilege to the listed uids.
	 *
	 * @param list<string> $privileged The privileged uids.
	 *
	 * @return ObjectOwnerAccessPolicy
	 */
	private function policy(array $privileged): ObjectOwnerAccessPolicy {
		$policy = $this->createMock(ObjectOwnerAccessPolicy::class);
		$policy->method('isPrivileged')->willReturnCallback(static fn (string $uid): bool => in_array($uid, $privileged, true));

		return $policy;
	}//end policy()

	/**
	 * An action that echoes the uid it was given.
	 *
	 * @return callable
	 */
	private static function action(): callable {
		return static fn (string $uid): JSONResponse => new JSONResponse(['uid' => $uid]);
	}//end action()

	/**
	 * A privileged caller runs the action with their uid.
	 *
	 * @return void
	 */
	public function testAPrivilegedCallerRunsTheAction(): void {
		$response = (new CrmUserGuard(userSession: $this->session('anna'), policy: $this->policy(['anna'])))->respond(action: self::action());

		self::assertSame(['uid' => 'anna'], $response->getData());
		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testAPrivilegedCallerRunsTheAction()

	/**
	 * An unprivileged or anonymous caller is refused without running the action.
	 *
	 * @return void
	 */
	public function testOthersAreRefusedBeforeTheAction(): void {
		$ran = false;
		$action = static function (string $uid) use (&$ran): JSONResponse {
			$ran = true;
			return new JSONResponse([]);
		};

		foreach ([$this->session('bram'), $this->session(null)] as $session) {
			$response = (new CrmUserGuard(userSession: $session, policy: $this->policy(['anna'])))->respond(action: $action);
			self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
			self::assertSame(['error' => 'Forbidden'], $response->getData());
		}

		self::assertFalse($ran);
	}//end testOthersAreRefusedBeforeTheAction()

	/**
	 * respondToUser admits any signed-in user and refuses anonymous callers.
	 *
	 * @return void
	 */
	public function testRespondToUserNeedsOnlyASession(): void {
		self::assertSame(['uid' => 'bram'], (new CrmUserGuard(userSession: $this->session('bram'), policy: $this->policy([])))->respondToUser(action: self::action())->getData());
		self::assertSame(Http::STATUS_FORBIDDEN, (new CrmUserGuard(userSession: $this->session(null), policy: $this->policy([])))->respondToUser(action: self::action())->getStatus());
	}//end testRespondToUserNeedsOnlyASession()

	/**
	 * The caller gate answers the uid, a 401 without session and a 403 without privilege.
	 *
	 * @return void
	 */
	public function testTheCallerGate(): void {
		$privileged = new CrmCallerGate(userSession: $this->session('anna'), policy: $this->policy(['anna']));
		self::assertSame('anna', $privileged->userId());
		self::assertSame('anna', $privileged->privilegedUserId());

		$plain = (new CrmCallerGate(userSession: $this->session('bram'), policy: $this->policy(['anna'])))->privilegedUserId();
		self::assertInstanceOf(JSONResponse::class, $plain);
		self::assertSame(Http::STATUS_FORBIDDEN, $plain->getStatus());

		$anonymous = new CrmCallerGate(userSession: $this->session(null), policy: $this->policy(['anna']));
		self::assertNull($anonymous->userId());
		$refusal = $anonymous->privilegedUserId();
		self::assertSame(Http::STATUS_UNAUTHORIZED, $refusal->getStatus());
		self::assertSame(['error' => 'Authentication required'], $refusal->getData());
	}//end testTheCallerGate()
}//end class
