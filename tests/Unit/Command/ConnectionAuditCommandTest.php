<?php

/**
 * ConnectionAuditCommand: the manual social connection audit.
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

use OCA\Pipelinq\Command\ConnectionAuditCommand;
use OCA\Pipelinq\Service\Social\ConnectionAuditService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Prints the audit's counts, or its failure.
 *
 * @covers \OCA\Pipelinq\Command\ConnectionAuditCommand
 */
class ConnectionAuditCommandTest extends TestCase {

	/**
	 * A finished audit prints its counts and succeeds.
	 *
	 * @return void
	 */
	public function testAFinishedAuditPrintsItsCounts(): void {
		$audit = $this->createMock(ConnectionAuditService::class);
		$audit->expects($this->once())->method('run')->willReturn(['pairs' => 5, 'answered' => 3, 'unknown' => 2]);
		$command = new ConnectionAuditCommand($audit);
		$tester = new CommandTester($command);

		$this->assertSame('pipelinq:marketing:connection-audit', $command->getName());
		$this->assertSame(Command::SUCCESS, $tester->execute([]));
		$this->assertSame(
			'Checked 5 pair(s): 3 answered, 2 could not be answered by the network.' . PHP_EOL,
			$tester->getDisplay()
		);
	}//end testAFinishedAuditPrintsItsCounts()

	/**
	 * A failing audit is reported and fails.
	 *
	 * @return void
	 */
	public function testAFailingAuditFails(): void {
		$audit = $this->createMock(ConnectionAuditService::class);
		$audit->method('run')->willThrowException(new RuntimeException('rate limited'));
		$tester = new CommandTester(new ConnectionAuditCommand($audit));

		$this->assertSame(Command::FAILURE, $tester->execute([]));
		$this->assertSame('The connection audit failed: rate limited' . PHP_EOL, $tester->getDisplay());
	}//end testAFailingAuditFails()
}//end class
