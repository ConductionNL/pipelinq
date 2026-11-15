<?php

/**
 * PortalCleanupCommand: pseudonymising closed portal accounts.
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

use OCA\Pipelinq\Command\PortalCleanupCommand;
use OCA\Pipelinq\Service\Portal\PortalCleanupService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Prints how many contacts were pseudonymised, or the failure.
 *
 * @covers \OCA\Pipelinq\Command\PortalCleanupCommand
 */
class PortalCleanupCommandTest extends TestCase {

	/**
	 * A run prints its count and succeeds.
	 *
	 * @return void
	 */
	public function testARunPrintsItsCount(): void {
		$cleanup = $this->createMock(PortalCleanupService::class);
		$cleanup->expects($this->once())->method('run')->willReturn(6);
		$command = new PortalCleanupCommand($cleanup);
		$tester = new CommandTester($command);

		$this->assertSame('pipelinq:portal:cleanup', $command->getName());
		$this->assertSame(Command::SUCCESS, $tester->execute([]));
		$this->assertSame('Pseudonymised 6 closed-account contact(s).' . PHP_EOL, $tester->getDisplay());
	}//end testARunPrintsItsCount()

	/**
	 * A failing run is reported and fails.
	 *
	 * @return void
	 */
	public function testAFailingRunFails(): void {
		$cleanup = $this->createMock(PortalCleanupService::class);
		$cleanup->method('run')->willThrowException(new RuntimeException('lock held'));
		$tester = new CommandTester(new PortalCleanupCommand($cleanup));

		$this->assertSame(Command::FAILURE, $tester->execute([]));
		$this->assertSame('Portal cleanup failed: lock held' . PHP_EOL, $tester->getDisplay());
	}//end testAFailingRunFails()
}//end class
