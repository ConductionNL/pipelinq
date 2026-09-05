<?php

/**
 * Pipelinq BlastService.
 *
 * Send-orchestration engine for the marketing-segmentation-and-blast
 * chain. Owns the lifecycle transition from `draft` Blast to a queue of
 * BlastDelivery rows and the throttled batch dispatch. The per-delivery send
 * itself (render, resolve the Blast's `mailTransport`, dispatch to the
 * matching adapter) lives in
 * `OCA\Pipelinq\Service\Marketing\MailTransportService` since
 * marketing-mail-transports: BlastService never touches provider SDKs or
 * credentials, and no longer touches an OpenConnector source directly
 * either — that is `ConnectorSourceTransport`'s job, one of three transport
 * adapters `MailTransportService` picks between.
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
use OCA\Pipelinq\Service\Marketing\MailTransportService;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * BlastService — orchestrates a Blast send.
 *
 * Public entry points:
 * - `sendBlast()` — compliance-gate, queue BlastDeliveries, optional A/B split
 * - `dispatchBlastDeliveries()` — throttled dispatch via the resolved mailTransport
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
	 * Status used to mark a queued BlastDelivery that was cut by
	 * ComplianceService before dispatch.
	 */
	private const STATUS_UNSUBSCRIBED_BEFORE_SEND = 'unsubscribed-before-send';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container.
	 * @param IAppConfig $appConfig Pipelinq app config.
	 * @param SegmentService $segmentService Segment evaluator.
	 * @param MailTransportService $mailTransportService Transport resolution + per-delivery dispatch.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/marketing-segmentation-and-blast-04-blast-attribution-services/tasks.md#task-2.3
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private SegmentService $segmentService,
		private MailTransportService $mailTransportService,
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

		$transport = $this->mailTransportService->resolveTransport(blast: $blast);
		if ($transport === null) {
			$this->logger->warning(
				'BlastService.dispatchBlastDeliveries: no mail transport resolved for blast',
				['blastId' => $blastId]
			);
			return 0;
		}

		$rateLimit = $this->mailTransportService->resolveRateLimit(transport: $transport, callerRate: $maxPerSecond);
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
				$result = $this->mailTransportService->sendOneDelivery(
					delivery: $delivery,
					template: $template,
					transport: $transport,
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
			'transportId' => (string)($parent['transportId'] ?? ''),
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
			'transportId' => (string)($payload['transportId'] ?? ''),
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
	 * Append `utm_*` campaign parameters to the template body's links via
	 * {@see CampaignLinkDecorator::decorate()}.
	 *
	 * The blast's campaign is loaded first, so a blast that belongs to one
	 * carries the campaign's source, medium and campaign value instead of
	 * the per-blast defaults. A blast that belongs to none is decorated
	 * exactly as before.
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
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-tracked-link-is-minted-from-the-campaign-when-there-is-one
	 */
	private function decorateCampaignLinks(string $html, array $blast, array $template): string {
		if ($html === '') {
			return $html;
		}

		try {
			$decorator = $this->container->get('OCA\\Pipelinq\\Service\\CampaignLinkDecorator');
			return $decorator->decorate(
				html: $html,
				blast: $blast,
				template: $template,
				campaign: $this->campaignForBlast(blast: $blast),
			);
		} catch (Throwable $e) {
			$this->logger->info(
				'BlastService.decorateCampaignLinks: decorator unavailable or failed, sending links as authored',
				['exception' => $e->getMessage()]
			);
			return $html;
		}
	}//end decorateCampaignLinks()

	/**
	 * The campaign a blast belongs to, or an empty array.
	 *
	 * Lazy and fail-soft for the same reason as the decorator itself: a
	 * campaign that cannot be read must cost the blast its campaign
	 * parameters, never its send.
	 *
	 * @param array<string, mixed> $blast The blast row.
	 *
	 * @return array<string, mixed> The campaign, or an empty array.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-tracked-link-is-minted-from-the-campaign-when-there-is-one
	 */
	private function campaignForBlast(array $blast): array {
		if (trim((string)($blast['campaignId'] ?? '')) === '') {
			return [];
		}

		try {
			$campaigns = $this->container->get('OCA\\Pipelinq\\Service\\CampaignService');
			return $campaigns->forBlast(blast: $blast);
		} catch (Throwable $e) {
			$this->logger->info(
				'BlastService.campaignForBlast: campaign unavailable, using the per-blast parameters',
				['exception' => $e->getMessage()]
			);
			return [];
		}
	}//end campaignForBlast()

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
