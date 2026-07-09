<?php

/**
 * Pipelinq AttributionService.
 *
 * Closes the loop that classic ESPs (Mailchimp etc.) cannot —
 * joining a click on a BlastDelivery to a later closed-won Deal
 * (Pipelinq Lead with stage `closed-won`) and recording the attributed
 * EUR value on an AttributionLink row. Reads BlastDelivery and writes
 * AttributionLink rows via OpenRegister's `ObjectService` (ADR-001/022).
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
 * @spec openspec/changes/marketing-segmentation-and-blast-04-blast-attribution-services/tasks.md#task-2.4
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * AttributionService — click→deal joining and revenue roll-up.
 *
 * @spec openspec/changes/marketing-segmentation-and-blast-04-blast-attribution-services/tasks.md#task-2.4
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Attribution joins many payload shapes; complexity is inherent to the roll-up.
 */
class AttributionService
{
    /**
     * Default register slug used when no `register` app config value is set.
     */
    private const DEFAULT_REGISTER_SLUG = 'pipelinq';

    /**
     * Default BlastDelivery schema slug.
     */
    private const DEFAULT_BLAST_DELIVERY_SCHEMA_SLUG = 'blastDelivery';

    /**
     * Default AttributionLink schema slug.
     */
    private const DEFAULT_ATTRIBUTION_LINK_SCHEMA_SLUG = 'attributionLink';

    /**
     * Default Lead schema slug used to look up `closedWonAt` and value
     * when only a dealId is supplied.
     */
    private const DEFAULT_LEAD_SCHEMA_SLUG = 'lead';

    /**
     * Constructor.
     *
     * @param ContainerInterface $container DI container.
     * @param IAppConfig         $appConfig App config.
     * @param LoggerInterface    $logger    Logger.
     *
     * @spec openspec/changes/marketing-segmentation-and-blast-04-blast-attribution-services/tasks.md#task-2.4
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Record a click event on a BlastDelivery row.
     *
     * Sets `firstClickAt` only when not already set (subsequent clicks
     * preserve the earliest timestamp — the attribution window starts at
     * the first click) and appends the click URL to `clickedUrls`
     * (deduplicated). The BlastDelivery `status` is bumped to `clicked`
     * only when the row is in `delivered` or `opened` (we never overwrite
     * `bounced`/`unsubscribed`/`complained`).
     *
     * @param string               $blastDeliveryId BlastDelivery UUID or slug.
     * @param array<string, mixed> $clickEvent      Click event payload:
     *                                              `url`, `timestamp`,
     *                                              `userAgent` (optional).
     *
     * @return void
     *
     * @spec openspec/changes/marketing-segmentation-and-blast-04-blast-attribution-services/tasks.md#task-2.4
     */
    public function recordClick(string $blastDeliveryId, array $clickEvent): void
    {
        $delivery = $this->loadDelivery(id: $blastDeliveryId);
        if ($delivery === null) {
            return;
        }

        $timestamp = $this->extractClickTimestamp(event: $clickEvent);
        $url       = $this->extractClickUrl(event: $clickEvent);

        $payload = $delivery;
        if (empty($payload['firstClickAt']) === true) {
            $payload['firstClickAt'] = $timestamp;
        }

        $clickedUrls = ($payload['clickedUrls'] ?? []);
        if (is_array($clickedUrls) === false) {
            $clickedUrls = [];
        }

        if ($url !== '' && in_array($url, $clickedUrls, true) === false) {
            $clickedUrls[] = $url;
        }

        $payload['clickedUrls'] = $clickedUrls;

        $current = (string) ($payload['status'] ?? '');
        if (in_array($current, ['delivered', 'opened', 'sent'], true) === true) {
            $payload['status'] = 'clicked';
        }

        $this->saveObject(
            payload: $payload,
            schemaSlug: $this->getBlastDeliverySchemaSlug(),
            id: $this->extractId(payload: $delivery),
        );
    }//end recordClick()

    /**
     * Create an AttributionLink joining a Blast click to a closed Deal.
     *
     * Loads the BlastDelivery and the Deal (Lead), then writes a fresh
     * AttributionLink with `blastId` / `contactId` / `dealId` /
     * `firstClickAt` / `closedWonAt` / `attributedValue` (EUR, taken from
     * the Lead's `value` field). Idempotent: when an AttributionLink for
     * the same (blastId, contactId, dealId) triple already exists we
     * skip without raising.
     *
     * @param string $blastDeliveryId BlastDelivery UUID or slug.
     * @param string $dealId          Lead UUID or slug (closed-won).
     *
     * @return void
     *
     * @spec openspec/changes/marketing-segmentation-and-blast-04-blast-attribution-services/tasks.md#task-2.4
     */
    public function linkBlastToDeal(string $blastDeliveryId, string $dealId): void
    {
        $delivery = $this->loadDelivery(id: $blastDeliveryId);
        if ($delivery === null) {
            $this->logger->info(
                'AttributionService.linkBlastToDeal: delivery not found',
                ['blastDeliveryId' => $blastDeliveryId]
            );
            return;
        }

        $blastId   = (string) ($delivery['blastId'] ?? '');
        $contactId = (string) ($delivery['contactId'] ?? '');
        if ($blastId === '' || $contactId === '') {
            return;
        }

        if ($this->attributionExists(blastId: $blastId, contactId: $contactId, dealId: $dealId) === true) {
            return;
        }

        $deal        = $this->loadDeal(id: $dealId);
        $closedWonAt = $this->extractClosedWonAt(deal: $deal);
        $value       = $this->extractDealValue(deal: $deal);

        $payload = [
            'blastId'         => $blastId,
            'contactId'       => $contactId,
            'dealId'          => $dealId,
            'firstClickAt'    => (string) ($delivery['firstClickAt'] ?? ''),
            'closedWonAt'     => $closedWonAt,
            'attributedValue' => $value,
            'currency'        => 'EUR',
            'createdAt'       => $this->nowIso(),
        ];

        $this->saveObject(
            payload: $payload,
            schemaSlug: $this->getAttributionLinkSchemaSlug(),
        );
    }//end linkBlastToDeal()

    /**
     * Return the sum of `attributedValue` across every AttributionLink
     * row for one Blast.
     *
     * @param string $blastId Blast UUID or slug.
     *
     * @return float Sum in EUR.
     *
     * @spec openspec/changes/marketing-segmentation-and-blast-04-blast-attribution-services/tasks.md#task-2.4
     */
    public function getBlastAttributedValue(string $blastId): float
    {
        $rows  = $this->loadAttributionLinks(filters: ['blastId' => $blastId]);
        $total = 0.0;
        foreach ($rows as $row) {
            $raw = ($row['attributedValue'] ?? null);
            if (is_numeric($raw) === true) {
                $total += (float) $raw;
            }
        }

        return $total;
    }//end getBlastAttributedValue()

    /**
     * Return the attribution summary for a Blast — the number of distinct
     * attributed deals and the summed EUR `attributedValue`. Used by the
     * Performance Dashboard Attribution tab via
     * `GET /api/blasts/{id}/attribution` (member 08).
     *
     * @param string $blastId Blast UUID or slug.
     *
     * @return array{blastId: string, dealCount: int, attributedValue: float, currency: string}
     *
     * @spec openspec/changes/marketing-segmentation-and-blast-08-performance-dashboard/tasks.md#performancedashboard-vue-task-3-4-of-giant
     */
    public function getBlastAttributionSummary(string $blastId): array
    {
        $rows  = $this->loadAttributionLinks(filters: ['blastId' => $blastId]);
        $total = 0.0;
        $deals = [];
        foreach ($rows as $row) {
            $raw = ($row['attributedValue'] ?? null);
            if (is_numeric($raw) === true) {
                $total += (float) $raw;
            }

            $dealId = (string) ($row['dealId'] ?? '');
            if ($dealId !== '') {
                $deals[$dealId] = true;
            }
        }

        return [
            'blastId'         => $blastId,
            'dealCount'       => count($deals),
            'attributedValue' => $total,
            'currency'        => 'EUR',
        ];
    }//end getBlastAttributionSummary()

    /**
     * Extract a click timestamp from a webhook event.
     *
     * @param array<string, mixed> $event Click event.
     *
     * @return string Timestamp ISO-8601.
     */
    private function extractClickTimestamp(array $event): string
    {
        foreach (['timestamp', 'occurredAt', 'time'] as $key) {
            if (isset($event[$key]) === true) {
                $value = $event[$key];
                if (is_int($value) === true) {
                    return gmdate('Y-m-d\TH:i:s\Z', $value);
                }

                if (is_string($value) === true && $value !== '') {
                    return $value;
                }
            }
        }

        return $this->nowIso();
    }//end extractClickTimestamp()

    /**
     * Extract a click URL from a webhook event.
     *
     * @param array<string, mixed> $event Click event.
     *
     * @return string URL or empty.
     */
    private function extractClickUrl(array $event): string
    {
        foreach (['url', 'href', 'link'] as $key) {
            if (isset($event[$key]) === true && is_string($event[$key]) === true && $event[$key] !== '') {
                return (string) $event[$key];
            }
        }

        return '';
    }//end extractClickUrl()

    /**
     * Check whether an AttributionLink already exists for the triple.
     *
     * @param string $blastId   Blast id.
     * @param string $contactId Contact id.
     * @param string $dealId    Deal id.
     *
     * @return bool True when at least one row exists.
     */
    private function attributionExists(string $blastId, string $contactId, string $dealId): bool
    {
        $rows = $this->loadAttributionLinks(
            filters: ['blastId' => $blastId, 'contactId' => $contactId, 'dealId' => $dealId],
        );
        return ($rows !== []);
    }//end attributionExists()

    /**
     * Extract `closedWonAt` from a Lead payload.
     *
     * @param array<string, mixed>|null $deal Lead payload.
     *
     * @return string ISO-8601 or empty.
     */
    private function extractClosedWonAt(?array $deal): string
    {
        if ($deal === null) {
            return $this->nowIso();
        }

        foreach (['closedWonAt', 'closedAt', 'wonAt'] as $key) {
            if (isset($deal[$key]) === true && is_string($deal[$key]) === true && $deal[$key] !== '') {
                return (string) $deal[$key];
            }
        }

        return $this->nowIso();
    }//end extractClosedWonAt()

    /**
     * Extract the EUR value to attribute from a Lead payload.
     *
     * @param array<string, mixed>|null $deal Lead payload.
     *
     * @return float Value in EUR (0 when unknown).
     */
    private function extractDealValue(?array $deal): float
    {
        if ($deal === null) {
            return 0.0;
        }

        foreach (['value', 'dealValue', 'amount', 'attributedValue'] as $key) {
            if (isset($deal[$key]) === true && is_numeric($deal[$key]) === true) {
                return (float) $deal[$key];
            }
        }

        return 0.0;
    }//end extractDealValue()

    /**
     * Load one BlastDelivery row by id.
     *
     * @param string $id BlastDelivery UUID or slug.
     *
     * @return array<string, mixed>|null Row or null.
     */
    private function loadDelivery(string $id): ?array
    {
        return $this->loadOne(id: $id, schemaSlug: $this->getBlastDeliverySchemaSlug());
    }//end loadDelivery()

    /**
     * Load one Lead (Deal) row by id.
     *
     * @param string $id Lead UUID or slug.
     *
     * @return array<string, mixed>|null Row or null.
     */
    private function loadDeal(string $id): ?array
    {
        if ($id === '') {
            return null;
        }

        return $this->loadOne(id: $id, schemaSlug: $this->getLeadSchemaSlug());
    }//end loadDeal()

    /**
     * Load one object by id and schema slug.
     *
     * @param string $id         Object UUID or slug.
     * @param string $schemaSlug Schema slug.
     *
     * @return array<string, mixed>|null Payload or null.
     */
    private function loadOne(string $id, string $schemaSlug): ?array
    {
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
                'AttributionService.loadOne: not found',
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
     * Load AttributionLink rows with the given filter map.
     *
     * @param array<string, mixed> $filters Filter map.
     *
     * @return array<int, array<string, mixed>> Rows.
     */
    private function loadAttributionLinks(array $filters): array
    {
        $register = $this->getRegisterSlug();
        $schema   = $this->getAttributionLinkSchemaSlug();
        if ($register === '' || $schema === '') {
            return [];
        }

        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return [];
        }

        try {
            $rows = $objectService->findAll(
                filters: $filters,
                register: $register,
                schema: $schema,
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'AttributionService.loadAttributionLinks: findAll failed',
                ['filters' => $filters, 'exception' => $e->getMessage()]
            );
            return [];
        }

        $out = [];
        foreach (($rows ?? []) as $row) {
            $out[] = $this->toArray(value: $row);
        }

        return $out;
    }//end loadAttributionLinks()

    /**
     * Persist a payload via OpenRegister's ObjectService.
     *
     * Returns the saved row for callers that need to chain off it (e.g.
     * follow-up auditing); current callers fire-and-forget but the return
     * remains part of the contract.
     *
     * @param array<string, mixed> $payload    Payload.
     * @param string               $schemaSlug Schema slug.
     * @param string|null          $id         Existing object id or null.
     *
     * @return array<string, mixed>|null Saved row or null on failure.
     *
     * @psalm-suppress UnusedReturnValue
     */
    private function saveObject(array $payload, string $schemaSlug, ?string $id=null): ?array
    {
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
                'AttributionService.saveObject: save failed',
                ['schema' => $schemaSlug, 'exception' => $e->getMessage()]
            );
            return null;
        }

        return $this->toArray(value: $saved);
    }//end saveObject()

    /**
     * Extract the object id from a payload (`uuid` / `id` / `slug`).
     *
     * @param array<string, mixed> $payload Payload.
     *
     * @return string Id or empty.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential id-candidate probes across two payload shapes; extraction adds no clarity.
     */
    private function extractId(array $payload): string
    {
        foreach (['uuid', 'id', 'slug'] as $key) {
            if (isset($payload[$key]) === true && is_scalar($payload[$key]) === true && (string) $payload[$key] !== '') {
                return (string) $payload[$key];
            }
        }

        if (isset($payload['@self']) === true && is_array($payload['@self']) === true) {
            foreach (['uuid', 'id', 'slug'] as $key) {
                $value = ($payload['@self'][$key] ?? null);
                if (is_scalar($value) === true && (string) $value !== '') {
                    return (string) $value;
                }
            }
        }

        return '';
    }//end extractId()

    /**
     * Resolve the AttributionLink schema slug from app config.
     *
     * @return string Slug.
     */
    private function getAttributionLinkSchemaSlug(): string
    {
        $slug = $this->appConfig->getValueString(Application::APP_ID, 'attributionLink_schema', '');
        if ($slug !== '') {
            return $slug;
        }

        return self::DEFAULT_ATTRIBUTION_LINK_SCHEMA_SLUG;
    }//end getAttributionLinkSchemaSlug()

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
     * Resolve the Lead (Deal) schema slug from app config.
     *
     * @return string Slug.
     */
    private function getLeadSchemaSlug(): string
    {
        $slug = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');
        if ($slug !== '') {
            return $slug;
        }

        return self::DEFAULT_LEAD_SCHEMA_SLUG;
    }//end getLeadSchemaSlug()

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
     * Resolve the OpenRegister ObjectService lazily.
     *
     * @return object|null ObjectService or null when OR is unavailable.
     */
    private function getObjectService(): ?object
    {
        try {
            return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        } catch (Throwable $e) {
            $this->logger->warning(
                'AttributionService.getObjectService: OpenRegister unavailable',
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

    /**
     * Current time as an ISO-8601 string.
     *
     * @return string Timestamp.
     */
    private function nowIso(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }//end nowIso()
}//end class
