<?php

/**
 * Pipelinq SearchConsoleImportCommand.
 *
 * `occ pipelinq:marketing:search-console:import` runs the Search Console
 * import on demand: the same pass the daily job performs, with the window
 * as an option, so an admin can backfill after connecting a property
 * without waiting for cron.
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
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Command;

use OCA\Pipelinq\Service\SearchConsole\SearchConsoleImportService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Console entrypoint for the Search Console import.
 *
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
 */
class SearchConsoleImportCommand extends Command {

	/**
	 * Constructor.
	 *
	 * @param SearchConsoleImportService $importer The importer.
	 */
	public function __construct(
		private SearchConsoleImportService $importer,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Configure the command name, description and options.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
	 */
	protected function configure(): void {
		$this->setName(name: 'pipelinq:marketing:search-console:import')
			->setDescription('Import Search Console query rows for every configured property (service account).')
			->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'How many days back from today to import', '3');
	}//end configure()

	/**
	 * Execute the import.
	 *
	 * @param InputInterface $input The console input.
	 * @param OutputInterface $output The console output.
	 *
	 * @return int The exit code.
	 *
	 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($this->importer->hasKey() === false) {
			$output->writeln('<error>No service account key is configured (search.gsc.service_account_key).</error>');
			return Command::FAILURE;
		}

		$properties = $this->importer->properties();
		if ($properties === []) {
			$output->writeln('<error>No properties are configured (search.gsc.properties).</error>');
			return Command::FAILURE;
		}

		$days = max(0, (int)$input->getOption('days'));
		$output->writeln(
			sprintf(
				'Importing the last %d day(s) for %d %s as %s',
				$days,
				count($properties),
				$this->plural(count: count($properties)),
				$this->importer->serviceAccountEmail()
			)
		);

		try {
			$result = $this->importer->importRecent(days: $days);
		} catch (Throwable $e) {
			$output->writeln('<error>Search Console import failed: ' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}

		foreach ($result['errors'] as $property => $message) {
			$output->writeln(sprintf('<error>%s: %s</error>', $property, $message));
		}

		$output->writeln(
			sprintf('<info>Imported %d row(s) across %d %s.</info>', $result['rows'], $result['properties'], $this->plural(count: $result['properties']))
		);

		if ($result['properties'] === 0) {
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}//end execute()

	/**
	 * "property" or "properties".
	 *
	 * @param int $count The count.
	 *
	 * @return string
	 */
	private function plural(int $count): string {
		if ($count === 1) {
			return 'property';
		}

		return 'properties';
	}//end plural()
}//end class
