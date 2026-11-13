<?php

/**
 * Unit tests for CreateMessagingServiceGroup.
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

use OCA\Pipelinq\Repair\CreateMessagingServiceGroup;
use OCA\Pipelinq\Service\MessagingServiceAccount;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The messaging service group exists after the step, and the configured account is put in it.
 *
 * @covers \OCA\Pipelinq\Repair\CreateMessagingServiceGroup
 * @covers \OCA\Pipelinq\Repair\CreateServiceGroup
 * @uses \OCA\Pipelinq\Service\MessagingServiceAccount
 * @uses \OCA\Pipelinq\Service\ServiceAccount
 */
class CreateMessagingServiceGroupTest extends TestCase {

	/**
	 * Build the step over a messaging account.
	 *
	 * @param string           $configured The configured uid.
	 * @param IGroupManager    $groups     The group manager.
	 * @param IUser|null       $user       The account the uid resolves to.
	 * @param IAppConfig|null  $appConfig  The app config, to watch writes.
	 *
	 * @return CreateMessagingServiceGroup The step.
	 */
	private function step(string $configured, IGroupManager $groups, ?IUser $user = null, ?IAppConfig $appConfig = null): CreateMessagingServiceGroup {
		if ($appConfig === null) {
			$appConfig = $this->createMock(IAppConfig::class);
		}

		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'messaging_service_account' ? $configured : $default)
		);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(static fn (string $uid): ?IUser => ($user !== null && $uid === $configured) ? $user : null);

		return new CreateMessagingServiceGroup(
			new MessagingServiceAccount(
				appConfig: $appConfig,
				userManager: $users,
				groupManager: $groups,
				userSession: $this->createMock(IUserSession::class),
				logger: $this->createMock(LoggerInterface::class),
			)
		);
	}//end step()

	/**
	 * A group manager whose messaging group exists, or is created.
	 *
	 * @param IGroup|null $existing The existing group.
	 * @param IGroup|null $created  What creation answers; null throws.
	 *
	 * @return IGroupManager The group manager.
	 */
	private function groupManager(?IGroup $existing, ?IGroup $created = null): IGroupManager {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(
			static fn (string $gid): ?IGroup => $gid === 'pipelinq-messaging-service' ? $existing : null
		);
		$groups->method('createGroup')->willReturnCallback(static function (string $gid) use ($created): IGroup {
			if ($created === null) {
				throw new RuntimeException('backend read-only');
			}

			return $created;
		});
		$groups->method('isInGroup')->willReturn(true);

		return $groups;
	}//end groups()

	/**
	 * An enabled account.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser The account.
	 */
	private function enabled(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('isEnabled')->willReturn(true);
		return $user;
	}//end enabled()

	/**
	 * The step names what it does.
	 *
	 * @return void
	 */
	public function testTheStepIsNamed(): void {
		self::assertSame(
			'Create the group the SMS and WhatsApp webhooks write with',
			$this->step(configured: '', groups: $this->groupManager(existing: null))->getName()
		);
	}//end testTheStepIsNamed()

	/**
	 * A group that cannot be created is warned about, and nothing else happens.
	 *
	 * @return void
	 */
	public function testAGroupThatCannotBeCreatedIsWarned(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning')->with('Could not create group pipelinq-messaging-service.');
		$output->expects(self::never())->method('info');

		$this->step(configured: 'sms-bot', groups: $this->groupManager(existing: null, created: null))->run(output: $output);
	}//end testAGroupThatCannotBeCreatedIsWarned()

	/**
	 * Without a configured account the group is created and the messaging warning is given.
	 *
	 * @return void
	 */
	public function testWithoutAnAccountTheMessagingWarningIsGiven(): void {
		$group = $this->createMock(IGroup::class);
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning')->with(
			'The SMS and WhatsApp webhooks have no service account yet, so they save nothing. Pick one in the Pipelinq messaging settings.'
		);

		$this->step(configured: '', groups: $this->groupManager(existing: null, created: $group))->run(output: $output);
	}//end testWithoutAnAccountTheMessagingWarningIsGiven()

	/**
	 * The configured account is added to the group, stored, and reported.
	 *
	 * @return void
	 */
	public function testTheConfiguredAccountIsPutInTheGroup(): void {
		$user = $this->enabled('sms-bot');
		$group = $this->createMock(IGroup::class);
		$group->method('inGroup')->willReturn(false);
		$group->expects(self::once())->method('addUser')->with($user);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects(self::once())->method('setValueString')->with('pipelinq', 'messaging_service_account', 'sms-bot');
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with('The service account sms-bot is in group pipelinq-messaging-service.');
		$output->expects(self::never())->method('warning');

		$this->step(configured: 'sms-bot', groups: $this->groupManager(existing: $group), user: $user, appConfig: $appConfig)->run(output: $output);
	}//end testTheConfiguredAccountIsPutInTheGroup()

	/**
	 * An account that does not exist, or is disabled, is warned about.
	 *
	 * @return void
	 */
	public function testAnUnusableAccountIsWarned(): void {
		$group = $this->createMock(IGroup::class);

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning')->with('The service account ghost cannot be used: No account "ghost" exists.');
		$this->step(configured: 'ghost', groups: $this->groupManager(existing: $group))->run(output: $output);

		$disabled = $this->createMock(IUser::class);
		$disabled->method('isEnabled')->willReturn(false);
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning')->with('The service account sms-bot cannot be used: The account "sms-bot" is disabled.');
		$this->step(configured: 'sms-bot', groups: $this->groupManager(existing: $group), user: $disabled)->run(output: $output);
	}//end testAnUnusableAccountIsWarned()
}//end class
