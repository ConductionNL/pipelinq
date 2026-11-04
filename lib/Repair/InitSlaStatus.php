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
use OCA\Pipelinq\Service\Sla\SlaSweepRun;
use OCA\Pipelinq\Service\Sla\SlaSweepSource;
use OCA\Pipelinq\Service\Sla\SlaSweepSurface;
use OCA\Pipelinq\Service\SlaEngineService;
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
	 * @param SlaEngineService $engine SLA engine.
	 * @param SlaSweepSource $source The register and the SLA-tracked surfaces.
	 * @param ContainerInterface $container DI container.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private SlaEngineService $engine,
		private SlaSweepSource $source,
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
	 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-ticket-supertype-schema
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
		$data = (array)$row;
		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			$data = $row->getObject();
		}

		$uuid = (string)($data['uuid'] ?? $data['id'] ?? '');
		if (is_object($row) === true && method_exists($row, 'getUuid') === true) {
			$uuid = (string)$row->getUuid();
		}

		if ($uuid === '') {
			return false;
		}

		$slaStatus = $data['slaStatus'] ?? null;
		if (is_array($slaStatus) === true && ($slaStatus['policyId'] ?? '') !== '') {
			return false;
		}

		$metadata = [
			'tier' => (string)($data['slaTier'] ?? $data['customerTier'] ?? ''),
			'organisationId' => (string)($data['organisationId'] ?? $data['client'] ?? ''),
			'contractId' => (string)($data['contractId'] ?? ''),
		];

		$policy = $this->engine->resolvePolicyForObject($surface->type, $uuid, $metadata);
		if ($policy === null) {
			return false;
		}

		$startedAt = $this->parseStarted(data: $data) ?? $surface->run->now;
		$data['slaStatus'] = $this->engine->initialiseStatus($policy, $startedAt);

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

		return true;
	}//end initialiseObject()

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
