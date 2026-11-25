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
 * @spec openspec/changes/example-set-zuiddrecht/tasks.md#task-2.5
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

	private ClientContactCardSyncInterface&MockObject $sync;

	private IUserSession&MockObject $session;

	private IUserManager&MockObject $users;

	private IGroupManager&MockObject $groups;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->sync    = $this->createMock(ClientContactCardSyncInterface::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->users   = $this->createMock(IUserManager::class);
		$this->groups  = $this->createMock(IGroupManager::class);
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
		$admin = $this->createMock(IUser::class);
		$group = $this->createMock(IGroup::class);
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

		$this->assertSame(Command::SUCCESS, $code);
		$this->assertStringContainsString('Wrote the card uid-henk for "Henk Bakker"', $output);
		$this->assertStringContainsString('Skipped "Zonder kaart" (c-3): it names no contactsUid', $output);
		$this->assertStringContainsString('1 written, 1 skipped, 0 failed.', $output);
	}//end testRunsAsTheFirstAdminAndPrintsTheReport()

	/**
	 * --user picks the account and --dry-run is passed on.
	 *
	 * @return void
	 */
	public function testUserAndDryRunArePassedOn(): void {
		$daan = $this->createMock(IUser::class);
		$this->users->method('get')->with('daan')->willReturn($daan);
		$this->session->expects($this->once())->method('setUser')->with($daan);
		$this->sync->expects($this->once())->method('run')->with(true)->willReturn([
			'written' => [['id' => 'c-1', 'name' => 'A', 'uid' => 'uid-a']],
			'skipped' => [],
			'failed'  => [],
		]);

		$tester = $this->tester();
		$tester->execute(['--user' => 'daan', '--dry-run' => true]);

		$this->assertStringContainsString('Would write the card uid-a for "A"', $tester->getDisplay());
	}//end testUserAndDryRunArePassedOn()

	/**
	 * An unknown --user fails before anything is written.
	 *
	 * @return void
	 */
	public function testAnUnknownUserFails(): void {
		$this->users->method('get')->willReturn(null);
		$this->sync->expects($this->never())->method('run');

		$this->assertSame(Command::FAILURE, $this->tester()->execute(['--user' => 'nobody']));
	}//end testAnUnknownUserFails()

	/**
	 * A failed card, or a run that cannot start, fails the command.
	 *
	 * @return void
	 */
	public function testFailuresFailTheCommand(): void {
		$user = $this->createMock(IUser::class);
		$this->session->method('getUser')->willReturn($user);
		$this->sync->method('run')->willReturnOnConsecutiveCalls(
			['written' => [], 'skipped' => [], 'failed' => [['id' => 'c-1', 'name' => 'A', 'uid' => 'uid-a']]],
			$this->throwException(new \RuntimeException('Nextcloud Contacts is not available for the acting user.'))
		);

		$tester = $this->tester();
		$this->assertSame(Command::FAILURE, $tester->execute([]));
		$this->assertStringContainsString('Could not write the card uid-a for "A"', $tester->getDisplay());

		$this->assertSame(Command::FAILURE, $tester->execute([]));
		$this->assertStringContainsString('Nextcloud Contacts is not available', $tester->getDisplay());
	}//end testFailuresFailTheCommand()
}//end class
