<?php

/**
 * Pipelinq InitSlaStatus.
 *
 * Repair step that initialises `slaStatus` on existing tracked
 * objects (request + klacht tickets, callbacks) so post-upgrade SLA
 * reporting can include legacy rows. Idempotent: rows that already carry
 * an `slaStatus.policyId` are left untouched.
 *
 * The request and klacht surfaces live in the unified `ticket` schema
 * (unify-ticket-supertype) and are narrowed with the `ticketType`
 * discriminator; callbacks keep their own `callback_schema`.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\Kernel\Contracts\SlaEngineServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\SlaSweepSourceInterface;
use OCA\Pipelinq\Service\Sla\SlaSweepRun;
use OCA\Pipelinq\Service\Sla\SlaSweepSurface;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step: bootstrap slaStatus on tracked objects.
 *
 * Walks every SLA-tracked surface (request + klacht tickets, callbacks),
 * picks rows without slaStatus, resolves the most-specific active policy,
 * computes deadlines and persists the snapshot. Logs every initialised
 * object via the `OCA\Pipelinq` logger.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */
class InitSlaStatus implements IRepairStep {
	private const BATCH_SIZE = 250;

	/**
	 * Constructor.
	 *
	 * @param SlaEngineServiceInterface $engine SLA engine.
	 * @param SlaSweepSourceInterface $source The register and the SLA-tracked surfaces.
	 * @param ContainerInterface $container DI container.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private SlaEngineServiceInterface $engine,
		private SlaSweepSourceInterface $source,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the repair step name.
	 *
	 * @return string Name.
	 *
	 * @spec exclude repair-step display name accessor, no behavioural spec surface.
	 */
	public function getName(): string {
		return 'Initialise SLA tracking (slaStatus) on existing tracked objects';
	}//end getName()

	/**
	 * Run the repair.
	 *
	 * @param IOutput $output Output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-ticket-supertype-schema
	 */
	public function run(IOutput $output): void {
		$register = $this->source->register();
		if ($register === '') {
			$output->info('InitSlaStatus: pipelinq register not configured, skipping');
			return;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			$output->warning('InitSlaStatus: OpenRegister ObjectService unavailable, skipping (' . $e->getMessage() . ')');
			return;
		}

		$run = new SlaSweepRun(
			register: $register,
			policies: [],
			now: new DateTimeImmutable('now', new DateTimeZone('UTC')),
			objectService: $objectService
		);
		$totals = ['initialised' => 0, 'skipped' => 0];
		foreach ($this->source->surfaces(run: $run) as $surface) {
			$counts = $this->initialiseSurface(surface: $surface);
			$totals['initialised'] += $counts['initialised'];
			$totals['skipped'] += $counts['skipped'];
		}

		$output->info(sprintf('InitSlaStatus: initialised=%d, skipped=%d', $totals['initialised'], $totals['skipped']));
		$this->logger->info('InitSlaStatus: completed', $totals);
	}//end run()

	/**
	 * Initialise every untracked object of one surface, page by page.
	 *
	 * Stops at the first failed read, and after 10000 rows.
	 *
	 * @param SlaSweepSurface $surface The surface.
	 *
	 * @return array{initialised: int, skipped: int}
	 */
	private function initialiseSurface(SlaSweepSurface $surface): array {
		$counts = ['initialised' => 0, 'skipped' => 0];
		$offset = 0;
		do {
			$rows = $this->readPage(surface: $surface, offset: $offset);
			$count = count($rows);
			foreach ($rows as $row) {
				$outcome = $this->initialiseCounted(row: $row, surface: $surface);
				$counts[$outcome]++;
			}

			$offset += self::BATCH_SIZE;
		} while ($count === self::BATCH_SIZE && $offset < 10000);

		return $counts;
	}//end initialiseSurface()

	/**
	 * One page of a surface's rows; empty when the read fails.
	 *
	 * @param SlaSweepSurface $surface The surface.
	 * @param int $offset The page offset.
	 *
	 * @return array<int, mixed>
	 */
	private function readPage(SlaSweepSurface $surface, int $offset): array {
		try {
			$rows = $surface->run->objectService->findAll(
				config: [
					'filters' => $surface->filters(),
					'limit' => self::BATCH_SIZE,
					'offset' => $offset,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'InitSlaStatus: findAll failed',
				['error' => $e->getMessage(), 'schema' => $surface->schemaId]
			);
			return [];
		}

		if (is_array($rows) === false) {
			$rows = iterator_to_array($rows);
		}

		return $rows;
	}//end readPage()

	/**
	 * Initialise one object, counting a failure as skipped.
	 *
	 * @param mixed $row OR row (entity or array).
	 * @param SlaSweepSurface $surface The surface it was read from.
	 *
	 * @return string `initialised` or `skipped`.
	 */
	private function initialiseCounted(mixed $row, SlaSweepSurface $surface): string {
		try {
			if ($this->initialiseObject(row: $row, surface: $surface) === true) {
				return 'initialised';
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'InitSlaStatus: per-object failed',
				['exceptionClass' => get_class($e), 'error' => $e->getMessage()]
			);
		}

		return 'skipped';
	}//end initialiseCounted()

	/**
	 * Initialise slaStatus on a single tracked object.
	 *
	 * Skips objects with no UUID, an already-initialised slaStatus, or no matching
	 * policy; otherwise writes the initial status and persists the object.
	 *
	 * @param mixed $row OR row (entity or array).
	 * @param SlaSweepSurface $surface The surface it was read from; its run carries
	 *   the register, the object service and the fallback start instant.
	 *
	 * @return bool True when a status was written, false when the object was skipped.
	 */
	private function initialiseObject(mixed $row, SlaSweepSurface $surface): bool {
		$data = $this->rowData(row: $row);
		$uuid = $this->rowUuid(row: $row, data: $data);
		if ($uuid === '') {
			return false;
		}

		if ($this->hasSlaStatus(data: $data) === true) {
			return false;
		}

		$metadata = [
			'tier' => $this->firstValue(data: $data, keys: ['slaTier', 'customerTier']),
			'organisationId' => $this->firstValue(data: $data, keys: ['organisationId', 'client']),
			'contractId' => $this->firstValue(data: $data, keys: ['contractId']),
		];

		$policy = $this->engine->resolvePolicyForObject($surface->type, $uuid, $metadata);
		if ($policy === null) {
			return false;
		}

		$startedAt = $this->parseStarted(data: $data) ?? $surface->run->now;
		$data['slaStatus'] = $this->engine->initialiseStatus($policy, $startedAt);

		$this->saveInitialised(data: $data, surface: $surface, uuid: $uuid);

		return true;
	}//end initialiseObject()

	/**
	 * The data of an OR row (entity or array).
	 *
	 * @param mixed $row OR row.
	 *
	 * @return array<string, mixed>
	 */
	private function rowData(mixed $row): array {
		$data = (array)$row;
		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			$data = $row->getObject();
		}

		return $data;
	}//end rowData()

	/**
	 * The UUID of an OR row: the entity's own, else the data's uuid or id.
	 *
	 * @param mixed                $row  OR row.
	 * @param array<string, mixed> $data The row's data.
	 *
	 * @return string
	 */
	private function rowUuid(mixed $row, array $data): string {
		$uuid = (string)($data['uuid'] ?? $data['id'] ?? '');
		if (is_object($row) === true && method_exists($row, 'getUuid') === true) {
			$uuid = (string)$row->getUuid();
		}

		return $uuid;
	}//end rowUuid()

	/**
	 * Whether the object already carries an initialised slaStatus.
	 *
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return bool
	 */
	private function hasSlaStatus(array $data): bool {
		$slaStatus = $data['slaStatus'] ?? null;
		return is_array($slaStatus) === true && ($slaStatus['policyId'] ?? '') !== '';
	}//end hasSlaStatus()

	/**
	 * The first of the keys that holds a non-null value, as a string ('' when none does).
	 *
	 * @param array<string, mixed> $data The object data.
	 * @param array<int, string>   $keys The keys, in order of preference.
	 *
	 * @return string
	 */
	private function firstValue(array $data, array $keys): string {
		foreach ($keys as $key) {
			if (isset($data[$key]) === true) {
				return (string)$data[$key];
			}
		}

		return '';
	}//end firstValue()

	/**
	 * Persist an object with its initialised slaStatus as the system.
	 *
	 * @param array<string, mixed> $data    The object data.
	 * @param SlaSweepSurface      $surface The surface it was read from.
	 * @param string               $uuid    The object UUID.
	 *
	 * @return void
	 */
	private function saveInitialised(array $data, SlaSweepSurface $surface, string $uuid): void {
		$objectService = $surface->run->objectService;
		$this->execAsSystem(
			objectService: $objectService,
			operation: static fn () => $objectService->saveObject(
				object: $data,
				extend: [],
				register: $surface->run->register,
				schema: $surface->schemaId,
				uuid: $uuid,
			)
		);
	}//end saveInitialised()

	/**
	 * Execute an OpenRegister write under the scoped system-operation context.
	 *
	 * Repair steps run without a user session (web-triggered upgrades, webcron),
	 * so OpenRegister RBAC denies every write as 'Anonymous'
	 * (NotAuthorizedException). ObjectService::runAsSystem scopes trusted-system
	 * elevation to the callable; older OpenRegister versions without it fall
	 * back to the direct call.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param callable $operation The write operation to execute.
	 *
	 * @return mixed Whatever the operation returns.
	 *
	 * @spec exclude system-context adoption — back-compat elevation shim around OR writes, no behavioural spec surface.
	 */
	private function execAsSystem(object $objectService, callable $operation): mixed {
		if (method_exists($objectService, 'runAsSystem') === true) {
			return $objectService->runAsSystem($operation);
		}

		return $operation();
	}//end execAsSystem()

	/**
	 * Parse the object's created/started timestamp.
	 *
	 * @param array<string, mixed> $data Row data.
	 *
	 * @return ?DateTimeImmutable Start instant, or null on missing/invalid.
	 */
	private function parseStarted(array $data): ?DateTimeImmutable {
		foreach (['createdAt', 'created', '@self.createdAt', 'opened_at'] as $key) {
			if (isset($data[$key]) === false) {
				continue;
			}

			try {
				return new DateTimeImmutable((string)$data[$key]);
			} catch (Throwable $e) {
				continue;
			}
		}

		return null;
	}//end parseStarted()
}//end class
