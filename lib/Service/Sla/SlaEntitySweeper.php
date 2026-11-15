<?php

/**
 * Pipelinq SlaEntitySweeper.
 *
 * Walks the tracked surfaces of an SLA sweep and escalates each object.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Sla
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Sla;

use DateTimeInterface;
use OCA\Pipelinq\Kernel\Contracts\SlaEntitySweeperInterface;
use OCA\Pipelinq\Service\ComplaintSlaService;
use OCA\Pipelinq\Service\SlaEngineService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Walks every tracked surface in batches, re-evaluates each object's SLA
 * targets and fires escalation chains whose thresholds are newly crossed.
 *
 * Idempotent: the per-object `currentEscalationLevel` prevents duplicate
 * firings across runs.
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 * @spec openspec/specs/klachtenregistratie/spec.md#Background-Job-for-SLA-Monitoring
 */
class SlaEntitySweeper implements SlaEntitySweeperInterface {
	use MergesBreachEvents;

	private const BATCH_SIZE = 100;

	/**
	 * At most this many objects per surface per run.
	 */
	private const MAX_OBJECTS = 5000;

	/**
	 * Constructor.
	 *
	 * @param SlaEngineService    $engine       SLA engine.
	 * @param ComplaintSlaService $complaintSla Per-category complaint SLA service.
	 * @param LoggerInterface     $logger       PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private SlaEngineService $engine,
		private ComplaintSlaService $complaintSla,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The active policies by identity.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function indexPolicies(): array {
		$index = [];
		foreach ($this->engine->loadActivePolicies() as $policy) {
			$key = $this->engine->policyIdentity($policy);
			if ($key !== '') {
				$index[$key] = $policy;
			}
		}

		return $index;
	}//end indexPolicies()

	/**
	 * Sweep every surface and add up the counters.
	 *
	 * @param array<int, SlaSweepSurface> $surfaces The surfaces.
	 *
	 * @return array{processed: int, escalated: int}
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function sweepAll(array $surfaces): array {
		$totals = ['processed' => 0, 'escalated' => 0];
		foreach ($surfaces as $surface) {
			$counts               = $this->sweepSurface(surface: $surface);
			$totals['processed'] += $counts['processed'];
			$totals['escalated'] += $counts['escalated'];
		}

		return $totals;
	}//end sweepAll()

	/**
	 * Sweep one surface in batches.
	 *
	 * @param SlaSweepSurface $surface The surface.
	 *
	 * @return array{processed: int, escalated: int}
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	private function sweepSurface(SlaSweepSurface $surface): array {
		$totals = ['processed' => 0, 'escalated' => 0];
		$offset = 0;
		do {
			$rows  = $this->fetchBatch(surface: $surface, offset: $offset);
			$count = count($rows);
			if ($count === 0) {
				break;
			}

			$totals['processed'] += $count;
			$totals['escalated'] += $this->processBatch(surface: $surface, rows: $rows);
			$offset += self::BATCH_SIZE;
		} while ($count === self::BATCH_SIZE && $offset < self::MAX_OBJECTS);

		return $totals;
	}//end sweepSurface()

	/**
	 * One page of a surface's objects; empty when the read fails.
	 *
	 * @param SlaSweepSurface $surface The surface.
	 * @param int             $offset  The page offset.
	 *
	 * @return array<int|string, mixed>
	 */
	private function fetchBatch(SlaSweepSurface $surface, int $offset): array {
		$rows = $this->findRows(surface: $surface, offset: $offset);
		if (is_array($rows) === false) {
			$rows = iterator_to_array($rows);
		}

		return $rows;
	}//end fetchBatch()

	/**
	 * What findAll() answers for one page; an empty page when it throws.
	 *
	 * @param SlaSweepSurface $surface The surface.
	 * @param int             $offset  The page offset.
	 *
	 * @return mixed
	 */
	private function findRows(SlaSweepSurface $surface, int $offset): mixed {
		$config = ['filters' => $surface->filters(), 'limit' => self::BATCH_SIZE, 'offset' => $offset];
		try {
			return $surface->run->objectService->findAll(config: $config);
		} catch (Throwable $e) {
			$this->logger->warning('SlaDeadlineSweepJob: findAll failed', ['error' => $e->getMessage(), 'schema' => $surface->schemaId]);
			return [];
		}
	}//end findRows()

	/**
	 * Process a page of rows.
	 *
	 * @param SlaSweepSurface          $surface The surface.
	 * @param array<int|string, mixed> $rows    The rows.
	 *
	 * @return int How many rows escalated.
	 */
	private function processBatch(SlaSweepSurface $surface, array $rows): int {
		$escalated = 0;
		foreach ($rows as $entity) {
			if ($this->processEntity(surface: $surface, entity: $entity) === true) {
				$escalated++;
			}
		}

		return $escalated;
	}//end processBatch()

	/**
	 * Process one row; a failure is logged and counts as not escalated.
	 *
	 * @param SlaSweepSurface $surface The surface.
	 * @param mixed           $entity  Object entity or array.
	 *
	 * @return bool True when an escalation fired.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 * @spec openspec/specs/klachtenregistratie/spec.md#Background-Job-for-SLA-Monitoring
	 */
	private function processEntity(SlaSweepSurface $surface, mixed $entity): bool {
		try {
			return $this->escalateEntity(surface: $surface, entity: $entity);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SlaDeadlineSweepJob: per-object processing failed',
				['error' => $e->getMessage()]
			);
			return false;
		}
	}//end processEntity()

	/**
	 * Check a row's complaint deadline, then apply its SLA policy.
	 *
	 * Complaints carry a category-derived `slaDeadline` rather than a policy
	 * `slaStatus` envelope, so they are checked even when no policy applies.
	 *
	 * @param SlaSweepSurface $surface The surface.
	 * @param mixed           $entity  Object entity or array.
	 *
	 * @return bool True when an escalation fired.
	 *
	 * @spec openspec/specs/klachtenregistratie/spec.md#Background-Job-for-SLA-Monitoring
	 */
	private function escalateEntity(SlaSweepSurface $surface, mixed $entity): bool {
		// A locked (frozen) record refuses every write. Escalating it would
		// notify, then fail to record the new level, and notify again on
		// every later sweep; so it is skipped and left as it is.
		if (self::isFrozen(entity: $entity) === true) {
			$this->logger->info(
				'SlaDeadlineSweepJob: skipped a locked record',
				['schema' => $surface->schemaId]
			);
			return false;
		}

		$object = $this->sweptObject(entity: $entity);
		if ($surface->type === 'complaint') {
			$this->checkComplaintDeadline(object: $object, now: $surface->run->now);
		}

		return $this->applyPolicy(surface: $surface, object: $object);
	}//end escalateEntity()

	/**
	 * Whether a swept row is a locked (frozen) record: an object whose
	 * `isFrozen()` says so, or a row whose `@self.frozen` holds the lock.
	 *
	 * @param mixed $entity The swept row.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/platform-record-lock/specs/record-locking/spec.md#requirement-a-user-can-lock-a-client-a-finished-lead-or-a-finished-ticket-req-prl-001
	 */
	private static function isFrozen(mixed $entity): bool {
		if (is_object($entity) === true && method_exists($entity, 'isFrozen') === true) {
			return $entity->isFrozen() === true;
		}

		$frozen = ((array) $entity)['@self']['frozen'] ?? null;
		return is_array($frozen) === true && $frozen !== [];
	}//end isFrozen()

	/**
	 * Read an OpenRegister entity or array row.
	 *
	 * @param mixed $entity Object entity or array.
	 *
	 * @return SlaSweptObject
	 */
	private function sweptObject(mixed $entity): SlaSweptObject {
		$data = (array) $entity;
		if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {
			$data = $entity->getObject();
		}

		if (is_object($entity) === true && method_exists($entity, 'getUuid') === true) {
			return new SlaSweptObject(data: $data, uuid: (string) $entity->getUuid());
		}

		return new SlaSweptObject(data: $data, uuid: (string) ($data['uuid'] ?? $data['id'] ?? ''));
	}//end sweptObject()

	/**
	 * Re-evaluate an object's targets under its policy, then escalate.
	 *
	 * @param SlaSweepSurface $surface The surface.
	 * @param SlaSweptObject  $object  The object.
	 *
	 * @return bool True when an escalation fired.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	private function applyPolicy(SlaSweepSurface $surface, SlaSweptObject $object): bool {
		$slaStatus = $object->activeSlaStatus();
		if ($slaStatus === null) {
			return false;
		}

		$policy = $surface->run->policyFor(slaStatus: $slaStatus);
		if ($policy === null) {
			return false;
		}

		$slaStatus['targets'] = $this->engine->evaluateTargets($slaStatus['targets'] ?? [], $policy, $surface->run->now);
		return $this->escalate(surface: $surface, object: $object->withSlaStatus(slaStatus: $slaStatus));
	}//end applyPolicy()

	/**
	 * Fire newly crossed escalations for an object whose targets are
	 * evaluated, and store the updated envelope.
	 *
	 * @param SlaSweepSurface $surface The surface.
	 * @param SlaSweptObject  $object  An object with evaluated targets.
	 *
	 * @return bool True when an escalation fired.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	private function escalate(SlaSweepSurface $surface, SlaSweptObject $object): bool {
		$slaStatus     = (array) $object->activeSlaStatus();
		$policy        = (array) $surface->run->policyFor(slaStatus: $slaStatus);
		$previousLevel = (int) ($slaStatus['currentEscalationLevel'] ?? 0);
		$targets       = $slaStatus['targets'];
		$result        = $this->engine->executeEscalations($policy, $surface->type, $object->uuid, $targets, $targets, $previousLevel);
		$escalated     = ($result['level'] > $previousLevel);
		if ($escalated === true) {
			$slaStatus = $this->mergeEscalation(slaStatus: $slaStatus, result: $result);
		}

		$slaStatus['lastEvaluatedAt'] = $surface->run->now->format(DateTimeInterface::ATOM);
		$this->save(surface: $surface, object: $object->withSlaStatus(slaStatus: $slaStatus));
		return $escalated;
	}//end escalate()

	/**
	 * Record a fired escalation in the slaStatus envelope.
	 *
	 * @param array<string, mixed> $slaStatus Current slaStatus.
	 * @param array<string, mixed> $result    executeEscalations() result.
	 *
	 * @return array<string, mixed> Updated slaStatus.
	 */
	private function mergeEscalation(array $slaStatus, array $result): array {
		$slaStatus['currentEscalationLevel'] = $result['level'];
		$slaStatus['targets']                = $this->withBreachEvents(targets: $slaStatus['targets'], eventIds: $result['eventIds']);
		return $slaStatus;
	}//end mergeEscalation()

	/**
	 * Store an object that has a UUID.
	 *
	 * @param SlaSweepSurface $surface The surface.
	 * @param SlaSweptObject  $object  The object.
	 *
	 * @return void
	 */
	private function save(SlaSweepSurface $surface, SlaSweptObject $object): void {
		if ($object->uuid === '') {
			return;
		}

		$surface->run->objectService->saveObject(
			object: $object->data,
			extend: [],
			register: $surface->run->register,
			schema: $surface->schemaId,
			uuid: $object->uuid,
		);
	}//end save()

	/**
	 * Log a warning when a complaint is past its per-category SLA deadline.
	 *
	 * The check is read-only; the deadline itself is computed when the
	 * complaint is created, from `complaint_sla_{category}`.
	 *
	 * @param SlaSweptObject    $object The complaint.
	 * @param DateTimeInterface $now    The reference instant.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/klachtenregistratie/spec.md#Backend-SLA-Deadline-Service
	 * @spec openspec/specs/klachtenregistratie/spec.md#Background-Job-for-SLA-Monitoring
	 */
	private function checkComplaintDeadline(SlaSweptObject $object, DateTimeInterface $now): void {
		if ($this->complaintSla->isOverdue(complaint: $object->data, now: $now) === false) {
			return;
		}

		$this->logger->warning(
			'SlaDeadlineSweepJob: complaint past its SLA deadline',
			[
				'uuid'        => $object->uuid,
				'category'    => (string) ($object->data['complaintCategory'] ?? ''),
				'status'      => (string) ($object->data['status'] ?? ''),
				'slaDeadline' => (string) ($object->data['slaDeadline'] ?? ''),
			]
		);
	}//end checkComplaintDeadline()
}//end class
