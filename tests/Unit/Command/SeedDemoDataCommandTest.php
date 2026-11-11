<?php

/**
 * Unit tests for SeedDemoDataCommand.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Command
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Command;

use OCA\Pipelinq\Command\SeedDemoDataCommand;
use OCA\Pipelinq\Service\Demo\DemoRegisterImporter;
use OCA\Pipelinq\Service\DemoSeedService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Pins the impersonation, the exit code and every line of the report.
 */
final class SeedDemoDataCommandTest extends TestCase {
	/**
	 * Run the command.
	 *
	 * @param array<string, mixed> $options     Command options.
	 * @param bool                 $signedIn    Whether a session user exists.
	 * @param array<int, string>   $admins      The admin group's user ids, or null for no admin group.
	 * @param mixed                $seedResult  What the seed service answers, or a Throwable to throw.
	 *
	 * @return array<int, mixed> [exit code, output lines, impersonated uid].
	 */
	private function runCommand(array $options, bool $signedIn, ?array $admins, mixed $seedResult): array {
		$seed = $this->createMock(DemoSeedService::class);
		foreach (['seed', 'remove'] as $method) {
			$seed->method($method)->willReturnCallback(
				static function () use ($seedResult): array {
					if ($seedResult instanceof \Throwable) {
						throw $seedResult;
					}

					return $seedResult;
				}
			);
		}

		$importer = $this->createMock(DemoRegisterImporter::class);
		$importer->method('import')->willReturn(['imported' => 4, 'skipped' => 1]);
		$importer->method('remove')->willReturn(['removed' => 3, 'retained' => 2]);
		$session = $this->createMock(IUserSession::class);
		$impersonated = null;
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);
		$session->method('setUser')->willReturnCallback(
			static function (?IUser $user) use (&$impersonated): void {
				$impersonated = $user?->getUID();
			}
		);
		$groups = $this->createMock(IGroupManager::class);
		$group = null;
		if ($admins !== null) {
			$group = $this->createMock(IGroup::class);
			$group->method('getUsers')->willReturn(
				array_map(
					function (string $uid): IUser {
						$user = $this->createMock(IUser::class);
						$user->method('getUID')->willReturn($uid);
						return $user;
					},
					$admins
				)
			);
		}

		$groups->method('get')->willReturn($group);
		$command = new SeedDemoDataCommand($seed, $session, $groups, $importer);
		$output = new BufferedOutput();
		$code = $command->run(new ArrayInput($options), $output);
		return [$code, explode("\n", trim($output->fetch())), $impersonated];
	}//end runCommand()

	/**
	 * A seed under an impersonated admin reports every count, the example records included.
	 *
	 * @return void
	 */
	public function testSeedsUnderAnAdminAndReportsTheCounts(): void {
		$this->assertSame(
			[
				Command::SUCCESS,
				[
					'Running as admin user "root".',
					'pipelinq:demo:seed done',
					'  created  leads            = 5',
					'  created  example-records  = 4',
					'  skipped  leads            = 2',
					'  skipped  example-records  = 1',
				],
				'root',
			],
			$this->runCommand([], false, ['root', 'other'], ['success' => true, 'created' => ['leads' => 5], 'skipped' => ['leads' => 2]])
		);
	}//end testSeedsUnderAnAdminAndReportsTheCounts()

	/**
	 * A removal by a signed-in user reports the removed and retained counts.
	 *
	 * @return void
	 */
	public function testRemovesAndReportsTheCounts(): void {
		$this->assertSame(
			[
				Command::SUCCESS,
				[
					'pipelinq:demo:seed --remove done',
					'  removed  leads            = 5',
					'  removed  example-records  = 3',
					'  retained example-records  = 2',
				],
				null,
			],
			$this->runCommand(['--remove' => true], true, null, ['success' => true, 'removed' => ['leads' => 5]])
		);
	}//end testRemovesAndReportsTheCounts()

	/**
	 * No admin to run under, a failing seed, and a refused seed all fail with their own line.
	 *
	 * @return void
	 */
	public function testFailuresAreReported(): void {
		$this->assertSame([Command::FAILURE, ['No admin user found to run the seed under.'], null], $this->runCommand([], false, null, ['success' => true]));
		$this->assertSame([Command::FAILURE, ['No admin user found to run the seed under.'], null], $this->runCommand([], false, [], ['success' => true]));
		$this->assertSame(
			[Command::FAILURE, ['pipelinq:demo:seed failed: register offline'], null],
			$this->runCommand([], true, null, new RuntimeException('register offline'))
		);
		$this->assertSame(
			[Command::FAILURE, ['pipelinq:demo:seed issue: schemas missing'], null],
			$this->runCommand([], true, null, ['success' => false, 'message' => 'schemas missing'])
		);
		$this->assertSame(
			[Command::FAILURE, ['pipelinq:demo:seed issue: unknown error'], null],
			$this->runCommand(['--remove' => true], true, null, ['success' => false])
		);
	}//end testFailuresAreReported()
}//end class
