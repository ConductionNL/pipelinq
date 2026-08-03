<?php

/**
 * Pipelinq WhatsAppAdapter.
 *
 * WhatsApp Cloud API + BSP adapter. Manages the 24-hour session
 * window, template-vs-free-form gating, template parameter
 * validation, provider failover (Meta primary, BSP fallback), and
 * signature-verified inbound webhook ingestion. Every outbound goes
 * through ConsentService + BudgetService before reaching the
 * provider client.
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
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Provider\PermanentSmsProviderException;
use OCA\Pipelinq\Service\Provider\TransientSmsProviderException;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * WhatsAppAdapter — outbound + inbound WhatsApp surface.
 *
 * Public entry points:
 * - send(contact, body, templateId?, parameters?) — template or
 *   free-form send with full compliance gating.
 * - handleInboundWebhook(rawBody, signature, providerId) —
 *   signature-verified webhook ingestion.
 * - parseTemplatePlaceholders(templateBody) — pure utility that
 *   counts {{N}} placeholders.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Wires consent +
 *  budget + provider client + media + repository + cost capture +
 *  notifications + OR.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Aggregates the whole
 *  outbound/inbound WhatsApp lifecycle (send orchestration, failover,
 *  webhook ingestion, OR persistence helpers) as many small,
 *  single-purpose methods; splitting it would scatter one cohesive
 *  channel-adapter concern across several classes.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Same cohesion
 *  rationale: the length reflects breadth, not tangled logic — each
 *  extracted method is independently simple (verified via phpmd's
 *  per-method thresholds).
 * @SuppressWarnings(PHPMD.TooManyMethods)           The 2026-07 phpmd
 *  cleanup extracted several private single-purpose helpers
 *  (resolveSendContext, resolveTemplateForSend, attemptProviderSend,
 *  bodyForSend, templateIdForSend, latestInboundTimestamp, firstScalarId)
 *  specifically to bring per-method complexity under threshold; further
 *  splitting the class would scatter one channel-adapter concern without
 *  reducing real coupling.
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#2.1
 */
class WhatsAppAdapter
{
    /**
     * Default register slug.
     */
    private const DEFAULT_REGISTER_SLUG = 'pipelinq';

    /**
     * Default message schema slug.
     */
    private const DEFAULT_MESSAGE_SCHEMA_SLUG = 'message';

    /**
     * Default conversation schema slug.
     */
    private const DEFAULT_CONVERSATION_SCHEMA_SLUG = 'conversation';

    /**
     * Default contact schema slug.
     */
    private const DEFAULT_CONTACT_SCHEMA_SLUG = 'contact';

    /**
     * Default messageTemplate schema slug.
     */
    private const DEFAULT_TEMPLATE_SCHEMA_SLUG = 'messageTemplate';

    /**
     * 24h window in seconds — Meta-imposed customer-service window.
     */
    private const SESSION_WINDOW_SECONDS = 86400;

    /**
     * Send-result envelope statuses.
     */
    public const STATUS_SENT            = 'sent';
    public const STATUS_CONSENT_MISSING = 'consentMissing';
    public const STATUS_BUDGET_EXCEEDED = 'budgetExceeded';
    public const STATUS_NO_PROVIDER     = 'noProviderAvailable';
    public const STATUS_SESSION_WINDOW_EXPIRED = 'sessionWindowExpired';
    public const STATUS_TEMPLATE_NOT_FOUND     = 'templateNotFound';
    public const STATUS_TEMPLATE_NOT_APPROVED  = 'templateNotApproved';
    public const STATUS_TEMPLATE_MISMATCH      = 'templateParameterMismatch';
    public const STATUS_FAILED = 'failed';

    /**
     * Constructor.
     *
     * @param ContainerInterface        $container           DI container.
     * @param IAppConfig                $appConfig           App config.
     * @param ChannelProviderRepository $providerRepo        Provider read-side.
     * @param WhatsAppProviderClient    $providerClient      Vendor transport.
     * @param ConsentService            $consentService      Opt-out gate.
     * @param BudgetService             $budgetService       Budget gate.
     * @param NotificationService       $notificationService Admin notifications.
     * @param LoggerInterface           $logger              Logger.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#2.1
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private ChannelProviderRepository $providerRepo,
        private WhatsAppProviderClient $providerClient,
        private ConsentService $consentService,
        private BudgetService $budgetService,
        private NotificationService $notificationService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Send a WhatsApp message.
     *
     * Decision tree:
     * 1. Consent gate.
     * 2. Resolve a phone number on the contact.
     * 3. If templateId set → load + verify approved + validate
     *    parameters. Send-template via the priority-ordered provider
     *    list.
     * 4. Else free-form → check session window; if expired return
     *    sessionWindowExpired; otherwise send-text.
     * 5. Budget gate per provider attempt; on transient failure try
     *    the next priority. Persist + recordSend on success.
     *
     * @param array<string, mixed> $contact    Contact row.
     * @param string               $body       Free-form body (ignored when templateId set).
     * @param string|null          $templateId Template UUID/slug or null for free-form.
     * @param array<int, string>   $parameters Positional template parameters.
     * @param array<string, mixed> $context    Optional send context for the
     *                                         outbound audit (`clientId`, `agent`).
     *
     * @return array{
     *     status: string,
     *     messageId?: string,
     *     externalMessageId?: string,
     *     providerId?: string,
     *     vendor?: string,
     *     expected?: int,
     *     given?: int,
     *     error?: string
     * } Send outcome.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#2.1
     * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-005-consent-gating-and-recording
     */
    public function send(
        array $contact,
        string $body,
        ?string $templateId=null,
        array $parameters=[],
        array $context=[]
    ): array {
        $contactId = $this->extractId(payload: $contact);
        $toNumber  = $this->extractPhone(contact: $contact);

        if ($toNumber === '') {
            return ['status' => self::STATUS_FAILED, 'error' => 'no recipient phone number'];
        }

        $isBusinessInitiated = (($templateId ?? '') !== '');
        if ($this->consentForSend(contactId: $contactId, businessInitiated: $isBusinessInitiated) === false) {
            return ['status' => self::STATUS_CONSENT_MISSING];
        }

        $language = (string) $this->appConfig->getValueString(Application::APP_ID, 'whatsapp.default_language', 'nl');
        $context  = $this->resolveSendContext(
            templateId: $templateId,
            parameters: $parameters,
            language: $language,
            contactId: $contactId
        );
        if ($context['error'] !== null) {
            return $context['error'];
        }

        $template     = $context['template'];
        $templateName = $context['templateName'];
        $language     = $context['language'];

        $tenantId = $this->getTenantId();
        $ordered  = $this->orderedProvidersForSend(templateProviderId: (string) ($template['providerId'] ?? ''));

        if ($ordered === []) {
            return ['status' => self::STATUS_NO_PROVIDER];
        }

        foreach ($ordered as $row) {
            $outcome = $this->attemptProviderSend(
                row: $row,
                tenantId: $tenantId,
                contactId: $contactId,
                toNumber: $toNumber,
                body: $body,
                templateName: $templateName,
                language: $language,
                parameters: $parameters,
                template: $template,
                context: $context,
            );

            if ($outcome !== null) {
                return $outcome;
            }
        }//end foreach

        return $this->persistFailureAndAlert(
            contactId: $contactId,
            providerId: '',
            body: $this->bodyForSend(templateName: $templateName, body: $body),
            templateId: $this->templateIdForSend(template: $template),
            error: 'all WhatsApp providers returned transient errors',
        );
    }//end send()

    /**
     * Resolve the send context: template resolution for a template send, or
     * the in-window session check for a free-form send.
     *
     * Extracted from {@see send()} so the orchestrator stays within the
     * complexity budget; behaviour is unchanged — the two branches are
     * mutually exclusive on whether `$templateId` is set.
     *
     * @param string|null        $templateId Template UUID/slug or null for free-form.
     * @param array<int, string> $parameters Positional template parameters.
     * @param string             $language   Default language (app-config).
     * @param string             $contactId  Contact UUID (session-window lookup).
     *
     * @return array{
     *     error: array<string, mixed>|null,
     *     template?: array<string, mixed>|null,
     *     templateName?: string,
     *     language?: string
     * } Resolved context, or an `error` outcome to return immediately.
     */
    private function resolveSendContext(?string $templateId, array $parameters, string $language, string $contactId): array
    {
        $isTemplateSend = (($templateId ?? '') !== '');
        if ($isTemplateSend === true) {
            $resolved = $this->resolveTemplateForSend(
                templateId: (string) $templateId,
                parameters: $parameters,
                language: $language
            );
            if ($resolved['ok'] === false) {
                return ['error' => $resolved['error']];
            }

            return [
                'error'        => null,
                'template'     => $resolved['template'],
                'templateName' => $resolved['templateName'],
                'language'     => $resolved['language'],
            ];
        }

        // Free-form: require an in-window session.
        if ($this->isWithinSessionWindow(contactId: $contactId) === false) {
            return ['error' => ['status' => self::STATUS_SESSION_WINDOW_EXPIRED]];
        }

        return [
            'error'        => null,
            'template'     => null,
            'templateName' => '',
            'language'     => $language,
        ];
    }//end resolveSendContext()

    /**
     * Consent gate for a WhatsApp send.
     *
     * A business-initiated send (a template send, normally outside the 24h
     * session window — including SLA escalations) requires the contact's
     * latest `messagingConsentRecord` for whatsapp to be `opted-in` (Meta
     * business-messaging policy). A within-window free-form reply keeps the
     * opt-out-only gate (customer initiated the session).
     *
     * @param string $contactId         Contact UUID.
     * @param bool   $businessInitiated Whether this is a template / business-initiated send.
     *
     * @return bool True when the send may proceed.
     *
     * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-005-consent-gating-and-recording
     */
    private function consentForSend(string $contactId, bool $businessInitiated): bool
    {
        if ($businessInitiated === true) {
            return $this->consentService->canSendBusinessInitiated(contactId: $contactId, channel: 'whatsapp');
        }

        return $this->consentService->canSend(contactId: $contactId, channel: 'whatsapp');
    }//end consentForSend()

    /**
     * Resolve + validate a template send.
     *
     * Extracted from {@see send()} so the orchestrator stays within the
     * complexity budget; behaviour (template load / approval / parameter
     * count checks) is unchanged.
     *
     * @param string             $templateId Template UUID/slug.
     * @param array<int, string> $parameters Positional template parameters.
     * @param string             $language   Fallback language when the
     *                                       template does not specify one.
     *
     * @return array{
     *     ok: bool,
     *     error?: array<string, mixed>,
     *     template?: array<string, mixed>,
     *     templateName?: string,
     *     language?: string
     * } Resolution outcome.
     */
    private function resolveTemplateForSend(string $templateId, array $parameters, string $language): array
    {
        $template = $this->loadTemplate(id: $templateId);
        if ($template === null) {
            return ['ok' => false, 'error' => ['status' => self::STATUS_TEMPLATE_NOT_FOUND]];
        }

        $status = (string) ($template['status'] ?? '');
        if ($status !== 'approved') {
            return ['ok' => false, 'error' => ['status' => self::STATUS_TEMPLATE_NOT_APPROVED]];
        }

        $templateName = (string) ($template['externalId'] ?? '');
        $language     = (string) ($template['language'] ?? $language);

        $expected = $this->parseTemplatePlaceholders(templateBody: (string) ($template['body'] ?? ''));
        $given    = count($parameters);
        if ($expected !== $given) {
            return [
                'ok'    => false,
                'error' => [
                    'status'   => self::STATUS_TEMPLATE_MISMATCH,
                    'expected' => $expected,
                    'given'    => $given,
                ],
            ];
        }

        return [
            'ok'           => true,
            'template'     => $template,
            'templateName' => $templateName,
            'language'     => $language,
        ];
    }//end resolveTemplateForSend()

    /**
     * Attempt a send through one provider row.
     *
     * Extracted from {@see send()}'s failover loop so the orchestrator stays
     * within the complexity budget; behaviour is unchanged: a budget denial,
     * a transient provider failure, or any unexpected throwable all signal
     * "try the next provider" (null return, mirrors the previous `continue`);
     * a permanent provider failure or a successful send are terminal
     * (mirrors the previous `return`).
     *
     * @param array<string, mixed>      $row          Provider row.
     * @param string                    $tenantId     Tenant id.
     * @param string                    $contactId    Contact UUID.
     * @param string                    $toNumber     Recipient phone number.
     * @param string                    $body         Free-form body.
     * @param string                    $templateName Resolved template external id, or empty for free-form.
     * @param string                    $language     Resolved language.
     * @param array<int, string>        $parameters   Positional template parameters.
     * @param array<string, mixed>|null $template     Resolved template row, or null for free-form.
     * @param array<string, mixed>      $context      Send context for the outbound audit.
     *
     * @return array<string, mixed>|null Terminal outcome, or null to try the next provider.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) The per-attempt send state
     *  (row + tenant + contact + recipient + body + template resolution + params
     *  + audit context) is threaded explicitly to keep the failover loop pure and
     *  free of mutable per-send instance state.
     */
    private function attemptProviderSend(
        array $row,
        string $tenantId,
        string $contactId,
        string $toNumber,
        string $body,
        string $templateName,
        string $language,
        array $parameters,
        ?array $template,
        array $context=[]
    ): ?array {
        $providerId = $this->extractId(payload: $row);

        if ($this->budgetService->canSend(tenantId: $tenantId, providerId: $providerId) === false) {
            // Budget-failure is per-provider; the caller may still try the
            // next priority.
            return null;
        }

        try {
            $result = match (true) {
                $templateName !== '' => $this->providerClient->sendTemplate(
                    channelProvider: $row,
                    phoneNumber: $toNumber,
                    templateName: $templateName,
                    language: $language,
                    parameters: $parameters,
                ),
                default => $this->providerClient->sendFreeForm(
                    channelProvider: $row,
                    phoneNumber: $toNumber,
                    body: $body,
                ),
            };
        } catch (TransientSmsProviderException $e) {
            $this->logger->warning(
                'WhatsAppAdapter.send: transient — failing over',
                ['providerId' => $providerId, 'message' => $e->getMessage()]
            );
            return null;
        } catch (PermanentSmsProviderException $e) {
            $this->logger->warning(
                'WhatsAppAdapter.send: permanent provider failure',
                ['providerId' => $providerId, 'message' => $e->getMessage()]
            );
            return $this->persistFailureAndAlert(
                contactId: $contactId,
                providerId: $providerId,
                body: $this->bodyForSend(templateName: $templateName, body: $body),
                templateId: $this->templateIdForSend(template: $template),
                error: $e->getMessage(),
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'WhatsAppAdapter.send: unexpected — failing over',
                ['providerId' => $providerId, 'message' => $e->getMessage()]
            );
            return null;
        }//end try

        $persisted = $this->persistOutbound(
            contactId: $contactId,
            providerId: $providerId,
            body: $this->bodyForSend(templateName: $templateName, body: $body),
            externalMessageId: $result['externalMessageId'],
            templateId: $this->templateIdForSend(template: $template),
            templateParameters: $parameters,
        );

        $this->budgetService->recordSend(tenantId: $tenantId, providerId: $providerId);

        $messageId = $this->extractId(payload: ($persisted ?? []));
        $this->auditOutbound(
            contactId: $contactId,
            messageId: $messageId,
            conversationId: (string) (($persisted ?? [])['conversationId'] ?? ''),
            body: $this->bodyForSend(templateName: $templateName, body: $body),
            context: $context,
        );

        return [
            'status'            => self::STATUS_SENT,
            'messageId'         => $messageId,
            'externalMessageId' => $result['externalMessageId'],
            'providerId'        => $providerId,
            'vendor'            => $result['vendor'],
        ];
    }//end attemptProviderSend()

    /**
     * Best-effort outbound contactmoment audit (REQ-OM-006).
     *
     * Resolves {@see ContactmomentService} lazily through the container and
     * records the WhatsApp send as a `channel: chat` contactmoment with
     * `channelMetadata.platform = whatsapp` (omnichannel convention).
     * Log-and-continue: an audit failure MUST never affect the send outcome.
     *
     * @param string               $contactId      Contact UUID.
     * @param string               $messageId      Persisted message UUID.
     * @param string               $conversationId Conversation UUID.
     * @param string               $body           Message body / template marker.
     * @param array<string, mixed> $context        Send context (`clientId`, `agent`).
     *
     * @return void
     *
     * @spec openspec/specs/omnichannel-registratie/spec.md#requirement-outbound-messages-registered-as-contactmomenten
     */
    private function auditOutbound(
        string $contactId,
        string $messageId,
        string $conversationId,
        string $body,
        array $context
    ): void {
        try {
            $auditor = $this->container->get('OCA\\Pipelinq\\Service\\ContactmomentService');
        } catch (Throwable $e) {
            return;
        }

        if (($auditor instanceof ContactmomentService) === false) {
            return;
        }

        $auditor->recordOutboundMessage(
            channel: 'chat',
            subject: 'Outbound WhatsApp',
            summary: $body,
            channelMetadata: [
                'platform'       => 'whatsapp',
                'direction'      => 'outbound',
                'messageId'      => $messageId,
                'conversationId' => $conversationId,
                'contactId'      => $contactId,
            ],
            clientId: (string) ($context['clientId'] ?? ''),
            agent: (string) ($context['agent'] ?? ''),
        );
    }//end auditOutbound()

    /**
     * Outbound body: a `[template:NAME]` marker for template sends, the raw
     * free-form body otherwise.
     *
     * @param string $templateName Resolved template external id, or empty for free-form.
     * @param string $body         Free-form body.
     *
     * @return string
     */
    private function bodyForSend(string $templateName, string $body): string
    {
        if ($templateName !== '') {
            return sprintf('[template:%s]', $templateName);
        }

        return $body;
    }//end bodyForSend()

    /**
     * Outbound templateId: the resolved template's id, or empty for free-form.
     *
     * @param array<string, mixed>|null $template Resolved template row, or null for free-form.
     *
     * @return string
     */
    private function templateIdForSend(?array $template): string
    {
        if ($template !== null) {
            return $this->extractId(payload: $template);
        }

        return '';
    }//end templateIdForSend()

    /**
     * Handle a Meta `messages` inbound webhook.
     *
     * Validates the X-Hub-Signature-256, opens or finds a
     * conversation, persists the inbound message, advances the
     * conversation `lastInboundAt`, fires automatic STOP / opt-in
     * detection, and creates a placeholder contact when needed.
     *
     * @param string $rawBody    Raw request body.
     * @param string $signature  Signature header value.
     * @param string $providerId channelProvider UUID.
     *
     * @return array{
     *     status: string,
     *     messageId?: string,
     *     conversationId?: string,
     *     placeholderCreated?: bool,
     *     optOutRecorded?: bool,
     *     error?: string
     * } Outcome envelope.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#2.6
     */
    public function handleInboundWebhook(string $rawBody, string $signature, string $providerId): array
    {
        $row = $this->providerRepo->findById(id: $providerId);
        if ($row === null) {
            return ['status' => 'providerUnknown'];
        }

        if ($this->providerClient->verifySignature(channelProvider: $row, rawBody: $rawBody, signature: $signature) === false) {
            $this->logger->warning(
                'WhatsAppAdapter.handleInboundWebhook: invalid signature',
                ['providerId' => $providerId]
            );
            return ['status' => 'invalidSignature'];
        }

        $extracted = $this->extractMetaMessage(rawBody: $rawBody);
        $from      = $extracted['from'];
        $body      = $extracted['body'];

        if ($from === '') {
            return ['status' => 'invalidPayload'];
        }

        $contactInfo = $this->findOrCreatePlaceholderContact(phone: $from);
        $contactId   = $contactInfo['contactId'];
        $placeholder = $contactInfo['created'];

        $conversationId = $this->findOrOpenConversation(
            contactId: $contactId,
            providerId: $providerId,
            channel: 'whatsapp',
        );

        $now = $this->nowIso();
        $windowExpiresAt = gmdate('Y-m-d\TH:i:s\Z', (time() + self::SESSION_WINDOW_SECONDS));

        $persisted = $this->persistMessage(
            payload: [
                'contactId'       => $contactId,
                'conversationId'  => $conversationId,
                'channel'         => 'whatsapp',
                'direction'       => 'inbound',
                'body'            => $body,
                'providerId'      => $providerId,
                'deliveryStatus'  => 'delivered',
                'sentAt'          => $now,
                'windowExpiresAt' => $windowExpiresAt,
                'metadata'        => ['raw' => $extracted],
            ],
        );

        $this->touchConversationWindow(conversationId: $conversationId, windowExpiresAt: $windowExpiresAt);

        $optOutRecorded = false;
        if ($this->consentService->isOptOutKeyword(body: $body) === true) {
            $this->consentService->recordOptOut(
                contactId: $contactId,
                channel: 'whatsapp',
                source: 'keyword-stop',
                evidence: sprintf('Inbound WhatsApp body "%s" matched STOP keyword', $body),
            );
            $optOutRecorded = true;
            // Auto-acknowledgement: free-form acknowledgement is
            // allowed under WhatsApp's utility-category rules within
            // a session. We use the open session created by this very
            // inbound message.
            $this->sendOptOutAcknowledgement(contactId: $contactId, providerRow: $row);
        } else if ($this->consentService->isOptInKeyword(body: $body) === true) {
            $this->consentService->recordOptIn(
                contactId: $contactId,
                channel: 'whatsapp',
                source: 'chat-reply',
                evidence: sprintf('Inbound WhatsApp body "%s" matched opt-in keyword', $body),
            );
        }//end if

        return [
            'status'             => 'received',
            'messageId'          => $this->extractId(payload: $persisted ?? []),
            'conversationId'     => $conversationId,
            'placeholderCreated' => $placeholder,
            'optOutRecorded'     => $optOutRecorded,
        ];
    }//end handleInboundWebhook()

    /**
     * Count {{N}} placeholders in a template body.
     *
     * @param string $templateBody Template body.
     *
     * @return int Distinct placeholder positions.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#2.3
     */
    public function parseTemplatePlaceholders(string $templateBody): int
    {
        $matches = [];
        preg_match_all('/\{\{(\d+)\}\}/u', $templateBody, $matches);
        $unique = array_unique(array_map('intval', $matches[1]));
        return count($unique);
    }//end parseTemplatePlaceholders()

    /**
     * Whether the contact has an in-window inbound message.
     *
     * @param string $contactId Contact UUID.
     *
     * @return bool True if the most recent inbound is within 24h.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#2.2
     */
    public function isWithinSessionWindow(string $contactId): bool
    {
        if ($contactId === '') {
            return false;
        }

        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return false;
        }

        try {
            $rows = $objectService->findAll(
                config: [
                    'filters' => [
                        'contactId' => $contactId,
                        'channel'   => 'whatsapp',
                        'direction' => 'inbound',
                        'register'  => $this->getRegisterSlug(),
                        'schema'    => $this->resolveSchemaSlug(
                            key: 'message_schema',
                            default: self::DEFAULT_MESSAGE_SCHEMA_SLUG
                        ),
                    ],
                ]
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'WhatsAppAdapter.isWithinSessionWindow: findAll failed',
                ['contactId' => $contactId, 'exception' => $e->getMessage()]
            );
            return false;
        }//end try

        if (is_array($rows) === false || $rows === []) {
            return false;
        }

        $latestTimestamp = $this->latestInboundTimestamp(rows: $rows);
        if ($latestTimestamp === 0) {
            return false;
        }

        return ((time() - $latestTimestamp) < self::SESSION_WINDOW_SECONDS);
    }//end isWithinSessionWindow()

    /**
     * Latest `sentAt` timestamp across a set of inbound message rows.
     *
     * Extracted from {@see isWithinSessionWindow()} so it stays within the
     * complexity budget; behaviour is unchanged: unparsable/missing
     * timestamps count as 0 and never win the max.
     *
     * @param array<int, mixed> $rows Inbound message rows.
     *
     * @return int Latest unix timestamp, or 0 when none parsed.
     */
    private function latestInboundTimestamp(array $rows): int
    {
        $latestTimestamp = 0;
        foreach ($rows as $raw) {
            $arr       = $this->toArray(value: $raw);
            $timestamp = strtotime((string) ($arr['sentAt'] ?? ''));
            if ($timestamp === false) {
                $timestamp = 0;
            }

            if ($timestamp > $latestTimestamp) {
                $latestTimestamp = $timestamp;
            }
        }

        return $latestTimestamp;
    }//end latestInboundTimestamp()

    /**
     * Provider list for an outbound send.
     *
     * When the template is bound to a specific provider, that
     * provider goes first; any other active provider then acts as
     * fallback (provided it also has the template approved — the
     * caller already verified `approved` on the bound provider).
     *
     * @param string $templateProviderId Provider UUID bound to the
     *                                   template (or empty).
     *
     * @return array<int, array<string, mixed>> Provider rows.
     */
    private function orderedProvidersForSend(string $templateProviderId): array
    {
        $cloud  = $this->providerRepo->listActive(kind: 'whatsapp-cloud-api');
        $bsp    = $this->providerRepo->listActive(kind: 'whatsapp-bsp');
        $merged = array_merge($cloud, $bsp);

        if ($templateProviderId === '') {
            return $merged;
        }

        $pinned = [];
        $rest   = [];
        foreach ($merged as $row) {
            if ($this->extractId(payload: $row) === $templateProviderId) {
                $pinned[] = $row;
                continue;
            }

            $rest[] = $row;
        }

        return array_merge($pinned, $rest);
    }//end orderedProvidersForSend()

    /**
     * Persist + alert on full-failure.
     *
     * @param string $contactId  Contact UUID.
     * @param string $providerId Provider UUID.
     * @param string $body       Body (or template marker).
     * @param string $templateId Template UUID or empty.
     * @param string $error      Error description.
     *
     * @return array{status: string, error: string, providerId?: string} Outcome.
     */
    private function persistFailureAndAlert(
        string $contactId,
        string $providerId,
        string $body,
        string $templateId,
        string $error
    ): array {
        $conversationId = $this->findOrOpenConversation(
            contactId: $contactId,
            providerId: $providerId,
            channel: 'whatsapp',
        );

        $this->persistMessage(
            payload: [
                'contactId'      => $contactId,
                'conversationId' => $conversationId,
                'channel'        => 'whatsapp',
                'direction'      => 'outbound',
                'body'           => $body,
                'providerId'     => $providerId,
                'templateId'     => $templateId,
                'deliveryStatus' => 'failed',
                'sentAt'         => $this->nowIso(),
                'metadata'       => ['error' => $error],
            ],
        );

        try {
            $this->notificationService->sendNotification(
                userId: 'admin',
                subject: 'messaging_whatsapp_send_failed',
                parameters: ['contactId' => $contactId, 'providerId' => $providerId, 'error' => $error],
                objectType: 'message',
                objectId: $contactId,
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'WhatsAppAdapter.persistFailureAndAlert: notification failed',
                ['exception' => $e->getMessage()]
            );
        }

        return [
            'status'     => self::STATUS_FAILED,
            'error'      => $error,
            'providerId' => $providerId,
        ];
    }//end persistFailureAndAlert()

    /**
     * Persist an outbound message row.
     *
     * @param string             $contactId          Contact UUID.
     * @param string             $providerId         Provider UUID.
     * @param string             $body               Body.
     * @param string             $externalMessageId  Provider id.
     * @param string             $templateId         Template UUID or empty.
     * @param array<int, string> $templateParameters Positional template parameters.
     *
     * @return array<string, mixed>|null Saved row.
     */
    private function persistOutbound(
        string $contactId,
        string $providerId,
        string $body,
        string $externalMessageId,
        string $templateId,
        array $templateParameters
    ): ?array {
        $conversationId = $this->findOrOpenConversation(
            contactId: $contactId,
            providerId: $providerId,
            channel: 'whatsapp',
        );

        return $this->persistMessage(
            payload: [
                'contactId'          => $contactId,
                'conversationId'     => $conversationId,
                'channel'            => 'whatsapp',
                'direction'          => 'outbound',
                'body'               => $body,
                'providerId'         => $providerId,
                'externalMessageId'  => $externalMessageId,
                'templateId'         => $templateId,
                'templateParameters' => array_values($templateParameters),
                'deliveryStatus'     => 'queued',
                'sentAt'             => $this->nowIso(),
            ],
        );
    }//end persistOutbound()

    /**
     * Auto-acknowledgement free-form send used after a keyword opt-out.
     *
     * Permitted under utility-category rules because it happens
     * within the inbound's open session window.
     *
     * @param string               $contactId   Contact UUID.
     * @param array<string, mixed> $providerRow Provider row.
     *
     * @return void
     */
    private function sendOptOutAcknowledgement(string $contactId, array $providerRow): void
    {
        // Best-effort; the inbound has already opened a session.
        // Failure to acknowledge does not break the opt-out audit
        // trail (which is already persisted).
        try {
            $contact = ['id' => $contactId, 'phoneNumber' => ''];

            // Best effort: look up the actual phone via repo. The
            // canonical send() requires a phone number; we degrade
            // gracefully if missing.
            $contactRow = $this->loadContact(id: $contactId);
            if ($contactRow !== null) {
                $contact = $contactRow;
            }

            $body = (string) $this->appConfig->getValueString(
                Application::APP_ID,
                'whatsapp.opt_out_ack_body',
                'Bedankt. U bent uitgeschreven en ontvangt geen verdere berichten via dit kanaal.'
            );

            // We bypass consent here because the user just opted out —
            // ConsentService.canSend() would refuse. Use the provider
            // client directly with the open session.
            $this->providerClient->sendFreeForm(
                channelProvider: $providerRow,
                phoneNumber: $this->extractPhone(contact: $contact),
                body: $body,
            );
        } catch (Throwable $e) {
            $this->logger->info(
                'WhatsAppAdapter.sendOptOutAcknowledgement: best-effort failed',
                ['contactId' => $contactId, 'exception' => $e->getMessage()]
            );
        }//end try
    }//end sendOptOutAcknowledgement()

    /**
     * Extract the message body and sender phone number from a Meta
     * webhook payload.
     *
     * @param string $rawBody Raw body.
     *
     * @return array{from: string, body: string, raw: array<string, mixed>} Extracted.
     */
    private function extractMetaMessage(string $rawBody): array
    {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded) === false) {
            return ['from' => '', 'body' => '', 'raw' => []];
        }

        // Meta envelope: entry[0].changes[0].value.messages[0].
        $entry   = ($decoded['entry'][0] ?? []);
        $change  = ($entry['changes'][0] ?? []);
        $value   = ($change['value'] ?? []);
        $message = ($value['messages'][0] ?? null);

        if (is_array($message) === false) {
            // Fall back to flat shapes used by tests + BSPs.
            return [
                'from' => (string) ($decoded['from'] ?? ''),
                'body' => (string) (($decoded['body'] ?? $decoded['text'] ?? '')),
                'raw'  => $decoded,
            ];
        }

        $body = (string) ($message['text']['body'] ?? ($message['body'] ?? ''));
        return [
            'from' => (string) ($message['from'] ?? ''),
            'body' => $body,
            'raw'  => $decoded,
        ];
    }//end extractMetaMessage()

    /**
     * Look up a contact by phone number, creating a placeholder
     * when no match exists.
     *
     * @param string $phone Sender phone number (E.164).
     *
     * @return array{contactId: string, created: bool} Contact handle.
     */
    private function findOrCreatePlaceholderContact(string $phone): array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return ['contactId' => '', 'created' => false];
        }

        $schema = $this->resolveSchemaSlug(key: 'contact_schema', default: self::DEFAULT_CONTACT_SCHEMA_SLUG);

        try {
            $rows = $objectService->findAll(
                config: [
                    'filters' => [
                        'phoneNumber' => $phone,
                        'register'    => $this->getRegisterSlug(),
                        'schema'      => $schema,
                    ],
                ]
            );
        } catch (Throwable $e) {
            $rows = [];
        }

        if (is_array($rows) === true && $rows !== []) {
            return [
                'contactId' => $this->extractId(payload: $this->toArray(value: $rows[0])),
                'created'   => false,
            ];
        }

        try {
            $saved = $objectService->saveObject(
                object: [
                    'phoneNumber' => $phone,
                    'displayName' => 'Unknown ('.$phone.')',
                    'source'      => 'whatsapp-inbound',
                    'placeholder' => true,
                ],
                register: $this->getRegisterSlug(),
                schema: $schema,
                uuid: null,
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'WhatsAppAdapter.findOrCreatePlaceholderContact: create failed',
                ['phone' => $phone, 'exception' => $e->getMessage()]
            );
            return ['contactId' => '', 'created' => false];
        }

        return [
            'contactId' => $this->extractId(payload: $this->toArray(value: $saved)),
            'created'   => true,
        ];
    }//end findOrCreatePlaceholderContact()

    /**
     * Find or open a conversation row for (contact, provider, channel).
     *
     * @param string $contactId  Contact UUID.
     * @param string $providerId Provider UUID.
     * @param string $channel    Channel.
     *
     * @return string Conversation UUID.
     */
    private function findOrOpenConversation(string $contactId, string $providerId, string $channel): string
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return '';
        }

        $schema = $this->resolveSchemaSlug(key: 'conversation_schema', default: self::DEFAULT_CONVERSATION_SCHEMA_SLUG);

        try {
            $rows = $objectService->findAll(
                config: [
                    'filters' => [
                        'contactId'  => $contactId,
                        'providerId' => $providerId,
                        'channel'    => $channel,
                        'status'     => 'open',
                        'register'   => $this->getRegisterSlug(),
                        'schema'     => $schema,
                    ],
                ]
            );
        } catch (Throwable $e) {
            $rows = [];
        }

        if (is_array($rows) === true && $rows !== []) {
            return $this->extractId(payload: $this->toArray(value: $rows[0]));
        }

        try {
            $saved = $objectService->saveObject(
                object: [
                    'contactId'  => $contactId,
                    'providerId' => $providerId,
                    'channel'    => $channel,
                    'status'     => 'open',
                ],
                register: $this->getRegisterSlug(),
                schema: $schema,
                uuid: null,
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'WhatsAppAdapter.findOrOpenConversation: open failed',
                ['exception' => $e->getMessage()]
            );
            return '';
        }

        return $this->extractId(payload: $this->toArray(value: $saved));
    }//end findOrOpenConversation()

    /**
     * Refresh the conversation's lastInboundAt + windowExpiresAt.
     *
     * @param string $conversationId  Conversation UUID.
     * @param string $windowExpiresAt ISO 8601 expiry.
     *
     * @return void
     */
    private function touchConversationWindow(string $conversationId, string $windowExpiresAt): void
    {
        if ($conversationId === '') {
            return;
        }

        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return;
        }

        $schema = $this->resolveSchemaSlug(key: 'conversation_schema', default: self::DEFAULT_CONVERSATION_SCHEMA_SLUG);

        try {
            $existing = $objectService->find(
                id: $conversationId,
                register: $this->getRegisterSlug(),
                schema: $schema,
            );
        } catch (Throwable $e) {
            return;
        }

        if ($existing === null) {
            return;
        }

        $payload = $this->toArray(value: $existing);
        $payload['lastInboundAt']   = $this->nowIso();
        $payload['windowExpiresAt'] = $windowExpiresAt;

        try {
            $objectService->saveObject(
                object: $payload,
                register: $this->getRegisterSlug(),
                schema: $schema,
                uuid: $conversationId,
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'WhatsAppAdapter.touchConversationWindow: save failed',
                ['conversationId' => $conversationId, 'exception' => $e->getMessage()]
            );
        }
    }//end touchConversationWindow()

    /**
     * Persist a message payload.
     *
     * @param array<string, mixed> $payload Payload.
     *
     * @return array<string, mixed>|null Saved row.
     */
    private function persistMessage(array $payload): ?array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return null;
        }

        try {
            $saved = $objectService->saveObject(
                object: $payload,
                register: $this->getRegisterSlug(),
                schema: $this->resolveSchemaSlug(key: 'message_schema', default: self::DEFAULT_MESSAGE_SCHEMA_SLUG),
                uuid: null,
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'WhatsAppAdapter.persistMessage: save failed',
                ['exception' => $e->getMessage()]
            );
            return null;
        }

        return $this->toArray(value: $saved);
    }//end persistMessage()

    /**
     * Load a contact by id (best effort).
     *
     * @param string $id Contact UUID.
     *
     * @return array<string, mixed>|null Row or null.
     */
    private function loadContact(string $id): ?array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null || $id === '') {
            return null;
        }

        try {
            $entity = $objectService->find(
                id: $id,
                register: $this->getRegisterSlug(),
                schema: $this->resolveSchemaSlug(key: 'contact_schema', default: self::DEFAULT_CONTACT_SCHEMA_SLUG),
            );
        } catch (Throwable $e) {
            return null;
        }

        if ($entity === null) {
            return null;
        }

        return $this->toArray(value: $entity);
    }//end loadContact()

    /**
     * Load a messageTemplate by id.
     *
     * @param string $id Template UUID / slug.
     *
     * @return array<string, mixed>|null Row or null.
     */
    private function loadTemplate(string $id): ?array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null || $id === '') {
            return null;
        }

        try {
            $entity = $objectService->find(
                id: $id,
                register: $this->getRegisterSlug(),
                schema: $this->resolveSchemaSlug(key: 'messageTemplate_schema', default: self::DEFAULT_TEMPLATE_SCHEMA_SLUG),
            );
        } catch (Throwable $e) {
            return null;
        }

        if ($entity === null) {
            return null;
        }

        return $this->toArray(value: $entity);
    }//end loadTemplate()

    /**
     * Extract a phone number from a contact row.
     *
     * @param array<string, mixed> $contact Contact row.
     *
     * @return string Phone number or empty.
     */
    private function extractPhone(array $contact): string
    {
        foreach (['phoneNumber', 'mobile', 'telephone', 'phone'] as $key) {
            $value = ($contact[$key] ?? null);
            if (is_string($value) === true && $value !== '') {
                return $value;
            }
        }

        return '';
    }//end extractPhone()

    /**
     * Tenant id (app-config; defaults to `default`).
     *
     * @return string Tenant id.
     */
    private function getTenantId(): string
    {
        $tenantId = $this->appConfig->getValueString(Application::APP_ID, 'tenant_id', '');
        if ($tenantId !== '') {
            return $tenantId;
        }

        return 'default';
    }//end getTenantId()

    /**
     * App-config schema slug override.
     *
     * @param string $key     App-config key.
     * @param string $default Built-in default.
     *
     * @return string Slug.
     */
    private function resolveSchemaSlug(string $key, string $default): string
    {
        $slug = $this->appConfig->getValueString(Application::APP_ID, $key, '');
        if ($slug !== '') {
            return $slug;
        }

        return $default;
    }//end resolveSchemaSlug()

    /**
     * Register slug.
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
     * Resolve OpenRegister ObjectService.
     *
     * @return object|null Service or null.
     */
    private function getObjectService(): ?object
    {
        try {
            return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        } catch (Throwable $e) {
            $this->logger->warning(
                'WhatsAppAdapter.getObjectService: OpenRegister unavailable',
                ['exception' => $e->getMessage()]
            );
            return null;
        }
    }//end getObjectService()

    /**
     * Normalise an OR entity to an array.
     *
     * @param mixed $value Entity or array.
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
     * Extract a UUID / id / slug from a payload.
     *
     * @param array<string, mixed> $payload Payload.
     *
     * @return string Id or empty.
     */
    private function extractId(array $payload): string
    {
        $id = $this->firstScalarId(source: $payload);
        if ($id !== '') {
            return $id;
        }

        if (isset($payload['@self']) === true && is_array($payload['@self']) === true) {
            return $this->firstScalarId(source: $payload['@self']);
        }

        return '';
    }//end extractId()

    /**
     * First non-empty scalar value among `uuid` / `id` / `slug` keys.
     *
     * Extracted from {@see extractId()} so both the top-level and `@self`
     * lookups share one implementation; behaviour is unchanged.
     *
     * @param array<string, mixed> $source Source array to probe.
     *
     * @return string The first matching value, or empty when none match.
     */
    private function firstScalarId(array $source): string
    {
        foreach (['uuid', 'id', 'slug'] as $key) {
            $value = ($source[$key] ?? null);
            if (is_scalar($value) === true && (string) $value !== '') {
                return (string) $value;
            }
        }

        return '';
    }//end firstScalarId()

    /**
     * Current ISO 8601 UTC timestamp.
     *
     * @return string Timestamp.
     */
    private function nowIso(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }//end nowIso()
}//end class
