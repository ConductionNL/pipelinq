<?php

/**
 * Pipelinq BreachTally.
 *
 * The running breach counts of one SLA attainment computation: the totals,
 * the in-flight and closed split, and the per-target and per-group counts,
 * with the grouping context the computation built.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * Breach counts in progress; built by SlaAttainmentService, filled event by event.
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */
final class BreachTally {
	/**
	 * Events counted.
	 *
	 * @var integer
	 */
	public int $total = 0;

	/**
	 * Events breached.
	 *
	 * @var integer
	 */
	public int $breached = 0;

	/**
	 * Breaches not yet resolved.
	 *
	 * @var integer
	 */
	public int $inFlight = 0;

	/**
	 * Breaches resolved.
	 *
	 * @var integer
	 */
	public int $closed = 0;

	/**
	 * Breached/met counts per target kind.
	 *
	 * @var array<string, array<string, int>>
	 */
	public array $byTarget = [];

	/**
	 * Name, total and breached counts per group key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $groupAccum = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $context  The grouping context of the computation (its tracked objects).
	 * @param SlaGrouping          $grouping The grouping rules of the computation.
	 */
	public function __construct(
		private readonly array $context,
		private readonly SlaGrouping $grouping,
	) {
	}//end __construct()

	/**
	 * Count one breach event: in flight or closed, per target and per group.
	 *
	 * @param array<string, mixed> $event The breach event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function add(array $event): void {
		$this->total++;
		$kind = (string)($event['targetKind'] ?? 'resolution');
		$resolved = isset($event['resolvedAt']) === true && $event['resolvedAt'] !== '';
		if ($resolved === true) {
			$this->closed++;
		}

		if ($resolved === false) {
			$this->inFlight++;
		}

		$this->breached++;
		$this->byTarget[$kind] = ($this->byTarget[$kind] ?? ['breached' => 0, 'met' => 0]);
		$this->byTarget[$kind]['breached']++;

		// The object the breach belongs to supplies its tier, team and customer.
		$tracked = ($this->context['tracked'][(string)($event['targetObjectId'] ?? '')] ?? []);
		$this->addToGroups(subject: ['policyId' => (string)($event['policyId'] ?? ''), 'kinds' => [$kind], 'tracked' => $tracked, 'event' => $event]);
	}//end add()

	/**
	 * Count one breach in every group its subject belongs to.
	 *
	 * @param array<string, mixed> $subject The breach's policy, kinds, tracked object and event.
	 *
	 * @return void
	 */
	private function addToGroups(array $subject): void {
		foreach ($this->grouping->keysFor(subject: $subject) as $groupKey) {
			$groupName = $this->grouping->nameOf(key: $groupKey);
			$this->groupAccum[$groupKey] = ($this->groupAccum[$groupKey] ?? ['name' => $groupName, 'total' => 0, 'breached' => 0]);
			$this->groupAccum[$groupKey]['total']++;
			$this->groupAccum[$groupKey]['breached']++;
		}
	}//end addToGroups()

	/**
	 * The counts as the computation reads them.
	 *
	 * @return array{total: int, breached: int, inFlight: int, closed: int, byTarget: array<string, mixed>, groupAccum: array<string, mixed>}
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function summary(): array {
		return [
			'total' => $this->total,
			'breached' => $this->breached,
			'inFlight' => $this->inFlight,
			'closed' => $this->closed,
			'byTarget' => $this->byTarget,
			'groupAccum' => $this->groupAccum,
		];
	}//end summary()
}//end class
