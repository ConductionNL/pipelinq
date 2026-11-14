<?php

/**
 * Unit tests for CrmPrivilegeCheck.
 *
 * Pins the refusal ladder (401 for no session user, 403 for a non-privileged
 * user, null for a privileged one) and the three body shapes the controllers
 * rely on: `error`, translated `error`, and `message`.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Lifecycle;

use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck
 * @uses   \OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy
 */
class CrmPrivilegeCheckTest extends TestCase {

	/**
	 * Build the check for a session user (null = no session) and a group map.
	 *
	 * @param string|null                     $uid        The session user's uid, or null.
	 * @param array<string,array<int,string>> $groups     Groups per user.
	 * @param string                          $crmGroup   The configured crm_group setting.
	 *
	 * @return CrmPrivilegeCheck The check.
	 */
	private function check(?string $uid, array $groups = [], string $crmGroup = ''): CrmPrivilegeCheck {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $user, string $group): bool => in_array($group, ($groups[$user] ?? []), true)
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (
				$key === ObjectOwnerAccessPolicy::CRM_GROUP_KEY ? $crmGroup : $default
			)
		);

		return new CrmPrivilegeCheck(
			userSession: $session,
			policy: new ObjectOwnerAccessPolicy(groupManager: $groupManager, appConfig: $appConfig),
		);
	}//end check()

	/**
	 * Without a session user the acting uid is empty and every refusal is a 401.
	 *
	 * @return void
	 */
	public function testNoSessionUserIsUnauthenticated(): void {
		$check = $this->check(uid: null);

		$this->assertSame('', $check->actingUserId());

		$response = $check->refusal();
		$this->assertNotNull($response);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Authentication required'], $response->getData());

		$message = $check->refusalAsMessage();
		$this->assertNotNull($message);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $message->getStatus());
		$this->assertSame(['message' => 'Authentication required'], $message->getData());
	}//end testNoSessionUserIsUnauthenticated()

	/**
	 * A session user with an empty uid is treated as unauthenticated too.
	 *
	 * @return void
	 */
	public function testAnEmptyUidIsUnauthenticated(): void {
		$check = $this->check(uid: '', groups: ['' => ['admin']]);

		$response = $check->refusal();
		$this->assertNotNull($response);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testAnEmptyUidIsUnauthenticated()

	/**
	 * A signed-in user outside every privileged group gets a 403.
	 *
	 * @return void
	 */
	public function testANonPrivilegedUserIsForbidden(): void {
		$check = $this->check(uid: 'carol', groups: ['carol' => ['users']]);

		$this->assertSame('carol', $check->actingUserId());

		$response = $check->refusal();
		$this->assertNotNull($response);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'Forbidden'], $response->getData());

		$message = $check->refusalAsMessage();
		$this->assertNotNull($message);
		$this->assertSame(Http::STATUS_FORBIDDEN, $message->getStatus());
		$this->assertSame(['message' => 'Forbidden'], $message->getData());
	}//end testANonPrivilegedUserIsForbidden()

	/**
	 * Built-in privileged groups and the configured CRM group are admitted.
	 *
	 * @return void
	 */
	public function testPrivilegedUsersAreAdmitted(): void {
		$this->assertNull($this->check(uid: 'ann', groups: ['ann' => ['admin']])->refusal());
		$this->assertNull($this->check(uid: 'sam', groups: ['sam' => ['sales']])->refusalAsMessage());
		$this->assertNull(
			$this->check(uid: 'kim', groups: ['kim' => ['klantcontact']], crmGroup: 'klantcontact')->refusal()
		);
	}//end testPrivilegedUsersAreAdmitted()

	/**
	 * The translated refusal routes both messages through IL10N.
	 *
	 * @return void
	 */
	public function testTranslatedRefusalUsesTheL10n(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'NL:' . $text);

		$unauthenticated = $this->check(uid: null)->translatedRefusal($l10n);
		$this->assertNotNull($unauthenticated);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $unauthenticated->getStatus());
		$this->assertSame(['error' => 'NL:Authentication required'], $unauthenticated->getData());

		$forbidden = $this->check(uid: 'carol')->translatedRefusal($l10n);
		$this->assertNotNull($forbidden);
		$this->assertSame(Http::STATUS_FORBIDDEN, $forbidden->getStatus());
		$this->assertSame(['error' => 'NL:Forbidden'], $forbidden->getData());

		$this->assertNull($this->check(uid: 'ann', groups: ['ann' => ['admin']])->translatedRefusal($l10n));
	}//end testTranslatedRefusalUsesTheL10n()

	/**
	 * Custom bodies are passed through verbatim for each refusal kind.
	 *
	 * @return void
	 */
	public function testRefusalWithPassesCustomBodies(): void {
		$unauthenticated = $this->check(uid: null)->refusalWith(
			unauthenticated: ['code' => 'no-session'],
			forbidden: ['code' => 'no-privilege']
		);
		$this->assertNotNull($unauthenticated);
		$this->assertSame(['code' => 'no-session'], $unauthenticated->getData());

		$forbidden = $this->check(uid: 'carol')->refusalWith(
			unauthenticated: ['code' => 'no-session'],
			forbidden: ['code' => 'no-privilege']
		);
		$this->assertNotNull($forbidden);
		$this->assertSame(Http::STATUS_FORBIDDEN, $forbidden->getStatus());
		$this->assertSame(['code' => 'no-privilege'], $forbidden->getData());
	}//end testRefusalWithPassesCustomBodies()
}//end class
