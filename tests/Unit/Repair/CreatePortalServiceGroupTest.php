<?php

/**
 * Unit tests for CreatePortalServiceGroup.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\Pipelinq\Repair\CreatePortalServiceGroup;
use OCA\Pipelinq\Service\Portal\PortalServiceAccount;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The portal service group exists after the step, and the configured account is put in it.
 *
 * @covers \OCA\Pipelinq\Repair\CreatePortalServiceGroup
 * @covers \OCA\Pipelinq\Repair\CreateServiceGroup
 * @uses \OCA\Pipelinq\Service\Portal\PortalServiceAccount
 * @uses \OCA\Pipelinq\Service\ServiceAccount
 */
class CreatePortalServiceGroupTest extends TestCase {

	/**
	 * Build the step over a portal account.
	 *
	 * @param string          $configured The configured uid.
	 * @param IGroup|null     $group      The existing portal group.
	 * @param IUser|null      $user       The account the uid resolves to.
	 * @param IAppConfig|null $appConfig  The app config, to watch writes.
	 *
	 * @return CreatePortalServiceGroup The step.
	 */
	private function step(string $configured, ?IGroup $group, ?IUser $user = null, ?IAppConfig $appConfig = null): CreatePortalServiceGroup {
		if ($appConfig === null) {
			$appConfig = $this->createMock(IAppConfig::class);
		}

		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'portal_service_account' ? $configured : $default)
		);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(static fn (string $uid): ?IUser => ($user !== null && $uid === $configured) ? $user : null);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(static fn (string $gid): ?IGroup => $gid === 'pipelinq-portal-service' ? $group : null);
		$groups->method('createGroup')->willReturn($group);
		$groups->method('isInGroup')->willReturn(true);

		return new CreatePortalServiceGroup(
			new PortalServiceAccount(
				appConfig: $appConfig,
				userManager: $users,
				groupManager: $groups,
				userSession: $this->createMock(IUserSession::class),
				logger: $this->createMock(LoggerInterface::class),
			)
		);
	}//end step()

	/**
	 * The step names what it does.
	 *
	 * @return void
	 */
	public function testTheStepIsNamed(): void {
		self::assertSame(
			'Create the group the customer portal service account writes with',
			$this->step(configured: '', group: null)->getName()
		);
	}//end testTheStepIsNamed()

	/**
	 * Without a configured account the group exists and the portal warning is given.
	 *
	 * @return void
	 */
	public function testWithoutAnAccountThePortalWarningIsGiven(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning')->with(
			'The customer portal has no service account yet, so it refuses every write. Pick one in the Pipelinq admin settings.'
		);
		$output->expects(self::never())->method('info');

		$this->step(configured: '', group: $this->createMock(IGroup::class))->run(output: $output);
	}//end testWithoutAnAccountThePortalWarningIsGiven()

	/**
	 * The configured account is added to the portal group and reported.
	 *
	 * @return void
	 */
	public function testTheConfiguredAccountIsPutInThePortalGroup(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('portal-bot');
		$user->method('isEnabled')->willReturn(true);
		$group = $this->createMock(IGroup::class);
		$group->method('inGroup')->willReturn(false);
		$group->expects(self::once())->method('addUser')->with($user);
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with('The service account portal-bot is in group pipelinq-portal-service.');
		$output->expects(self::never())->method('warning');

		$this->step(configured: 'portal-bot', group: $group, user: $user)->run(output: $output);
	}//end testTheConfiguredAccountIsPutInThePortalGroup()
}//end class
