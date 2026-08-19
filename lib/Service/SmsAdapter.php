<?php

/**
 * Pipelinq SmsAdapter.
 *
 * Multi-provider SMS adapter. Resolves the right provider via
 * priority order (lower priority value = tried first), fails over to
 * the next provider on transient (5xx / network) errors, gates every
 * send through ConsentService + BudgetService, persists the outbound
 * row, and routes inbound webhook events to the conversation.
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
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#3.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Provider\PermanentSmsProviderException;
use OCA\Pipelinq\Service\Provider\SmsProviderClientInterface;
use OCA\Pipelinq\Service\Provider\TransientSmsProviderException;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * SmsAdapter — vendor-neutral outbound + inbound SMS surface.
 *
 * Public entry points:
 * - send(contact, body, providerHint?) — gated, failover-aware
 *   send returning an outcome envelope.
 * - handleInboundWebhook(rawBody, signature, providerId) —
 *   signature-verified webhook ingestion.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Wires consent +
 * budget + provider + repository + notifications + OR.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Vendor-neutral send/receive
 * surface with failover, persistence and webhook ingestion kept cohesive.
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#3.1
 */
class SmsAdapter
{
    /**
     * Default pipelinq register slug.
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
     * Default contact schema slug (in the pipelinq register; the
     * client-management register may also expose it under a
     * different namespace).
     */
    private const DEFAULT_CONTACT_SCHEMA_SLUG = 'contact';

    /**
     * Outcome label — sent successfully.
     */
    public const STATUS_SENT = 'sent';

    /**
     * Outcome label — refused by consent service (403).
     */
    public const STATUS_CONSENT_MISSING = 'consentMissing';

    /**
     * Outcome label — refused by budget service (403).
     */
    public const STATUS_BUDGET_EXCEEDED = 'budgetExceeded';

    /**
     * Outcome label — no active provider matches.
     */
    public const STATUS_NO_PROVIDER = 'noProviderAvailable';

    /**
     * Outcome label — provider call failed (all failover exhausted).
     */
    public const STATUS_FAILED = 'failed';

    /**
     * Constructor.
     *
     * @param ContainerInterface        $container           DI container.
     * @param IAppConfig                $appConfig           App config.
     * @param ChannelProviderRepository $providerRepo        Provider read-side.
     * @param SmsProviderFactory        $providerFactory     Vendor client factory.
     * @param ConsentService            $consentService      Opt-out gate.
     * @param BudgetService             $budgetService       Budget gate.
     * @param NotificationService       $notificationService Admin notifications.
     * @param LoggerInterface           $logger              Logger.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#3.1
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private ChannelProviderRepository $providerRepo,
        private SmsProviderFactory $providerFactory,
        private ConsentService $consentService,
        private BudgetService $budgetService,
        private NotificationService $notificationService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Send an SMS, honouring consent, budget, and provider failover.
     *
     * @param array<string, mixed> $contact      Contact row (must
     *                                           expose `id` and a
     *                                           phone number).
     * @param string               $body         Message body.
     * @param string|null          $providerHint Pin a specific vendor
     *                                           (caller-pinned, no
     *                                           failover).
     * @param array<string, mixed> $context      Optional send context for
     *                                           the outbound audit
     *                                           (`clientId`, `agent`).
     *
     * @return array{
     *     status: string,
     *     messageId?: string,
     *     externalMessageId?: string,
     *     providerId?: string,
     *     vendor?: string,
     *     error?: string
     * } Outcome envelope.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#3.1
     * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-006-outbound-sends-audited-as-contactmomenten
     */
    public function send(array $contact, string $body, ?string $providerHint=null, array $context=[]): array
    {
        $contactId = $this->extractId(payload: $contact);
        $toNumber  = $this->extractPhone(contact: $contact);

        if ($toNumber === '') {
            return ['status' => self::STATUS_FAILED, 'error' => 'no recipient phone number'];
        }

        if ($this->consentService->canSend(contactId: $contactId, channel: 'sms') === false) {
            return ['status' => self::STATUS_CONSENT_MISSING];
        }

        $tenantId = $this->getTenantId();
        $ordered  = $this->orderedProvidersForSend(providerHint: $providerHint);
        if ($ordered === []) {
            return ['status' => self::STATUS_NO_PROVIDER];
        }

        $allowFailover = ($providerHint === null);

        foreach ($ordered as $row) {
            $outcome = $this->attemptProviderSend(
                row: $row,
                toNumber: $toNumber,
                body: $body,
                contactId: $contactId,
                tenantId: $tenantId,
                allowFailover: $allowFailover,
                context: $context,
            );
            if ($outcome !== null) {
                return $outcome;
            }
        }

        // Exhausted all providers transiently — persist failed row + alert.
        return $this->persistFailureAndAlert(
            contactId: $contactId,
            providerId: '',
            body: $body,
            error: 'all SMS providers returned transient errors',
        );
    }//end send()

    /**
     * Try sending via a single provider row.
     *
     * Returns the final outcome envelope when the caller should stop
     * (sent, budget-exceeded-without-failover, or a persisted failure
     * that exhausts failover), or null when the caller should move on
     * to the next provider in the ordered list.
     *
     * @param array<string, mixed> $row           Provider row.
     * @param string               $toNumber      Recipient E.164 number.
     * @param string               $body          Message body.
     * @param string               $contactId     Contact UUID.
     * @param string               $tenantId      Tenant id.
     * @param bool                 $allowFailover Whether failover to the next provider is allowed.
     * @param array<string, mixed> $context       Send context for the outbound audit.
     *
     * @return array<string, mixed>|null Outcome envelope, or null to continue.
     */
    private function attemptProviderSend(
        array $row,
        string $toNumber,
        string $body,
        string $contactId,
        string $tenantId,
        bool $allowFailover,
        array $context=[]
    ): ?array {
        $providerId = $this->extractId(payload: $row);

        if ($this->budgetService->canSend(tenantId: $tenantId, providerId: $providerId) === false) {
            if ($allowFailover === false) {
                return ['status' => self::STATUS_BUDGET_EXCEEDED, 'providerId' => $providerId];
            }

            return null;
        }

        $client = $this->providerFactory->create(channelProvider: $row);
        if ($client === null) {
            return null;
        }

        try {
            $result = $client->send(toNumber: $toNumber, body: $body);
        } catch (TransientSmsProviderException $e) {
            $this->logger->warning(
                'SmsAdapter.send: transient — failing over',
                ['providerId' => $providerId, 'vendor' => $client->getVendor(), 'message' => $e->getMessage()]
            );
            if ($allowFailover === false) {
                return $this->persistFailureAndAlert(
                    contactId: $contactId,
                    providerId: $providerId,
                    body: $body,
                    error: $e->getMessage(),
                );
            }

            return null;
        } catch (PermanentSmsProviderException $e) {
            $this->logger->warning(
                'SmsAdapter.send: permanent provider failure',
                ['providerId' => $providerId, 'vendor' => $client->getVendor(), 'message' => $e->getMessage()]
            );
            return $this->persistFailureAndAlert(
                contactId: $contactId,
                providerId: $providerId,
                body: $body,
                error: $e->getMessage(),
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'SmsAdapter.send: unexpected exception — failing over',
                ['providerId' => $providerId, 'vendor' => $client->getVendor(), 'message' => $e->getMessage()]
            );
            if ($allowFailover === false) {
                return $this->persistFailureAndAlert(
                    contactId: $contactId,
                    providerId: $providerId,
                    body: $body,
                    error: $e->getMessage(),
                );
            }

            return null;
        }//end try

        $persisted = $this->persistOutbound(
            contactId: $contactId,
            providerId: $providerId,
            body: $body,
            externalMessageId: $result['externalMessageId'],
        );

        $this->budgetService->recordSend(tenantId: $tenantId, providerId: $providerId);

        $messageId = $this->extractId(payload: ($persisted ?? []));
        $this->auditOutbound(
            contactId: $contactId,
            messageId: $messageId,
            conversationId: (string) (($persisted ?? [])['conversationId'] ?? ''),
            body: $body,
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
     * records the SMS send as a `channel: sms` contactmoment. Log-and-continue:
     * an audit failure MUST never affect the send outcome.
     *
     * @param string               $contactId      Contact UUID.
     * @param string               $messageId      Persisted message UUID.
     * @param string               $conversationId Conversation UUID.
     * @param string               $body           Message body.
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
            channel: 'sms',
            subject: 'Outbound SMS',
            summary: $body,
            channelMetadata: [
                'platform'       => 'sms',
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
     * Handle an inbound SMS webhook.
     *
     * Verifies the signature using the channelProvider.webhookSecret,
     * persists the inbound message, auto-detects STOP keywords, and
     * fires placeholder-contact creation when the number is unknown.
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
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#3.5
     */
    public function handleInboundWebhook(string $rawBody, string $signature, string $providerId): array
    {
        $row = $this->providerRepo->findById(id: $providerId);
        if ($row === null) {
            return ['status' => 'providerUnknown'];
        }

        $client = $this->providerFactory->create(channelProvider: $row);
        if ($client === null) {
            return ['status' => 'vendorUnsupported'];
        }

        if ($client->verifySignature(rawBody: $rawBody, signature: $signature) === false) {
            $this->logger->warning(
                'SmsAdapter.handleInboundWebhook: invalid signature',
                ['providerId' => $providerId]
            );
            return ['status' => 'invalidSignature'];
        }

        $decoded = $this->decodeBody(rawBody: $rawBody);
        $body    = (string) ($decoded['body'] ?? ($decoded['Body'] ?? ''));
        $from    = (string) ($decoded['from'] ?? ($decoded['From'] ?? ''));

        if ($from === '' || $body === '') {
            return ['status' => 'invalidPayload'];
        }

        $contactInfo = $this->findOrCreatePlaceholderContact(phone: $from);
        $contactId   = $contactInfo['contactId'];
        $placeholder = $contactInfo['created'];

        $conversationId = $this->findOrOpenConversation(
            contactId: $contactId,
            providerId: $providerId,
            channel: 'sms',
        );

        $persisted = $this->persistInbound(
            contactId: $contactId,
            providerId: $providerId,
            conversationId: $conversationId,
            body: $body,
        );

        $optOutRecorded = false;
        if ($this->consentService->isOptOutKeyword(body: $body) === true) {
            $this->consentService->recordOptOut(
                contactId: $contactId,
                channel: 'sms',
                source: 'keyword-stop',
                evidence: sprintf('Inbound SMS body "%s" matched STOP keyword', $body),
            );
            $optOutRecorded = true;
        } else if ($this->consentService->isOptInKeyword(body: $body) === true) {
            $this->consentService->recordOptIn(
                contactId: $contactId,
                channel: 'sms',
                source: 'chat-reply',
                evidence: sprintf('Inbound SMS body "%s" matched opt-in keyword', $body),
            );
        }

        return [
            'status'             => 'received',
            'messageId'          => $this->extractId(payload: $persisted ?? []),
            'conversationId'     => $conversationId,
            'placeholderCreated' => $placeholder,
            'optOutRecorded'     => $optOutRecorded,
        ];
    }//end handleInboundWebhook()

    /**
     * Producer of the ordered provider list for an outbound send.
     *
     * @param string|null $providerHint Pinned vendor or null.
     *
     * @return array<int, array<string, mixed>> Provider rows.
     */
    private function orderedProvidersForSend(?string $providerHint): array
    {
        if ($providerHint !== null && $providerHint !== '') {
            $row = $this->providerRepo->findByVendor(kind: 'sms', vendor: $providerHint);
            if ($row === null) {
                return [];
            }

            return [$row];
        }

        return $this->providerRepo->listActive(kind: 'sms');
    }//end orderedProvidersForSend()

    /**
     * Persist a `failed` outbound row, leave a system note, and
     * notify the tenant admin.
     *
     * @param string $contactId  Contact UUID.
     * @param string $providerId Provider UUID (empty when none tried).
     * @param string $body       Message body.
     * @param string $error      Error description.
     *
     * @return array{status: string, error: string, providerId?: string} Outcome.
     */
    private function persistFailureAndAlert(
        string $contactId,
        string $providerId,
        string $body,
        string $error
    ): array {
        $conversationId = $this->findOrOpenConversation(
            contactId: $contactId,
            providerId: $providerId,
            channel: 'sms',
        );

        $this->persistMessage(
            payload: [
                'contactId'      => $contactId,
                'conversationId' => $conversationId,
                'channel'        => 'sms',
                'direction'      => 'outbound',
                'body'           => $body,
                'providerId'     => $providerId,
                'deliveryStatus' => 'failed',
                'sentAt'         => $this->nowIso(),
                'metadata'       => ['error' => $error],
            ],
        );

        try {
            $this->notificationService->sendNotification(
                userId: 'admin',
                subject: 'messaging_sms_send_failed',
                parameters: ['contactId' => $contactId, 'providerId' => $providerId, 'error' => $error],
                objectType: 'message',
                objectId: $contactId,
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'SmsAdapter.persistFailureAndAlert: notification failed',
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
     * @param string $contactId         Contact UUID.
     * @param string $providerId        Provider UUID.
     * @param string $body              Body.
     * @param string $externalMessageId Provider message id.
     *
     * @return array<string, mixed>|null Saved row.
     */
    private function persistOutbound(
        string $contactId,
        string $providerId,
        string $body,
        string $externalMessageId
    ): ?array {
        $conversationId = $this->findOrOpenConversation(
            contactId: $contactId,
            providerId: $providerId,
            channel: 'sms',
        );

        return $this->persistMessage(
            payload: [
                'contactId'         => $contactId,
                'conversationId'    => $conversationId,
                'channel'           => 'sms',
                'direction'         => 'outbound',
                'body'              => $body,
                'providerId'        => $providerId,
                'externalMessageId' => $externalMessageId,
                'deliveryStatus'    => 'queued',
                'sentAt'            => $this->nowIso(),
            ],
        );
    }//end persistOutbound()

    /**
     * Persist an inbound message row.
     *
     * @param string $contactId      Contact UUID.
     * @param string $providerId     Provider UUID.
     * @param string $conversationId Conversation UUID.
     * @param string $body           Body.
     *
     * @return array<string, mixed>|null Saved row.
     */
    private function persistInbound(
        string $contactId,
        string $providerId,
        string $conversationId,
        string $body
    ): ?array {
        return $this->persistMessage(
            payload: [
                'contactId'      => $contactId,
                'conversationId' => $conversationId,
                'channel'        => 'sms',
                'direction'      => 'inbound',
                'body'           => $body,
                'providerId'     => $providerId,
                'deliveryStatus' => 'delivered',
                'sentAt'         => $this->nowIso(),
            ],
        );
    }//end persistInbound()

    /**
     * Persist a message payload via OpenRegister.
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
                'SmsAdapter.persistMessage: save failed',
                ['exception' => $e->getMessage()]
            );
            return null;
        }

        return $this->toArray(value: $saved);
    }//end persistMessage()

    /**
     * Look up the open conversation for (contact, provider, channel)
     * or open a new one.
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
            $this->logger->warning(
                'SmsAdapter.findOrOpenConversation: findAll failed',
                ['exception' => $e->getMessage()]
            );
            $rows = [];
        }

        if (is_array($rows) === true && $rows !== []) {
            return $this->extractId(payload: $this->toArray(value: $rows[0]));
        }

        try {
            $saved = $objectService->saveObject(
                object: [
                    'contactId'     => $contactId,
                    'providerId'    => $providerId,
                    'channel'       => $channel,
                    'status'        => 'open',
                    'lastInboundAt' => $this->nowIso(),
                ],
                register: $this->getRegisterSlug(),
                schema: $schema,
                uuid: null,
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'SmsAdapter.findOrOpenConversation: open failed',
                ['exception' => $e->getMessage()]
            );
            return '';
        }

        return $this->extractId(payload: $this->toArray(value: $saved));
    }//end findOrOpenConversation()

    /**
     * Look up a contact by phone number, creating a placeholder when
     * no match exists. Returns {contactId, created}.
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
                    'source'      => 'sms-inbound',
                    'placeholder' => true,
                ],
                register: $this->getRegisterSlug(),
                schema: $schema,
                uuid: null,
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'SmsAdapter.findOrCreatePlaceholderContact: create failed',
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
     * Decode an inbound webhook body — accepts JSON or form-encoded.
     *
     * @param string $rawBody Raw body.
     *
     * @return array<string, mixed> Decoded payload.
     */
    private function decodeBody(string $rawBody): array
    {
        $trimmed = trim($rawBody);
        if ($trimmed === '') {
            return [];
        }

        if ($trimmed[0] === '{' || $trimmed[0] === '[') {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded) === true) {
                return $decoded;
            }

            return [];
        }

        $parsed = [];
        parse_str($trimmed, $parsed);
        return $parsed;
    }//end decodeBody()

    /**
     * Extract the most relevant phone number from a contact row.
     *
     * @param array<string, mixed> $contact Contact row.
     *
     * @return string Phone number (E.164) or empty.
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
     * Tenant id — defaults to the NC instance id when none is set.
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
     * Resolve a schema slug app-config override.
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
                'SmsAdapter.getObjectService: OpenRegister unavailable',
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
        $id = $this->firstScalarValue(source: $payload, keys: ['uuid', 'id', 'slug']);
        if ($id !== '') {
            return $id;
        }

        if (isset($payload['@self']) === true && is_array($payload['@self']) === true) {
            return $this->firstScalarValue(source: $payload['@self'], keys: ['uuid', 'id', 'slug']);
        }

        return '';
    }//end extractId()

    /**
     * Return the first non-empty scalar value found under any of the given keys.
     *
     * @param array<string, mixed> $source Source array.
     * @param array<int, string>   $keys   Keys to try, in order.
     *
     * @return string The first matching value cast to string, or empty.
     */
    private function firstScalarValue(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            $value = ($source[$key] ?? null);
            if (is_scalar($value) === true && (string) $value !== '') {
                return (string) $value;
            }
        }

        return '';
    }//end firstScalarValue()

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
