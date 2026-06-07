<?php

/**
 * Pipelinq ComplianceService.
 *
 * GDPR/CAN-SPAM gate that blocks marketing sends without lawful basis,
 * enforces unsubscribe + physical-address tokens on email templates, and
 * withdraws consent on unsubscribe / bounce events. Reads ConsentRecord
 * and CampaignTemplate (chain root 01) through OpenRegister's
 * ObjectService and works alongside SegmentService (member 02) which
 * supplies the recipient list. The downstream BlastService (member 04)
 * calls into this service to filter a segment to its compliant subset
 * before dispatching.
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
 * @spec openspec/changes/marketing-segmentation-and-blast-03-compliance-service/tasks.md#compliance-service
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ComplianceService — GDPR/CAN-SPAM gate for marketing blasts.
 *
 * Public surface (filled in by subsequent tasks):
 *
 * - `checkSegmentCompliance($segmentId, $channel)` — list compliant and
 *   missing-consent members for a Segment on the requested channel.
 * - `hasConsentForChannel($contactId, $channel)` — per-contact gate.
 * - `recordConsentWithdrawal($contactId, $channel, $reason, ?$sourceBlastId)` —
 *   transition a ConsentRecord to withdrawn and skip queued deliveries.
 * - `validateTemplate(array $templateData, $channel)` — enforce
 *   `{{unsubscribe_link}}` + physical-address token on email templates;
 *   SMS templates pass through.
 *
 * @spec openspec/changes/marketing-segmentation-and-blast-03-compliance-service/tasks.md#compliance-service
 */
class ComplianceService
{
    /**
     * Default register slug — matches SegmentService and the chain-root
     * register fragment.
     */
    private const DEFAULT_REGISTER_SLUG = 'pipelinq';

    /**
     * Default ConsentRecord schema slug — matches the register fragment.
     */
    private const DEFAULT_CONSENT_RECORD_SCHEMA_SLUG = 'consentRecord';

    /**
     * Default BlastDelivery schema slug — used by withdrawal to skip
     * queued rows. Wired through to BlastService in member 04.
     */
    private const DEFAULT_BLAST_DELIVERY_SCHEMA_SLUG = 'blastDelivery';

    /**
     * Lawful-basis values that DO satisfy marketing consent gating.
     *
     * "imported" is intentionally NOT on this list — ADR-005 fail-safe
     * rule: bulk-imported rows cannot stand in for documented consent.
     * The withdrawal ledger may still carry the row, but a send is
     * never permitted on the bare "imported" basis.
     *
     * @var array<int, string>
     */
    private const LAWFUL_BASIS_ALLOWED = ['consent', 'legitimate-interest', 'contract'];

    /**
     * Lawful-basis values that are recorded on a ConsentRecord but do
     * NOT permit a marketing send. The list is consulted explicitly so
     * an audit-log line surfaces every blocked "imported" send.
     *
     * @var array<int, string>
     */
    private const LAWFUL_BASIS_UNSATISFYING = ['imported'];

    /**
     * Constructor.
     *
     * @param ContainerInterface $container      DI container (lazy OR resolve).
     * @param IAppConfig         $appConfig      Pipelinq app config.
     * @param SegmentService     $segmentService Segment member projection.
     * @param LoggerInterface    $logger         Logger.
     *
     * @spec openspec/changes/marketing-segmentation-and-blast-03-compliance-service/tasks.md#di
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private SegmentService $segmentService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Check every member of a Segment for a usable ConsentRecord on the
     * requested channel.
     *
     * Returns a triple:
     *
     * - `compliant` (bool) — true when no member is missing consent.
     * - `missingConsent` (string[]) — contactIds without a usable record.
     * - `missingCount` (int) — convenience count of the above.
     *
     * Members without a contactId are conservatively treated as
     * missing-consent (they cannot be matched to a ConsentRecord row, so
     * the fail-safe rule applies). When SegmentService returns an empty
     * recipient set (segment not found, OR unreachable, etc.) the result
     * is `compliant: true, missingCount: 0` — there's nothing to send.
     *
     * @param string $segmentId Segment UUID or slug.
     * @param string $channel   "email" or "sms".
     *
     * @return array{compliant: bool, missingConsent: array<int, string>, missingCount: int}
     *
     * @spec openspec/changes/marketing-segmentation-and-blast-03-compliance-service/tasks.md#check-segment-compliance
     */
    public function checkSegmentCompliance(string $segmentId, string $channel): array
    {
        $channel = strtolower(trim($channel));
        $members = $this->segmentService->getMembersForBlast(segmentId: $segmentId);
        if ($members === []) {
            return [
                'compliant'      => true,
                'missingConsent' => [],
                'missingCount'   => 0,
            ];
        }

        $missing = [];
        foreach ($members as $member) {
            $contactId = (string) ($member['contactId'] ?? '');
            if ($contactId === '') {
                // Members without a stable contactId cannot be matched to
                // a ConsentRecord — fail safe, treat as missing.
                $missing[] = '';
                continue;
            }
            if ($this->hasConsentForChannel(contactId: $contactId, channel: $channel) === false) {
                $missing[] = $contactId;
            }
        }

        $missing = array_values(array_unique($missing));

        return [
            'compliant'      => ($missing === []),
            'missingConsent' => $missing,
            'missingCount'   => count($missing),
        ];
    }//end checkSegmentCompliance()

    /**
     * Return whether a Contact has a usable ConsentRecord on the channel.
     *
     * True iff a ConsentRecord exists for `(contactId, channel)` whose
     * `lawfulBasis` is one of `consent`, `legitimate-interest`,
     * `contract` AND whose `withdrawnAt` is empty. Any failure
     * resolving the record returns false — the caller blocks the send.
     *
     * @param string $contactId Contact UUID / slug.
     * @param string $channel   "email" or "sms".
     *
     * @return bool True when the channel is gated open for this contact.
     *
     * @spec openspec/changes/marketing-segmentation-and-blast-03-compliance-service/tasks.md#has-consent-for-channel
     */
    public function hasConsentForChannel(string $contactId, string $channel): bool
    {
        $channel = strtolower(trim($channel));
        if ($contactId === '' || $channel === '') {
            return false;
        }

        $record = $this->findConsentRecord(contactId: $contactId, channel: $channel);
        if ($record === null) {
            return false;
        }

        $lawfulBasis = strtolower(trim((string) ($record['lawfulBasis'] ?? '')));
        if ($lawfulBasis === '') {
            return false;
        }
        if (in_array($lawfulBasis, self::LAWFUL_BASIS_UNSATISFYING, true) === true) {
            // ADR-005 fail-safe: "imported" rows are recorded for
            // GDPR audit purposes but cannot themselves authorise a
            // marketing dispatch. Log the block so the operator sees
            // why the contact was excluded.
            $this->logger->info(
                'ComplianceService.hasConsentForChannel: blocked — lawfulBasis "imported" does not permit marketing sends',
                ['contactId' => $contactId, 'channel' => $channel]
            );
            return false;
        }
        if (in_array($lawfulBasis, self::LAWFUL_BASIS_ALLOWED, true) === false) {
            return false;
        }

        $withdrawnAt = $record['withdrawnAt'] ?? null;
        if (is_string($withdrawnAt) === true && trim($withdrawnAt) !== '') {
            return false;
        }

        return true;
    }//end hasConsentForChannel()

    /**
     * Look up a ConsentRecord by (contactId, channel).
     *
     * @param string $contactId Contact UUID / slug.
     * @param string $channel   "email" or "sms".
     *
     * @return array<string, mixed>|null Record array or null.
     */
    private function findConsentRecord(string $contactId, string $channel): ?array
    {
        $register = $this->getRegisterSlug();
        $schema   = $this->getConsentRecordSchemaSlug();
        if ($register === '' || $schema === '') {
            return null;
        }
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return null;
        }

        try {
            $rows = $objectService->findAll(
                filters: [
                    'contactId' => $contactId,
                    'channel'   => $channel,
                ],
                register: $register,
                schema: $schema,
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'ComplianceService.findConsentRecord: findAll failed',
                [
                    'contactId' => $contactId,
                    'channel'   => $channel,
                    'exception' => $e->getMessage(),
                ]
            );
            return null;
        }

        foreach (($rows ?? []) as $row) {
            $array = $this->toArray(value: $row);
            if ($array === []) {
                continue;
            }
            // Defensive in-PHP filter — OR's filter DSL may ignore
            // unknown keys silently, so re-check here.
            $rowContact = (string) ($array['contactId'] ?? '');
            $rowChannel = strtolower((string) ($array['channel'] ?? ''));
            if ($rowContact === $contactId && $rowChannel === $channel) {
                return $array;
            }
        }
        return null;
    }//end findConsentRecord()

    /**
     * Resolve the register slug from app config.
     *
     * @return string Register slug.
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
     * Resolve the ConsentRecord schema slug from app config.
     *
     * @return string Schema slug.
     */
    private function getConsentRecordSchemaSlug(): string
    {
        $slug = $this->appConfig->getValueString(Application::APP_ID, 'consent_record_schema', '');
        if ($slug !== '') {
            return $slug;
        }
        return self::DEFAULT_CONSENT_RECORD_SCHEMA_SLUG;
    }//end getConsentRecordSchemaSlug()

    /**
     * Resolve the BlastDelivery schema slug from app config.
     *
     * @return string Schema slug.
     */
    private function getBlastDeliverySchemaSlug(): string
    {
        $slug = $this->appConfig->getValueString(Application::APP_ID, 'blast_delivery_schema', '');
        if ($slug !== '') {
            return $slug;
        }
        return self::DEFAULT_BLAST_DELIVERY_SCHEMA_SLUG;
    }//end getBlastDeliverySchemaSlug()

    /**
     * Resolve OpenRegister's ObjectService lazily.
     *
     * @return object|null ObjectService, or null when OpenRegister is
     *                     not loaded.
     */
    private function getObjectService(): ?object
    {
        try {
            return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        } catch (Throwable $e) {
            $this->logger->warning(
                'ComplianceService.getObjectService: OpenRegister unavailable',
                ['exception' => $e->getMessage()]
            );
            return null;
        }
    }//end getObjectService()

    /**
     * Normalise an OpenRegister entity (or array) to a plain array.
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
     * Extract the canonical id from an OpenRegister entity payload.
     *
     * @param array<string, mixed> $payload Entity payload.
     *
     * @return string Identifier or empty string.
     */
    private function extractObjectId(array $payload): string
    {
        foreach (['id', 'uuid', 'slug'] as $key) {
            if (isset($payload[$key]) === true && is_scalar($payload[$key]) === true) {
                $value = (string) $payload[$key];
                if ($value !== '') {
                    return $value;
                }
            }
        }
        if (isset($payload['@self']) === true && is_array($payload['@self']) === true) {
            foreach (['uuid', 'id', 'slug'] as $key) {
                if (isset($payload['@self'][$key]) === true && is_scalar($payload['@self'][$key]) === true) {
                    $value = (string) $payload['@self'][$key];
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }
        return '';
    }//end extractObjectId()
}//end class
