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
	private function controller(bool $signedIn = true, bool $privileged = true, bool $admin = false, array $groups = [], string $configured = ''): BrpController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('handler');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);

		$policy = $this->createMock(ObjectOwnerAccessPolicy::class);
		$policy->method('isPrivileged')->with('handler')->willReturn($privileged);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->with('handler')->willReturn($admin);
		$groupManager->method('get')->willReturnCallback(
			function (string $groupId) use ($groups, $user): ?IGroup {
				if (array_key_exists($groupId, $groups) === false) {
					return null;
				}

				$group = $this->createMock(IGroup::class);
				$group->method('inGroup')->with($user)->willReturn($groups[$groupId]);
				return $group;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'brp.allowed_groups' ? $configured : $default)
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->haalCentraal = $this->createMock(HaalCentraalClientInterface::class);
		$this->haalCentraal->expects($this->never())->method('lookupPersoon');
		$this->audit = $this->createMock(BsnAuditServiceInterface::class);
		$this->audit->expects($this->never())->method($this->anything());

		return new BrpController(
			request: $this->createMock(IRequest::class),
			userSession: $session,
			accessPolicy: $policy,
			groupManager: $groupManager,
			l10n: $l10n,
			appConfig: $appConfig,
			validation: $this->createMock(BsnValidationService::class),
			cacheService: $this->createMock(BrpCacheServiceInterface::class),
			haalCentraal: $this->haalCentraal,
			audit: $this->audit,
			optOut: $this->createMock(OptOutServiceInterface::class),
			webhookListener: $this->createMock(BrpMutationWebhookListenerInterface::class),
			logger: $this->createMock(LoggerInterface::class),
			objectService: $this->createMock(ObjectServiceInterface::class),
		);
	}//end controller()

	/**
	 * A member of a configured group may look up.
	 *
	 * @return void
	 */
	public function testAMemberOfAnAllowedGroupMayLookUp(): void {
		$response = $this->controller(groups: ['klantcontact' => true], configured: 'burgerzaken, klantcontact')->access();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['allowed' => true], $response->getData());
	}//end testAMemberOfAnAllowedGroupMayLookUp()

	/**
	 * A user in none of the configured groups may not.
	 *
	 * @return void
	 */
	public function testAUserOutsideTheAllowedGroupsMayNot(): void {
		$response = $this->controller(groups: ['klantcontact' => false, 'behandelaar-burgerzaken' => true], configured: 'klantcontact')->access();

		$this->assertSame(['allowed' => false], $response->getData());
	}//end testAUserOutsideTheAllowedGroupsMayNot()

	/**
	 * Without a configured list, the default groups apply.
	 *
	 * @return void
	 */
	public function testWithoutAConfiguredListTheDefaultGroupsApply(): void {
		$this->assertSame(['allowed' => true], $this->controller(groups: ['behandelaar-avg' => true])->access()->getData());
		$this->assertSame(['allowed' => false], $this->controller(groups: ['klantcontact' => true])->access()->getData());
	}//end testWithoutAConfiguredListTheDefaultGroupsApply()

	/**
	 * An admin may look up without being in a group.
	 *
	 * @return void
	 */
	public function testAnAdminMayLookUp(): void {
		$this->assertSame(['allowed' => true], $this->controller(admin: true)->access()->getData());
	}//end testAnAdminMayLookUp()

	/**
	 * Without the CRM privilege nobody may, not even a group member or an admin.
	 *
	 * @return void
	 */
	public function testWithoutTheCrmPrivilegeNobodyMay(): void {
		$this->assertSame(['allowed' => false], $this->controller(privileged: false, admin: true, groups: ['behandelaar-avg' => true])->access()->getData());
	}//end testWithoutTheCrmPrivilegeNobodyMay()

	/**
	 * Without a session the answer is 401, not a yes or no.
	 *
	 * @return void
	 */
	public function testWithoutASessionTheAnswerIsUnauthorised(): void {
		$response = $this->controller(signedIn: false)->access();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertArrayNotHasKey('allowed', $response->getData());
	}//end testWithoutASessionTheAnswerIsUnauthorised()
}//end class
