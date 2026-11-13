<?php

/**
 * Contract of BlastService
 *
 * The public surface of BlastService that other components call, so they depend
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
 * Contract of BlastService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BlastServiceInterface {

	/**
	 * Cancel a Blast: transition status → `cancelled` and flip every
	 * queued BlastDelivery row to `unsubscribed-before-send`.
	 *
	 * Idempotent: a Blast already in a terminal status (`sent`, `failed`,
	 * `cancelled`) is returned unchanged with a no-op summary so retries
	 * are safe. Member-05 background jobs will skip cancelled blasts on
	 * the next tick.
	 *
	 * @param string $blastId Blast UUID or slug.
	 *
	 * @return array{status: string, cancelledDeliveries: int} Summary.
	 *
	 * @spec openspec/changes/archive/2026-06-14-marketing-segmentation-and-blast-06-rest-controllers/tasks.md#blastcontroller-task-2.6-of-giant
	 */
	public function cancelBlast(string $blastId): array;

	/**
	 * Create a new Blast in `draft` status with server-set `createdBy`.
	 *
	 * Validates that the referenced Segment, CampaignTemplate and (when
	 * supplied) connector source identifiers are non-empty strings — the
	 * REST controller never trusts any `createdBy` value supplied in the
	 * request body (ADR-005). Returns either `{blast: array}` on success
	 * or `{error: string}` on invalid input so the controller can map
	 * each branch to an HTTP status.
	 *
	 * @param array<string, mixed> $payload Inbound payload.
	 * @param string $createdByUid Authenticated user id.
	 *
	 * @return array{blast?: array<string, mixed>, error?: string}
	 *
	 * @spec openspec/changes/archive/2026-06-14-marketing-segmentation-and-blast-06-rest-controllers/tasks.md#blastcontroller-task-2.6-of-giant
	 */
	public function createDraftBlast(array $payload, string $createdByUid): array;

	/**
	 * Dispatch queued BlastDeliveries for a Blast through its resolved mail transport.
	 *
	 * Reads queued BlastDelivery rows for `$blastId`, batches them (default
	 * 50), and hands each one to {@see MailTransportService::sendOneDelivery()},
	 * which renders the CampaignTemplate, resolves the Blast's `mailTransport`
	 * (instance mail server, a Mail account, or an OpenConnector source) and
	 * sends through the matching adapter. Rate limit is resolved once per
	 * call via {@see MailTransportService::resolveRateLimit()} — for a
	 * `provider`-kind transport this reads the OpenConnector source's
	 * `rateLimitLimit`/`rateLimitWindow` fields (preferred over the caller's
	 * `$maxPerSecond` when smaller); for `instance`/`mailAccount` it is the
	 * caller's rate — and enforced by sleeping between batches.
	 *
	 * The transport is resolved ONCE per call (not per delivery) and reused
	 * for every delivery in the batch. Returns the count of deliveries
	 * successfully accepted. Pipelinq code never reads provider credentials
	 * and never constructs an SDK request directly — that is the resolved
	 * transport adapter's job.
	 *
	 * @param string $blastId Blast UUID or slug.
	 * @param int $maxPerSecond Caller-supplied rate ceiling.
	 *
	 * @return int Number of deliveries dispatched.
	 *
	 * @spec openspec/changes/marketing-mail-transports/specs/marketing-blast/spec.md#requirement-send-via-openconnector-with-per-tenant-provider
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential throttle/dispatch guard
	 *  clauses over the delivery batch; extraction adds no clarity.
	 * @SuppressWarnings(PHPMD.NPathComplexity) Measured 320, threshold 200. Same
	 *  cause as the cyclomatic suppression above: the guards are INDEPENDENT, so
	 *  NPath multiplies out even though no two of them nest.
	 */
	public function dispatchBlastDeliveries(string $blastId, int $maxPerSecond = 100): int;

	/**
	 * Public accessor returning one Blast or null.
	 *
	 * Thin wrapper around the private `loadBlast()` helper so the REST
	 * controller (member 06) can fetch one Blast without re-implementing
	 * the schema-slug resolution.
	 *
	 * @param string $blastId Blast UUID or slug.
	 *
	 * @return array<string, mixed>|null Blast payload or null.
	 *
	 * @spec openspec/changes/archive/2026-06-14-marketing-segmentation-and-blast-06-rest-controllers/tasks.md#blastcontroller-task-2.6-of-giant
	 */
	public function getBlastById(string $blastId): ?array;

	/**
	 * List Blasts with optional status filter and pagination envelope.
	 *
	 * The list scope is the Pipelinq register's `blast` schema — never the
	 * raw object table — so OpenRegister enforces the Pipelinq RBAC profile
	 * (per-schema group restrictions). The envelope shape `{data,
	 * pagination}` is the one the marketing Vue views (member 07) consume.
	 *
	 * @param string|null $status Optional `status` filter
	 *                            (`draft|scheduled|sending|sent|paused|failed|cancelled`).
	 * @param int $page 1-based page number (clamped to >= 1).
	 * @param int $limit Page size (clamped 1..100).
	 *
	 * @return array{data: array<int, array<string, mixed>>, pagination: array{page: int, limit: int, total: int, pages: int}}
	 *
	 * @spec openspec/changes/archive/2026-06-14-marketing-segmentation-and-blast-06-rest-controllers/tasks.md#blastcontroller-task-2.6-of-giant
	 */
	public function listBlasts(?string $status, int $page, int $limit): array;

	/**
	 * List BlastDelivery rows for a Blast with pagination.
	 *
	 * Scoped to the supplied blast id so callers cannot pull the entire
	 * BlastDelivery table by passing an empty/wildcard id (IDOR
	 * prevention).
	 *
	 * @param string $blastId Blast UUID or slug.
	 * @param int $page 1-based page (clamped).
	 * @param int $limit Page size (clamped 1..100).
	 *
	 * @return array{data: array<int, array<string, mixed>>, pagination: array{page: int, limit: int, total: int, pages: int}}
	 *
	 * @spec openspec/changes/archive/2026-06-14-marketing-segmentation-and-blast-06-rest-controllers/tasks.md#blastcontroller-task-2.6-of-giant
	 */
	public function listDeliveriesForBlast(string $blastId, int $page, int $limit): array;

	/**
	 * Patch the editable Blast fields. Only `name` is editable post-create.
	 *
	 * Re-derives `updatedAt`; ignores any client-supplied `createdBy` or
	 * status to preserve the server-authoritative lifecycle (ADR-005).
	 *
	 * @param string $blastId Blast UUID or slug.
	 * @param string $name New name (validated non-empty).
	 *
	 * @return array<string, mixed>|null Saved Blast or null on failure.
	 *
	 * @spec openspec/changes/archive/2026-06-14-marketing-segmentation-and-blast-06-rest-controllers/tasks.md#blastcontroller-task-2.6-of-giant
	 */
	public function patchBlastName(string $blastId, string $name): ?array;

	/**
	 * Send (queue) a Blast — the compliance-gated entry point.
	 *
	 * Loads the Blast, asserts its status is `draft`, calls
	 * `ComplianceService.checkSegmentCompliance()` for lawful-basis
	 * filtering, and (when compliant) queues one `BlastDelivery` per
	 * compliant recipient. The Blast then transitions from `draft` to
	 * `sending` so the background job (member 05) picks it up.
	 *
	 * When `abSplitPercent` is set on the parent Blast a child variant-B
	 * Blast is created via `createAbVariant()` and the segment is split
	 * deterministically with `hash(contactId) % 100 < abSplitPercent` →
	 * variant B. The same contact ALWAYS receives the same variant: the
	 * split function is pure of `contactId` and `abSplitPercent`.
	 *
	 * `$isDraft = true` performs a dry-run: the audience is sliced and
	 * compliance is checked but nothing is persisted and the Blast stays
	 * in `draft`. Returns the same summary shape so callers can preview.
	 *
	 * @param string $blastId Blast UUID or slug.
	 * @param bool $isDraft When true, do not persist deliveries or
	 *                      transition the Blast status.
	 *
	 * @return array<string, mixed> Send summary:
	 *                              `queued`, `skippedNoConsent`, `variantA`,
	 *                              `variantB`, `variantBlastId`, `status`.
	 *
	 * @spec openspec/changes/archive/2026-06-14-marketing-segmentation-and-blast-04-blast-attribution-services/tasks.md#task-2.3
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   $isDraft selects the documented
	 *  dry-run preview mode; it is the method's defined contract, not a toggle to split.
	 */
	public function sendBlast(string $blastId, bool $isDraft = false): array;

	/**
	 * Recount BlastDeliveries by status and overwrite the Blast `totals` map.
	 *
	 * Called from `sendBlast()` after queueing, from
	 * `dispatchBlastDeliveries()` after a batch run, and from the
	 * webhook ingest path (member 05). The map matches the schema:
	 * `{queued, sent, delivered, bounced, opened, clicked, unsubscribed, complained}`.
	 *
	 * @param string $blastId Blast UUID or slug.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-14-marketing-segmentation-and-blast-04-blast-attribution-services/tasks.md#task-2.3
	 */
	public function updateBlastTotals(string $blastId): void;
}//end interface
