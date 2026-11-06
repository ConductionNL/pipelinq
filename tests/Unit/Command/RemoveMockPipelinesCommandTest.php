<?php

/**
 * Unit tests for RemoveMockPipelinesCommand.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Command;

use OCA\Pipelinq\Command\RemoveMockPipelinesCommand;
use OCA\Pipelinq\Service\MockPipelineCleanupService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for RemoveMockPipelinesCommand.
 */
class RemoveMockPipelinesCommandTest extends TestCase {

	/**
	 * The report lists what went, what stayed and why, and more than one default.
	 *
	 * @return void
	 */
	public function testPrintsTheReport(): void {
		$cleanup = $this->createMock(originalClassName: MockPipelineCleanupService::class);
		$cleanup->expects($this->once())->method('run')->with(false)->willReturn([
			'removed'  => [['id' => 'p-demo', 'title' => '[Demo] Sales pipeline']],
			'kept'     => [['id' => 'p-sales', 'title' => 'Sales Pipeline', 'usedBy' => ['lead', 'ticket']]],
			'failed'   => [],
			'defaults' => [['id' => 'p-sales', 'title' => 'Sales Pipeline'], ['id' => 'p-vragen', 'title' => 'Vragen en meldingen']],
		]);

		$tester = new CommandTester(new RemoveMockPipelinesCommand(cleanup: $cleanup));
		$code   = $tester->execute([]);
		$output = $tester->getDisplay();

		$this->assertSame(expected: Command::SUCCESS, actual: $code);
		$this->assertStringContainsString(needle: 'Removed "[Demo] Sales pipeline" (p-demo)', haystack: $output);
		$this->assertStringContainsString(needle: 'Kept "Sales Pipeline" (p-sales): lead and ticket objects are on it', haystack: $output);
		$defaults = 'More than one pipeline is marked as default: "Sales Pipeline", "Vragen en meldingen"';
		$this->assertStringContainsString(needle: $defaults, haystack: $output);
		$this->assertStringContainsString(needle: '1 removed, 1 kept, 0 refused.', haystack: $output);
	}//end testPrintsTheReport()

	/**
	 * --dry-run is passed on and worded as what would happen.
	 *
	 * @return void
	 */
	public function testADryRunSaysWhatWouldGo(): void {
		$cleanup = $this->createMock(originalClassName: MockPipelineCleanupService::class);
		$cleanup->expects($this->once())->method('run')->with(true)->willReturn([
			'removed'  => [['id' => 'p-demo', 'title' => '[Demo] Sales pipeline']],
			'kept'     => [],
			'failed'   => [],
			'defaults' => [],
		]);

		$tester = new CommandTester(new RemoveMockPipelinesCommand(cleanup: $cleanup));
		$tester->execute(['--dry-run' => true]);

		$this->assertStringContainsString(needle: 'Would remove "[Demo] Sales pipeline" (p-demo)', haystack: $tester->getDisplay());
	}//end testADryRunSaysWhatWouldGo()

	/**
	 * A refused delete, or a run that cannot start, fails the command.
	 *
	 * @return void
	 */
	public function testARefusalOrAnErrorFailsTheCommand(): void {
		$refused = $this->createMock(originalClassName: MockPipelineCleanupService::class);
		$refused->method('run')->willReturn([
			'removed'  => [],
			'kept'     => [],
			'failed'   => [['id' => 'p-demo', 'title' => '[Demo] Sales pipeline']],
			'defaults' => [],
		]);
		$tester = new CommandTester(new RemoveMockPipelinesCommand(cleanup: $refused));
		$this->assertSame(expected: Command::FAILURE, actual: $tester->execute([]));
		$this->assertStringContainsString(needle: 'OpenRegister refused to remove "[Demo] Sales pipeline"', haystack: $tester->getDisplay());

		$broken = $this->createMock(originalClassName: MockPipelineCleanupService::class);
		$broken->method('run')->willThrowException(new \RuntimeException('Pipelinq is not configured: register is empty'));
		$tester = new CommandTester(new RemoveMockPipelinesCommand(cleanup: $broken));
		$this->assertSame(expected: Command::FAILURE, actual: $tester->execute([]));
		$this->assertStringContainsString(needle: 'register is empty', haystack: $tester->getDisplay());
	}//end testARefusalOrAnErrorFailsTheCommand()
}//end class
