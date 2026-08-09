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
 * @spec openspec/specs/marketing-compliance/spec.md#requirement-blast-cannot-send-without-lawful-basis
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
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Cohesive consent + template + queued-delivery gate; splitting fragments one policy.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Guard-heavy but flat GDPR/CAN-SPAM checks; each operation is independently unit-tested.
 *
 * @spec openspec/specs/marketing-compliance/spec.md#requirement-blast-cannot-send-without-lawful-basis
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
     * Delivery statuses that should be transitioned to
     * "unsubscribed-before-send" on a withdrawal — the queue is still
     * upstream of the provider, so the message has not left the system.
     *
     * @var array<int, string>
     */
    private const QUEUED_DELIVERY_STATUSES = ['queued'];

    /**
     * The withdrawal-side delivery status applied by
     * `recordConsentWithdrawal()` to queued rows.
     */
    private const STATUS_UNSUBSCRIBED_BEFORE_SEND = 'unsubscribed-before-send';

    /**
     * The token every compliant email body must embed (the renderer
     * substitutes the unsubscribe URL on send).
     */
    private const UNSUBSCRIBE_TOKEN = '{{unsubscribe_link}}';

    /**
     * Token alternatives the validator will accept for the CAN-SPAM
     * physical-address requirement (any one of these in the body OR a
     * non-empty footerOverride satisfies the rule).
     *
     * @var array<int, string>
     */
    private const PHYSICAL_ADDRESS_TOKENS = [
        '{{physical_address}}',
        '{{sender_address}}',
        '{{company_address}}',
        '{{address_block}}',
    ];

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
     * @spec openspec/specs/marketing-compliance/spec.md#requirement-blast-cannot-send-without-lawful-basis
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
     * @spec openspec/specs/marketing-compliance/spec.md#requirement-blast-cannot-send-without-lawful-basis
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
     * Run every pre-send compliance check for one Blast in a single call.
     *
     * Convenience entry point for the blast-send path used by
     * BlastService (member 04) and the blast controller (member 06) —
     * it runs `validateTemplate()` over the supplied template payload
     * and `checkSegmentCompliance()` over the supplied segment + channel,
     * returning a structured `{ valid, templateError, segmentCompliance }`
     * triple. Centralising the template-then-segment sequence here keeps
     * every caller honest about the order: never queue a Blast whose
     * template did not pass `validateTemplate()` first.
     *
     * Empty segment + empty template short-circuit to `valid: true` so
     * downstream "what would block this blast?" preview endpoints can
     * call the method without first proving they have data.
     *
     * @param string               $segmentId Segment UUID / slug.
     * @param array<string, mixed> $template  CampaignTemplate payload.
     * @param string               $channel   "email" or "sms".
     *
     * @return array<string, mixed> Preflight triple — see
     *                              `validateTemplate()` / `checkSegmentCompliance()`
     *                              for the field shapes.
     *
     * @spec openspec/specs/marketing-compliance/spec.md#requirement-blast-cannot-send-without-lawful-basis
     */
    public function preflightBlast(string $segmentId, array $template, string $channel): array
    {
        $templateError = $this->validateTemplate(templateData: $template, channel: $channel);
        $segmentCheck  = $this->checkSegmentCompliance(segmentId: $segmentId, channel: $channel);

        $valid = ($templateError === null && $segmentCheck['compliant'] === true);

        return [
            'valid'             => $valid,
            'templateError'     => $templateError,
            'segmentCompliance' => $segmentCheck,
        ];
    }//end preflightBlast()

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
     * @spec openspec/specs/marketing-compliance/spec.md#requirement-blast-cannot-send-without-lawful-basis
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
     * Validate a CampaignTemplate payload against the channel's rules.
     *
     * For email templates the body MUST embed `{{unsubscribe_link}}`
     * (token literally present in `bodyHtml` or `bodyText`) AND a
     * physical-address indicator — either one of the recognised
     * placeholder tokens (see `PHYSICAL_ADDRESS_TOKENS`) or a non-empty
     * `footerOverride` (the operator is supplying a literal address
     * block in place of the templated one).
     *
     * Returns `null` on success or a human-readable error string on
     * failure. Callers (controller / save path) surface the error as a
     * field-level validation error and refuse to persist.
     *
     * SMS templates have no footer requirement (carriers strip
     * footers; the CAN-SPAM rule is email-specific) — this method
     * returns null for any non-email channel.
     *
     * @param array<string, mixed> $templateData CampaignTemplate payload.
     * @param string               $channel      "email" or "sms".
     *
     * @return string|null Error message or null when valid.
     *
     * @spec openspec/specs/marketing-compliance/spec.md#requirement-unsubscribe-footer-enforced-on-email-templates
     */
    public function validateTemplate(array $templateData, string $channel): ?string
    {
        $channel = strtolower(trim($channel));
        if ($channel !== 'email') {
            return null;
        }

        $bodyHtml       = (string) ($templateData['bodyHtml'] ?? '');
        $bodyText       = (string) ($templateData['bodyText'] ?? '');
        $footerOverride = (string) ($templateData['footerOverride'] ?? '');

        $haystack = $bodyHtml."\n".$bodyText."\n".$footerOverride;

        if (str_contains($haystack, self::UNSUBSCRIBE_TOKEN) === false) {
            return sprintf(
                'Email templates must embed the %s token (GDPR Art. 7(3) withdrawal).',
                self::UNSUBSCRIBE_TOKEN
            );
        }

        $hasAddress = (trim($footerOverride) !== '');
        if ($hasAddress === false) {
            foreach (self::PHYSICAL_ADDRESS_TOKENS as $token) {
                if (str_contains($haystack, $token) === true) {
                    $hasAddress = true;
                    break;
                }
            }
        }

        if ($hasAddress === false) {
            return 'Email templates must include a physical-address block '
                .'(footerOverride or one of {{physical_address}} / '
                .'{{sender_address}} / {{company_address}} / '
                .'{{address_block}}) per CAN-SPAM § 7704(a)(5).';
        }

        return null;
    }//end validateTemplate()

    /**
     * List CampaignTemplates with pagination envelope.
     *
     * @param int $page  1-based page number (clamped to >= 1).
     * @param int $limit Page size (clamped 1..100).
     *
     * @return array{data: array<int, array<string, mixed>>, pagination: array{page: int, limit: int, total: int, pages: int}}
     *
     * @spec openspec/changes/marketing-segmentation-and-blast-06-rest-controllers/tasks.md#templatecontroller-task-2.8-of-giant
     */
    public function listTemplates(int $page, int $limit): array
    {
        $page  = max(1, $page);
        $limit = min(100, max(1, $limit));
        $all   = $this->loadTemplatesRaw();
        $total = count($all);
        $slice = array_slice($all, (($page - 1) * $limit), $limit);
        return [
            'data'       => $slice,
            'pagination' => [
                'page'  => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => $this->computePages(total: $total, limit: $limit),
            ],
        ];
    }//end listTemplates()

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
    private function computePages(int $total, int $limit): int
    {
        if ($total <= 0 || $limit <= 0) {
            return 0;
        }

        return (int) ceil($total / $limit);
    }//end computePages()

    /**
     * Fetch one CampaignTemplate by id.
     *
     * @param string $templateId Template UUID or slug.
     *
     * @return array<string, mixed>|null Template payload or null.
     *
     * @spec openspec/changes/marketing-segmentation-and-blast-06-rest-controllers/tasks.md#templatecontroller-task-2.8-of-giant
     */
    public function getTemplateById(string $templateId): ?array
    {
        if ($templateId === '') {
            return null;
        }

        $register      = $this->getRegisterSlug();
        $schema        = $this->getCampaignTemplateSchemaSlug();
        $objectService = $this->getObjectService();
        if ($register === '' || $schema === '' || $objectService === null) {
            return null;
        }

        try {
            $entity = $objectService->find(id: $templateId, register: $register, schema: $schema);
        } catch (Throwable $e) {
            $this->logger->info(
                'ComplianceService.getTemplateById: not found',
                ['templateId' => $templateId, 'exception' => $e->getMessage()]
            );
            return null;
        }

        if ($entity === null) {
            return null;
        }

        return $this->toArray(value: $entity);
    }//end getTemplateById()

    /**
     * Create a CampaignTemplate after compliance validation.
     *
     * Calls `validateTemplate()` for the requested channel before
     * persisting. An invalid template is rejected with a human-readable
     * error string; the row is never written. `createdBy` is stamped
     * from the authenticated user id (ADR-005).
     *
     * @param array<string, mixed> $payload      Inbound template payload.
     * @param string               $createdByUid Authenticated user id.
     *
     * @return array{template?: array<string, mixed>, error?: string}
     *
     * @spec openspec/changes/marketing-segmentation-and-blast-06-rest-controllers/tasks.md#templatecontroller-task-2.8-of-giant
     */
    public function createTemplate(array $payload, string $createdByUid): array
    {
        $name = (string) ($payload['name'] ?? '');
        if (trim($name) === '') {
            return ['error' => 'Invalid name'];
        }

        $channel = strtolower((string) ($payload['channel'] ?? ''));
        if (in_array($channel, ['email', 'sms'], true) === false) {
            return ['error' => 'Invalid channel'];
        }

        $validationError = $this->validateTemplate(templateData: $payload, channel: $channel);
        if ($validationError !== null) {
            return ['error' => $validationError];
        }

        $now    = gmdate('Y-m-d\TH:i:s\Z');
        $object = [
            'name'           => $name,
            'channel'        => $channel,
            'subject'        => (string) ($payload['subject'] ?? ''),
            'bodyHtml'       => (string) ($payload['bodyHtml'] ?? ''),
            'bodyText'       => (string) ($payload['bodyText'] ?? ''),
            'senderName'     => (string) ($payload['senderName'] ?? ''),
            'senderEmail'    => (string) ($payload['senderEmail'] ?? ''),
            'footerOverride' => (string) ($payload['footerOverride'] ?? ''),
            'createdBy'      => $createdByUid,
            'createdAt'      => $now,
            'updatedAt'      => $now,
        ];

        $saved = $this->saveTemplateObject(payload: $object);
        if ($saved === null) {
            return ['error' => 'Could not create template'];
        }

        return ['template' => $saved];
    }//end createTemplate()

    /**
     * Patch an existing CampaignTemplate with compliance re-validation.
     *
     * Any change to body / channel / footer triggers a fresh
     * `validateTemplate()` call so an operator cannot edit a template
     * into a non-compliant state. `createdBy` / `createdAt` are
     * preserved from the existing row; `updatedAt` is bumped.
     *
     * @param string               $templateId Template UUID or slug.
     * @param array<string, mixed> $patch      Patch payload.
     *
     * @return array{template?: array<string, mixed>, error?: string}
     *
     * @spec openspec/changes/marketing-segmentation-and-blast-06-rest-controllers/tasks.md#templatecontroller-task-2.8-of-giant
     */
    public function patchTemplate(string $templateId, array $patch): array
    {
        $existing = $this->getTemplateById(templateId: $templateId);
        if ($existing === null) {
            return ['error' => 'Template not found'];
        }

        $editable = ['name', 'subject', 'bodyHtml', 'bodyText', 'senderName', 'senderEmail', 'footerOverride'];
        $payload  = $existing;
        foreach ($editable as $field) {
            if (array_key_exists($field, $patch) === true && is_string($patch[$field]) === true) {
                $payload[$field] = $patch[$field];
            }
        }

        $channel = strtolower((string) ($existing['channel'] ?? 'email'));
        $error   = $this->validateTemplate(templateData: $payload, channel: $channel);
        if ($error !== null) {
            return ['error' => $error];
        }

        $payload['updatedAt'] = gmdate('Y-m-d\TH:i:s\Z');
        $saved = $this->saveTemplateObject(payload: $payload, id: $this->extractTemplateId(payload: $existing));
        if ($saved === null) {
            return ['error' => 'Could not update template'];
        }

        return ['template' => $saved];
    }//end patchTemplate()

    /**
     * Load every CampaignTemplate via ObjectService.
     *
     * @return array<int, array<string, mixed>> Plain payloads.
     */
    private function loadTemplatesRaw(): array
    {
        $register      = $this->getRegisterSlug();
        $schema        = $this->getCampaignTemplateSchemaSlug();
        $objectService = $this->getObjectService();
        if ($register === '' || $schema === '' || $objectService === null) {
            return [];
        }

        try {
            // OpenRegister's ObjectService::findAll() takes a single $config array;
            // register/schema travel INSIDE $config['filters'] (see prepareFindAllConfig()).
            // The old findAll(register:, schema:, filters:) named-argument form no longer
            // exists and threw "Unknown named parameter $register" at runtime.
            $rows = $objectService->findAll(
                config: [
                    'filters' => [
                        'register' => $register,
                        'schema'   => $schema,
                    ],
                ]
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'ComplianceService.loadTemplatesRaw: findAll failed',
                ['exception' => $e->getMessage()]
            );
            return [];
        }

        $out = [];
        foreach (($rows ?? []) as $row) {
            $out[] = $this->toArray(value: $row);
        }

        return $out;
    }//end loadTemplatesRaw()

    /**
     * Persist a CampaignTemplate via ObjectService.
     *
     * @param array<string, mixed> $payload Template payload.
     * @param string|null          $id      Existing id when patching.
     *
     * @return array<string, mixed>|null Saved row or null on failure.
     */
    private function saveTemplateObject(array $payload, ?string $id=null): ?array
    {
        $register      = $this->getRegisterSlug();
        $schema        = $this->getCampaignTemplateSchemaSlug();
        $objectService = $this->getObjectService();
        if ($register === '' || $schema === '' || $objectService === null) {
            return null;
        }

        try {
            $saved = $objectService->saveObject(
                object: $payload,
                register: $register,
                schema: $schema,
                uuid: $id,
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'ComplianceService.saveTemplateObject: save failed',
                ['exception' => $e->getMessage()]
            );
            return null;
        }

        return $this->toArray(value: $saved);
    }//end saveTemplateObject()

    /**
     * Extract a template's id from a payload (uuid > id > slug).
     *
     * @param array<string, mixed> $payload Payload.
     *
     * @return string Id or empty string.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Ordered fallback lookup (uuid > id > slug); extraction adds no clarity.
     */
    private function extractTemplateId(array $payload): string
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
    }//end extractTemplateId()

    /**
     * Resolve the CampaignTemplate schema slug from app config.
     *
     * @return string Schema slug.
     */
    private function getCampaignTemplateSchemaSlug(): string
    {
        $slug = $this->appConfig->getValueString(Application::APP_ID, 'campaignTemplate_schema', '');
        if ($slug !== '') {
            return $slug;
        }

        return 'campaignTemplate';
    }//end getCampaignTemplateSchemaSlug()

    /**
     * Withdraw consent for a (contact, channel) pair and skip any
     * queued BlastDelivery rows for that contact.
     *
     * Sets `withdrawnAt` to the current UTC ISO-8601 timestamp and
     * `withdrawnReason` to the supplied reason. Queued BlastDelivery
     * rows for the contact are transitioned to
     * `"unsubscribed-before-send"` so they are skipped before the
     * provider receives them. Member 04 wires the deeper BlastService
     * counter roll-up; until then this method updates the rows directly
     * via ObjectService so the audit trail is preserved on the
     * delivery rows themselves.
     *
     * Idempotent: a record that is already withdrawn is left unchanged.
     * A missing ConsentRecord is created so the audit ledger always
     * reflects the withdrawal event — this matches GDPR Art. 7(3) which
     * grants a withdrawal even when consent was never formally captured.
     *
     * @param string      $contactId     Contact UUID / slug.
     * @param string      $channel       "email" or "sms".
     * @param string      $reason        Withdrawal reason
     *                                   ("user-unsubscribed", "bounce-hard",
     *                                   "bounce-soft-x5", "admin-removed",
     *                                   "complaint").
     * @param string|null $sourceBlastId Blast UUID that triggered the
     *                                   withdrawal (for audit context).
     *
     * @return void
     *
     * @spec openspec/specs/marketing-compliance/spec.md#requirement-consent-withdrawal-propagates
     */
    public function recordConsentWithdrawal(
        string $contactId,
        string $channel,
        string $reason,
        ?string $sourceBlastId=null,
    ): void {
        $channel = strtolower(trim($channel));
        if ($contactId === '' || $channel === '' || $reason === '') {
            return;
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');

        $record = $this->findConsentRecord(contactId: $contactId, channel: $channel);
        if ($record === null) {
            $this->persistConsentCreate(
                contactId: $contactId,
                channel: $channel,
                reason: $reason,
                now: $now
            );
        }

        if ($record !== null) {
            $existingWithdrawnAt = $record['withdrawnAt'] ?? null;
            $alreadyWithdrawn    = (is_string($existingWithdrawnAt) === true && trim($existingWithdrawnAt) !== '');
            if ($alreadyWithdrawn === true) {
                // Already withdrawn — keep first-withdrawal timestamp.
                $this->logger->info(
                    'ComplianceService.recordConsentWithdrawal: already withdrawn',
                    [
                        'contactId'     => $contactId,
                        'channel'       => $channel,
                        'sourceBlastId' => $sourceBlastId,
                    ]
                );
            }

            if ($alreadyWithdrawn === false) {
                $record['withdrawnAt']     = $now;
                $record['withdrawnReason'] = $reason;
                $this->persistConsentUpdate(record: $record);
            }
        }//end if

        $this->transitionQueuedDeliveries(contactId: $contactId, sourceBlastId: $sourceBlastId);

        $this->logger->info(
            'ComplianceService.recordConsentWithdrawal: withdrawal recorded',
            [
                'contactId'     => $contactId,
                'channel'       => $channel,
                'reason'        => $reason,
                'sourceBlastId' => $sourceBlastId,
            ]
        );
    }//end recordConsentWithdrawal()

    /**
     * Persist a withdrawal update on an existing ConsentRecord.
     *
     * @param array<string, mixed> $record Updated record payload.
     *
     * @return void
     */
    private function persistConsentUpdate(array $record): void
    {
        $register = $this->getRegisterSlug();
        $schema   = $this->getConsentRecordSchemaSlug();
        if ($register === '' || $schema === '') {
            return;
        }

        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return;
        }

        $id = $this->extractObjectId(payload: $record);
        if ($id === '') {
            return;
        }

        try {
            $objectService->updateObject(
                id: $id,
                object: $record,
                register: $register,
                schema: $schema,
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'ComplianceService.persistConsentUpdate: updateObject failed',
                [
                    'id'        => $id,
                    'exception' => $e->getMessage(),
                ]
            );
        }
    }//end persistConsentUpdate()

    /**
     * Persist a synthetic ConsentRecord at withdrawal time when none
     * existed — preserves the audit ledger for GDPR Art. 7(3).
     *
     * @param string $contactId Contact UUID / slug.
     * @param string $channel   "email" or "sms".
     * @param string $reason    Withdrawal reason.
     * @param string $now       UTC timestamp string.
     *
     * @return void
     */
    private function persistConsentCreate(
        string $contactId,
        string $channel,
        string $reason,
        string $now,
    ): void {
        $register = $this->getRegisterSlug();
        $schema   = $this->getConsentRecordSchemaSlug();
        if ($register === '' || $schema === '') {
            return;
        }

        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return;
        }

        $payload = [
            'contactId'       => $contactId,
            'channel'         => $channel,
            'lawfulBasis'     => 'consent',
            'consentSource'   => 'auto-withdrawal-ledger',
            'consentedAt'     => $now,
            'withdrawnAt'     => $now,
            'withdrawnReason' => $reason,
            'softBounceCount' => 0,
        ];

        try {
            $objectService->saveObject(
                object: $payload,
                register: $register,
                schema: $schema,
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'ComplianceService.persistConsentCreate: saveObject failed',
                [
                    'contactId' => $contactId,
                    'channel'   => $channel,
                    'exception' => $e->getMessage(),
                ]
            );
        }
    }//end persistConsentCreate()

    /**
     * Transition queued BlastDelivery rows for the contact to
     * `unsubscribed-before-send` so they are skipped on dispatch.
     *
     * Member 04 wires this through BlastService::transitionQueuedDeliveries
     * for centralised counter roll-up. Until that lands the rows are
     * updated directly so the audit trail and skip behaviour are still
     * correct end-to-end.
     *
     * @param string      $contactId     Contact UUID / slug.
     * @param string|null $sourceBlastId Blast UUID for audit context.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential guard clauses over queued rows; extraction adds no clarity.
     * @SuppressWarnings(PHPMD.NPathComplexity)      Sequential guard clauses over queued rows; extraction adds no clarity.
     */
    private function transitionQueuedDeliveries(string $contactId, ?string $sourceBlastId): void
    {
        $register = $this->getRegisterSlug();
        $schema   = $this->getBlastDeliverySchemaSlug();
        if ($register === '' || $schema === '') {
            return;
        }

        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return;
        }

        try {
            $rows = $objectService->findAll(
                config: [
                    'filters' => [
                        'contactId' => $contactId,
                        'register'  => $register,
                        'schema'    => $schema,
                    ],
                ]
            );
        } catch (Throwable $e) {
            $this->logger->info(
                'ComplianceService.transitionQueuedDeliveries: findAll failed',
                [
                    'contactId' => $contactId,
                    'exception' => $e->getMessage(),
                ]
            );
            return;
        }

        foreach (($rows ?? []) as $row) {
            $array = $this->toArray(value: $row);
            if ($array === []) {
                continue;
            }

            $status = strtolower((string) ($array['status'] ?? ''));
            if (in_array($status, self::QUEUED_DELIVERY_STATUSES, true) === false) {
                continue;
            }

            if ($sourceBlastId !== null && $sourceBlastId !== '') {
                $rowBlast = (string) ($array['blastId'] ?? '');
                if ($rowBlast !== '' && $rowBlast !== $sourceBlastId) {
                    // Sourcing blast pinned and delivery belongs to a
                    // different blast — leave it alone.
                    continue;
                }
            }

            $id = $this->extractObjectId(payload: $array);
            if ($id === '') {
                continue;
            }

            $array['status']         = self::STATUS_UNSUBSCRIBED_BEFORE_SEND;
            $array['unsubscribedAt'] = gmdate('Y-m-d\TH:i:s\Z');

            try {
                $objectService->updateObject(
                    id: $id,
                    object: $array,
                    register: $register,
                    schema: $schema,
                );
            } catch (Throwable $e) {
                $this->logger->warning(
                    'ComplianceService.transitionQueuedDeliveries: updateObject failed',
                    [
                        'id'        => $id,
                        'exception' => $e->getMessage(),
                    ]
                );
            }
        }//end foreach
    }//end transitionQueuedDeliveries()

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
                config: [
                    'filters' => [
                        'contactId' => $contactId,
                        'channel'   => $channel,
                        'register'  => $register,
                        'schema'    => $schema,
                    ],
                ]
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
        }//end try

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
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Ordered fallback lookup over id keys; extraction adds no clarity.
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
