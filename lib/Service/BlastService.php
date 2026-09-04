<?php

/**
 * Pipelinq BlastService.
 *
 * Send-orchestration engine for the marketing-segmentation-and-blast
 * chain. Owns the lifecycle transition from `draft` Blast to a queue of
 * BlastDelivery rows and the throttled dispatch via openconnector. Never
 * touches provider SDKs or credentials — every send is a generic HTTP call
 * through `OCA\OpenConnector\Service\CallService::call()` against the
 * connector Source resolved from OpenRegister's `openconnector`/`source`
 * register+schema (ADR-005). `SourceService` / `executeAction()` no longer
 * exist in OpenConnector — its Source/Mapping/Synchronization/Job entities
 * moved onto OpenRegister's generic object API when OpenConnector's CRUD
 * was rebuilt on top of it.
 *
 * Reads Blast / BlastDelivery / CampaignTemplate schemas (member 01) via
 * `ObjectService`, consults `SegmentService` (member 02) for recipient
 * lists, and gates every send through `ComplianceService` (member 03).
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
 * @spec openspec/changes/marketing-segmentation-and-blast-04-blast-attribution-services/tasks.md#task-2.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * BlastService — orchestrates a Blast send.
 *
 * Public entry points:
 * - `sendBlast()` — compliance-gate, queue BlastDeliveries, optional A/B split
 * - `dispatchBlastDeliveries()` — throttled openconnector dispatch
 * - `createAbVariant()` — create the variant-B Blast
 * - `updateBlastTotals()` — recount BlastDelivery statuses
 * - `transitionQueuedDeliveries()` — called by ComplianceService on consent
 *   withdrawal to flip queued rows to `unsubscribed-before-send`
 * - `listBlasts()` / `getBlastById()` / `createDraftBlast()` /
 *   `patchBlastName()` / `cancelBlast()` / `listDeliveriesForBlast()` —
 *   thin repository surface consumed by the REST controllers (member 06).
 *
 * @spec openspec/changes/marketing-segmentation-and-blast-04-blast-attribution-services/tasks.md#task-2.3
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Blast lifecycle plus the thin
 *  repository surface consumed by the REST controllers live together by design;
 *  splitting would only scatter the seam.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Aggregate complexity is driven
 *  by the number of lifecycle operations; each method stays individually simple.
 * @SuppressWarnings(PHPMD.TooManyMethods)           The send/dispatch/AB lifecycle
 *  plus the repository surface justify the method count; splitting adds no clarity.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     The REST controllers consume a
 *  cohesive public repository surface on top of the lifecycle operations.
 */
class BlastService {
	/**
	 * Default register slug used when no `register` app config value is set.
	 */
	private const DEFAULT_REGISTER_SLUG = 'pipelinq';

	/**
	 * Default Blast schema slug used when no `blast_schema` app config
	 * value is set.
	 */
	private const DEFAULT_BLAST_SCHEMA_SLUG = 'blast';

	/**
	 * Default BlastDelivery schema slug used when no
	 * `blastDelivery_schema` app config value is set.
	 */
	private const DEFAULT_BLAST_DELIVERY_SCHEMA_SLUG = 'blastDelivery';

	/**
	 * Default CampaignTemplate schema slug used when no
	 * `campaignTemplate_schema` app config value is set.
	 */
	private const DEFAULT_CAMPAIGN_TEMPLATE_SCHEMA_SLUG = 'campaignTemplate';

	/**
	 * Default dispatch batch size when no `blast.dispatch_batch_size`
	 * app config key is set. Member-04 design pins this at 50.
	 */
	private const DEFAULT_DISPATCH_BATCH_SIZE = 50;

	/**
	 * Fallback rate limit (messages per second) when neither the call-site
	 * nor the openconnector source declare one.
	 */
	private const DEFAULT_RATE_LIMIT_PER_SECOND = 100;

	/**
	 * Status used to mark a queued BlastDelivery that was cut by
	 * ComplianceService before dispatch.
	 */
	private const STATUS_UNSUBSCRIBED_BEFORE_SEND = 'unsubscribed-before-send';

	/**
	 * OpenConnector's own OpenRegister register slug. Source objects
	 * (formerly served by the now-removed `SourceService`) live here, not
	 * in pipelinq's own `register` app-config register.
	 */
	private const OPENCONNECTOR_REGISTER_SLUG = 'openconnector';

	/**
	 * OpenConnector's Source schema slug within {@see OPENCONNECTOR_REGISTER_SLUG}.
	 */
	private const OPENCONNECTOR_SOURCE_SCHEMA_SLUG = 'source';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container.
	 * @param IAppConfig $appConfig Pipelinq app config.
	 * @param SegmentService $segmentService Segment evaluator.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/marketing-segmentation-and-blast-04-blast-attribution-services/tasks.md#task-2.3
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private SegmentService $segmentService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

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
	 * @spec openspec/changes/marketing-segmentation-and-blast-04-blast-attribution-services/tasks.md#task-2.3
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   $isDraft selects the documented
	 *  dry-run preview mode; it is the method's defined contract, not a toggle to split.
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Sequential compliance/audience/AB
	 *  guard clauses; extraction adds no clarity.
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Same flat guard sequence; path count is a
	 *  product of independent conditions, not nesting.
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Single linear send pipeline kept
	 *  together for readability; the steps share local state.
	 */
	public function sendBlast(string $blastId, bool $isDraft = false): array {
		$blast = $this->loadBlast(blastId: $blastId);
		if ($blast === null) {
			return $this->emptySummary(status: 'not-found');
		}

		$status = (string)($blast['status'] ?? 'draft');
		if ($status !== 'draft') {
			return $this->emptySummary(status: 'not-draft-' . $status);
		}

		$segmentId = (string)($blast['segmentId'] ?? '');
		$listId = (string)($blast['listId'] ?? '');
		if ($segmentId === '' && $listId === '') {
			return $this->emptySummary(status: 'no-audience');
		}

		$channel = (string)($blast['channel'] ?? 'email');

		$audience = $this->resolveAudience(segmentId: $segmentId, listId: $listId, channel: $channel);
		$missingConsent = $audience['missingConsent'];
		$compliantMembers = $audience['members'];

		if ($compliantMembers === []) {
			$this->logger->info(
				'BlastService.sendBlast: no compliant recipients — keeping draft',
				['blastId' => $blastId, 'channel' => $channel, 'missingCount' => count($missingConsent)]
			);
			return [
				'queued' => 0,
				'skippedNoConsent' => count($missingConsent),
				'variantA' => 0,
				'variantB' => 0,
				'variantBlastId' => null,
				'status' => 'skipped-no-consent',
			];
		}

		$abSplit = $this->extractAbSplitPercent(blast: $blast);
		$variantBlastId = null;
		if ($abSplit !== null && $isDraft === false) {
			$variantBlastId = $this->createAbVariant(
				parentBlastId: $blastId,
				variantData: ['suffix' => '-B'],
			);
		}

		$sliced = $this->sliceMembersForAb(
			members: $compliantMembers,
			abSplitPercent: $abSplit,
			parentBlastId: $blastId,
			variantBlastId: $variantBlastId,
		);

		$variantACount = 0;
		$variantBCount = 0;

		// A draft dry-run counts the whole sliced audience without persisting;
		// a real send persists each delivery first and counts only the rows
		// that were stored (a failed persist is skipped).
		$countableRows = $sliced;
		if ($isDraft === false) {
			$countableRows = [];
			foreach ($sliced as $row) {
				$persisted = $this->persistQueuedDelivery(
					blastId: $row['blastId'],
					member: $row['member'],
					channel: $channel,
				);
				if ($persisted === false) {
					continue;
				}

				$countableRows[] = $row;
			}

			$this->updateBlastStatus(blastId: $blastId, newStatus: 'sending');
			if ($variantBlastId !== null) {
				$this->updateBlastStatus(blastId: $variantBlastId, newStatus: 'sending');
			}

			$this->updateBlastTotals(blastId: $blastId);
			if ($variantBlastId !== null) {
				$this->updateBlastTotals(blastId: $variantBlastId);
			}
		}//end if

		foreach ($countableRows as $row) {
			if ($row['variant'] === 'B') {
				$variantBCount++;
				continue;
			}

			$variantACount++;
		}

		return [
			'queued' => ($variantACount + $variantBCount),
			'skippedNoConsent' => count($missingConsent),
			'variantA' => $variantACount,
			'variantB' => $variantBCount,
			'variantBlastId' => $variantBlastId,
			'status' => $this->summaryStatusFor(isDraft: $isDraft),
		];
	}//end sendBlast()

	/**
	 * Return the summary `status` for a sendBlast call.
	 *
	 * @param bool $isDraft Whether the call was a dry-run preview.
	 *
	 * @return string Status label.
	 */
	private function summaryStatusFor(bool $isDraft): string {
		if ($isDraft === true) {
			return 'draft-preview';
		}

		return 'queued';
	}//end summaryStatusFor()

	/**
	 * Dispatch queued BlastDeliveries for a Blast through openconnector.
	 *
	 * Reads queued BlastDelivery rows for `$blastId`, batches them
	 * (default 50), renders the CampaignTemplate per recipient, then POSTs
	 * the rendered payload to the openconnector Source's base URL via
	 * `CallService::call()`. The returned `providerId` (parsed from the
	 * provider's JSON response, when present) is persisted on the
	 * BlastDelivery and the row transitions to `sent`. Rate limit is read
	 * from the Source's `rateLimitLimit` / `rateLimitWindow` fields
	 * (preferred over the caller's `$maxPerSecond` when the source value is
	 * smaller, so the source config always wins) and enforced by sleeping
	 * between batches.
	 *
	 * The Source is resolved ONCE per call (not per delivery) via
	 * OpenRegister's `openconnector`/`source` register+schema and reused for
	 * every delivery in the batch.
	 *
	 * Returns the count of deliveries successfully accepted by the
	 * provider. Pipelinq code never reads provider credentials and never
	 * constructs an SDK request — that is the openconnector source's job.
	 *
	 * @param string $blastId Blast UUID or slug.
	 * @param int $maxPerSecond Caller-supplied rate ceiling.
	 *
	 * @return int Number of deliveries dispatched.
	 *
	 * @spec openspec/changes/marketing-segmentation-and-blast-04-blast-attribution-services/tasks.md#task-2.3
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential throttle/dispatch guard
	 *  clauses over the delivery batch; extraction adds no clarity.
	 * @SuppressWarnings(PHPMD.NPathComplexity) Measured 320, threshold 200. Same
	 *  cause as the cyclomatic suppression above: the guards are INDEPENDENT, so
	 *  NPath multiplies out even though no two of them nest.
	 */
	public function dispatchBlastDeliveries(string $blastId, int $maxPerSecond = 100): int {
		$blast = $this->loadBlast(blastId: $blastId);
		if ($blast === null) {
			return 0;
		}

		$template = $this->loadTemplate(templateId: (string)($blast['templateId'] ?? ''));
		if ($template === null) {
			$this->logger->warning(
				'BlastService.dispatchBlastDeliveries: template missing',
				['blastId' => $blastId, 'templateId' => ($blast['templateId'] ?? null)]
			);
			return 0;
		}

		// Campaign parameters go on the template once per blast, BEFORE the
		// per-delivery render and the click-redirect wrap, so the redirect
		// target carries them (marketing-campaign-attribution).
		$template['bodyHtml'] = $this->decorateCampaignLinks(
			html: (string)($template['bodyHtml'] ?? ''),
			blast: $blast,
			template: $template,
		);

		$connectorSourceId = (string)($blast['connectorSourceId'] ?? '');
		if ($connectorSourceId === '') {
			$this->logger->warning(
				'BlastService.dispatchBlastDeliveries: no connectorSourceId on blast',
				['blastId' => $blastId]
			);
			return 0;
		}

		$source = $this->resolveConnectorSource(connectorSourceId: $connectorSourceId);
		if ($source === null) {
			return 0;
		}

		$rateLimit = $this->resolveRateLimit(
			source: $source,
			callerRate: $maxPerSecond,
		);
		$batchSize = $this->resolveBatchSize();

		$queued = $this->loadQueuedDeliveries(blastId: $blastId);
		if ($queued === []) {
			return 0;
		}

		$dispatched = 0;
		$batches = array_chunk($queued, $batchSize);
		foreach ($batches as $batchIndex => $batch) {
			$start = microtime(true);
			foreach ($batch as $delivery) {
				$result = $this->sendOneDelivery(
					delivery: $delivery,
					template: $template,
					source: $source,
					connectorSourceId: $connectorSourceId,
				);
				if ($result === false) {
					continue;
				}

				$dispatched++;
			}

			// Enforce rate limit BETWEEN batches — wait until the configured
			// budget for this batch has elapsed.
			$expectedDuration = (count($batch) / max($rateLimit, 1));
			$elapsed = (microtime(true) - $start);
			$remaining = ($expectedDuration - $elapsed);
			if ($remaining > 0.0 && $batchIndex < (count($batches) - 1)) {
				$this->throttle(seconds: $remaining);
			}
		}//end foreach

		$this->updateBlastTotals(blastId: $blastId);

		return $dispatched;
	}//end dispatchBlastDeliveries()

	/**
	 * Create a sibling variant-B Blast cloned from the parent.
	 *
	 * The child copies the parent's segmentId / templateId / channel and
	 * sets `abVariantOf = parentBlastId`. The name is suffixed (default
	 * ` (Variant B)`) so dashboards distinguish the pair at a glance.
	 * Callers may override `templateId` / `name` / suffix via
	 * `$variantData`.
	 *
	 * @param string $parentBlastId Parent Blast UUID or slug.
	 * @param array<string, mixed> $variantData Override fields:
	 *                                          `templateId`, `name`, `suffix`.
	 *
	 * @return string New Blast UUID/slug or empty on failure.
	 *
	 * @spec openspec/changes/marketing-segmentation-and-blast-04-blast-attribution-services/tasks.md#task-2.3
	 */
	public function createAbVariant(string $parentBlastId, array $variantData): string {
		$parent = $this->loadBlast(blastId: $parentBlastId);
		if ($parent === null) {
			return '';
		}

		$suffix = (string)($variantData['suffix'] ?? ' (Variant B)');
		$baseName = (string)($parent['name'] ?? 'Blast');
		$override = (string)($variantData['name'] ?? '');
		$childName = ($baseName . $suffix);
		if ($override !== '') {
			$childName = $override;
		}

		$childPayload = [
			'name' => $childName,
			'segmentId' => (string)($parent['segmentId'] ?? ''),
			'templateId' => (string)($variantData['templateId'] ?? ($parent['templateId'] ?? '')),
			'channel' => (string)($parent['channel'] ?? 'email'),
			'status' => 'draft',
			'abVariantOf' => (string)($parent['@self']['uuid'] ?? $parent['uuid'] ?? $parentBlastId),
			'connectorSourceId' => (string)($parent['connectorSourceId'] ?? ''),
			'totals' => $this->emptyTotals(),
			'createdBy' => (string)($parent['createdBy'] ?? ''),
			'createdAt' => $this->nowIso(),
		];

		$created = $this->saveObject(
			payload: $childPayload,
			schemaSlug: $this->getBlastSchemaSlug(),
		);
		if ($created === null) {
			return '';
		}

		return $this->extractId(payload: $created);
	}//end createAbVariant()

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
	 * @spec openspec/changes/marketing-segmentation-and-blast-04-blast-attribution-services/tasks.md#task-2.3
	 */
	public function updateBlastTotals(string $blastId): void {
		$blast = $this->loadBlast(blastId: $blastId);
		if ($blast === null) {
			return;
		}

		$deliveries = $this->loadAllDeliveriesForBlast(blastId: $blastId);
		$totals = $this->emptyTotals();
		foreach ($deliveries as $delivery) {
			$status = (string)($delivery['status'] ?? '');
			if ($status === '' || isset($totals[$status]) === false) {
				continue;
			}

			$totals[$status]++;
		}

		$payload = $blast;
		$payload['totals'] = $totals;
		$this->saveObject(
			payload: $payload,
			schemaSlug: $this->getBlastSchemaSlug(),
			id: $this->extractId(payload: $blast),
		);
	}//end updateBlastTotals()

	/**
	 * Transition every queued BlastDelivery for one contact on one Blast.
	 *
	 * Wired by `ComplianceService.recordConsentWithdrawal()` (member 03):
	 * when a contact unsubscribes mid-send, every still-queued row for
	 * them on the source Blast flips to `unsubscribed-before-send`. The
	 * row STAYS — we keep audit history, we just stop the provider call.
	 *
	 * @param string $contactId Contact UUID or slug.
	 * @param string $blastId Source Blast UUID or slug.
	 * @param string $newStatus Target status (default
	 *                          `unsubscribed-before-send`).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-segmentation-and-blast-04-blast-attribution-services/tasks.md#task-2.3
	 */
	public function transitionQueuedDeliveries(string $contactId, string $blastId, string $newStatus = self::STATUS_UNSUBSCRIBED_BEFORE_SEND): void {
		$deliveries = $this->loadQueuedDeliveriesForContact(blastId: $blastId, contactId: $contactId);
		foreach ($deliveries as $delivery) {
			$payload = $delivery;
			$payload['status'] = $newStatus;
			$payload['unsubscribedAt'] = $this->nowIso();
			$this->saveObject(
				payload: $payload,
				schemaSlug: $this->getBlastDeliverySchemaSlug(),
				id: $this->extractId(payload: $delivery),
			);
		}

		if ($deliveries !== []) {
			$this->updateBlastTotals(blastId: $blastId);
		}
	}//end transitionQueuedDeliveries()

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
	 * @spec openspec/changes/marketing-segmentation-and-blast-06-rest-controllers/tasks.md#blastcontroller-task-2.6-of-giant
	 */
	public function listBlasts(?string $status, int $page, int $limit): array {
		$page = max(1, $page);
		$limit = min(100, max(1, $limit));
		$filters = [];
		if ($status !== null && $status !== '') {
			$filters['status'] = $status;
		}

		$schemaSlug = $this->getBlastSchemaSlug();
		$offset = (($page - 1) * $limit);
		$total = $this->countObjects(schemaSlug: $schemaSlug, filters: $filters);
		$slice = $this->loadObjects(
			schemaSlug: $schemaSlug,
			filters: $filters,
			limit: $limit,
			offset: $offset,
		);
		return [
			'data' => $slice,
			'pagination' => [
				'page' => $page,
				'limit' => $limit,
				'total' => $total,
				'pages' => $this->computePages(total: $total, limit: $limit),
			],
		];
	}//end listBlasts()

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
	 * @spec openspec/changes/marketing-segmentation-and-blast-06-rest-controllers/tasks.md#blastcontroller-task-2.6-of-giant
	 */
	public function getBlastById(string $blastId): ?array {
		if ($blastId === '') {
			return null;
		}

		return $this->loadBlast(blastId: $blastId);
	}//end getBlastById()

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
	 * @spec openspec/changes/marketing-segmentation-and-blast-06-rest-controllers/tasks.md#blastcontroller-task-2.6-of-giant
	 */
	public function createDraftBlast(array $payload, string $createdByUid): array {
		$error = $this->validateDraftBlastPayload(payload: $payload);
		if ($error !== null) {
			return ['error' => $error];
		}

		$now = $this->nowIso();
		$object = [
			'name' => (string)$payload['name'],
			'segmentId' => (string)($payload['segmentId'] ?? ''),
			'listId' => (string)($payload['listId'] ?? ''),
			'templateId' => (string)$payload['templateId'],
			'channel' => (string)$payload['channel'],
			'connectorSourceId' => (string)($payload['connectorSourceId'] ?? ''),
			'status' => 'draft',
			'totals' => $this->emptyTotals(),
			'createdBy' => $createdByUid,
			'createdAt' => $now,
			'updatedAt' => $now,
		];
		if (isset($payload['scheduledFor']) === true && is_string($payload['scheduledFor']) === true) {
			$object['scheduledFor'] = $payload['scheduledFor'];
		}

		$saved = $this->saveObject(payload: $object, schemaSlug: $this->getBlastSchemaSlug());
		if ($saved === null) {
			return ['error' => 'Could not create blast'];
		}

		return ['blast' => $saved];
	}//end createDraftBlast()

	/**
	 * Validate the inbound payload for createDraftBlast.
	 *
	 * @param array<string, mixed> $payload Inbound payload.
	 *
	 * @return string|null Error message or null when valid.
	 */
	private function validateDraftBlastPayload(array $payload): ?string {
		$name = (string)($payload['name'] ?? '');
		if (trim($name) === '') {
			return 'Invalid name';
		}

		$audienceError = $this->validateAudience(payload: $payload);
		if ($audienceError !== null) {
			return $audienceError;
		}

		$templateId = (string)($payload['templateId'] ?? '');
		if (trim($templateId) === '' || $this->loadTemplate(templateId: $templateId) === null) {
			return 'Invalid template';
		}

		$channel = strtolower((string)($payload['channel'] ?? ''));
		if (in_array($channel, ['email', 'sms'], true) === false) {
			return 'Invalid channel';
		}

		return null;
	}//end validateDraftBlastPayload()

	/**
	 * Validate the audience half of a draft-blast payload.
	 *
	 * A blast names exactly one audience. Naming both would leave the send
	 * path to pick, and whichever it picked would surprise the marketer who
	 * named the other one; naming neither is the `no-audience` refusal
	 * `sendBlast()` would otherwise reach much later, with a draft already
	 * stored.
	 *
	 * @param array<string, mixed> $payload Inbound payload.
	 *
	 * @return string|null Error message, or null when the audience is usable.
	 *
	 * @spec openspec/specs/marketing-blast/spec.md#requirement-a-blast-may-target-a-mailing-list
	 */
	private function validateAudience(array $payload): ?string {
		$segmentId = trim((string)($payload['segmentId'] ?? ''));
		$listId = trim((string)($payload['listId'] ?? ''));

		if ($segmentId !== '' && $listId !== '') {
			return 'Choose either a segment or a mailing list, not both';
		}

		if ($segmentId === '' && $listId === '') {
			return 'Choose a segment or a mailing list';
		}

		if ($listId !== '') {
			if ($this->loadOne(id: $listId, schemaSlug: $this->getMailingListSchemaSlug()) === null) {
				return 'Invalid mailing list';
			}

			return null;
		}

		if ($this->loadOne(id: $segmentId, schemaSlug: $this->segmentService->getSegmentSchemaSlugPublic()) === null) {
			return 'Invalid segment';
		}

		return null;
	}//end validateAudience()

	/**
	 * Resolve the MailingList schema slug from app config.
	 *
	 * @return string Schema slug.
	 */
	private function getMailingListSchemaSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'mailing_list_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return 'mailingList';
	}//end getMailingListSchemaSlug()

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
	 * @spec openspec/changes/marketing-segmentation-and-blast-06-rest-controllers/tasks.md#blastcontroller-task-2.6-of-giant
	 */
	public function patchBlastName(string $blastId, string $name): ?array {
		if (trim($name) === '') {
			return null;
		}

		$blast = $this->loadBlast(blastId: $blastId);
		if ($blast === null) {
			return null;
		}

		$payload = $blast;
		$payload['name'] = $name;
		$payload['updatedAt'] = $this->nowIso();
		return $this->saveObject(
			payload: $payload,
			schemaSlug: $this->getBlastSchemaSlug(),
			id: $this->extractId(payload: $blast),
		);
	}//end patchBlastName()

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
	 * @spec openspec/changes/marketing-segmentation-and-blast-06-rest-controllers/tasks.md#blastcontroller-task-2.6-of-giant
	 */
	public function cancelBlast(string $blastId): array {
		$blast = $this->loadBlast(blastId: $blastId);
		if ($blast === null) {
			return ['status' => 'not-found', 'cancelledDeliveries' => 0];
		}

		$current = (string)($blast['status'] ?? 'draft');
		if (in_array($current, ['sent', 'failed', 'cancelled'], true) === true) {
			return ['status' => 'noop-' . $current, 'cancelledDeliveries' => 0];
		}

		$queued = $this->loadQueuedDeliveries(blastId: $blastId);
		foreach ($queued as $delivery) {
			$row = $delivery;
			$row['status'] = self::STATUS_UNSUBSCRIBED_BEFORE_SEND;
			$row['unsubscribedAt'] = $this->nowIso();
			$this->saveObject(
				payload: $row,
				schemaSlug: $this->getBlastDeliverySchemaSlug(),
				id: $this->extractId(payload: $delivery),
			);
		}

		$this->updateBlastStatus(blastId: $blastId, newStatus: 'cancelled');
		$this->updateBlastTotals(blastId: $blastId);
		return ['status' => 'cancelled', 'cancelledDeliveries' => count($queued)];
	}//end cancelBlast()

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
	 * @spec openspec/changes/marketing-segmentation-and-blast-06-rest-controllers/tasks.md#blastcontroller-task-2.6-of-giant
	 */
	public function listDeliveriesForBlast(string $blastId, int $page, int $limit): array {
		$page = max(1, $page);
		$limit = min(100, max(1, $limit));
		if ($blastId === '') {
			return [
				'data' => [],
				'pagination' => ['page' => $page, 'limit' => $limit, 'total' => 0, 'pages' => 0],
			];
		}

		$filters = ['blastId' => $blastId];
		$offset = (($page - 1) * $limit);
		$total = $this->countDeliveries(filters: $filters);
		$slice = $this->loadDeliveries(filters: $filters, limit: $limit, offset: $offset);
		return [
			'data' => $slice,
			'pagination' => [
				'page' => $page,
				'limit' => $limit,
				'total' => $total,
				'pages' => $this->computePages(total: $total, limit: $limit),
			],
		];
	}//end listDeliveriesForBlast()

	/**
	 * Compute the page-count from a total + page-size pair.
	 *
	 * Centralised so the inline ternary stays out of the envelope
	 * builders (matches the team's "no inline IF" coding style).
	 *
	 * @param int $total Total row count.
	 * @param int $limit Page size.
	 *
	 * @return int Page count (0 when total is 0).
	 */
	private function computePages(int $total, int $limit): int {
		if ($total <= 0 || $limit <= 0) {
			return 0;
		}

		return (int)ceil($total / $limit);
	}//end computePages()

	/**
	 * Load objects of the given schema (used by listBlasts), with optional
	 * server-side paging pushed down to OpenRegister.
	 *
	 * @param string $schemaSlug Schema slug.
	 * @param array<string, mixed> $filters Filter map.
	 * @param int|null $limit Optional page size pushed to OR.
	 * @param int|null $offset Optional offset pushed to OR.
	 *
	 * @return array<int, array<string, mixed>> Plain payloads.
	 */
	private function loadObjects(string $schemaSlug, array $filters, ?int $limit = null, ?int $offset = null): array {
		$register = $this->getRegisterSlug();
		if ($register === '' || $schemaSlug === '') {
			return [];
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$config = [
			'filters' => array_merge(['register' => $register, 'schema' => $schemaSlug], $filters),
		];
		if ($limit !== null) {
			$config['limit'] = $limit;
		}

		if ($offset !== null) {
			$config['offset'] = $offset;
		}

		try {
			$rows = $objectService->findAll(config: $config);
		} catch (Throwable $e) {
			$this->logger->warning(
				'BlastService.loadObjects: findAll failed',
				['schema' => $schemaSlug, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$out = [];
		foreach (($rows ?? []) as $row) {
			$out[] = $this->toArray(value: $row);
		}

		return $out;
	}//end loadObjects()

	/**
	 * Count objects of a schema matching the given filters via OpenRegister.
	 *
	 * Pushes the COUNT down into the OR query engine so paginated list
	 * endpoints can report a total without fetching every matching row.
	 *
	 * @param string $schemaSlug The schema slug.
	 * @param array<string, mixed> $filters Field filter map.
	 *
	 * @return int The matching row count, or 0 when OR is unavailable.
	 *
	 * @spec openspec/changes/pipelinq-query-pushdown-batch-1/tasks.md#task-5
	 */
	private function countObjects(string $schemaSlug, array $filters): int {
		$register = $this->getRegisterSlug();
		if ($register === '' || $schemaSlug === '') {
			return 0;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return 0;
		}

		try {
			return $objectService->count(
				config: [
					'filters' => array_merge(['register' => $register, 'schema' => $schemaSlug], $filters),
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'BlastService.countObjects: count failed',
				['schema' => $schemaSlug, 'exception' => $e->getMessage()]
			);
			return 0;
		}
	}//end countObjects()

	/**
	 * Slice the recipient list into A/B variants deterministically.
	 *
	 * The slicer is exposed as a separate method so member-09 tests can
	 * verify same-input → same-output across runs without instantiating
	 * the full send pipeline.
	 *
	 * @param array<int, array<string, string>> $members Recipient rows.
	 * @param int|null $abSplitPercent A/B percent (0-100).
	 * @param string $parentBlastId Parent Blast id.
	 * @param string|null $variantBlastId Variant B Blast id.
	 *
	 * @return array<int, array{member: array<string, string>, variant: string, blastId: string}>
	 *
	 * @spec openspec/changes/marketing-segmentation-and-blast-04-blast-attribution-services/tasks.md#task-2.3
	 */
	public function sliceMembersForAb(array $members, ?int $abSplitPercent, string $parentBlastId, ?string $variantBlastId): array {
		$sliced = [];
		foreach ($members as $member) {
			$contactId = (string)($member['contactId'] ?? '');
			if ($contactId === '') {
				continue;
			}

			$variant = $this->variantFor(contactId: $contactId, abSplitPercent: $abSplitPercent);
			$targetId = $parentBlastId;
			if ($variant === 'B' && $variantBlastId !== null && $variantBlastId !== '') {
				$targetId = $variantBlastId;
			}

			$sliced[] = [
				'member' => $member,
				'variant' => $variant,
				'blastId' => $targetId,
			];
		}

		return $sliced;
	}//end sliceMembersForAb()

	/**
	 * Resolve the audience a Blast names, whether that is a Segment or a
	 * mailing list.
	 *
	 * A Segment is a saved query over people the tenant already holds, so
	 * its members are gated by the channel-wide ConsentRecord. A mailing
	 * list is something a person joined, so its members are the confirmed
	 * subscriptions whose LIST consent stands. Both are evaluated at send
	 * time and both come back in the same shape, so nothing downstream
	 * branches on where the recipients came from.
	 *
	 * @param string $segmentId Segment UUID or slug, empty when a list is named.
	 * @param string $listId MailingList UUID or slug, empty when a segment is named.
	 * @param string $channel Channel ("email" / "sms").
	 *
	 * @return array{members: array<int, array<string, mixed>>, missingConsent: array<int, string>}
	 *
	 * @spec openspec/specs/marketing-blast/spec.md#requirement-a-blast-may-target-a-mailing-list
	 */
	protected function resolveAudience(string $segmentId, string $listId, string $channel): array {
		if ($listId !== '') {
			return $this->resolveListAudience(listId: $listId);
		}

		$complianceResult = $this->checkSegmentCompliance(segmentId: $segmentId, channel: $channel);
		$missingConsent = $complianceResult['missingConsent'];
		$missingSet = array_flip($missingConsent);

		$members = [];
		foreach ($this->segmentService->getMembersForBlast(segmentId: $segmentId) as $member) {
			$contactId = (string)($member['contactId'] ?? '');
			if ($contactId === '' || isset($missingSet[$contactId]) === true) {
				continue;
			}

			$members[] = $member;
		}

		return ['members' => $members, 'missingConsent' => $missingConsent];
	}//end resolveAudience()

	/**
	 * Resolve a mailing list's audience through SubscriptionQueryService.
	 *
	 * Fails closed when that service cannot be resolved: an empty
	 * audience leaves the Blast in draft, which is the same outcome the
	 * segment path already produces when compliance is unavailable.
	 *
	 * `missingConsent` carries every membership that was NOT queued, so the
	 * send summary's `skippedNoConsent` counts a pending or unsubscribed
	 * member exactly as it counts a contact without a ConsentRecord.
	 *
	 * @param string $listId MailingList UUID or slug.
	 *
	 * @return array{members: array<int, array<string, mixed>>, missingConsent: array<int, string>}
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-pending-subscription-never-receives-a-blast
	 */
	protected function resolveListAudience(string $listId): array {
		try {
			$service = $this->container->get('OCA\\Pipelinq\\Service\\Marketing\\SubscriptionQueryService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'BlastService.resolveListAudience: SubscriptionQueryService unavailable — failing closed',
				['listId' => $listId, 'exception' => $e->getMessage()]
			);
			return ['members' => [], 'missingConsent' => []];
		}

		try {
			$audience = $service->getBlastAudienceForList($listId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'BlastService.resolveListAudience: call failed — failing closed',
				['listId' => $listId, 'exception' => $e->getMessage()]
			);
			return ['members' => [], 'missingConsent' => []];
		}

		if (is_array($audience) === false) {
			return ['members' => [], 'missingConsent' => []];
		}

		$members = [];
		foreach (($audience['members'] ?? []) as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$members[] = [
				'contactId' => (string)($row['contactId'] ?? ''),
				'email' => (string)($row['email'] ?? ''),
				'unsubscribeUrl' => (string)($row['unsubscribeUrl'] ?? ''),
			];
		}

		$skipped = [];
		foreach (($audience['skipped'] ?? []) as $contactId) {
			if (is_scalar($contactId) === true) {
				$skipped[] = (string)$contactId;
			}
		}

		return ['members' => $members, 'missingConsent' => $skipped];
	}//end resolveListAudience()

	/**
	 * Wrap the ComplianceService call so this service compiles before
	 * member 03 lands. When ComplianceService is unavailable in the
	 * container we fail closed: every member is treated as missing
	 * consent so nothing is sent.
	 *
	 * @param string $segmentId Segment UUID or slug.
	 * @param string $channel Channel ("email" / "sms").
	 *
	 * @return array<string, mixed> `{compliant, missingConsent[], missingCount}`.
	 */
	protected function checkSegmentCompliance(string $segmentId, string $channel): array {
		try {
			$service = $this->container->get('OCA\\Pipelinq\\Service\\ComplianceService');
		} catch (Throwable $e) {
			$this->logger->info(
				'BlastService.checkSegmentCompliance: ComplianceService unavailable',
				['exception' => $e->getMessage()]
			);
			$members = $this->segmentService->getMembersForBlast(segmentId: $segmentId);
			$missingConsent = [];
			foreach ($members as $member) {
				$contactId = (string)($member['contactId'] ?? '');
				if ($contactId !== '') {
					$missingConsent[] = $contactId;
				}
			}

			return [
				'compliant' => false,
				'missingConsent' => $missingConsent,
				'missingCount' => count($missingConsent),
			];
		}//end try

		try {
			$result = $service->checkSegmentCompliance($segmentId, $channel);
		} catch (Throwable $e) {
			$this->logger->warning(
				'BlastService.checkSegmentCompliance: call failed — failing closed',
				['segmentId' => $segmentId, 'channel' => $channel, 'exception' => $e->getMessage()]
			);
			return ['compliant' => false, 'missingConsent' => [], 'missingCount' => 0];
		}

		if (is_array($result) === false) {
			return ['compliant' => false, 'missingConsent' => [], 'missingCount' => 0];
		}

		$missing = ($result['missingConsent'] ?? []);
		if (is_array($missing) === false) {
			$missing = [];
		}

		$missingScalars = [];
		foreach ($missing as $id) {
			if (is_scalar($id) === true) {
				$missingScalars[] = (string)$id;
			}
		}

		return [
			'compliant' => (bool)($result['compliant'] ?? false),
			'missingConsent' => $missingScalars,
			'missingCount' => (int)($result['missingCount'] ?? count($missingScalars)),
		];
	}//end checkSegmentCompliance()

	/**
	 * Deterministic A/B assignment from contactId.
	 *
	 * Uses `crc32` (cheap, deterministic) modulo 100 against the split.
	 * Returns "B" when the result is strictly below `abSplitPercent`,
	 * otherwise "A". `null` / out-of-range `abSplitPercent` collapses to
	 * "A" (single-variant blast).
	 *
	 * @param string $contactId Contact id.
	 * @param int|null $abSplitPercent Split (0-100).
	 *
	 * @return string "A" or "B".
	 *
	 * @spec openspec/changes/marketing-segmentation-and-blast-04-blast-attribution-services/tasks.md#task-2.3
	 */
	public function variantFor(string $contactId, ?int $abSplitPercent): string {
		if ($abSplitPercent === null || $abSplitPercent <= 0 || $abSplitPercent > 100) {
			return 'A';
		}

		$bucket = (crc32($contactId) % 100);
		if ($bucket < $abSplitPercent) {
			return 'B';
		}

		return 'A';
	}//end variantFor()

	/**
	 * Persist a queued BlastDelivery row.
	 *
	 * @param string $blastId Target Blast id.
	 * @param array<string, string> $member Recipient projection.
	 * @param string $channel Channel.
	 *
	 * @return bool True when the row was persisted.
	 */
	private function persistQueuedDelivery(string $blastId, array $member, string $channel): bool {
		$payload = [
			'blastId' => $blastId,
			'contactId' => (string)($member['contactId'] ?? ''),
			'email' => (string)($member['email'] ?? ''),
			'status' => 'queued',
		];
		$unsubscribeUrl = (string)($member['unsubscribeUrl'] ?? '');
		if ($unsubscribeUrl !== '') {
			$payload['unsubscribeUrl'] = $unsubscribeUrl;
		}

		if ($channel === 'sms') {
			$payload['phone'] = (string)($member['phone'] ?? '');
		}

		$created = $this->saveObject(
			payload: $payload,
			schemaSlug: $this->getBlastDeliverySchemaSlug(),
		);
		return ($created !== null);
	}//end persistQueuedDelivery()

	/**
	 * POST one rendered delivery to the openconnector Source's base URL via
	 * `CallService::call()` and persist the result.
	 *
	 * @param array<string, mixed> $delivery Queued BlastDelivery row.
	 * @param array<string, mixed> $template CampaignTemplate row.
	 * @param array<string, mixed>|object $source Resolved openconnector Source
	 *                                            entity (from {@see resolveConnectorSource()}).
	 *                                            Always an `ObjectEntity` in
	 *                                            production; an array in tests
	 *                                            is tolerated for the same
	 *                                            reason {@see toArray()} exists.
	 * @param string $connectorSourceId Source UUID (for logging only —
	 *                                  `$source` is already resolved).
	 *
	 * @return bool True when the provider accepted the call (2xx response).
	 */
	private function sendOneDelivery(array $delivery, array $template, array|object $source, string $connectorSourceId): bool {
		$rendered = $this->renderTemplate(template: $template, delivery: $delivery);

		if ($this->firstPartyTrackingEnabled() === true) {
			$rendered['bodyHtml'] = $this->injectTrackingLinks(
				html: (string)($rendered['bodyHtml'] ?? ''),
				blastDeliveryId: $this->extractId(payload: $delivery),
			);
		}

		$callService = $this->getCallService();
		if ($callService === null) {
			return false;
		}

		try {
			$callLog = $callService->call(
				source: $source,
				endpoint: '',
				method: 'POST',
				config: ['json' => $rendered],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'BlastService.sendOneDelivery: connector call failed (transport)',
				['connectorSourceId' => $connectorSourceId, 'exception' => $e->getMessage()]
			);
			return false;
		}

		$callLogData = $this->toArray(value: $callLog);
		$statusCode = (int)($callLogData['statusCode'] ?? 0);
		if ($statusCode < 200 || $statusCode >= 300) {
			$this->logger->warning(
				'BlastService.sendOneDelivery: connector source responded with a non-2xx status',
				['connectorSourceId' => $connectorSourceId, 'statusCode' => $statusCode]
			);
			return false;
		}

		$providerId = $this->extractProviderId(
			result: $this->decodeCallLogResponseBody(callLogData: $callLogData),
		);

		$payload = $delivery;
		$payload['status'] = 'sent';
		$payload['sentAt'] = $this->nowIso();
		if ($providerId !== null && $providerId !== '') {
			$payload['providerId'] = $providerId;
		}

		$this->saveObject(
			payload: $payload,
			schemaSlug: $this->getBlastDeliverySchemaSlug(),
			id: $this->extractId(payload: $delivery),
		);

		return true;
	}//end sendOneDelivery()

	/**
	 * Resolve the OpenConnector Source object addressed by `$connectorSourceId`.
	 *
	 * Sources are OpenRegister objects in the `openconnector` register /
	 * `source` schema (OpenConnector's own CRUD, not pipelinq's `register`
	 * app-config register) — `OCA\OpenConnector\Service\SourceService`, the
	 * class this used to resolve through, no longer exists.
	 *
	 * @param string $connectorSourceId Source UUID.
	 *
	 * @return array<string, mixed>|object|null The resolved Source entity
	 *                                           (an `ObjectEntity` in
	 *                                           production), or null when
	 *                                           OpenRegister is unavailable
	 *                                           or the source does not exist.
	 */
	private function resolveConnectorSource(string $connectorSourceId): array|object|null {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$source = $objectService->find(
				id: $connectorSourceId,
				register: self::OPENCONNECTOR_REGISTER_SLUG,
				schema: self::OPENCONNECTOR_SOURCE_SCHEMA_SLUG,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'BlastService.resolveConnectorSource: lookup failed',
				['connectorSourceId' => $connectorSourceId, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if ($source === null) {
			$this->logger->warning(
				'BlastService.resolveConnectorSource: connector source not found',
				['connectorSourceId' => $connectorSourceId]
			);
			return null;
		}

		if (is_array($source) === false && is_object($source) === false) {
			return null;
		}

		return $source;
	}//end resolveConnectorSource()

	/**
	 * Resolve OpenConnector's `CallService` from the DI container.
	 *
	 * @return object|null The service, or null when OpenConnector is
	 *                      unavailable or lacks `call()`.
	 */
	private function getCallService(): ?object {
		try {
			$callService = $this->container->get('OCA\\OpenConnector\\Service\\CallService');
		} catch (Throwable $e) {
			$this->logger->error(
				'BlastService.getCallService: OpenConnector CallService unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		if (method_exists($callService, 'call') === false) {
			$this->logger->error('BlastService.getCallService: CallService lacks call()');
			return null;
		}

		return $callService;
	}//end getCallService()

	/**
	 * Decode a CallLog's `response.body` for provider-id extraction.
	 *
	 * Mirrors OpenConnector's own `SourceCallNode::decodeBody()` convention:
	 * a UTF-8 JSON-object body is decoded; anything else (non-UTF-8/base64,
	 * non-JSON, empty) is left for {@see extractProviderId()} to fail
	 * gracefully on.
	 *
	 * @param array<string, mixed> $callLogData CallLog payload
	 *                                          (`getObject()`/`jsonSerialize()` shape).
	 *
	 * @return mixed Decoded JSON body, or null when it cannot be decoded.
	 */
	private function decodeCallLogResponseBody(array $callLogData): mixed {
		$response = ($callLogData['response'] ?? null);
		if (is_array($response) === false) {
			return null;
		}

		$body = ($response['body'] ?? null);
		if (is_string($body) === false || $body === '') {
			return null;
		}

		if ((string)($response['encoding'] ?? 'UTF-8') !== 'UTF-8') {
			return null;
		}

		$decoded = json_decode($body, true);
		if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) === true) {
			return $decoded;
		}

		return null;
	}//end decodeCallLogResponseBody()

	/**
	 * Whether first-party open/click tracking is enabled.
	 *
	 * Off by default (`blast.first_party_tracking` app-config key) so the
	 * render path is byte-for-byte unchanged unless an admin opts in;
	 * telemetry then continues to arrive only via provider webhooks
	 * (marketing-email-open-click-tracking).
	 *
	 * @return bool True when the admin has enabled first-party tracking.
	 *
	 * @spec openspec/changes/marketing-email-open-click-tracking/tasks.md#3.2
	 */
	private function firstPartyTrackingEnabled(): bool {
		return $this->appConfig->getValueString(
			Application::APP_ID,
			'blast.first_party_tracking',
			'false',
		) === 'true';
	}//end firstPartyTrackingEnabled()

	/**
	 * Append `utm_*` campaign parameters to the template body's links via
	 * {@see CampaignLinkDecorator::decorate()}.
	 *
	 * Resolved lazily through the container like the tracking service, so
	 * an install whose container cannot build the decorator (or a test
	 * that never registered it) sends the body as authored. Fails soft:
	 * a decorator fault never blocks a send.
	 *
	 * @param string $html Template body HTML.
	 * @param array<string, mixed> $blast The blast row.
	 * @param array<string, mixed> $template The template row.
	 *
	 * @return string The decorated HTML, or the original on failure.
	 *
	 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-blast-links-carry-campaign-parameters
	 */
	private function decorateCampaignLinks(string $html, array $blast, array $template): string {
		if ($html === '') {
			return $html;
		}

		try {
			$decorator = $this->container->get('OCA\\Pipelinq\\Service\\CampaignLinkDecorator');
			return $decorator->decorate(html: $html, blast: $blast, template: $template);
		} catch (Throwable $e) {
			$this->logger->info(
				'BlastService.decorateCampaignLinks: decorator unavailable or failed, sending links as authored',
				['exception' => $e->getMessage()]
			);
			return $html;
		}
	}//end decorateCampaignLinks()

	/**
	 * Rewrite a rendered email body's links + append the open pixel via
	 * {@see TrackingLinkService::injectTracking()}.
	 *
	 * Resolved lazily through the DI container (not constructor-injected)
	 * because `TrackingLinkService` itself depends on `BlastService` for
	 * the totals roll-up — a constructor cycle would break the container.
	 * Fails soft: any resolution or injection error returns the original
	 * HTML unchanged so a tracking-service fault never blocks a send.
	 *
	 * @param string $html Rendered email body HTML.
	 * @param string $blastDeliveryId BlastDelivery UUID or slug.
	 *
	 * @return string The rewritten HTML, or the original on failure.
	 *
	 * @spec openspec/changes/marketing-email-open-click-tracking/tasks.md#3.2
	 */
	private function injectTrackingLinks(string $html, string $blastDeliveryId): string {
		if ($html === '' || $blastDeliveryId === '') {
			return $html;
		}

		try {
			$trackingLinkService = $this->container->get('OCA\\Pipelinq\\Service\\TrackingLinkService');
		} catch (Throwable $e) {
			$this->logger->info(
				'BlastService.injectTrackingLinks: TrackingLinkService unavailable',
				['exception' => $e->getMessage()]
			);
			return $html;
		}

		try {
			return $trackingLinkService->injectTracking(html: $html, blastDeliveryId: $blastDeliveryId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'BlastService.injectTrackingLinks: injection failed',
				['blastDeliveryId' => $blastDeliveryId, 'exception' => $e->getMessage()]
			);
			return $html;
		}
	}//end injectTrackingLinks()

	/**
	 * Extract a provider message id from a decoded connector response body.
	 *
	 * @param mixed $result Decoded response body.
	 *
	 * @return string|null Provider message id.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Flat fallback chain over candidate
	 *  id keys across the array/object result shapes; each branch is an early return.
	 */
	private function extractProviderId(mixed $result): ?string {
		if (is_array($result) === true) {
			foreach (['providerId', 'messageId', 'id'] as $key) {
				if (isset($result[$key]) === true && is_scalar($result[$key]) === true && (string)$result[$key] !== '') {
					return (string)$result[$key];
				}
			}
		}

		if (is_object($result) === true) {
			foreach (['providerId', 'messageId', 'id'] as $key) {
				if (isset($result->{$key}) === true && is_scalar($result->{$key}) === true && (string)$result->{$key} !== '') {
					return (string)$result->{$key};
				}
			}
		}

		if (is_string($result) === true && $result !== '') {
			return $result;
		}

		return null;
	}//end extractProviderId()

	/**
	 * Render the template's subject/body with per-recipient substitution.
	 *
	 * Substitution is intentionally minimal: `{{email}}` and `{{contactId}}`
	 * from the delivery snapshot, plus `{{unsubscribe_link}}` when the
	 * delivery carries one. A mailing list send always carries one, minted
	 * per membership by `SubscriptionQueryService`, because rule 1 of the
	 * marketing architecture says the unsubscribe is ours and not the
	 * provider's. A segment send has no membership to unsubscribe from, so
	 * the token resolves empty and the openconnector source appends its own
	 * as it does today.
	 *
	 * @param array<string, mixed> $template Template payload.
	 * @param array<string, mixed> $delivery Delivery payload.
	 *
	 * @return array<string, mixed> Rendered request body for the connector call.
	 */
	private function renderTemplate(array $template, array $delivery): array {
		$tokens = [
			'{{email}}' => (string)($delivery['email'] ?? ''),
			'{{contactId}}' => (string)($delivery['contactId'] ?? ''),
			'{{unsubscribe_link}}' => (string)($delivery['unsubscribeUrl'] ?? ''),
		];

		$subject = strtr((string)($template['subject'] ?? ''), $tokens);
		$bodyHtml = strtr((string)($template['bodyHtml'] ?? ''), $tokens);
		$bodyText = strtr((string)($template['bodyText'] ?? ''), $tokens);

		return [
			'to' => (string)($delivery['email'] ?? ''),
			'subject' => $subject,
			'bodyHtml' => $bodyHtml,
			'bodyText' => $bodyText,
			'senderName' => (string)($template['senderName'] ?? ''),
			'senderEmail' => (string)($template['senderEmail'] ?? ''),
			'replyTo' => (string)($template['replyTo'] ?? ''),
		];
	}//end renderTemplate()

	/**
	 * Resolve the effective rate limit (messages per second).
	 *
	 * The openconnector source's rate limit always wins when it is lower
	 * than the caller's value (so a tight provider limit cannot be blown by
	 * a permissive caller). When neither is set, fall back to
	 * `DEFAULT_RATE_LIMIT_PER_SECOND` (100).
	 *
	 * @param array<string, mixed>|object $source Resolved openconnector Source entity.
	 * @param int $callerRate Caller's max-per-second.
	 *
	 * @return int Resolved rate limit (>=1).
	 */
	private function resolveRateLimit(array|object $source, int $callerRate): int {
		$sourceRate = $this->readSourceRateLimit(source: $source);
		$candidate = self::DEFAULT_RATE_LIMIT_PER_SECOND;
		if ($callerRate > 0) {
			$candidate = $callerRate;
		}

		if ($sourceRate !== null && $sourceRate > 0 && $sourceRate < $candidate) {
			return $sourceRate;
		}

		return max($candidate, 1);
	}//end resolveRateLimit()

	/**
	 * Read the effective per-second rate limit from an openconnector source.
	 *
	 * The current Source schema has no single `sendRateLimit` field (that
	 * name never existed on the migrated schema); it carries
	 * `rateLimitLimit` (requests per window) and `rateLimitWindow` (window
	 * size in seconds) instead. Both are optional admin-configured fields —
	 * absent on most sources, in which case this returns null and the
	 * caller's own rate wins.
	 *
	 * @param array<string, mixed>|object $source Resolved openconnector Source entity.
	 *
	 * @return int|null Rate limit (messages/second) or null when unset.
	 */
	private function readSourceRateLimit(array|object $source): ?int {
		$limitValue = $this->readSourceField(source: $source, field: 'rateLimitLimit');
		if ($limitValue === null || is_numeric($limitValue) === false) {
			return null;
		}

		$limit = (int)$limitValue;
		if ($limit <= 0) {
			return null;
		}

		$windowValue = $this->readSourceField(source: $source, field: 'rateLimitWindow');
		$window = 1;
		if ($windowValue !== null && is_numeric($windowValue) === true) {
			$window = (int)$windowValue;
		}

		if ($window <= 0) {
			$window = 1;
		}

		return max(1, (int)floor($limit / $window));
	}//end readSourceRateLimit()

	/**
	 * Read a field from an openconnector source object or array.
	 *
	 * @param mixed $source The source row.
	 * @param string $field Field name.
	 *
	 * @return mixed Field value or null.
	 */
	private function readSourceField(mixed $source, string $field): mixed {
		if (is_array($source) === true) {
			return ($source[$field] ?? null);
		}

		if (is_object($source) === true) {
			$getter = 'get' . ucfirst($field);
			if (method_exists($source, $getter) === true) {
				return $source->{$getter}();
			}

			if (isset($source->{$field}) === true) {
				return $source->{$field};
			}

			if (method_exists($source, 'jsonSerialize') === true) {
				$serialised = $source->jsonSerialize();
				if (is_array($serialised) === true && isset($serialised[$field]) === true) {
					return $serialised[$field];
				}
			}
		}

		return null;
	}//end readSourceField()

	/**
	 * Sleep for `$seconds` (float). Indirected to a method so tests can
	 * stub it out without sleeping.
	 *
	 * @param float $seconds How long to sleep.
	 *
	 * @return void
	 */
	protected function throttle(float $seconds): void {
		$micro = (int)round(($seconds * 1_000_000));
		if ($micro > 0) {
			usleep($micro);
		}
	}//end throttle()

	/**
	 * Resolve the dispatch batch size from app config.
	 *
	 * @return int Batch size.
	 */
	private function resolveBatchSize(): int {
		$configured = $this->appConfig->getValueString(
			Application::APP_ID,
			'blast.dispatch_batch_size',
			(string)self::DEFAULT_DISPATCH_BATCH_SIZE,
		);
		if ($configured === '' || is_numeric($configured) === false) {
			return self::DEFAULT_DISPATCH_BATCH_SIZE;
		}

		$size = (int)$configured;
		if ($size <= 0) {
			return self::DEFAULT_DISPATCH_BATCH_SIZE;
		}

		return $size;
	}//end resolveBatchSize()

	/**
	 * Persist a payload via OpenRegister's ObjectService.
	 *
	 * @param array<string, mixed> $payload Payload.
	 * @param string $schemaSlug Schema slug.
	 * @param string|null $id Existing object id or null.
	 *
	 * @return array<string, mixed>|null Saved row or null on failure.
	 */
	private function saveObject(array $payload, string $schemaSlug, ?string $id = null): ?array {
		$register = $this->getRegisterSlug();
		if ($register === '' || $schemaSlug === '') {
			return null;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$saved = $objectService->saveObject(
				object: $payload,
				register: $register,
				schema: $schemaSlug,
				uuid: $id,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'BlastService.saveObject: save failed',
				['schema' => $schemaSlug, 'exception' => $e->getMessage()]
			);
			return null;
		}

		return $this->toArray(value: $saved);
	}//end saveObject()

	/**
	 * Load one Blast payload.
	 *
	 * @param string $blastId Blast UUID or slug.
	 *
	 * @return array<string, mixed>|null Blast payload or null.
	 */
	private function loadBlast(string $blastId): ?array {
		return $this->loadOne(id: $blastId, schemaSlug: $this->getBlastSchemaSlug());
	}//end loadBlast()

	/**
	 * Load one CampaignTemplate payload.
	 *
	 * @param string $templateId Template UUID or slug.
	 *
	 * @return array<string, mixed>|null Template payload or null.
	 */
	private function loadTemplate(string $templateId): ?array {
		if ($templateId === '') {
			return null;
		}

		return $this->loadOne(id: $templateId, schemaSlug: $this->getCampaignTemplateSchemaSlug());
	}//end loadTemplate()

	/**
	 * Load one object by id and schema slug.
	 *
	 * @param string $id Object UUID or slug.
	 * @param string $schemaSlug Schema slug.
	 *
	 * @return array<string, mixed>|null Payload or null.
	 */
	private function loadOne(string $id, string $schemaSlug): ?array {
		$register = $this->getRegisterSlug();
		if ($register === '' || $schemaSlug === '') {
			return null;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$entity = $objectService->find(
				id: $id,
				register: $register,
				schema: $schemaSlug,
			);
		} catch (Throwable $e) {
			$this->logger->info(
				'BlastService.loadOne: not found',
				['id' => $id, 'schema' => $schemaSlug, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $this->toArray(value: $entity);
	}//end loadOne()

	/**
	 * Load every BlastDelivery row for a Blast.
	 *
	 * @param string $blastId Blast UUID or slug.
	 *
	 * @return array<int, array<string, mixed>> Delivery rows.
	 */
	private function loadAllDeliveriesForBlast(string $blastId): array {
		return $this->loadDeliveries(filters: ['blastId' => $blastId]);
	}//end loadAllDeliveriesForBlast()

	/**
	 * Load queued BlastDelivery rows for a Blast.
	 *
	 * @param string $blastId Blast UUID or slug.
	 *
	 * @return array<int, array<string, mixed>> Queued rows.
	 */
	private function loadQueuedDeliveries(string $blastId): array {
		return $this->loadDeliveries(filters: ['blastId' => $blastId, 'status' => 'queued']);
	}//end loadQueuedDeliveries()

	/**
	 * Load queued rows for one contact on one blast.
	 *
	 * @param string $blastId Blast UUID or slug.
	 * @param string $contactId Contact UUID or slug.
	 *
	 * @return array<int, array<string, mixed>> Queued rows for the contact.
	 */
	private function loadQueuedDeliveriesForContact(string $blastId, string $contactId): array {
		return $this->loadDeliveries(
			filters: ['blastId' => $blastId, 'contactId' => $contactId, 'status' => 'queued'],
		);
	}//end loadQueuedDeliveriesForContact()

	/**
	 * Run a findAll against BlastDelivery with the given filters.
	 *
	 * @param array<string, mixed> $filters Filter map.
	 * @param int|null $limit Optional page size pushed to OR.
	 * @param int|null $offset Optional offset pushed to OR.
	 *
	 * @return array<int, array<string, mixed>> Matching rows.
	 */
	private function loadDeliveries(array $filters, ?int $limit = null, ?int $offset = null): array {
		$register = $this->getRegisterSlug();
		$schema = $this->getBlastDeliverySchemaSlug();
		if ($register === '' || $schema === '') {
			return [];
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$config = [
			'filters' => array_merge(['register' => $register, 'schema' => $schema], $filters),
		];
		if ($limit !== null) {
			$config['limit'] = $limit;
		}

		if ($offset !== null) {
			$config['offset'] = $offset;
		}

		try {
			$rows = $objectService->findAll(config: $config);
		} catch (Throwable $e) {
			$this->logger->warning(
				'BlastService.loadDeliveries: findAll failed',
				['filters' => $filters, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$out = [];
		foreach (($rows ?? []) as $row) {
			$out[] = $this->toArray(value: $row);
		}

		return $out;
	}//end loadDeliveries()

	/**
	 * Count BlastDelivery rows matching the given filters via OpenRegister.
	 *
	 * @param array<string, mixed> $filters Filter map.
	 *
	 * @return int Matching row count, or 0 when OR is unavailable.
	 *
	 * @spec openspec/changes/pipelinq-query-pushdown-batch-1/tasks.md#task-5
	 */
	private function countDeliveries(array $filters): int {
		$register = $this->getRegisterSlug();
		$schema = $this->getBlastDeliverySchemaSlug();
		if ($register === '' || $schema === '') {
			return 0;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return 0;
		}

		try {
			return $objectService->count(
				config: [
					'filters' => array_merge(['register' => $register, 'schema' => $schema], $filters),
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'BlastService.countDeliveries: count failed',
				['filters' => $filters, 'exception' => $e->getMessage()]
			);
			return 0;
		}
	}//end countDeliveries()

	/**
	 * Update only the `status` field on a Blast.
	 *
	 * @param string $blastId Blast UUID or slug.
	 * @param string $newStatus New status.
	 *
	 * @return void
	 */
	private function updateBlastStatus(string $blastId, string $newStatus): void {
		$blast = $this->loadBlast(blastId: $blastId);
		if ($blast === null) {
			return;
		}

		$payload = $blast;
		$payload['status'] = $newStatus;
		if ($newStatus === 'sending' && empty($blast['sentAt']) === true) {
			$payload['sentAt'] = $this->nowIso();
		}

		$this->saveObject(
			payload: $payload,
			schemaSlug: $this->getBlastSchemaSlug(),
			id: $this->extractId(payload: $blast),
		);
	}//end updateBlastStatus()

	/**
	 * Extract `abSplitPercent` from a Blast payload.
	 *
	 * @param array<string, mixed> $blast Blast payload.
	 *
	 * @return int|null Split or null if absent.
	 */
	private function extractAbSplitPercent(array $blast): ?int {
		$raw = ($blast['abSplitPercent'] ?? null);
		if ($raw === null || is_numeric($raw) === false) {
			return null;
		}

		$value = (int)$raw;
		if ($value < 0 || $value > 100) {
			return null;
		}

		return $value;
	}//end extractAbSplitPercent()

	/**
	 * Default-zero `totals` map matching the Blast schema's keys.
	 *
	 * @return array<string, int> Totals map.
	 */
	private function emptyTotals(): array {
		return [
			'queued' => 0,
			'sent' => 0,
			'delivered' => 0,
			'bounced' => 0,
			'opened' => 0,
			'clicked' => 0,
			'unsubscribed' => 0,
			'complained' => 0,
		];
	}//end emptyTotals()

	/**
	 * Empty send summary skeleton.
	 *
	 * @param string $status Status label.
	 *
	 * @return array<string, mixed> Summary.
	 */
	private function emptySummary(string $status): array {
		return [
			'queued' => 0,
			'skippedNoConsent' => 0,
			'variantA' => 0,
			'variantB' => 0,
			'variantBlastId' => null,
			'status' => $status,
		];
	}//end emptySummary()

	/**
	 * Extract the object id from a saved payload.
	 *
	 * @param array<string, mixed> $payload Payload.
	 *
	 * @return string Id (uuid / id / slug) or empty.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Flat fallback chain over candidate
	 *  id keys; each branch is an independent early return.
	 */
	private function extractId(array $payload): string {
		foreach (['uuid', 'id', 'slug'] as $key) {
			if (isset($payload[$key]) === true && is_scalar($payload[$key]) === true && (string)$payload[$key] !== '') {
				return (string)$payload[$key];
			}
		}

		if (isset($payload['@self']) === true && is_array($payload['@self']) === true) {
			foreach (['uuid', 'id', 'slug'] as $key) {
				$value = ($payload['@self'][$key] ?? null);
				if (is_scalar($value) === true && (string)$value !== '') {
					return (string)$value;
				}
			}
		}

		return '';
	}//end extractId()

	/**
	 * Resolve the Blast schema slug from app config.
	 *
	 * @return string Slug.
	 */
	private function getBlastSchemaSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'blast_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_BLAST_SCHEMA_SLUG;
	}//end getBlastSchemaSlug()

	/**
	 * Resolve the BlastDelivery schema slug from app config.
	 *
	 * @return string Slug.
	 */
	private function getBlastDeliverySchemaSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'blastDelivery_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_BLAST_DELIVERY_SCHEMA_SLUG;
	}//end getBlastDeliverySchemaSlug()

	/**
	 * Resolve the CampaignTemplate schema slug from app config.
	 *
	 * @return string Slug.
	 */
	private function getCampaignTemplateSchemaSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'campaignTemplate_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_CAMPAIGN_TEMPLATE_SCHEMA_SLUG;
	}//end getCampaignTemplateSchemaSlug()

	/**
	 * Resolve the register slug from app config.
	 *
	 * @return string Slug.
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_REGISTER_SLUG;
	}//end getRegisterSlug()

	/**
	 * Resolve the OpenRegister ObjectService lazily.
	 *
	 * @return object|null ObjectService or null when OR is unavailable.
	 */
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'BlastService.getObjectService: OpenRegister unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end getObjectService()

	/**
	 * Normalise an OpenRegister entity or array to a plain array.
	 *
	 * @param mixed $value Entity object or array.
	 *
	 * @return array<string, mixed> Plain payload.
	 */
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($value) === true && method_exists($value, 'getObject') === true) {
			$payload = $value->getObject();
			if (is_array($payload) === true) {
				return $payload;
			}
		}

		return [];
	}//end toArray()

	/**
	 * Current time as an ISO-8601 string.
	 *
	 * @return string Timestamp.
	 */
	private function nowIso(): string {
		return gmdate('Y-m-d\TH:i:s\Z');
	}//end nowIso()
}//end class
