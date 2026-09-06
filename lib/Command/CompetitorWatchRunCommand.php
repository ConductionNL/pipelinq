<?php

/**
 * Pipelinq CompetitorWatchRunCommand.
 *
 * `occ pipelinq:marketing:competitor-watch:run` runs the competitor watches
 * that are due, on demand.
 *
 * IT IS NOT THE SCHEDULER. ADR-094 gives the schedule to an OpenRegister flow,
 * and this command exists for the two moments a flow does not cover: an
 * administrator who has just configured the egress source and wants to see
 * whether it works, and one who is debugging a watch without waiting an hour.
 * It runs exactly the same pass the flow's node runs.
 *
 * @category Command
 * @package  OCA\Pipelinq\Command
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-runs-on-an-openregister-flow-schedule-and-never-on-a-job-of-ours
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Command;

use OCA\Pipelinq\Service\Competitor\CompetitorWatchService;
use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Console entry point for a competitor watch pass.
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-runs-on-an-openregister-flow-schedule-and-never-on-a-job-of-ours
 */
class CompetitorWatchRunCommand extends Command {

	/**
	 * Constructor.
	 *
	 * @param CompetitorWatchService $watches The watch service.
	 * @param ConnectorEgress $egress Whether an egress source is configured.
	 */
	public function __construct(
		private CompetitorWatchService $watches,
		private ConnectorEgress $egress,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Configure the command name, description and options.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-runs-on-an-openregister-flow-schedule-and-never-on-a-job-of-ours
	 */
	protected function configure(): void {
		$this->setName(name: 'pipelinq:marketing:competitor-watch:run')
			->setDescription('Run the competitor watches that are due, the same pass the scheduled flow runs.')
			->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'How many watches to run at most', (string)CompetitorWatchService::DEFAULT_LIMIT)
			->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'The user id the run acts as, for the relevance scoring guard', '');
	}//end configure()

	/**
	 * Run the due watches.
	 *
	 * @param InputInterface $input The console input.
	 * @param OutputInterface $output The console output.
	 *
	 * @return int The exit code.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-runs-on-an-openregister-flow-schedule-and-never-on-a-job-of-ours
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($this->egress->isConfigured(configKey: CompetitorWatchService::SOURCE_KEY) === false) {
			$output->writeln('<error>No egress source is configured (' . CompetitorWatchService::SOURCE_KEY . ').</error>');
			return Command::FAILURE;
		}

		$limit = max(1, (int)$input->getOption('limit'));
		$user = trim((string)$input->getOption('user'));
		$actingUser = null;
		if ($user !== '') {
			$actingUser = $user;
		}

		try {
			$summary = $this->watches->runDue(limit: $limit, actingUserId: $actingUser);
		} catch (Throwable $e) {
			$output->writeln('<error>The watch pass failed: ' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}

		foreach ($summary['failures'] as $watchId => $reason) {
			$output->writeln(sprintf('<comment>%s: %s</comment>', $watchId, $reason));
		}

		$output->writeln(
			sprintf('<info>Ran %d watch(es) and recorded %d new event(s).</info>', $summary['watches'], $summary['events'])
		);

		return Command::SUCCESS;
	}//end execute()
}//end class
