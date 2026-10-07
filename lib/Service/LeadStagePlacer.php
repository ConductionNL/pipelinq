<?php

/**
 * Pipelinq LeadStagePlacer.
 *
 * Every lead sits in a pipeline stage. A lead without a stage is invisible on
 * the pipeline board and in every per-stage figure, and nothing tells the user
 * it is missing (pipelinq review F1). The lead form only set a stage when a
 * default pipeline existed, the enquiry flow and the relation backfill set a
 * pipeline without a stage, and the API accepted a lead with neither.
 *
 * This class decides where a lead belongs: its own pipeline, or the default
 * lead pipeline, and in it the first stage that is not closed. It is pure: it
 * reads arrays and returns the fields to add. The creating listener applies it
 * on every new lead, and the BackfillLeadRelations repair step applies it to
 * the leads already stored.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/pipeline-numbers-tell-the-truth/specs/lead-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Places a lead in its pipeline's first open stage.
 *
 * @spec openspec/specs/lead-management/spec.md
 */
final class LeadStagePlacer {

	/**
	 * The logical slug a lead pipeline is scoped to.
	 *
	 * @var string
	 */
	private const LEAD_SLUG = 'lead';

	/**
	 * Pick the pipeline a lead without one goes on.
	 *
	 * Only pipelines that apply to leads count. A pipeline marked default wins;
	 * otherwise the first one. Any pipeline beats none: a lead on the wrong
	 * pipeline is visible and can be moved, a lead on none cannot be seen.
	 *
	 * @param array<int, array<string, mixed>> $pipelines Stored pipelines.
	 *
	 * @return array<string, mixed>|null The pipeline, or null when none applies.
	 *
	 * @spec openspec/changes/pipeline-numbers-tell-the-truth/specs/lead-management/spec.md
	 */
	public function defaultPipeline(array $pipelines): ?array {
		$fallback = null;
		foreach ($pipelines as $pipeline) {
			if (trim((string)($pipeline['id'] ?? '')) === '' || $this->appliesToLeads(pipeline: $pipeline) === false) {
				continue;
			}

			if (($pipeline['isDefault'] ?? false) === true) {
				return $pipeline;
			}

			$fallback ??= $pipeline;
		}

		return $fallback;
	}//end defaultPipeline()

	/**
	 * The first stage of a pipeline that is not closed, by stage order.
	 *
	 * @param array<string, mixed> $pipeline The pipeline.
	 *
	 * @return array{name: string, order: int}|null The stage, or null when the pipeline has no open stage.
	 *
	 * @spec openspec/changes/pipeline-numbers-tell-the-truth/specs/lead-management/spec.md
	 */
	public function firstOpenStage(array $pipeline): ?array {
		$stages = array_values(array_filter(
			(array)($pipeline['stages'] ?? []),
			static fn ($stage): bool => is_array($stage) === true
				&& trim((string)($stage['name'] ?? '')) !== ''
				&& ($stage['isClosed'] ?? false) !== true
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
	}//end firstOpenStage()

	/**
	 * The fields a NEW lead is missing to sit in a stage.
	 *
	 * Fills in the pipeline (default when absent), the stage (first open
	 * stage when absent), the stage order, and an entry time when the lead
	 * has none.
	 *
	 * @param array<string, mixed>             $lead      The lead data.
	 * @param array<int, array<string, mixed>> $pipelines Stored pipelines.
	 * @param string|null                      $now       ISO 8601 entry time; null means now.
	 *
	 * @return array<string, mixed> The fields to merge into the lead; [] when nothing is missing or nothing fits.
	 *
	 * @spec openspec/changes/pipeline-numbers-tell-the-truth/specs/lead-management/spec.md
	 */
	public function forNewLead(array $lead, array $pipelines, ?string $now = null): array {
		$patch = $this->stagePatch(lead: $lead, pipelines: $pipelines);
		if ($patch === null) {
			return [];
		}

		if (isset($patch['stage']) === true || $this->isBlank(value: $lead['stageEnteredAt'] ?? null) === true) {
			$patch['stageEnteredAt'] = $this->moment(iso: $now);
		}

		return $patch;
	}//end forNewLead()

	/**
	 * The fields a STORED lead is missing to sit in a stage.
	 *
	 * Like forNewLead(), but a lead that already had a stage keeps its entry
	 * time as it is (unset means aging falls back to the creation date), so a
	 * repair run does not restart anyone's aging.
	 *
	 * @param array<string, mixed>             $lead      The lead data.
	 * @param array<int, array<string, mixed>> $pipelines Stored pipelines.
	 * @param string|null                      $enteredAt ISO 8601 entry time for a newly placed lead, usually its creation.
	 *
	 * @return array<string, mixed> The fields to merge into the lead; [] when nothing is missing or nothing fits.
	 *
	 * @spec openspec/changes/pipeline-numbers-tell-the-truth/specs/lead-management/spec.md
	 */
	public function forStoredLead(array $lead, array $pipelines, ?string $enteredAt = null): array {
		$patch = $this->stagePatch(lead: $lead, pipelines: $pipelines);
		if ($patch === null) {
			return [];
		}

		if (isset($patch['stage']) === true) {
			$patch['stageEnteredAt'] = $this->moment(iso: $enteredAt);
		}

		return $patch;
	}//end forStoredLead()

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

		$target = $this->defaultPipeline(pipelines: $pipelines);
		if ($target === null) {
			return [];
		}

		$stage = $this->closedStageFor(pipeline: $target, status: (string)($lead['status'] ?? ''));
		$stage ??= $this->firstOpenStage(pipeline: $target);

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
	 * The pipeline, stage and stage order a lead is missing.
	 *
	 * A lead with a stage keeps it; only a missing `stageOrder` is filled in.
	 * A lead without a stage goes to the first open stage of its own
	 * pipeline, or of the default pipeline when it has none. A lead on a
	 * pipeline that does not exist is left alone: guessing a pipeline would
	 * silently move it.
	 *
	 * @param array<string, mixed>             $lead      The lead data.
	 * @param array<int, array<string, mixed>> $pipelines Stored pipelines.
	 *
	 * @return array<string, mixed>|null The fields, or null when no pipeline fits.
	 */
	private function stagePatch(array $lead, array $pipelines): ?array {
		$pipeline = $this->pipelineFor(lead: $lead, pipelines: $pipelines);
		if ($pipeline === null) {
			return null;
		}

		$patch = [];
		if (trim((string)($lead['pipeline'] ?? '')) === '') {
			$patch['pipeline'] = (string)$pipeline['id'];
		}

		$stageName = trim((string)($lead['stage'] ?? ''));
		if ($stageName === '') {
			$first = $this->firstOpenStage(pipeline: $pipeline);
			if ($first === null) {
				return $patch;
			}

			$patch['stage'] = $first['name'];
			$patch['stageOrder'] = $first['order'];
			return $patch;
		}

		$order = $this->stageOrder(pipeline: $pipeline, stageName: $stageName);
		if ($order !== null && $this->isBlank(value: $lead['stageOrder'] ?? null) === true) {
			$patch['stageOrder'] = $order;
		}

		return $patch;
	}//end stagePatch()

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

	/**
	 * The pipeline a lead belongs to: its own, or the default when it has none.
	 *
	 * @param array<string, mixed>             $lead      The lead data.
	 * @param array<int, array<string, mixed>> $pipelines Stored pipelines.
	 *
	 * @return array<string, mixed>|null The pipeline, or null.
	 */
	private function pipelineFor(array $lead, array $pipelines): ?array {
		$pipelineId = trim((string)($lead['pipeline'] ?? ''));
		if ($pipelineId === '') {
			return $this->defaultPipeline(pipelines: $pipelines);
		}

		foreach ($pipelines as $pipeline) {
			if ((string)($pipeline['id'] ?? '') === $pipelineId) {
				return $pipeline;
			}
		}

		return null;
	}//end pipelineFor()

	/**
	 * The order of a named stage in a pipeline.
	 *
	 * @param array<string, mixed> $pipeline  The pipeline.
	 * @param string               $stageName The stage name.
	 *
	 * @return int|null The order, or null when the stage is unknown.
	 */
	private function stageOrder(array $pipeline, string $stageName): ?int {
		if ($stageName === '') {
			return null;
		}

		foreach ((array)($pipeline['stages'] ?? []) as $stage) {
			if (is_array($stage) === true && (string)($stage['name'] ?? '') === $stageName && isset($stage['order']) === true) {
				return (int)$stage['order'];
			}
		}

		return null;
	}//end stageOrder()

	/**
	 * Whether a pipeline can hold leads.
	 *
	 * Mirrors pipelineAppliesTo() in src/services/pipelineUtils.js: a pipeline
	 * that declares no scope applies to everything.
	 *
	 * @param array<string, mixed> $pipeline The pipeline.
	 *
	 * @return bool True when leads may sit on it.
	 */
	private function appliesToLeads(array $pipeline): bool {
		$slugs = [];
		foreach ((array)($pipeline['propertyMappings'] ?? []) as $mapping) {
			if (is_array($mapping) === true && (string)($mapping['schemaSlug'] ?? '') !== '') {
				$slugs[] = (string)$mapping['schemaSlug'];
			}
		}

		if ($slugs === []) {
			$entityType = (string)($pipeline['entityType'] ?? '');
			if ($entityType === '' || $entityType === 'both') {
				return true;
			}

			$slugs = [$entityType];
		}

		return in_array(self::LEAD_SLUG, $slugs, true);
	}//end appliesToLeads()

	/**
	 * Whether a value is empty for placement purposes.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for null and blank strings.
	 */
	private function isBlank(mixed $value): bool {
		return $value === null || (is_string($value) === true && trim($value) === '');
	}//end isBlank()
}//end class
