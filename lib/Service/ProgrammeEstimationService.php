<?php

/**
 * Pipelinq ProgrammeEstimationService.
 *
 * The estimation scale, the per-role estimates, and the cycle that snapshots
 * what it showed.
 *
 * ONE ACTIVE SCALE PER SCOPE. Two active ladders in one scope means a 5 is two
 * different amounts of work depending on who wrote it, and nothing in the
 * numbers says so. The second activation is refused.
 *
 * A RETIRED SCALE STILL RESOLVES. An estimate records the scale it was written
 * on, so a figure from last year keeps its meaning after the ladder changes.
 *
 * THE TOTAL IS DERIVED, NEVER STORED. A bezwaar costs juridisch time and
 * vakafdeling time, and today both are one number. Storing the total means it
 * drifts from its parts the first time one changes, silently.
 *
 * A CLOSED CYCLE READS ITS SNAPSHOT. March's burndown has to still exist in
 * April, whatever anybody edits underneath it afterwards. Each close appends
 * its own version rather than overwriting, so closing, reopening and closing
 * again leaves two snapshots and loses neither.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/the-project-above-the-cases/specs/project-portfolio/spec.md#requirement-an-estimation-scale-shall-be-administered-with-one-active-set-per-scope-req-prj-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Scales, per-role estimates and cycle snapshots.
 *
 * @spec openspec/changes/the-project-above-the-cases/specs/project-portfolio/spec.md#requirement-a-work-item-shall-be-estimable-per-role-with-a-derived-total-req-prj-005
 */
class ProgrammeEstimationService {
	/**
	 * Constructor.
	 *
	 * @param ProgrammePortfolioService $portfolio Reads and writes the programme schemas.
	 * @param IUserSession $userSession Who closed a cycle.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly ProgrammePortfolioService $portfolio,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a scale may be activated in its scope.
	 *
	 * @param array<string, mixed> $scale The scale being activated.
	 * @param array<int, array<string, mixed>> $existingScales Every scale already declared.
	 *
	 * @return array{allowed: bool, reason: string} The answer.
	 *
	 * @spec openspec/changes/the-project-above-the-cases/specs/project-portfolio/spec.md#requirement-an-estimation-scale-shall-be-administered-with-one-active-set-per-scope-req-prj-004
	 */
	public function mayActivate(array $scale, array $existingScales): array {
		$scope = trim((string)($scale['scope'] ?? ''));
		if ($scope === '') {
			return ['allowed' => false, 'reason' => 'A scale needs a scope.'];
		}

		$id = trim((string)($scale['id'] ?? $scale['uuid'] ?? ''));

		foreach ($existingScales as $existing) {
			if (($existing['active'] ?? false) !== true) {
				continue;
			}

			if (trim((string)($existing['scope'] ?? '')) !== $scope) {
				continue;
			}

			$existingId = trim((string)($existing['id'] ?? $existing['uuid'] ?? ''));
			if ($existingId !== '' && $existingId === $id) {
				continue;
			}

			$name = (string)($existing['name'] ?? $existingId);

			return [
				'allowed' => false,
				'reason' => "Scope {$scope} already has an active scale: {$name}.",
			];
		}

		return ['allowed' => true, 'reason' => ''];
	}//end mayActivate()

	/**
	 * The weight of one point on one scale.
	 *
	 * Resolves on an INACTIVE scale too: an estimate written last year keeps
	 * its meaning after the ladder is retired.
	 *
	 * @param array<string, mixed> $scale The scale the estimate was written on.
	 * @param string $point The point's label.
	 *
	 * @return float|null The weight, or null when the scale does not carry it.
	 */
	public function weightOf(array $scale, string $point): ?float {
		foreach (($scale['points'] ?? []) as $declared) {
			if ((string)($declared['label'] ?? '') === trim($point)) {
				return (float)($declared['weight'] ?? 0);
			}
		}

		return null;
	}//end weightOf()

	/**
	 * What one work item is estimated at, per role and in total.
	 *
	 * The total is computed here, on read, and stored nowhere.
	 *
	 * @param array<int, array<string, mixed>> $estimates The item's estimates.
	 * @param array<string, array<string, mixed>> $scales The scales, keyed by id.
	 *
	 * @return array{perRole: array<string, float>, total: float, unresolved: array<int, string>}
	 *   The figures.
	 *
	 * @spec openspec/changes/the-project-above-the-cases/specs/project-portfolio/spec.md#requirement-a-work-item-shall-be-estimable-per-role-with-a-derived-total-req-prj-005
	 */
	public function totalFor(array $estimates, array $scales): array {
		$perRole = [];
		$unresolved = [];

		foreach ($estimates as $estimate) {
			$role = trim((string)($estimate['role'] ?? ''));
			if ($role === '') {
				continue;
			}

			$scale = ($scales[trim((string)($estimate['scale'] ?? ''))] ?? []);
			$weight = $this->weightOf(scale: $scale, point: (string)($estimate['point'] ?? ''));

			if ($weight === null) {
				// Reported rather than counted as zero: an estimate whose
				// point nobody can resolve is a question, not a nil.
				$unresolved[] = $role;
				continue;
			}

			// One estimate per role: a second one for the same role replaces
			// rather than adds, so the total cannot double-count a revision.
			$perRole[$role] = $weight;
		}

		return [
			'perRole' => $perRole,
			'total' => array_sum($perRole),
			'unresolved' => $unresolved,
		];
	}//end totalFor()

	/**
	 * The snapshot a close writes, given the cycle as it stands.
	 *
	 * @param array<string, mixed> $cycle The cycle.
	 * @param int $done Items finished.
	 * @param int $total Items in the cycle.
	 * @param DateTimeImmutable|null $now The moment of the close.
	 *
	 * @return array<string, mixed> The snapshot.
	 *
	 * @spec openspec/changes/the-project-above-the-cases/specs/project-portfolio/spec.md#requirement-a-cycle-shall-snapshot-its-progress-when-it-closes-req-prj-006
	 */
	public function snapshotFor(array $cycle, int $done, int $total, ?DateTimeImmutable $now = null): array {
		$now = ($now ?? new DateTimeImmutable());
		$existing = ($cycle['snapshots'] ?? []);
		if (is_array($existing) === false) {
			$existing = [];
		}

		return [
			'version' => (count($existing) + 1),
			'closedAt' => $now->format(DateTimeInterface::ATOM),
			'closedBy' => ($this->userSession->getUser()?->getUID() ?? ''),
			'done' => $done,
			'total' => $total,
		];
	}//end snapshotFor()

	/**
	 * Close a cycle, writing its snapshot.
	 *
	 * @param array<string, mixed> $cycle The cycle.
	 * @param array<int, array<string, mixed>> $tasks The tasks in it.
	 *
	 * @return array<string, mixed> `status` plus either `cycle` or `error`.
	 *
	 * @spec openspec/changes/the-project-above-the-cases/specs/project-portfolio/spec.md#requirement-a-cycle-shall-snapshot-its-progress-when-it-closes-req-prj-006
	 */
	public function closeCycle(array $cycle, array $tasks): array {
		$done = count(
			array_filter($tasks, static fn (array $task): bool => ($task['status'] ?? '') === 'closed')
		);

		$snapshot = $this->snapshotFor(cycle: $cycle, done: $done, total: count($tasks));

		$snapshots = ($cycle['snapshots'] ?? []);
		if (is_array($snapshots) === false) {
			$snapshots = [];
		}

		// APPENDED. Overwriting would mean closing, reopening and closing
		// again leaves one snapshot, and the first chart is gone.
		$snapshots[] = $snapshot;

		$cycle['snapshots'] = $snapshots;
		$cycle['status'] = 'closed';

		$saved = $this->portfolio->write(
			schemaKey: 'programmeCycle_schema',
			object: $cycle,
			uuid: (string)($cycle['id'] ?? $cycle['uuid'] ?? ''),
		);

		if ($saved === false) {
			return ['status' => 500, 'error' => 'The cycle could not be closed.'];
		}

		return ['status' => 200, 'cycle' => $cycle];
	}//end closeCycle()

	/**
	 * What a cycle's chart should read, and what it is live.
	 *
	 * A closed cycle's chart reads its LAST snapshot; the live figures sit
	 * beside it rather than replacing it, so both questions can be asked.
	 *
	 * @param array<string, mixed> $cycle The cycle.
	 * @param array<int, array<string, mixed>> $tasks The tasks in it, now.
	 *
	 * @return array{chart: array<string, mixed>, live: array<string, int>, source: string}
	 *   The figures.
	 *
	 * @spec openspec/changes/the-project-above-the-cases/specs/project-portfolio/spec.md#requirement-a-cycle-shall-snapshot-its-progress-when-it-closes-req-prj-006
	 */
	public function chartFor(array $cycle, array $tasks): array {
		$live = [
			'done' => count(
				array_filter($tasks, static fn (array $task): bool => ($task['status'] ?? '') === 'closed')
			),
			'total' => count($tasks),
		];

		$snapshots = ($cycle['snapshots'] ?? []);
		if (is_array($snapshots) === false) {
			$snapshots = [];
		}

		if ((string)($cycle['status'] ?? '') !== 'closed' || $snapshots === []) {
			return ['chart' => $live, 'live' => $live, 'source' => 'live'];
		}

		return [
			'chart' => end($snapshots),
			'live' => $live,
			'source' => 'snapshot',
		];
	}//end chartFor()

	/**
	 * Carry the unfinished work of one cycle into the next, in one act.
	 *
	 * @param string $targetCycleId The cycle the work moves into.
	 * @param array<int, array<string, mixed>> $tasks The source cycle's tasks.
	 *
	 * @return array<string, mixed> `status`, the tasks moved and a per-item trail.
	 *
	 * @spec openspec/changes/the-project-above-the-cases/specs/project-portfolio/spec.md#requirement-a-cycle-shall-snapshot-its-progress-when-it-closes-req-prj-006
	 */
	public function carryOver(string $targetCycleId, array $tasks): array {
		$targetCycleId = trim($targetCycleId);
		if ($targetCycleId === '') {
			return ['status' => 400, 'error' => 'A target cycle is required.'];
		}

		$moved = [];
		foreach ($tasks as $task) {
			if (($task['status'] ?? '') === 'closed') {
				// Finished work stays where it was finished, or the closed
				// cycle's own chart stops matching its snapshot.
				continue;
			}

			$sourceCycle = (string)($task['cycle'] ?? '');
			$task['cycle'] = $targetCycleId;

			$written = $this->portfolio->write(
				schemaKey: 'programmeTask_schema',
				object: $task,
				uuid: (string)($task['id'] ?? $task['uuid'] ?? ''),
			);

			if ($written === false) {
				$this->logger->error(
					'ProgrammeEstimationService: a task could not be carried over',
					['task' => ($task['id'] ?? '')]
				);
				continue;
			}

			$moved[] = [
				'task' => (string)($task['id'] ?? $task['uuid'] ?? ''),
				'from' => $sourceCycle,
				'to' => $targetCycleId,
			];
		}

		return ['status' => 200, 'moved' => count($moved), 'trail' => $moved];
	}//end carryOver()
}//end class
