<?php

/**
 * Pipelinq AvailabilityCacheRefreshJob.
 *
 * Hourly background job that warm-busts the per-resource AvailabilityCache for
 * the next 30 days. Pulls every active Resource from OpenRegister and tells
 * the AvailabilityService (member 02) to invalidate the cache for today + 30
 * days each — guaranteeing that calendar-leaf-synced blocks (lunch, meetings,
 * vacations) flush within an hour even when the booking write-path doesn't
 * naturally bust the cache (REQ-APT-018 scenario 3).
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\AvailabilityService;
use OCA\Pipelinq\Util\AnyPayloadToArray;
use OCA\Pipelinq\Util\RowIdOf;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Hourly availability-cache warm-bust.
 *
 * Iterates active Resources and invalidates the AvailabilityCache for today
 * through today + 30 days, catching per-resource errors so one bad row never
 * stops the next from being processed.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
class AvailabilityCacheRefreshJob extends TimedJob {
	use AnyPayloadToArray;
	use RowIdOf;


	/**
	 * Run interval in seconds (1 hour).
	 *
	 * @var int
	 */
	private const INTERVAL = 3600;

	/**
	 * Number of forward-looking days to refresh.
	 *
	 * @var int
	 */
	public const HORIZON_DAYS = 30;

	/**
	 * App-config key for the Resource schema id/slug.
	 *
	 * @var string
	 */
	public const RESOURCE_SCHEMA_KEY = 'resource_schema';

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory.
	 * @param ContainerInterface $container The DI container (OR lookup).
	 * @param IAppConfig $appConfig The app configuration.
	 * @param AvailabilityService $availabilityService Member 02 — invalidation target.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private AvailabilityService $availabilityService,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL);
	}//end __construct()

	/**
	 * Iterate active Resources and invalidate today+30d.
	 *
	 * @param mixed $argument The job argument (unused; part of the TimedJob contract).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is required by
	 *  TimedJob::run().
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	protected function run(mixed $argument): void {
		try {
			$resources = $this->loadActiveResources();
		} catch (Throwable $e) {
			$this->logger->error(
				'Pipelinq: availability cache refresh failed to load resources',
				['exception' => $e->getMessage()]
			);
			return;
		}

		if ($resources === []) {
			return;
		}

		$dates = $this->horizonDates();
		$count = 0;
		$errors = 0;
		foreach ($resources as $resourceId) {
			foreach ($dates as $date) {
				try {
					$this->availabilityService->invalidateCache(
						resourceId: $resourceId,
						date: $date
					);
					$count++;
				} catch (Throwable $e) {
					$errors++;
					$this->logger->warning(
						'Pipelinq: availability cache invalidation failed',
						['resource' => $resourceId, 'date' => $date]
					);
				}
			}
		}//end foreach

		$this->logger->info(
			'Pipelinq: availability cache refresh complete',
			[
				'resources' => count($resources),
				'invalidated' => $count,
				'errors' => $errors,
				'horizon_days' => self::HORIZON_DAYS,
			]
		);
	}//end run()

	/**
	 * Resolve the active-resource id list from OpenRegister.
	 *
	 * Returns just the ids — the AvailabilityService doesn't need the full row
	 * to invalidate. When OR is unavailable or the schema is unconfigured the
	 * list is empty (the next run will pick up the work).
	 *
	 * @return array<int, string>
	 */
	private function loadActiveResources(): array {
		$register = $this->registerSlug();
		$schema = $this->schemaSlug(key: self::RESOURCE_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'status' => 'active',
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: availability cache refresh resource lookup failed',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		if (is_array($rows) === false) {
			return [];
		}

		$ids = [];
		foreach ($rows as $row) {
			$data = $this->toArray(object: $row);
			if ($data === null) {
				continue;
			}

			$id = $this->idOf(object: $data);
			if ($id === '') {
				continue;
			}

			$ids[] = $id;
		}

		return $ids;
	}//end loadActiveResources()

	/**
	 * Build the date horizon: today + HORIZON_DAYS (UTC).
	 *
	 * @return array<int, string> ISO `YYYY-MM-DD` dates.
	 */
	private function horizonDates(): array {
		$today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
		$dates = [];
		for ($offset = 0; $offset <= self::HORIZON_DAYS; $offset++) {
			$date = $today->modify(sprintf('+%d days', $offset));
			$dates[] = $date->format('Y-m-d');
		}

		return $dates;
	}//end horizonDates()

	/**
	 * Resolve the pipelinq register slug from app config.
	 *
	 * @return string
	 */
	private function registerSlug(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'register', '');
	}//end registerSlug()

	/**
	 * Resolve a schema slug by app-config key.
	 *
	 * @param string $key App-config key.
	 *
	 * @return string
	 */
	private function schemaSlug(string $key): string {
		return $this->appConfig->getValueString(Application::APP_ID, $key, '');
	}//end schemaSlug()

	/**
	 * Resolve the OpenRegister ObjectService via the DI container.
	 *
	 * @return object The ObjectService instance.
	 *
	 * @throws RuntimeException If OpenRegister is not available.
	 */
	private function getObjectService(): object {
		// Availability established before the reach (ADR-083) — the lookup names
		// OpenRegister only as a string, so without this the dependency is
		// declared nowhere a reader or a gate can see it. Converting to a typed
		// constructor property is the ADR's preferred shape: pipelinq#1160.
		if (class_exists('\OCA\OpenRegister\Service\ObjectService') === false) {
			throw new RuntimeException('The OpenRegister app is not installed');
		}

		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			throw new RuntimeException('OpenRegister ObjectService is unavailable.', 0, $e);
		}
	}//end getObjectService()
}//end class
