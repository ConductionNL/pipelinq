<?php

/**
 * Unit tests for BrpController::access.
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/request-dialog-client-lookups/specs/request-management/spec.md#requirement-the-new-request-dialog-finds-the-client-or-looks-them-up
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Controller\BrpController;
use OCA\Pipelinq\Kernel\Contracts\BrpCacheServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\BrpMutationWebhookListenerInterface;
use OCA\Pipelinq\Kernel\Contracts\BsnAuditServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\HaalCentraalClientInterface;
use OCA\Pipelinq\Kernel\Contracts\OptOutServiceInterface;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\BsnValidationService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Whether the new request dialog may offer the BRP lookup: the same privilege
 * and group checks the lookup itself runs, and never a BRP call or audit.
 *
 * @covers \OCA\Pipelinq\Controller\BrpController
 */
final class BrpAccessTest extends TestCase {

	/**
	 * The BRP client; asserted never to be asked.
	 *
	 * @var HaalCentraalClientInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private HaalCentraalClientInterface $haalCentraal;

	/**
	 * The audit; asserted never to record.
	 *
	 * @var BsnAuditServiceInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private BsnAuditServiceInterface $audit;

	/**
	 * The controller for a session user.
	 *
	 * @param bool                 $signedIn   Whether a user is signed in.
	 * @param bool                 $privileged What the CRM privilege check answers.
	 * @param bool                 $admin      Whether the user is an admin.
	 * @param array<string, bool>  $groups     Group id to membership; absent groups do not exist.
	 * @param string               $configured The `brp.allowed_groups` value.
	 *
	 * @return BrpController
	 */
	private function controller(
		bool $signedIn = true,
		bool $privileged = true,
		bool $admin = false,
		array $groups = [],
		string $configured = ''
	): BrpController {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('handler');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$sessionUser = null;
		if ($signedIn === true) {
			$sessionUser = $user;
		}

		$session->method('getUser')->willReturn($sessionUser);

		$policy = $this->createMock(originalClassName: ObjectOwnerAccessPolicy::class);
		$policy->method('isPrivileged')->with('handler')->willReturn($privileged);

		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('isAdmin')->with('handler')->willReturn($admin);
		$groupManager->method('get')->willReturnCallback(
			function (string $groupId) use ($groups, $user): ?IGroup {
				if (array_key_exists($groupId, $groups) === false) {
					return null;
				}

				$group = $this->createMock(originalClassName: IGroup::class);
				$group->method('inGroup')->with($user)->willReturn($groups[$groupId]);
				return $group;
			}
		);

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'brp.allowed_groups' => $configured,
				default => $default,
			}
		);

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->haalCentraal = $this->createMock(originalClassName: HaalCentraalClientInterface::class);
		$this->haalCentraal->expects($this->never())->method('lookupPersoon');
		$this->audit = $this->createMock(originalClassName: BsnAuditServiceInterface::class);
		$this->audit->expects($this->never())->method($this->anything());

		return new BrpController(
			request: $this->createMock(originalClassName: IRequest::class),
			userSession: $session,
			accessPolicy: $policy,
			groupManager: $groupManager,
			l10n: $l10n,
			appConfig: $appConfig,
			validation: $this->createMock(originalClassName: BsnValidationService::class),
			cacheService: $this->createMock(originalClassName: BrpCacheServiceInterface::class),
			haalCentraal: $this->haalCentraal,
			audit: $this->audit,
			optOut: $this->createMock(originalClassName: OptOutServiceInterface::class),
			webhookListener: $this->createMock(originalClassName: BrpMutationWebhookListenerInterface::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			objectService: $this->createMock(originalClassName: ObjectServiceInterface::class),
		);
	}//end controller()

	/**
	 * A member of a configured group may look up.
	 *
	 * @return void
	 */
	public function testAMemberOfAnAllowedGroupMayLookUp(): void {
		$response = $this->controller(groups: ['klantcontact' => true], configured: 'burgerzaken, klantcontact')->access();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['allowed' => true], actual: $response->getData());
	}//end testAMemberOfAnAllowedGroupMayLookUp()

	/**
	 * A user in none of the configured groups may not.
	 *
	 * @return void
	 */
	public function testAUserOutsideTheAllowedGroupsMayNot(): void {
		$response = $this->controller(groups: ['klantcontact' => false, 'behandelaar-burgerzaken' => true], configured: 'klantcontact')->access();

		$this->assertSame(expected: ['allowed' => false], actual: $response->getData());
	}//end testAUserOutsideTheAllowedGroupsMayNot()

	/**
	 * Without a configured list, the default groups apply.
	 *
	 * @return void
	 */
	public function testWithoutAConfiguredListTheDefaultGroupsApply(): void {
		$this->assertSame(expected: ['allowed' => true], actual: $this->controller(groups: ['behandelaar-avg' => true])->access()->getData());
		$this->assertSame(expected: ['allowed' => false], actual: $this->controller(groups: ['klantcontact' => true])->access()->getData());
	}//end testWithoutAConfiguredListTheDefaultGroupsApply()

	/**
	 * An admin may look up without being in a group.
	 *
	 * @return void
	 */
	public function testAnAdminMayLookUp(): void {
		$this->assertSame(expected: ['allowed' => true], actual: $this->controller(admin: true)->access()->getData());
	}//end testAnAdminMayLookUp()

	/**
	 * Without the CRM privilege nobody may, not even a group member or an admin.
	 *
	 * @return void
	 */
	public function testWithoutTheCrmPrivilegeNobodyMay(): void {
		$this->assertSame(
			expected: ['allowed' => false],
			actual: $this->controller(privileged: false, admin: true, groups: ['behandelaar-avg' => true])->access()->getData()
		);
	}//end testWithoutTheCrmPrivilegeNobodyMay()

	/**
	 * Without a session the answer is 401, not a yes or no.
	 *
	 * @return void
	 */
	public function testWithoutASessionTheAnswerIsUnauthorised(): void {
		$response = $this->controller(signedIn: false)->access();

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
		$this->assertArrayNotHasKey(key: 'allowed', array: $response->getData());
	}//end testWithoutASessionTheAnswerIsUnauthorised()
}//end class
