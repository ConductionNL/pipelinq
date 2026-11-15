<?php

/**
 * CompetitorWatchRunCommand: the manual competitor-watch pass.
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

use OCA\Pipelinq\Command\CompetitorWatchRunCommand;
use OCA\Pipelinq\Service\Competitor\CompetitorWatchService;
use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Refuses without an egress source, forwards limit and user, prints every
 * failure and the totals.
 *
 * @covers \OCA\Pipelinq\Command\CompetitorWatchRunCommand
 */
class CompetitorWatchRunCommandTest extends TestCase {

	/**
	 * Watch service mock.
	 *
	 * @var CompetitorWatchService&MockObject
	 */
	private CompetitorWatchService&MockObject $watches;

	/**
	 * Egress mock.
	 *
	 * @var ConnectorEgress&MockObject
	 */
	private ConnectorEgress&MockObject $egress;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->watches = $this->createMock(CompetitorWatchService::class);
		$this->egress = $this->createMock(ConnectorEgress::class);
	}//end setUp()

	/**
	 * A tester over the command.
	 *
	 * @return CommandTester
	 */
	private function tester(): CommandTester {
		return new CommandTester(new CompetitorWatchRunCommand($this->watches, $this->egress));
	}//end tester()

	/**
	 * The command carries its name and both options with their defaults.
	 *
	 * @return void
	 */
	public function testItIsConfigured(): void {
		$command = new CompetitorWatchRunCommand($this->watches, $this->egress);

		$this->assertSame('pipelinq:marketing:competitor-watch:run', $command->getName());
		$this->assertSame((string)CompetitorWatchService::DEFAULT_LIMIT, $command->getDefinition()->getOption('limit')->getDefault());
		$this->assertSame('l', $command->getDefinition()->getOption('limit')->getShortcut());
		$this->assertSame('', $command->getDefinition()->getOption('user')->getDefault());
	}//end testItIsConfigured()

	/**
	 * Without an egress source nothing runs.
	 *
	 * @return void
	 */
	public function testWithoutAnEgressSourceNothingRuns(): void {
		$this->egress->expects($this->once())->method('isConfigured')->with(CompetitorWatchService::SOURCE_KEY)->willReturn(false);
		$this->watches->expects($this->never())->method('runDue');

		$tester = $this->tester();
		$this->assertSame(Command::FAILURE, $tester->execute([]));
		$this->assertSame('No egress source is configured (' . CompetitorWatchService::SOURCE_KEY . ').' . PHP_EOL, $tester->getDisplay());
	}//end testWithoutAnEgressSourceNothingRuns()

	/**
	 * The default run asks for the default limit with no acting user and
	 * prints the totals.
	 *
	 * @return void
	 */
	public function testTheDefaultRunPrintsTheTotals(): void {
		$this->egress->method('isConfigured')->willReturn(true);
		$this->watches->expects($this->once())->method('runDue')
			->with(CompetitorWatchService::DEFAULT_LIMIT, null)
			->willReturn(['watches' => 3, 'events' => 7, 'failures' => []]);

		$tester = $this->tester();
		$this->assertSame(Command::SUCCESS, $tester->execute([]));
		$this->assertSame('Ran 3 watch(es) and recorded 7 new event(s).' . PHP_EOL, $tester->getDisplay());
	}//end testTheDefaultRunPrintsTheTotals()

	/**
	 * The limit is at least 1, the user is trimmed, and each failure is
	 * printed before the totals.
	 *
	 * @return void
	 */
	public function testOptionsAndFailuresArePassedThrough(): void {
		$this->egress->method('isConfigured')->willReturn(true);
		$this->watches->expects($this->once())->method('runDue')
			->with(1, 'ann')
			->willReturn(['watches' => 2, 'events' => 0, 'failures' => ['w-1' => 'timeout', 'w-2' => 'refused']]);

		$tester = $this->tester();
		$this->assertSame(Command::SUCCESS, $tester->execute(['--limit' => '-4', '--user' => ' ann ']));
		$this->assertSame(
			'w-1: timeout' . PHP_EOL . 'w-2: refused' . PHP_EOL . 'Ran 2 watch(es) and recorded 0 new event(s).' . PHP_EOL,
			$tester->getDisplay()
		);
	}//end testOptionsAndFailuresArePassedThrough()

	/**
	 * A blank user means no acting user; a failing pass is reported and fails.
	 *
	 * @return void
	 */
	public function testAFailingPassIsReported(): void {
		$this->egress->method('isConfigured')->willReturn(true);
		$this->watches->expects($this->once())->method('runDue')->with(10, null)->willThrowException(new RuntimeException('register offline'));

		$tester = $this->tester();
		$this->assertSame(Command::FAILURE, $tester->execute(['--limit' => '10', '--user' => '   ']));
		$this->assertSame('The watch pass failed: register offline' . PHP_EOL, $tester->getDisplay());
	}//end testAFailingPassIsReported()
}//end class
