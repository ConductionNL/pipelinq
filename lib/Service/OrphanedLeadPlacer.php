<?php

/**
 * Pipelinq OrphanedLeadPlacer.
 *
 * Decides where a lead goes when its pipeline no longer exists (pipelinq
 * review F1). Pure: it reads arrays and returns the fields to change. Kept
 * beside LeadStagePlacer, whose default pipeline and first open stage it
 * reuses, so neither class grows past its complexity budget.
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
 * @spec openspec/changes/review-audit-fixes-b/specs/lead-management/spec.md#requirement-a-repair-step-moves-leads-off-a-deleted-pipeline-req-raf-011
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Places a lead whose pipeline was deleted.
 *
 * @spec openspec/changes/review-audit-fixes-b/specs/lead-management/spec.md#requirement-a-repair-step-moves-leads-off-a-deleted-pipeline-req-raf-011
 */
final class OrphanedLeadPlacer {
	/**
	 * Constructor.
	 *
	 * @param LeadStagePlacer $placer Picks the default pipeline and its first open stage.
	 */
	public function __construct(
		private readonly LeadStagePlacer $placer = new LeadStagePlacer(),
	) {
	}//end __construct()

	/**
	 * The fields that move a lead off a pipeline that no longer exists.
	 *
	 * A lead whose pipeline id points at nothing is on no board, yet counts
	 * in the open pipeline and the forecast (pipelinq review F1). It goes to
	 * the default lead pipeline: an open lead to its first open stage, a won
	 * lead to its won stage and a lost lead to its closed, not won stage, so
	 * a closed deal does not reopen. A stage the target lacks falls back to
	 * the first open stage. A lead without a pipeline, or on a pipeline that
	 * exists, is not this method's business and gets [].
	 *
	 * @param array<string, mixed>             $lead      The stored lead.
	 * @param array<int, array<string, mixed>> $pipelines Stored pipelines.
	 * @param string|null                      $now       ISO 8601 entry time; null means now.
	 *
	 * @return array<string, mixed> The fields to merge into the lead; [] when nothing applies.
	 *
	 * @spec openspec/changes/review-audit-fixes-b/specs/lead-management/spec.md#requirement-a-repair-step-moves-leads-off-a-deleted-pipeline-req-raf-011
	 */
	public function forOrphanedLead(array $lead, array $pipelines, ?string $now = null): array {
		$pipelineId = trim((string)($lead['pipeline'] ?? ''));
		if ($pipelineId === '') {
			return [];
		}

		foreach ($pipelines as $pipeline) {
			if ((string)($pipeline['id'] ?? '') === $pipelineId) {
				return [];
			}
		}

		$target = $this->placer->defaultPipeline(pipelines: $pipelines);
		if ($target === null) {
			return [];
		}

		$stage = $this->closedStageFor(pipeline: $target, status: (string)($lead['status'] ?? ''));
		$stage ??= $this->placer->firstOpenStage(pipeline: $target);

		$patch = ['pipeline' => (string)$target['id']];
		if ($stage !== null) {
			$patch['stage'] = $stage['name'];
			$patch['stageOrder'] = $stage['order'];
			$patch['stageEnteredAt'] = $this->moment(iso: $now);
		}

		return $patch;
	}//end forOrphanedLead()

	/**
	 * The closed stage a won or lost lead belongs in, by stage order.
	 *
	 * @param array<string, mixed> $pipeline The pipeline.
	 * @param string               $status   The lead status.
	 *
	 * @return array{name: string, order: int}|null The stage, or null for an open lead or no such stage.
	 */
	private function closedStageFor(array $pipeline, string $status): ?array {
		if ($status !== 'won' && $status !== 'lost') {
			return null;
		}

		$wantWon = ($status === 'won');
		$stages = array_values(array_filter(
			(array)($pipeline['stages'] ?? []),
			static fn ($stage): bool => is_array($stage) === true
				&& trim((string)($stage['name'] ?? '')) !== ''
				&& ($stage['isClosed'] ?? false) === true
				&& (($stage['isWon'] ?? false) === true) === $wantWon
		));
		if ($stages === []) {
			return null;
		}

		usort(
			$stages,
			static fn (array $left, array $right): int => ((int)($left['order'] ?? 0) <=> (int)($right['order'] ?? 0))
		);

		return [
			'name' => (string)$stages[0]['name'],
			'order' => (int)($stages[0]['order'] ?? 1),
		];
	}//end closedStageFor()

	/**
	 * An ISO 8601 moment: the given one, or now.
	 *
	 * @param string|null $iso The moment, or null for now.
	 *
	 * @return string The moment.
	 */
	private function moment(?string $iso): string {
		if ($iso !== null && trim($iso) !== '') {
			return $iso;
		}

		return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
	}//end moment()
}//end class
