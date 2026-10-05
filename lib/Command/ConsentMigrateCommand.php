<?php

/**
 * Pipelinq ConsentMigrateCommand.
 *
 * `occ pipelinq:consent:migrate`: runs the consent migration into integriq
 * on demand, for an instance that installed integriq after its last pipelinq
 * upgrade. Idempotent.
 *
 * @category Command
 * @package  OCA\Pipelinq\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-s-consent-records-are-migrated-into-integriq-once-req-cii-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Command;

use OCA\Pipelinq\Service\ConsentMigrationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console entry point for the consent migration.
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-s-consent-records-are-migrated-into-integriq-once-req-cii-001
 */
class ConsentMigrateCommand extends Command {

	/**
	 * Constructor.
	 *
	 * @param ConsentMigrationService $migration The migration.
	 */
	public function __construct(
		private readonly ConsentMigrationService $migration,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name and description.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName(name: 'pipelinq:consent:migrate')
			->setDescription('Move pipelinq consent records into integriq and switch consent.store to integriq when none is refused.');
	}//end configure()

	/**
	 * Run it.
	 *
	 * @param InputInterface  $input  Unused.
	 * @param OutputInterface $output The output.
	 *
	 * @return int The exit code: 0 on a clean run, 1 when integriq is missing or refused a record.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $input is part of the Command::execute() contract.
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$counts = $this->migration->run();
		$output->writeln((string)json_encode($counts));
		if ($counts['status'] === 'done') {
			return Command::SUCCESS;
		}

		return Command::FAILURE;
	}//end execute()
}//end class
