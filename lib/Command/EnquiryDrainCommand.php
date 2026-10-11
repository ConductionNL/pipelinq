<?php

/**
 * Pipelinq Enquiry Drain Command
 *
 * `occ pipelinq:enquiry:drain [--apply] [--include-intake-submissions]`:
 * submits every open `enquiry` into the destination of the website form its
 * `source` names, and reports delivered, refused and unmapped (decision 179).
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
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-held-website-messages-are-drained-into-the-destination-their-form-names
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Command;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\WebForm\HeldObjectDrain;
use OCP\IAppConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Drains enquiries (and, on request, legacy intake submissions) into their forms' destinations.
 *
 * WHY READ-ONLY BY DEFAULT. A drain writes objects people then work on and
 * deletes the held ones. The default run reports what would happen and
 * changes nothing; `--apply` writes.
 *
 * Exit code: 0 when nothing is pending after the run (the enquiry schema may
 * then be removed), 1 when anything is still held, refused or unmapped.
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-held-website-messages-are-drained-into-the-destination-their-form-names
 */
class EnquiryDrainCommand extends Command {

	/**
	 * The legacy intake submission schema, drained only on request.
	 *
	 * @var string
	 */
	private const INTAKE_SUBMISSION_SCHEMA = 'intakeSubmission';

	/**
	 * Constructor.
	 *
	 * @param HeldObjectDrain $drain     The drain.
	 * @param IAppConfig      $appConfig App configuration: register and enquiry schema ids.
	 *
	 * @spec exclude constructor wiring only; it makes no decision a requirement can describe
	 */
	public function __construct(
		private readonly HeldObjectDrain $drain,
		private readonly IAppConfig $appConfig,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Declare the command.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-held-website-messages-are-drained-into-the-destination-their-form-names
	 */
	protected function configure(): void {
		$this->setName(name: 'pipelinq:enquiry:drain')
			->setDescription(
				description: 'Submit every open enquiry into the destination its website form names, and report. Read-only unless --apply is given.'
			)
			->addOption(name: 'apply', mode: InputOption::VALUE_NONE, description: 'Submit and delete the delivered enquiries.')
			->addOption(
				name: 'include-intake-submissions',
				mode: InputOption::VALUE_NONE,
				description: 'Also drain legacy intakeSubmission objects, by their source.'
			);

	}//end configure()

	/**
	 * Run the drain and print the report.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 when nothing is pending, 1 otherwise.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-held-website-messages-are-drained-into-the-destination-their-form-names
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$apply = (bool)$input->getOption('apply');
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$enquiry = $this->appConfig->getValueString(Application::APP_ID, 'enquiry_schema', '');
		if ($register === '') {
			$output->writeln('<error>The pipelinq register is not configured.</error>');
			return 1;
		}

		$sources = [];
		if ($enquiry !== '') {
			$sources['enquiry'] = [$enquiry, ['status' => 'new']];
		}

		if ((bool)$input->getOption('include-intake-submissions') === true) {
			$sources[self::INTAKE_SUBMISSION_SCHEMA] = [self::INTAKE_SUBMISSION_SCHEMA, []];
		}

		if ($sources === []) {
			$output->writeln('No enquiry schema is configured on this instance: nothing to drain.');
			return 0;
		}

		$pending = 0;
		foreach ($sources as $label => [$schema, $openFilter]) {
			try {
				$report = $this->drain->drain(register: $register, schema: $schema, keyProperty: 'source', openFilter: $openFilter, apply: $apply);
			} catch (Throwable $e) {
				$output->writeln(sprintf('<error>%s: could not be read: %s</error>', $label, $e->getMessage()));
				$pending++;
				continue;
			}

			$pending += $report['pending'];
			$this->print(label: $label, report: $report, apply: $apply, output: $output);
		}

		$output->writeln(sprintf('Pending after this run: %d.', $pending));
		if ($pending > 0) {
			return 1;
		}

		return 0;

	}//end execute()

	/**
	 * Print one schema's report.
	 *
	 * @param string               $label  The held schema.
	 * @param array<string, mixed> $report The drain report.
	 * @param bool                 $apply  Whether this run wrote.
	 * @param OutputInterface      $output Console output.
	 *
	 * @return void
	 */
	private function print(string $label, array $report, bool $apply, OutputInterface $output): void {
		$verb = 'would be delivered';
		if ($apply === true) {
			$verb = 'delivered';
		}

		$output->writeln(
			sprintf(
				'%s: %d %s, %d refused, %d unmapped.',
				$label,
				count($report['delivered']),
				$verb,
				count($report['refused']),
				count($report['unmapped'])
			)
		);
		foreach ($report['delivered'] as $row) {
			$line = sprintf('  delivered %s (%s) into %s %s', $row['id'], $row['key'], $row['destination'], $row['reference']);
			if (isset($row['notRemoved']) === true) {
				$line .= ' (not removed: ' . $row['notRemoved'] . ')';
			}

			$output->writeln(rtrim($line));
		}

		foreach ($report['refused'] as $row) {
			$output->writeln(sprintf('  refused %s: status %d %s', $row['id'], $row['status'], implode(',', $row['codes'])));
		}

		foreach ($report['unmapped'] as $row) {
			$output->writeln(sprintf('  unmapped %s: no published form for source "%s"', $row['id'], $row['key']));
		}

	}//end print()
}//end class
