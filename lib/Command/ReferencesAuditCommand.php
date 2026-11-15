<?php

/**
 * Pipelinq References Audit Command
 *
 * Audits, and on request backfills, the cross-app uuid reference that links a
 * pipelinq satellite record to the record another app owns.
 *
 * @category Command
 * @package  OCA\Pipelinq\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Command;

use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `occ pipelinq:references:audit [--write]`.
 *
 * WHY READ-ONLY BY DEFAULT. The consolidation gave `salesContract` a plain uuid
 * pointing at shillinq's `Contract`, and nothing ever populated it.
 * Filling it in is a cross-app write, and a WRONG cross-app link is worse than
 * an empty one: an empty reference is visibly absent, a wrong one silently
 * attributes one record to another. So the default run reports and changes
 * nothing, the write option fills in only an UNAMBIGUOUS single match on the
 * shared identity key, and anything else is named rather than guessed.
 *
 * The identity key is not a convenience. It is the same key that decided the
 * consolidation in the first place: two records carrying one `contractNumber` are
 * one thing, which is precisely why the two schemas were merged onto an owner.
 *
 * @spec openspec/specs/contract-renewal-tracking/spec.md
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment
 */
class ReferencesAuditCommand extends Command {
	/**
	 * Wire collaborators.
	 *
	 * @param ContainerInterface $container Container, for the lazy ObjectService resolve.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Declare the command.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName('pipelinq:references:audit')
			->setDescription(
				'Audit the salesContract to shillinq Contract uuid reference. Read-only unless the write option is given.'
			)
			->addOption(
				'write',
				null,
				InputOption::VALUE_NONE,
				'Fill in the reference where exactly one owner record shares the identity key.'
			);
	}//end configure()

	/**
	 * Run the audit.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 when no reference dangles, 1 when at least one does.
	 *
	 * @spec openspec/specs/contract-renewal-tracking/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$write = (bool)$input->getOption('write');

		try {
			$run = new ReferencesAuditRun(
				objectService: $this->container->get('OCA\OpenRegister\Service\ObjectService'),
				output: $output
			);
			$satellites = $run->readAll(register: ReferencesAuditRun::REGISTER, schema: ReferencesAuditRun::SATELLITE_SCHEMA);
		} catch (Throwable $e) {
			$output->writeln('<error>could not read '.ReferencesAuditRun::SATELLITE_SCHEMA.': '.$e->getMessage().'</error>');
			return 1;
		}

		$run->indexOwners();
		$counts = $run->audit(satellites: $satellites, write: $write);
		$run->report(counts: $counts, write: $write);

		if ($counts['dangling'] > 0) {
			return 1;
		}

		return 0;
	}//end execute()
}//end class
