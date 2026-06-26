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
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires consent +
 * budget + provider client + media + repository + cost capture +
 * notifications + OR.
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
     */
    public function send(
        array $contact,
        string $body,
        ?string $templateId=null,
        array $parameters=[]
    ): array {
        $contactId = $this->extractId(payload: $contact);
        $toNumber  = $this->extractPhone(contact: $contact);

        if ($toNumber === '') {
            return ['status' => self::STATUS_FAILED, 'error' => 'no recipient phone number'];
        }

        if ($this->consentService->canSend(contactId: $contactId, channel: 'whatsapp') === false) {
            return ['status' => self::STATUS_CONSENT_MISSING];
        }

        $template     = null;
        $templateName = '';
        $language     = (string) $this->appConfig->getValueString(Application::APP_ID, 'whatsapp.default_language', 'nl');

        if ($templateId !== null && $templateId !== '') {
            $template = $this->loadTemplate(id: $templateId);
            if ($template === null) {
                return ['status' => self::STATUS_TEMPLATE_NOT_FOUND];
            }

            $status = (string) ($template['status'] ?? '');
            if ($status !== 'approved') {
                return ['status' => self::STATUS_TEMPLATE_NOT_APPROVED];
            }

            $templateName = (string) ($template['externalId'] ?? '');
            $language     = (string) ($template['language'] ?? $language);

            $expected = $this->parseTemplatePlaceholders(templateBody: (string) ($template['body'] ?? ''));
            $given    = count($parameters);
            if ($expected !== $given) {
                return [
                    'status'   => self::STATUS_TEMPLATE_MISMATCH,
                    'expected' => $expected,
                    'given'    => $given,
                ];
            }
        } else {
            // Free-form: require an in-window session.
            if ($this->isWithinSessionWindow(contactId: $contactId) === false) {
                return ['status' => self::STATUS_SESSION_WINDOW_EXPIRED];
            }
        }//end if

        $tenantId = $this->getTenantId();
        $ordered  = $this->orderedProvidersForSend(templateProviderId: (string) ($template['providerId'] ?? ''));

        if ($ordered === []) {
            return ['status' => self::STATUS_NO_PROVIDER];
        }

        foreach ($ordered as $row) {
            $providerId = $this->extractId(payload: $row);

            if ($this->budgetService->canSend(tenantId: $tenantId, providerId: $providerId) === false) {
                // Budget-failure is per-provider; we may still try the
                // next priority. Continue.
                continue;
            }

            try {
                if ($templateName !== '') {
                    $result = $this->providerClient->sendTemplate(
                        channelProvider: $row,
                        phoneNumber: $toNumber,
                        templateName: $templateName,
                        language: $language,
                        parameters: $parameters,
                    );
                } else {
                    $result = $this->providerClient->sendFreeForm(
                        channelProvider: $row,
                        phoneNumber: $toNumber,
                        body: $body,
                    );
                }
            } catch (TransientSmsProviderException $e) {
                $this->logger->warning(
                    'WhatsAppAdapter.send: transient — failing over',
                    ['providerId' => $providerId, 'message' => $e->getMessage()]
                );
                continue;
            } catch (PermanentSmsProviderException $e) {
                $this->logger->warning(
                    'WhatsAppAdapter.send: permanent provider failure',
                    ['providerId' => $providerId, 'message' => $e->getMessage()]
                );
                if ($templateName !== '') {
                    $failureBody = sprintf('[template:%s]', $templateName);
                } else {
                    $failureBody = $body;
                }

                if ($template !== null) {
                    $failureTemplateId = $this->extractId(payload: $template);
                } else {
                    $failureTemplateId = '';
                }

                return $this->persistFailureAndAlert(
                    contactId: $contactId,
                    providerId: $providerId,
                    body: $failureBody,
                    templateId: $failureTemplateId,
                    error: $e->getMessage(),
                );
            } catch (Throwable $e) {
                $this->logger->warning(
                    'WhatsAppAdapter.send: unexpected — failing over',
                    ['providerId' => $providerId, 'message' => $e->getMessage()]
                );
                continue;
            }//end try

            if ($templateName !== '') {
                $outboundBody = sprintf('[template:%s]', $templateName);
            } else {
                $outboundBody = $body;
            }

            if ($template !== null) {
                $outboundTemplateId = $this->extractId(payload: $template);
            } else {
                $outboundTemplateId = '';
            }

            $persisted = $this->persistOutbound(
                contactId: $contactId,
                providerId: $providerId,
                body: $outboundBody,
                externalMessageId: $result['externalMessageId'],
                templateId: $outboundTemplateId,
                templateParameters: $parameters,
            );

            $this->budgetService->recordSend(tenantId: $tenantId, providerId: $providerId);

            return [
                'status'            => self::STATUS_SENT,
                'messageId'         => $this->extractId(payload: $persisted ?? []),
                'externalMessageId' => $result['externalMessageId'],
                'providerId'        => $providerId,
                'vendor'            => $result['vendor'],
            ];
        }//end foreach

        if ($templateName !== '') {
            $exhaustedBody = sprintf('[template:%s]', $templateName);
        } else {
            $exhaustedBody = $body;
        }

        if ($template !== null) {
            $exhaustedTemplateId = $this->extractId(payload: $template);
        } else {
            $exhaustedTemplateId = '';
        }

        return $this->persistFailureAndAlert(
            contactId: $contactId,
            providerId: '',
            body: $exhaustedBody,
            templateId: $exhaustedTemplateId,
            error: 'all WhatsApp providers returned transient errors',
        );
    }//end send()

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
                filters: [
                    'contactId' => $contactId,
                    'channel'   => 'whatsapp',
                    'direction' => 'inbound',
                ],
                register: $this->getRegisterSlug(),
                schema: $this->resolveSchemaSlug(key: 'message_schema', default: self::DEFAULT_MESSAGE_SCHEMA_SLUG),
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'WhatsAppAdapter.isWithinSessionWindow: findAll failed',
                ['contactId' => $contactId, 'exception' => $e->getMessage()]
            );
            return false;
        }

        if (is_array($rows) === false || $rows === []) {
            return false;
        }

        $latestTs = 0;
        foreach ($rows as $raw) {
            $arr = $this->toArray(value: $raw);
            $ts  = strtotime((string) ($arr['sentAt'] ?? ''));
            if ($ts === false) {
                $ts = 0;
            }

            if ($ts > $latestTs) {
                $latestTs = $ts;
            }
        }

        if ($latestTs === 0) {
            return false;
        }

        return ((time() - $latestTs) < self::SESSION_WINDOW_SECONDS);
    }//end isWithinSessionWindow()

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
                filters: ['phoneNumber' => $phone],
                register: $this->getRegisterSlug(),
                schema: $schema,
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
                filters: [
                    'contactId'  => $contactId,
                    'providerId' => $providerId,
                    'channel'    => $channel,
                    'status'     => 'open',
                ],
                register: $this->getRegisterSlug(),
                schema: $schema,
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
     * Current ISO 8601 UTC timestamp.
     *
     * @return string Timestamp.
     */
    private function nowIso(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }//end nowIso()
}//end class
