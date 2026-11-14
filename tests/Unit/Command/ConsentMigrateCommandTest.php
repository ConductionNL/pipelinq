<?php

/**
 * ConsentMigrateCommand: moving consent records into integriq.
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
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Command;

use OCA\Pipelinq\Command\ConsentMigrateCommand;
use OCA\Pipelinq\Service\ConsentMigrationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Prints the counts as JSON and succeeds only when the migration is done.
 *
 * @covers \OCA\Pipelinq\Command\ConsentMigrateCommand
 */
class ConsentMigrateCommandTest extends TestCase {

	/**
	 * Run the command over a migration answering the given counts.
	 *
	 * @param array<string, int|string> $counts The counts.
	 *
	 * @return array{0: int, 1: string} Exit code and output.
	 */
	private function migrate(array $counts): array {
		$migration = $this->createMock(ConsentMigrationService::class);
		$migration->expects($this->once())->method('run')->willReturn($counts);
		$command = new ConsentMigrateCommand($migration);
		$this->assertSame('pipelinq:consent:migrate', $command->getName());
		$tester = new CommandTester($command);

		return [$tester->execute([]), $tester->getDisplay()];
	}//end migrate()

	/**
	 * A completed migration prints its counts and succeeds.
	 *
	 * @return void
	 */
	public function testADoneMigrationSucceeds(): void {
		$counts = ['status' => 'done', 'migrated' => 4, 'skippedBounce' => 1, 'skippedNoAddress' => 0, 'refused' => 0, 'store' => 'integriq'];

		$this->assertSame(
			[Command::SUCCESS, '{"status":"done","migrated":4,"skippedBounce":1,"skippedNoAddress":0,"refused":0,"store":"integriq"}' . PHP_EOL],
			$this->migrate($counts)
		);
	}//end testADoneMigrationSucceeds()

	/**
	 * Any other status fails, still printing the counts.
	 *
	 * @return void
	 */
	public function testAnUnfinishedMigrationFails(): void {
		$counts = ['status' => 'refused', 'migrated' => 2, 'skippedBounce' => 0, 'skippedNoAddress' => 0, 'refused' => 3, 'store' => 'pipelinq'];

		[$code, $display] = $this->migrate($counts);

		$this->assertSame(Command::FAILURE, $code);
		$this->assertSame($counts, json_decode(trim($display), true));
	}//end testAnUnfinishedMigrationFails()
}//end class
