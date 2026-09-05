<?php

/**
 * Pipelinq SearchConsoleImportJob.
 *
 * Daily background job that pulls the last three days of Search Console
 * query rows for every configured property. Three days, not one, because
 * Google publishes a day with a lag of about two days and may revise it;
 * the upsert makes the overlap harmless.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
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

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\SearchConsole\SearchConsoleImportService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Timed job driving the daily Search Console import.
 *
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
 */
class SearchConsoleImportJob extends TimedJob {

	/**
	 * Days re-read on every run.
	 *
	 * @var int
	 */
	public const DAYS = 3;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory.
	 * @param SearchConsoleImportService $importer The importer.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
	 */
	public function __construct(
		ITimeFactory $time,
		private SearchConsoleImportService $importer,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 86400);
		$this->setTimeSensitivity(sensitivity: self::TIME_INSENSITIVE);
	}//end __construct()

	/**
	 * Execute the job.
	 *
	 * @param mixed $argument The job argument (unused, required by TimedJob).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	protected function run($argument): void {
		if ($this->importer->hasKey() === false || $this->importer->properties() === []) {
			$this->logger->debug('SearchConsoleImportJob: not configured, skipping');
			return;
		}

		try {
			$result = $this->importer->importRecent(days: self::DAYS);
			$this->logger->info('SearchConsoleImportJob: run finished', $result);
		} catch (Throwable $e) {
			$this->logger->error('SearchConsoleImportJob: run failed', ['exception' => $e->getMessage()]);
		}
	}//end run()
}//end class
