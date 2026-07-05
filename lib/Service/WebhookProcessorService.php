<?php

/**
 * Pipelinq WebhookProcessorService.
 *
 * Provider-agnostic blast-event processor for the marketing chain.
 * BlastWebhookController verifies the provider HMAC signature, enqueues
 * the raw event payload into the per-app webhook queue, and returns HTTP
 * 200 immediately. {@see BlastSendJob} then drains that queue and calls
 * this service to translate each event into a BlastDelivery status
 * transition plus the matching ComplianceService / AttributionService
 * side-effect (consent withdrawal, click attribution).
 *
 * Routing matrix:
 *   - `delivered`             → BlastDelivery.status = "delivered"
 *   - `bounce` (hard)         → status = "bounced" + recordConsentWithdrawal("bounce-hard")
 *   - `bounce` (soft, x5)     → soft-bounce counter; on threshold (5)
 *                               recordConsentWithdrawal("bounce-soft-x5")
 *   - `open`                  → BlastDelivery.openedAt set
 *   - `click`                 → AttributionService.recordClick() (utm_campaign)
 *   - `unsubscribe`           → recordConsentWithdrawal("user-unsubscribed")
 *   - `spam_report` / `complaint` → status = "complained"
 *
 * Pipelinq code never talks to SendGrid / SES / Twilio directly — the
 * BlastService dispatch path uses openconnector (ADR-005); this service
 * only normalises inbound events into the chain root schemas.
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
 * @spec openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#webhooks
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use Closure;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * WebhookProcessorService — translates one provider event into Pipelinq
 * state transitions.
 *
 * Public surface:
 * - `processEvent(array $event, string $provider)` — single entry point used
 *   by the queue drain (BlastSendJob) and by the controller fast-path.
 * - `processSendGridEvent` / `processBounce` / `processOpen` /
 *   `processClick` / `processUnsubscribe` / `processComplaint` — the
 *   event-type handlers exposed for tests and direct dispatch.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Wires three sibling
 * services + ObjectService. Each method has one collaborator.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One event-type handler per
 * routing-matrix row (delivered/bounce/open/click/unsubscribe/complaint), each
 * independently unit-tested; splitting would scatter one cohesive webhook contract.
 *
 * @spec openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#webhooks
 */
class WebhookProcessorService
{
    /**
     * Default register slug — matches the chain root schemas.
     */
    private const DEFAULT_REGISTER_SLUG = 'pipelinq';

    /**
     * Default BlastDelivery schema slug.
     */
    private const DEFAULT_BLAST_DELIVERY_SCHEMA_SLUG = 'blastDelivery';

    /**
     * App-config key holding the soft-bounce threshold (default 5).
     */
    private const APP_CONFIG_SOFT_BOUNCE_THRESHOLD = 'blast.soft_bounce_threshold';

    /**
     * Default soft-bounce threshold — withdraw consent after this many
     * consecutive soft bounces.
     */
    private const DEFAULT_SOFT_BOUNCE_THRESHOLD = 5;

    /**
     * App-config key prefix for per-contact soft-bounce counters.
     */
    private const SOFT_BOUNCE_KEY_PREFIX = 'blast.soft_bounce_count.';

    /**
     * Constructor.
     *
     * @param ContainerInterface $container          DI container — lazy
     *                                               resolves ObjectService +
     *                                               sibling services.
     * @param IAppConfig         $appConfig          Pipelinq app config.
     * @param ComplianceService  $complianceService  Consent withdrawal sink.
     * @param AttributionService $attributionService Click attribution sink.
     * @param BlastService       $blastService       Totals re-computation sink.
     * @param LoggerInterface    $logger             Logger.
     *
     * @spec openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#webhooks
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private ComplianceService $complianceService,
        private AttributionService $attributionService,
        private BlastService $blastService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Process one normalised provider event.
     *
     * Dispatches by `eventType` to the matching `process*` handler. Unknown
     * event types are logged and skipped — providers add new event types
     * regularly and the job must not crash on an unrecognised one.
     *
     * @param array<string, mixed> $event    Normalised event payload.
     * @param string               $provider Provider key ("sendgrid", "ses",
     *                                       "twilio").
     *
     * @return bool True when the event was handled (including
     *              intentionally-skipped unknowns).
     *
     * @spec openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#webhooks
     */
    public function processEvent(array $event, string $provider): bool
    {
        $eventType = strtolower((string) ($event['eventType'] ?? $event['event'] ?? ''));
        if ($eventType === '') {
            $this->logger->info(
                'WebhookProcessorService.processEvent: missing eventType',
                ['provider' => $provider]
            );
            return false;
        }

        $handler = $this->resolveEventHandler(eventType: $eventType);
        if ($handler === null) {
            $this->logger->info(
                'WebhookProcessorService.processEvent: unhandled eventType',
                ['provider' => $provider, 'eventType' => $eventType]
            );
            return true;
        }

        try {
            $handler($event);
        } catch (Throwable $e) {
            $this->logger->warning(
                'WebhookProcessorService.processEvent: handler failed',
                ['provider' => $provider, 'eventType' => $eventType, 'exception' => $e->getMessage()]
            );
            return false;
        }

        return true;
    }//end processEvent()

    /**
     * Resolve the process* handler closure for a normalised event type.
     *
     * Table-driven equivalent of the previous switch: keeps the exact same
     * eventType => handler routing (including provider aliases that share a
     * handler) while keeping cyclomatic complexity low.
     *
     * @param string $eventType Lower-cased event type / alias.
     *
     * @return Closure(array<string, mixed>): void|null The handler, or null when unrecognised.
     */
    private function resolveEventHandler(string $eventType): ?Closure
    {
        $handlers = [
            'delivered'         => $this->processDelivered(...),
            'bounce'            => $this->processBounce(...),
            'bounced'           => $this->processBounce(...),
            'open'              => $this->processOpen(...),
            'opened'            => $this->processOpen(...),
            'click'             => $this->processClick(...),
            'clicked'           => $this->processClick(...),
            'unsubscribe'       => $this->processUnsubscribe(...),
            'unsubscribed'      => $this->processUnsubscribe(...),
            'group_unsubscribe' => $this->processUnsubscribe(...),
            'spamreport'        => $this->processComplaint(...),
            'spam_report'       => $this->processComplaint(...),
            'complaint'         => $this->processComplaint(...),
            'complained'        => $this->processComplaint(...),
        ];

        return $handlers[$eventType] ?? null;
    }//end resolveEventHandler()

    /**
     * SendGrid POSTs a JSON array of events. Iterate and dispatch each.
     *
     * @param array<int, array<string, mixed>> $events Raw SendGrid batch.
     *
     * @return int Count of events handled.
     *
     * @spec openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#webhooks
     */
    public function processSendGridEvent(array $events): int
    {
        $handled = 0;
        foreach ($events as $raw) {
            $normalised = $this->normaliseSendGrid(event: $raw);
            if ($this->processEvent(event: $normalised, provider: 'sendgrid') === true) {
                $handled++;
            }
        }

        return $handled;
    }//end processSendGridEvent()

    /**
     * Handle a `delivered` event — set BlastDelivery.deliveredAt + status.
     *
     * @param array<string, mixed> $event Normalised event.
     *
     * @return void
     *
     * @spec openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#webhooks
     */
    public function processDelivered(array $event): void
    {
        $delivery = $this->locateDelivery(event: $event);
        if ($delivery === null) {
            return;
        }

        $payload = $delivery;
        if (empty($payload['deliveredAt']) === true) {
            $payload['deliveredAt'] = $this->extractTimestamp(event: $event);
        }

        $current = (string) ($payload['status'] ?? '');
        if (in_array($current, ['queued', 'sent'], true) === true) {
            $payload['status'] = 'delivered';
        }

        $this->saveDelivery(payload: $payload, id: $this->extractId(payload: $delivery));
    }//end processDelivered()

    /**
     * Handle a `bounce` event.
     *
     * Hard bounces → withdraw consent immediately with reason `bounce-hard`.
     * Soft bounces → increment a per-contact counter; once it hits the
     * threshold (default 5, configurable via `blast.soft_bounce_threshold`)
     * withdraw consent with reason `bounce-soft-x5` (the literal label
     * matches the audit-trail convention in the spec).
     *
     * @param array<string, mixed> $event Normalised event.
     *
     * @return void
     *
     * @spec openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#bounce-handling
     */
    public function processBounce(array $event): void
    {
        $delivery = $this->locateDelivery(event: $event);
        if ($delivery === null) {
            return;
        }

        $bounceType = strtolower((string) ($event['bounceType'] ?? $event['type'] ?? ''));
        if ($bounceType === '') {
            $bounceType = 'hard';
        }

        $payload           = $delivery;
        $payload['status'] = 'bounced';
        $payload['bounceType'] = $bounceType;
        $payload['bouncedAt']  = $this->extractTimestamp(event: $event);
        $bounceReason          = (string) ($event['reason'] ?? $event['bounceReason'] ?? '');
        if ($bounceReason !== '') {
            $payload['bounceReason'] = $bounceReason;
        }

        $this->saveDelivery(payload: $payload, id: $this->extractId(payload: $delivery));

        $contactId = (string) ($delivery['contactId'] ?? '');
        $channel   = (string) ($delivery['channel'] ?? 'email');
        $blastId   = (string) ($delivery['blastId'] ?? '');
        if ($contactId === '') {
            return;
        }

        if ($bounceType === 'hard') {
            $this->complianceService->recordConsentWithdrawal(
                contactId: $contactId,
                channel: $channel,
                reason: 'bounce-hard',
                sourceBlastId: $this->blastIdOrNull(blastId: $blastId),
            );
            $this->clearSoftBounceCounter(contactId: $contactId, channel: $channel);
            return;
        }

        // Soft bounce — bump per-contact counter; withdraw when at threshold.
        $threshold = $this->resolveSoftBounceThreshold();
        $next      = $this->incrementSoftBounceCounter(contactId: $contactId, channel: $channel);
        if ($next >= $threshold) {
            $this->complianceService->recordConsentWithdrawal(
                contactId: $contactId,
                channel: $channel,
                reason: 'bounce-soft-x'.$threshold,
                sourceBlastId: $this->blastIdOrNull(blastId: $blastId),
            );
            $this->clearSoftBounceCounter(contactId: $contactId, channel: $channel);
        }
    }//end processBounce()

    /**
     * Handle an `open` event — set BlastDelivery.openedAt + status.
     *
     * @param array<string, mixed> $event Normalised event.
     *
     * @return void
     *
     * @spec openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#webhooks
     */
    public function processOpen(array $event): void
    {
        $delivery = $this->locateDelivery(event: $event);
        if ($delivery === null) {
            return;
        }

        $payload = $delivery;
        if (empty($payload['openedAt']) === true) {
            $payload['openedAt'] = $this->extractTimestamp(event: $event);
        }

        $current = (string) ($payload['status'] ?? '');
        if (in_array($current, ['delivered', 'sent'], true) === true) {
            $payload['status'] = 'opened';
        }

        $this->saveDelivery(payload: $payload, id: $this->extractId(payload: $delivery));
    }//end processOpen()

    /**
     * Handle a `click` event — delegate to AttributionService and extract
     * `utm_campaign` from the URL query string for downstream reporting.
     *
     * @param array<string, mixed> $event Normalised event.
     *
     * @return void
     *
     * @spec openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#click-attribution
     */
    public function processClick(array $event): void
    {
        $delivery = $this->locateDelivery(event: $event);
        if ($delivery === null) {
            return;
        }

        $url       = (string) ($event['url'] ?? '');
        $timestamp = $this->extractTimestamp(event: $event);
        $utm       = $this->extractUtmCampaign(url: $url);

        $clickPayload = [
            'url'         => $url,
            'timestamp'   => $timestamp,
            'utmCampaign' => $utm,
        ];

        $deliveryId = $this->extractId(payload: $delivery);
        if ($deliveryId === '') {
            return;
        }

        $this->attributionService->recordClick(blastDeliveryId: $deliveryId, clickEvent: $clickPayload);
    }//end processClick()

    /**
     * Handle an `unsubscribe` event — withdraw consent with reason
     * `user-unsubscribed`.
     *
     * @param array<string, mixed> $event Normalised event.
     *
     * @return void
     *
     * @spec openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#unsubscribe-propagation
     */
    public function processUnsubscribe(array $event): void
    {
        $delivery = $this->locateDelivery(event: $event);
        if ($delivery === null) {
            return;
        }

        $payload           = $delivery;
        $payload['status'] = 'unsubscribed';
        $payload['unsubscribedAt'] = $this->extractTimestamp(event: $event);
        $this->saveDelivery(payload: $payload, id: $this->extractId(payload: $delivery));

        $contactId = (string) ($delivery['contactId'] ?? '');
        $channel   = (string) ($delivery['channel'] ?? 'email');
        $blastId   = (string) ($delivery['blastId'] ?? '');
        if ($contactId === '') {
            return;
        }

        $this->complianceService->recordConsentWithdrawal(
            contactId: $contactId,
            channel: $channel,
            reason: 'user-unsubscribed',
            sourceBlastId: $this->blastIdOrNull(blastId: $blastId),
        );
    }//end processUnsubscribe()

    /**
     * Handle a `spam_report` / `complaint` event — mark the delivery as
     * complained AND withdraw consent (a spam complaint is a stronger
     * signal than an unsubscribe; never email this contact again on this
     * channel).
     *
     * @param array<string, mixed> $event Normalised event.
     *
     * @return void
     *
     * @spec openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#webhooks
     */
    public function processComplaint(array $event): void
    {
        $delivery = $this->locateDelivery(event: $event);
        if ($delivery === null) {
            return;
        }

        $payload           = $delivery;
        $payload['status'] = 'complained';
        $payload['complainedAt'] = $this->extractTimestamp(event: $event);
        $this->saveDelivery(payload: $payload, id: $this->extractId(payload: $delivery));

        $contactId = (string) ($delivery['contactId'] ?? '');
        $channel   = (string) ($delivery['channel'] ?? 'email');
        $blastId   = (string) ($delivery['blastId'] ?? '');
        if ($contactId === '') {
            return;
        }

        $this->complianceService->recordConsentWithdrawal(
            contactId: $contactId,
            channel: $channel,
            reason: 'spam-complaint',
            sourceBlastId: $this->blastIdOrNull(blastId: $blastId),
        );
    }//end processComplaint()

    /**
     * Normalise a SendGrid event into our internal shape.
     *
     * SendGrid uses snake_case for some fields and embeds the BlastDelivery
     * id in `sg_message_id` (which we set as the `providerId` on the row
     * at dispatch time — see {@see BlastService::sendOneDelivery()}).
     *
     * @param array<string, mixed> $event Raw SendGrid event.
     *
     * @return array<string, mixed> Normalised event.
     *
     * @spec openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#webhooks
     */
    public function normaliseSendGrid(array $event): array
    {
        $type = strtolower((string) ($event['event'] ?? ''));
        if ($type === 'spamreport') {
            $type = 'spam_report';
        }

        $bounceType = strtolower((string) ($event['type'] ?? ''));
        if ($bounceType === '' && $type === 'bounce') {
            $bounceType = 'hard';
        }

        return [
            'eventType'       => $type,
            'providerId'      => (string) ($event['sg_message_id'] ?? $event['providerId'] ?? ''),
            'blastDeliveryId' => (string) ($event['blastDeliveryId'] ?? ''),
            'contactId'       => (string) ($event['contactId'] ?? ''),
            'email'           => (string) ($event['email'] ?? ''),
            'timestamp'       => $this->coerceTimestamp(raw: ($event['timestamp'] ?? null)),
            'url'             => (string) ($event['url'] ?? ''),
            'bounceType'      => $bounceType,
            'reason'          => (string) ($event['reason'] ?? ''),
        ];
    }//end normaliseSendGrid()

    /**
     * Locate the BlastDelivery row referenced by an event.
     *
     * Lookup order:
     *   1. explicit `blastDeliveryId` from the event payload;
     *   2. `providerId` (provider message id stored on the row at dispatch);
     *   3. (contactId, blastId) when both are present.
     *
     * @param array<string, mixed> $event Normalised event.
     *
     * @return array<string, mixed>|null Delivery row or null.
     */
    private function locateDelivery(array $event): ?array
    {
        $deliveryId = (string) ($event['blastDeliveryId'] ?? '');
        if ($deliveryId !== '') {
            $row = $this->loadDeliveryById(id: $deliveryId);
            if ($row !== null) {
                return $row;
            }
        }

        $providerId = (string) ($event['providerId'] ?? '');
        if ($providerId !== '') {
            $rows = $this->findDeliveries(filters: ['providerId' => $providerId]);
            if ($rows !== []) {
                return $rows[0];
            }
        }

        $contactId = (string) ($event['contactId'] ?? '');
        $blastId   = (string) ($event['blastId'] ?? '');
        if ($contactId !== '' && $blastId !== '') {
            $rows = $this->findDeliveries(filters: ['contactId' => $contactId, 'blastId' => $blastId]);
            if ($rows !== []) {
                return $rows[0];
            }
        }

        return null;
    }//end locateDelivery()

    /**
     * Coerce an empty blast id to null so
     * `ComplianceService::recordConsentWithdrawal()` records "no source
     * blast" rather than the empty-string sentinel.
     *
     * @param string $blastId Possibly-empty blast id.
     *
     * @return string|null Blast id or null.
     */
    private function blastIdOrNull(string $blastId): ?string
    {
        if ($blastId === '') {
            return null;
        }

        return $blastId;
    }//end blastIdOrNull()

    /**
     * Extract a normalised ISO timestamp from an event.
     *
     * @param array<string, mixed> $event Event payload.
     *
     * @return string ISO-8601 UTC.
     */
    private function extractTimestamp(array $event): string
    {
        return $this->coerceTimestamp(raw: ($event['timestamp'] ?? null));
    }//end extractTimestamp()

    /**
     * Coerce an arbitrary timestamp value into an ISO-8601 UTC string.
     *
     * Accepts unix integers, ISO strings, or null (→ "now").
     *
     * @param mixed $raw Raw timestamp.
     *
     * @return string ISO timestamp.
     */
    private function coerceTimestamp(mixed $raw): string
    {
        if ($raw === null || $raw === '') {
            return gmdate('Y-m-d\TH:i:s\Z');
        }

        if (is_int($raw) === true || (is_string($raw) === true && ctype_digit($raw) === true)) {
            return gmdate('Y-m-d\TH:i:s\Z', (int) $raw);
        }

        if (is_string($raw) === true) {
            $parsed = strtotime($raw);
            if ($parsed !== false) {
                return gmdate('Y-m-d\TH:i:s\Z', $parsed);
            }
        }

        return gmdate('Y-m-d\TH:i:s\Z');
    }//end coerceTimestamp()

    /**
     * Extract the `utm_campaign` query parameter from a URL.
     *
     * @param string $url Full clicked URL.
     *
     * @return string utm_campaign value or empty when absent.
     */
    private function extractUtmCampaign(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $query = parse_url($url, PHP_URL_QUERY);
        if (is_string($query) === false || $query === '') {
            return '';
        }

        parse_str($query, $params);
        return (string) ($params['utm_campaign'] ?? '');
    }//end extractUtmCampaign()

    /**
     * Soft-bounce counter — read/inc/clear via app-config so the count
     * survives restarts. Key: `blast.soft_bounce_count.<contact>.<channel>`.
     *
     * @param string $contactId Contact UUID or slug.
     * @param string $channel   Channel ("email" / "sms").
     *
     * @return int New count (>=1).
     */
    private function incrementSoftBounceCounter(string $contactId, string $channel): int
    {
        $key     = $this->softBounceKey(contactId: $contactId, channel: $channel);
        $current = (int) $this->appConfig->getValueString(Application::APP_ID, $key, '0');
        $next    = ($current + 1);
        $this->appConfig->setValueString(Application::APP_ID, $key, (string) $next);
        return $next;
    }//end incrementSoftBounceCounter()

    /**
     * Clear the soft-bounce counter for a contact/channel pair.
     *
     * @param string $contactId Contact UUID or slug.
     * @param string $channel   Channel.
     *
     * @return void
     */
    private function clearSoftBounceCounter(string $contactId, string $channel): void
    {
        $key = $this->softBounceKey(contactId: $contactId, channel: $channel);
        try {
            $this->appConfig->deleteKey(Application::APP_ID, $key);
        } catch (Throwable $e) {
            $this->logger->info(
                'WebhookProcessorService.clearSoftBounceCounter: delete failed',
                ['contactId' => $contactId, 'channel' => $channel, 'exception' => $e->getMessage()]
            );
        }
    }//end clearSoftBounceCounter()

    /**
     * Soft-bounce counter app-config key for a contact/channel.
     *
     * @param string $contactId Contact UUID or slug.
     * @param string $channel   Channel.
     *
     * @return string App-config key.
     */
    private function softBounceKey(string $contactId, string $channel): string
    {
        return self::SOFT_BOUNCE_KEY_PREFIX.$contactId.'.'.$channel;
    }//end softBounceKey()

    /**
     * Resolve the soft-bounce withdrawal threshold from app config.
     *
     * @return int Threshold (>=1).
     */
    private function resolveSoftBounceThreshold(): int
    {
        $raw = $this->appConfig->getValueString(
            Application::APP_ID,
            self::APP_CONFIG_SOFT_BOUNCE_THRESHOLD,
            (string) self::DEFAULT_SOFT_BOUNCE_THRESHOLD,
        );
        if ($raw === '' || is_numeric($raw) === false) {
            return self::DEFAULT_SOFT_BOUNCE_THRESHOLD;
        }

        $value = (int) $raw;
        if ($value < 1) {
            return self::DEFAULT_SOFT_BOUNCE_THRESHOLD;
        }

        return $value;
    }//end resolveSoftBounceThreshold()

    /**
     * Load one BlastDelivery row by id.
     *
     * @param string $id BlastDelivery UUID or slug.
     *
     * @return array<string, mixed>|null Delivery row or null.
     */
    private function loadDeliveryById(string $id): ?array
    {
        $register = $this->getRegisterSlug();
        $schema   = $this->getBlastDeliverySchemaSlug();
        if ($register === '' || $schema === '') {
            return null;
        }

        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return null;
        }

        try {
            $entity = $objectService->find(id: $id, register: $register, schema: $schema);
        } catch (Throwable $e) {
            $this->logger->info(
                'WebhookProcessorService.loadDeliveryById: not found',
                ['id' => $id, 'exception' => $e->getMessage()]
            );
            return null;
        }

        if ($entity === null) {
            return null;
        }

        return $this->toArray(value: $entity);
    }//end loadDeliveryById()

    /**
     * Query BlastDelivery rows by arbitrary filters.
     *
     * @param array<string, mixed> $filters Filter map.
     *
     * @return array<int, array<string, mixed>> Matching rows.
     */
    private function findDeliveries(array $filters): array
    {
        $register = $this->getRegisterSlug();
        $schema   = $this->getBlastDeliverySchemaSlug();
        if ($register === '' || $schema === '') {
            return [];
        }

        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return [];
        }

        try {
            $rows = $objectService->findAll(filters: $filters, register: $register, schema: $schema);
        } catch (Throwable $e) {
            $this->logger->warning(
                'WebhookProcessorService.findDeliveries: findAll failed',
                ['filters' => $filters, 'exception' => $e->getMessage()]
            );
            return [];
        }

        $out = [];
        foreach (($rows ?? []) as $row) {
            $out[] = $this->toArray(value: $row);
        }

        return $out;
    }//end findDeliveries()

    /**
     * Persist a BlastDelivery payload via ObjectService.
     *
     * @param array<string, mixed> $payload Delivery payload.
     * @param string               $id      Delivery id.
     *
     * @return void
     */
    private function saveDelivery(array $payload, string $id): void
    {
        $register = $this->getRegisterSlug();
        $schema   = $this->getBlastDeliverySchemaSlug();
        if ($register === '' || $schema === '' || $id === '') {
            return;
        }

        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return;
        }

        try {
            $objectService->saveObject(
                object: $payload,
                register: $register,
                schema: $schema,
                uuid: $id,
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'WebhookProcessorService.saveDelivery: save failed',
                ['id' => $id, 'exception' => $e->getMessage()]
            );
        }

        $blastId = (string) ($payload['blastId'] ?? '');
        if ($blastId !== '') {
            try {
                $this->blastService->updateBlastTotals(blastId: $blastId);
            } catch (Throwable $e) {
                $this->logger->info(
                    'WebhookProcessorService.saveDelivery: updateBlastTotals failed',
                    ['blastId' => $blastId, 'exception' => $e->getMessage()]
                );
            }
        }
    }//end saveDelivery()

    /**
     * Extract the object id from a saved payload.
     *
     * @param array<string, mixed> $payload Payload.
     *
     * @return string Id (uuid / id / slug) or empty.
     */
    private function extractId(array $payload): string
    {
        $searchSpaces = [$payload];
        $self         = ($payload['@self'] ?? null);
        if (is_array($self) === true) {
            $searchSpaces[] = $self;
        }

        foreach ($searchSpaces as $space) {
            foreach (['uuid', 'id', 'slug'] as $key) {
                $value = ($space[$key] ?? null);
                if (is_scalar($value) === true && (string) $value !== '') {
                    return (string) $value;
                }
            }
        }

        return '';
    }//end extractId()

    /**
     * Resolve the register slug from app config.
     *
     * @return string Slug.
     */
    private function getRegisterSlug(): string
    {
        $slug = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        if ($slug !== '') {
            return $slug;
        }

        return self::DEFAULT_REGISTER_SLUG;
    }//end getRegisterSlug()

    /**
     * Resolve the BlastDelivery schema slug from app config.
     *
     * @return string Slug.
     */
    private function getBlastDeliverySchemaSlug(): string
    {
        $slug = $this->appConfig->getValueString(Application::APP_ID, 'blastDelivery_schema', '');
        if ($slug !== '') {
            return $slug;
        }

        return self::DEFAULT_BLAST_DELIVERY_SCHEMA_SLUG;
    }//end getBlastDeliverySchemaSlug()

    /**
     * Lazy-resolve OpenRegister's ObjectService.
     *
     * @return object|null ObjectService or null when OR is unavailable.
     */
    private function getObjectService(): ?object
    {
        try {
            return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        } catch (Throwable $e) {
            $this->logger->warning(
                'WebhookProcessorService.getObjectService: OpenRegister unavailable',
                ['exception' => $e->getMessage()]
            );
            return null;
        }
    }//end getObjectService()

    /**
     * Normalise an OpenRegister entity or array into a plain array.
     *
     * @param mixed $value Entity object or array.
     *
     * @return array<string, mixed> Plain payload.
     */
    private function toArray(mixed $value): array
    {
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
}//end class
