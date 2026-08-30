<?php

/**
 * Pipelinq PortalCleanupCommand.
 *
 * `occ pipelinq:portal:cleanup` — runs the portal closed-account cleanup on
 * demand (the same pass the nightly PortalCleanupJob performs), pseudonymising
 * the contacts of closed accounts whose retention obligations have lapsed and
 * reporting how many were processed. Useful for a DPO fulfilling an erasure
 * request without waiting for the nightly run (REQ-010).
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
 * @spec openspec/changes/customer-portal/specs.md#REQ-010
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Command;

use OCA\Pipelinq\Service\Portal\PortalCleanupService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console entrypoint for the portal account-closure cleanup.
 */
class PortalCleanupCommand extends Command {
	/**
	 * Constructor.
	 *
	 * @param PortalCleanupService $cleanup The cleanup service.
	 */
	public function __construct(
		private PortalCleanupService $cleanup,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Configure the command name and description.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName(name: 'pipelinq:portal:cleanup')
			->setDescription('Pseudonymise contacts of closed portal accounts whose retention has lapsed (AVG Art. 17).');
	}//end configure()

	/**
	 * Execute the cleanup pass.
	 *
	 * @param InputInterface $input The console input (unused; no arguments).
	 * @param OutputInterface $output The console output.
	 *
	 * @return int The exit code.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $input is part of the
	 *  Symfony Command::execute() contract.
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$count = $this->cleanup->run();
			$output->writeln(sprintf('<info>Pseudonymised %d closed-account contact(s).</info>', $count));
			return Command::SUCCESS;
		} catch (\Throwable $e) {
			$output->writeln('<error>Portal cleanup failed: ' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}
	}//end execute()
}//end class
