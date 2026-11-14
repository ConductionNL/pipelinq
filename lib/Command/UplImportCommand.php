<?php

/**
 * Pipelinq UplImportCommand.
 *
 * `occ pipelinq:upl:import` downloads the national list of government
 * products (UPL) and imports it into the `uplProduct` lookup the product
 * form picks a service's UPL reference from. `--file` imports a local copy
 * instead, for an instance that cannot reach the national source;
 * `--url` downloads from another address.
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
 * @spec openspec/changes/product-service-catalogue-public/tasks.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Command;

use OCA\Pipelinq\Service\Upl\UplListImporter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use RuntimeException;
use Throwable;

/**
 * Imports the UPL list from occ.
 *
 * @spec openspec/changes/product-service-catalogue-public/tasks.md
 */
class UplImportCommand extends Command {

	/**
	 * Constructor.
	 *
	 * @param UplListImporter $importer Downloads and imports the list.
	 */
	public function __construct(
		private readonly UplListImporter $importer,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Configure the command.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName(name: 'pipelinq:upl:import')
			->setDescription('Import the national list of government products (UPL) that a service\'s UPL reference is picked from.')
			->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Import this local CSV instead of downloading the list')
			->addOption('url', 'u', InputOption::VALUE_REQUIRED, 'Download the list from this URL instead of the configured source');
	}//end configure()

	/**
	 * Run the import.
	 *
	 * @param InputInterface  $input  The options.
	 * @param OutputInterface $output Where the summary goes.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/product-service-catalogue-public/tasks.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$csv = $this->csv(file: (string)($input->getOption('file') ?? ''), url: (string)($input->getOption('url') ?? ''), output: $output);
			$result = $this->importer->import(csv: $csv);
		} catch (Throwable $e) {
			$output->writeln('<error>UPL import failed: ' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}

		$output->writeln(
			sprintf(
				'<info>Read %d UPL product(s): %d created, %d updated, %d unchanged, %d failed.</info>',
				$result['read'],
				$result['created'],
				$result['updated'],
				$result['unchanged'],
				$result['failed']
			)
		);

		if ($result['failed'] > 0) {
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}//end execute()

	/**
	 * The list to import: the local file when given, else a download.
	 *
	 * @param string          $file   A local CSV, or empty.
	 * @param string          $url    A URL to download from, or empty for the configured source.
	 * @param OutputInterface $output Where progress goes.
	 *
	 * @return string The CSV.
	 *
	 * @throws RuntimeException When the file cannot be read or the download fails.
	 */
	private function csv(string $file, string $url, OutputInterface $output): string {
		if ($file !== '') {
			$csv = false;
			if (is_readable($file) === true) {
				$csv = file_get_contents($file);
			}

			if ($csv === false) {
				throw new RuntimeException('The file ' . $file . ' cannot be read.');
			}

			return $csv;
		}

		if ($url === '') {
			$url = $this->importer->sourceUrl();
		}

		$output->writeln('Downloading the UPL list from ' . $url);

		return $this->importer->download(url: $url);
	}//end csv()
}//end class
