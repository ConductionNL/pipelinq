<?php

/**
 * Contract of SegmentService
 *
 * The public surface of SegmentService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

/**
 * Contract of SegmentService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SegmentServiceInterface {

	/**
	 * Create a Segment after validating the rule tree.
	 *
	 * Runs `validateRules()` against the resolved entity-schema before
	 * touching ObjectService — an invalid tree is rejected with a
	 * generic-but-actionable error and no row is persisted. `createdBy`
	 * is set from the authenticated user id (ADR-005); the request body
	 * is never trusted for this field.
	 *
	 * @param array<string, mixed> $payload Inbound Segment payload.
	 * @param string $createdByUid Authenticated user id.
	 *
	 * @return array{segment?: array<string, mixed>, error?: string, estimatedSize?: int}
	 *
	 * @spec openspec/changes/archive/2026-06-14-marketing-segmentation-and-blast-06-rest-controllers/tasks.md#segmentcontroller-task-2.7-of-giant
	 */
	public function createSegment(array $payload, string $createdByUid): array;

	/**
	 * Estimate the size of a Segment by counting matching entities.
	 *
	 * Loads the Segment, resolves its entityType schema, queries every
	 * object of that type via OpenRegister's ObjectService, evaluates
	 * the rule tree against each, and returns the match count. The
	 * result is cached via the NC distributed cache with the TTL given
	 * by the `segment.estimate_ttl_seconds` app config key (default
	 * 3600s) — repeated previews on the same Segment within the TTL
	 * window hit cache instead of re-scanning.
	 *
	 * Returns 0 when the Segment is not found, its rules are invalid,
	 * or the OpenRegister query fails — the count is a preview, not an
	 * authoritative billing input, so failing to 0 is preferable to
	 * raising on the segment-detail view.
	 *
	 * @param string $segmentId Segment UUID or slug.
	 *
	 * @return int Count of matching entities; 0 on failure.
	 *
	 * @spec openspec/specs/marketing-segmentation/spec.md#requirement-segments-are-live-not-frozen-lists
	 */
	public function estimateSize(string $segmentId): int;

	/**
	 * Return the minimal recipient projection used by the blast engine.
	 *
	 * Same query path as estimateSize, but returns one row per matching
	 * entity carrying just the fields a downstream Blast needs:
	 * `contactId`, `email`, `firstName`, `lastName`. No caching — a stale
	 * send list is worse than a slow one (compliance member 03 still
	 * filters this list against ConsentRecord before dispatch, but
	 * delivering to a deleted Contact would be a defect).
	 *
	 * @param string $segmentId Segment UUID or slug.
	 *
	 * @return array<int, array<string, string>> Recipient rows.
	 *
	 * @spec openspec/specs/marketing-segmentation/spec.md#requirement-segments-are-live-not-frozen-lists
	 */
	public function getMembersForBlast(string $segmentId): array;

	/**
	 * Public accessor returning one Segment payload (or null).
	 *
	 * Returns the raw Segment with the rules tree as stored, enabling the
	 * controller to layer an `estimatedSize` field on top via
	 * `estimateSize()` — kept as two separate methods so the rule-tree
	 * fetch path is reusable without paying for the count when the
	 * caller does not need it.
	 *
	 * @param string $segmentId Segment UUID or slug.
	 *
	 * @return array<string, mixed>|null The Segment payload or null.
	 *
	 * @spec openspec/changes/archive/2026-06-14-marketing-segmentation-and-blast-06-rest-controllers/tasks.md#segmentcontroller-task-2.7-of-giant
	 */
	public function getSegmentById(string $segmentId): ?array;

	/**
	 * List Segments with pagination envelope.
	 *
	 * Used by the marketing Vue views (member 07) to browse the segment
	 * library. Filtered list is server-paged so the views never load the
	 * entire register; the envelope shape mirrors `BlastService::listBlasts()`.
	 *
	 * @param int $page 1-based page number.
	 * @param int $limit Page size (clamped 1..100).
	 *
	 * @return array{data: array<int, array<string, mixed>>, pagination: array{page: int, limit: int, total: int, pages: int}}
	 *
	 * @spec openspec/changes/archive/2026-06-14-marketing-segmentation-and-blast-06-rest-controllers/tasks.md#segmentcontroller-task-2.7-of-giant
	 */
	public function listSegments(int $page, int $limit): array;

	/**
	 * Validate a rule tree and return its size estimate in one call.
	 *
	 * Convenience entry point for the segment-builder save path used by
	 * controllers (member 06) and BlastService (member 04) — it runs
	 * `validateRules()` on the supplied rule tree, returns a structured
	 * `{ valid, error, estimatedSize }` triple, and counts matching
	 * entities only when the tree is valid. Centralising the
	 * validate-then-estimate sequence here keeps every caller honest
	 * about the order: never persist a Segment whose tree did not pass
	 * `validateRules()` first.
	 *
	 * @param array<string, mixed> $rules Rule tree from the request.
	 * @param string $entityType "contact" or "customer".
	 *
	 * @return array{valid: bool, error: ?string, estimatedSize: int}
	 *
	 * @spec openspec/specs/marketing-segmentation/spec.md#requirement-segment-builder-composes-rule-trees
	 */
	public function previewRulePayload(array $rules, string $entityType): array;

	/**
	 * Project the per-recipient list used to preview a Segment's members.
	 *
	 * Reuses `getMembersForBlast()` so the preview shape matches what the
	 * blast engine will actually consume. Capped at `$limit` rows so the
	 * controller cannot accidentally exhaust memory on a million-row
	 * segment.
	 *
	 * @param string $segmentId Segment UUID or slug.
	 * @param int $limit Max rows returned (clamped 1..500).
	 *
	 * @return array<int, array<string, string>> Recipient rows.
	 *
	 * @spec openspec/changes/archive/2026-06-14-marketing-segmentation-and-blast-06-rest-controllers/tasks.md#segmentcontroller-task-2.7-of-giant
	 */
	public function previewSegmentMembers(string $segmentId, int $limit = 50): array;

	/**
	 * Recompute the Segment's `estimatedSize` and persist it.
	 *
	 * Backs the `POST /api/segments/:id/size` endpoint — used by the
	 * segment-builder to refresh the cached count after rule edits or
	 * after the underlying contact pool has changed. Returns the freshly
	 * computed value so the caller does not have to re-read.
	 *
	 * @param string $segmentId Segment UUID or slug.
	 *
	 * @return int The refreshed estimated size; 0 when the Segment is
	 *             missing or unreachable.
	 *
	 * @spec openspec/changes/archive/2026-06-14-marketing-segmentation-and-blast-06-rest-controllers/tasks.md#segmentcontroller-task-2.7-of-giant
	 */
	public function refreshSegmentSize(string $segmentId): int;

	/**
	 * Whether the bookkeeping behind the shillinq signals can be read.
	 *
	 * @return array{shillinq: bool, reason: string} The availability report.
	 *
	 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-an-unresolved-signal-shrinks-the-audience
	 */
	public function signalAvailability(): array;

	/**
	 * The derived fields a rule may use next to the schema's own properties.
	 *
	 * @return array<string, array{type: string, title: string, source: string, description: string}>
	 *         The signal catalogue, as {@see SegmentSignalService::catalogue()} publishes it.
	 *
	 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-the-segment-builder-lists-the-signals-and-validates-a-rule-on-one
	 */
	public function signalCatalogue(): array;

	/**
	 * Update an existing Segment after re-validating the rule tree.
	 *
	 * Backs the SegmentEdit page (marketing-segments-ui-repair): a marketer
	 * changes the name, description or rule tree of a saved Segment through
	 * SegmentBuilder, and the tree is re-validated with the same
	 * `validateRules()` path `createSegment()` uses before anything is
	 * persisted. Fields omitted from `$payload` keep their existing value
	 * so a partial edit (e.g. name only) does not clobber the rule tree.
	 *
	 * @param string $segmentId Existing Segment UUID or slug.
	 * @param array<string, mixed> $payload Inbound Segment payload (partial).
	 *
	 * @return array{segment?: array<string, mixed>, error?: string, estimatedSize?: int}
	 *
	 * @spec openspec/specs/marketing-api/spec.md#requirement-api-endpoints-crud-and-query
	 */
	public function updateSegment(string $segmentId, array $payload): array;
}//end interface
