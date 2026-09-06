<?php

/**
 * Pipelinq ConnectionAuditCommand.
 *
 * `occ pipelinq:marketing:connection-audit` re-runs the follow audit for every
 * client carrying a social handle.
 *
 * The audit is a read over other people's public graphs, so it is not put on a
 * schedule of its own: an administrator runs it when the account list or the
 * client list has changed. The command prints how many pairs it could answer
 * and how many it could not, because "how much of this can be known at all" is
 * the first question anybody has about it.
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
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-the-connection-audit-answers-only-what-an-api-will-say
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Command;

use OCA\Pipelinq\Service\Social\ConnectionAuditService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Console entry point for the connection audit.
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-the-connection-audit-answers-only-what-an-api-will-say
 */
class ConnectionAuditCommand extends Command {

	/**
	 * Constructor.
	 *
	 * @param ConnectionAuditService $audit The audit service.
	 */
	public function __construct(
		private ConnectionAuditService $audit,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Configure the command name and description.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-the-connection-audit-answers-only-what-an-api-will-say
	 */
	protected function configure(): void {
		$this->setName(name: 'pipelinq:marketing:connection-audit')
			->setDescription('Check, per client and network, whether we follow them and whether they follow us.');
	}//end configure()

	/**
	 * Run the audit.
	 *
	 * @param InputInterface $input The console input.
	 * @param OutputInterface $output The console output.
	 *
	 * @return int The exit code.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-the-connection-audit-answers-only-what-an-api-will-say
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $input is part of the
	 *  Command::execute() contract; this command takes no options.
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$summary = $this->audit->run();
		} catch (Throwable $e) {
			$output->writeln('<error>The connection audit failed: ' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}

		$output->writeln(
			sprintf(
				'<info>Checked %d pair(s): %d answered, %d could not be answered by the network.</info>',
				$summary['pairs'],
				$summary['answered'],
				$summary['unknown']
			)
		);

		return Command::SUCCESS;
	}//end execute()
}//end class
