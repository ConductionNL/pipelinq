<?php

/**
 * Unit tests for ContactsSyncCommand.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/archive/2026-11-26-example-set-zuiddrecht/tasks.md#task-2.5
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Command;

use OCA\Pipelinq\Command\ContactsSyncCommand;
use OCA\Pipelinq\Kernel\Contracts\ClientContactCardSyncInterface;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for ContactsSyncCommand.
 */
class ContactsSyncCommandTest extends TestCase {

	/**
	 * The card sync.
	 *
	 * @var ClientContactCardSyncInterface&MockObject
	 */
	private ClientContactCardSyncInterface&MockObject $sync;

	/**
	 * The session the cards are written as.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * The account lookup.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $users;

	/**
	 * The group lookup.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groups;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->sync    = $this->createMock(originalClassName: ClientContactCardSyncInterface::class);
		$this->session = $this->createMock(originalClassName: IUserSession::class);
		$this->users   = $this->createMock(originalClassName: IUserManager::class);
		$this->groups  = $this->createMock(originalClassName: IGroupManager::class);
	}//end setUp()

	/**
	 * The command under test.
	 *
	 * @return CommandTester
	 */
	private function tester(): CommandTester {
		return new CommandTester(new ContactsSyncCommand(
			sync: $this->sync,
			userSession: $this->session,
			userManager: $this->users,
			groupManager: $this->groups,
		));
	}//end tester()

	/**
	 * Without --user and without a session the first admin writes the cards; the report is printed.
	 *
	 * @return void
	 */
	public function testRunsAsTheFirstAdminAndPrintsTheReport(): void {
		$admin = $this->createMock(originalClassName: IUser::class);
		$group = $this->createMock(originalClassName: IGroup::class);
		$group->method('getUsers')->willReturn([$admin]);
		$this->groups->method('get')->with('admin')->willReturn($group);
		$this->session->expects($this->once())->method('setUser')->with($admin);
		$this->sync->expects($this->once())->method('run')->with(false)->willReturn([
			'written' => [['id' => 'c-1', 'name' => 'Henk Bakker', 'uid' => 'uid-henk']],
			'skipped' => [['id' => 'c-3', 'name' => 'Zonder kaart']],
			'failed'  => [],
		]);

		$tester = $this->tester();
		$code   = $tester->execute([]);
		$output = $tester->getDisplay();

		$this->assertSame(expected: Command::SUCCESS, actual: $code);
		$this->assertStringContainsString(needle: 'Wrote the card uid-henk for "Henk Bakker"', haystack: $output);
		$this->assertStringContainsString(needle: 'Skipped "Zonder kaart" (c-3): it names no contactsUid', haystack: $output);
		$this->assertStringContainsString(needle: '1 written, 1 skipped, 0 failed.', haystack: $output);
	}//end testRunsAsTheFirstAdminAndPrintsTheReport()

	/**
	 * --user picks the account and --dry-run is passed on.
	 *
	 * @return void
	 */
	public function testUserAndDryRunArePassedOn(): void {
		$daan = $this->createMock(originalClassName: IUser::class);
		$this->users->method('get')->with('daan')->willReturn($daan);
		$this->session->expects($this->once())->method('setUser')->with($daan);
		$this->sync->expects($this->once())->method('run')->with(true)->willReturn([
			'written' => [['id' => 'c-1', 'name' => 'A', 'uid' => 'uid-a']],
			'skipped' => [],
			'failed'  => [],
		]);

		$tester = $this->tester();
		$tester->execute(['--user' => 'daan', '--dry-run' => true]);

		$this->assertStringContainsString(needle: 'Would write the card uid-a for "A"', haystack: $tester->getDisplay());
	}//end testUserAndDryRunArePassedOn()

	/**
	 * An unknown --user fails before anything is written.
	 *
	 * @return void
	 */
	public function testAnUnknownUserFails(): void {
		$this->users->method('get')->willReturn(null);
		$this->sync->expects($this->never())->method('run');

		$this->assertSame(expected: Command::FAILURE, actual: $this->tester()->execute(['--user' => 'nobody']));
	}//end testAnUnknownUserFails()

	/**
	 * A failed card, or a run that cannot start, fails the command.
	 *
	 * @return void
	 */
	public function testFailuresFailTheCommand(): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$this->session->method('getUser')->willReturn($user);
		$this->sync->method('run')->willReturnOnConsecutiveCalls(
			['written' => [], 'skipped' => [], 'failed' => [['id' => 'c-1', 'name' => 'A', 'uid' => 'uid-a']]],
			$this->throwException(exception: new \RuntimeException('Nextcloud Contacts is not available for the acting user.'))
		);

		$tester = $this->tester();
		$this->assertSame(expected: Command::FAILURE, actual: $tester->execute([]));
		$this->assertStringContainsString(needle: 'Could not write the card uid-a for "A"', haystack: $tester->getDisplay());

		$this->assertSame(expected: Command::FAILURE, actual: $tester->execute([]));
		$this->assertStringContainsString(needle: 'Nextcloud Contacts is not available', haystack: $tester->getDisplay());
	}//end testFailuresFailTheCommand()
}//end class
