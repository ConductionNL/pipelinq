<?php

/**
 * Pipelinq RemoveMockPipelinesCommand.
 *
 * `occ pipelinq:pipelines:remove-mocks [--dry-run]` removes the known mock
 * pipelines no lead or ticket is on, and lists the ones it keeps. Safe to run
 * again: a second run finds nothing left to remove.
 *
 * @category Command
 * @package  OCA\Pipelinq\Command
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/only-the-drawn-pipelines/specs/pipeline/spec.md#requirement-mock-pipelines-can-be-removed-when-nothing-is-on-them
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Command;

use OCA\Pipelinq\Service\MockPipelineCleanupService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console entrypoint for removing the mock pipelines.
 *
 * @spec openspec/changes/only-the-drawn-pipelines/specs/pipeline/spec.md#requirement-mock-pipelines-can-be-removed-when-nothing-is-on-them
 */
class RemoveMockPipelinesCommand extends Command {
	/**
	 * Constructor.
	 *
	 * @param MockPipelineCleanupService $cleanup The cleanup service.
	 */
	public function __construct(
		private MockPipelineCleanupService $cleanup,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Configure the command name, description and options.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName(name: 'pipelinq:pipelines:remove-mocks')
			->setDescription('Remove the shipped, demo and mock pipelines no lead or ticket is on; list the ones kept.')
			->addOption(name: 'dry-run', mode: InputOption::VALUE_NONE, description: 'List what would be removed without removing it.');
	}//end configure()

	/**
	 * Run the cleanup and print its report.
	 *
	 * @param InputInterface  $input  The console input.
	 * @param OutputInterface $output The console output.
	 *
	 * @return int SUCCESS, or FAILURE when a removal was refused or the run failed.
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$dryRun = ($input->getOption('dry-run') === true);

		try {
			$report = $this->cleanup->run(dryRun: $dryRun);
		} catch (\Throwable $e) {
			$output->writeln('<error>Removing the mock pipelines failed: '.$e->getMessage().'</error>');
			return Command::FAILURE;
		}

		$verb = 'Removed';
		if ($dryRun === true) {
			$verb = 'Would remove';
		}

		foreach ($report['removed'] as $pipeline) {
			$output->writeln(sprintf('%s "%s" (%s)', $verb, $pipeline['title'], $pipeline['id']));
		}

		foreach ($report['kept'] as $pipeline) {
			$usedBy = implode(' and ', $pipeline['usedBy']);
			$output->writeln(sprintf('<comment>Kept "%s" (%s): %s objects are on it</comment>', $pipeline['title'], $pipeline['id'], $usedBy));
		}

		foreach ($report['failed'] as $pipeline) {
			$output->writeln(sprintf('<error>OpenRegister refused to remove "%s" (%s)</error>', $pipeline['title'], $pipeline['id']));
		}

		if (count($report['defaults']) > 1) {
			$titles = array_map(static fn (array $pipeline): string => '"'.$pipeline['title'].'"', $report['defaults']);
			$output->writeln('<comment>More than one pipeline is marked as default: '.implode(', ', $titles).'</comment>');
		}

		$counts = [count($report['removed']), count($report['kept']), count($report['failed'])];
		$output->writeln(vsprintf('<info>%d removed, %d kept, %d refused.</info>', $counts));

		if ($report['failed'] === []) {
			return Command::SUCCESS;
		}

		return Command::FAILURE;
	}//end execute()
}//end class
