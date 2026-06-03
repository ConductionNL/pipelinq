<?php

/**
 * Pipelinq BerichtenboxService.
 *
 * Core message-lifecycle orchestration for the MijnOverheid Berichtenbox
 * bridge: queueing outbound messages from a zaak status transition, dispatching
 * them via Logius (with email fallback on no-mailbox / opt-out / failure), read
 * receipts, the 5-working-day unread fallback, inbound reply ingestion into a
 * new Contactmoment, and AVG-erasure crypto-shredding. All BSN material is
 * encrypted at rest and masked in logs (ADR-005); every state change is written
 * to the append-only audit log.
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
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#REQ-OUTBOUND-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Exception\BerichtenboxException;
use OCA\Pipelinq\Exception\EmailSendException;
use OCA\Pipelinq\Exception\LogiusApiException;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Orchestrates the Berichtenbox message lifecycle.
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#REQ-OUTBOUND-001
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class BerichtenboxService
{
    /**
     * Retry backoff schedule in seconds (1m, 5m, 15m, 1h, 4h).
     *
     * @var array<int, int>
     */
    private const RETRY_BACKOFF = [60, 300, 900, 3600, 14400];

    /**
     * Working days a message may go unread before email fallback.
     *
     * @var int
     */
    private const FALLBACK_WORKING_DAYS = 5;

    /**
     * Maximum messages dispatched per job run.
     *
     * @var int
     */
    private const DISPATCH_BATCH = 100;

    /**
     * Constructor.
     *
     * @param ContainerInterface   $container         The DI container.
     * @param IAppConfig           $appConfig         The app config.
     * @param EncryptionService    $encryptionService The BSN crypto service.
     * @param TemplateRenderer     $templateRenderer  The template renderer.
     * @param MailboxResolver      $mailboxResolver   The mailbox resolver.
     * @param LogiusConnector      $logiusConnector   The Logius connector.
     * @param EmailFallbackSender  $emailFallback     The email fallback sender.
     * @param DeliveryAuditLogger  $auditLogger       The audit logger.
     * @param DutchHolidayCalendar $holidayCalendar   The working-day calendar.
     * @param LoggerInterface      $logger            The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private EncryptionService $encryptionService,
        private TemplateRenderer $templateRenderer,
        private MailboxResolver $mailboxResolver,
        private LogiusConnector $logiusConnector,
        private EmailFallbackSender $emailFallback,
        private DeliveryAuditLogger $auditLogger,
        private DutchHolidayCalendar $holidayCalendar,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Queue an outbound message from a zaak status transition.
     *
     * @param string                           $zaakId          The external zaak ID.
     * @param string                           $contactmomentId The linked contactmoment ID.
     * @param string                           $bsn             The citizen's validated BSN.
     * @param string                           $templateId      The template object ID.
     * @param array<string, mixed>             $context         Render context (gemeente, deadline, ...).
     * @param array<int, array<string, mixed>> $attachments     Optional attachments.
     *
     * @return array<string, mixed> The created message.
     *
     * @throws BerichtenboxException On render/encryption failure.
     */
    public function queueOutboundMessage(
        string $zaakId,
        string $contactmomentId,
        string $bsn,
        string $templateId,
        array $context=[],
        array $attachments=[]
    ): array {
        $this->assertBsn(bsn: $bsn);

        $template = $this->fetchObject(schemaKey: 'berichtenboxTemplate_schema', id: $templateId);
        if ($template === null) {
            throw new BerichtenboxException(message: 'Berichtenbox template not found.');
        }

        $deepLink = '';
        if ((bool) ($template['requiresDeepLink'] ?? false) === true) {
            $deepLink = (string) ($template['deepLinkBase'] ?? '').$zaakId;
        }

        $variables = array_merge(
            [
                'zaakId'   => $zaakId,
                'status'   => (string) ($template['status'] ?? ''),
                'gemeente' => (string) ($context['gemeente'] ?? ''),
                'deadline' => (string) ($context['deadline'] ?? ''),
                'deepLink' => $deepLink,
            ],
            $context
        );

        $rendered = $this->templateRenderer->render(template: $template, variables: $variables);

        $message = [
            'bsn'             => $this->encryptionService->encrypt($bsn),
            'bsnHash'         => $this->encryptionService->hashBsn($bsn),
            'contactmomentId' => $contactmomentId,
            'zaakId'          => $zaakId,
            'subject'         => $rendered['subject'],
            'body'            => $rendered['body'],
            'templateId'      => $templateId,
            'attachments'     => array_values($attachments),
            'deliveryStatus'  => 'queued',
            'retryCount'      => 0,
            'messageId'       => $this->uuid(),
        ];

        $saved = $this->createObject(schemaKey: 'berichtenboxMessage_schema', object: $message);
        $id    = $this->idOf(object: $saved);

        $this->auditLogger->log(messageId: $id, event: 'queued', payloadBody: $rendered['body']);
        $this->logger->info('Berichtenbox: message queued', ['zaakId' => $zaakId, 'bsn' => EncryptionService::mask(bsn: $bsn)]);

        return $saved;
    }//end queueOutboundMessage()

    /**
     * Dispatch all queued messages (called by the 5-minute job).
     *
     * @return int The number of messages processed.
     */
    public function dispatchQueuedMessages(): int
    {
        $now      = new DateTimeImmutable();
        $messages = $this->findMessages(filters: ['deliveryStatus' => 'queued'], limit: self::DISPATCH_BATCH);

        $processed = 0;
        foreach ($messages as $message) {
            // Respect the retry backoff window.
            $nextRetry = (string) ($message['nextRetryAt'] ?? '');
            if ($nextRetry !== '') {
                try {
                    if (new DateTimeImmutable($nextRetry) > $now) {
                        continue;
                    }
                } catch (Throwable $e) {
                    // Malformed timestamp -> attempt now.
                }
            }

            $this->dispatchOne(message: $message);
            $processed++;
        }

        return $processed;
    }//end dispatchQueuedMessages()

    /**
     * Dispatch a single queued message.
     *
     * @param array<string, mixed> $message The message data.
     *
     * @return void
     */
    private function dispatchOne(array $message): void
    {
        $id = $this->idOf(object: $message);

        try {
            $bsn        = $this->encryptionService->decrypt((string) ($message['bsn'] ?? ''));
            $resolution = $this->mailboxResolver->resolve($bsn);

            if ($resolution['mailboxAvailable'] === true && $resolution['optedOut'] === false) {
                $this->deliverViaLogius(id: $id, message: $message, bsn: $bsn);
                return;
            }

            // No mailbox / opted out -> email fallback if we have an address.
            $this->fallbackToEmail(id: $id, message: $message, reason: 'no-mailbox');
        } catch (Throwable $e) {
            $this->recordFailure(id: $id, message: $message, reason: $e->getMessage());
        }//end try
    }//end dispatchOne()

    /**
     * Deliver a message via the Logius API.
     *
     * @param string               $id      The message ID.
     * @param array<string, mixed> $message The message data.
     * @param string               $bsn     The plaintext BSN.
     *
     * @return void
     */
    private function deliverViaLogius(string $id, array $message, string $bsn): void
    {
        try {
            $response = $this->logiusConnector->sendMessage(
                array_merge($message, ['bsn' => $bsn, 'messageId' => ($message['messageId'] ?? $id)])
            );
        } catch (LogiusApiException $e) {
            $this->recordFailure(id: $id, message: $message, reason: $e->getReason());
            return;
        }

        $update = [
            'sentToBerichtenboxAt' => $this->now(),
            'logiusMessageId'      => $response['logiusMessageId'],
            'deliveryStatus'       => 'sent',
            'mailboxAvailable'     => true,
            'mailboxResolvedAt'    => $this->now(),
            'failureReason'        => '',
            'nextRetryAt'          => '',
        ];
        $this->patchMessage(id: $id, update: $update);
        $this->auditLogger->log(messageId: $id, event: 'sent', payloadBody: (string) ($message['body'] ?? ''));
    }//end deliverViaLogius()

    /**
     * Fall back to email for a message.
     *
     * @param string               $id      The message ID.
     * @param array<string, mixed> $message The message data.
     * @param string               $reason  The fallback reason.
     *
     * @return void
     */
    private function fallbackToEmail(string $id, array $message, string $reason): void
    {
        $email = $this->resolveBurgerEmail(message: $message);
        if ($email === '') {
            // Leave queued; nothing more we can do without an address.
            $this->logger->warning('Berichtenbox: no mailbox and no email; leaving queued', ['id' => $id]);
            return;
        }

        try {
            $this->emailFallback->send(message: $message, toEmail: $email);
        } catch (EmailSendException $e) {
            $this->recordFailure(id: $id, message: $message, reason: 'fallback-email-failed');
            return;
        }

        $this->patchMessage(
            id: $id,
            update: [
                'deliveryStatus'      => 'fallback-emailed',
                'fallbackTriggeredAt' => $this->now(),
                'fallbackEmail'       => $email,
                'fallbackSentAt'      => $this->now(),
                'nextRetryAt'         => '',
            ]
        );
        $this->auditLogger->log(messageId: $id, event: 'fallback', payloadBody: (string) ($message['body'] ?? ''), reason: $reason);
    }//end fallbackToEmail()

    /**
     * Record a delivery failure and schedule the next retry (capped).
     *
     * @param string               $id      The message ID.
     * @param array<string, mixed> $message The message data.
     * @param string               $reason  The failure reason.
     *
     * @return void
     */
    private function recordFailure(string $id, array $message, string $reason): void
    {
        $retryCount = (int) ($message['retryCount'] ?? 0);
        $update     = [
            'failureReason' => $reason,
            'retryCount'    => ($retryCount + 1),
        ];

        // Default: exhausted retries -> require operator intervention.
        $update['deliveryStatus'] = 'failed';
        $update['nextRetryAt']    = '';

        if ($retryCount < count(self::RETRY_BACKOFF)) {
            $delay = self::RETRY_BACKOFF[$retryCount];
            $update['deliveryStatus'] = 'queued';
            $update['nextRetryAt']    = (new DateTimeImmutable())->modify('+'.$delay.' seconds')->format(DateTimeInterface::ATOM);
        }

        $this->patchMessage(id: $id, update: $update);
        $this->auditLogger->log(messageId: $id, event: 'failed', payloadBody: (string) ($message['body'] ?? ''), reason: $reason);
    }//end recordFailure()

    /**
     * Handle a read receipt from Logius.
     *
     * @param string $logiusMessageId The Logius message ID.
     * @param string $readAt          The read timestamp (ISO 8601).
     *
     * @return void
     */
    public function handleReadReceipt(string $logiusMessageId, string $readAt): void
    {
        if ($logiusMessageId === '') {
            return;
        }

        $matches = $this->findMessages(filters: ['logiusMessageId' => $logiusMessageId], limit: 1);
        if ($matches === []) {
            $this->logger->warning('Berichtenbox: read receipt for unknown message', ['logiusMessageId' => $logiusMessageId]);
            return;
        }

        $message = $matches[0];
        $id      = $this->idOf(object: $message);
        $this->patchMessage(id: $id, update: ['readAt' => $readAt, 'deliveryStatus' => 'read']);
        $this->auditLogger->log(messageId: $id, event: 'read', payloadBody: (string) ($message['body'] ?? ''));
    }//end handleReadReceipt()

    /**
     * Process the 5-working-day unread fallback queue (called by the daily job).
     *
     * @return int The number of fallback emails sent.
     */
    public function processFallbackQueue(): int
    {
        $sent  = $this->findMessages(filters: ['deliveryStatus' => 'sent'], limit: self::DISPATCH_BATCH);
        $now   = new DateTimeImmutable();
        $count = 0;

        foreach ($sent as $message) {
            if (($message['readAt'] ?? '') !== '' && $message['readAt'] !== null) {
                continue;
            }

            $sentAt = (string) ($message['sentToBerichtenboxAt'] ?? '');
            if ($sentAt === '') {
                continue;
            }

            try {
                $deadline = $this->holidayCalendar->addWorkingDays(new DateTimeImmutable($sentAt), self::FALLBACK_WORKING_DAYS);
            } catch (Throwable $e) {
                continue;
            }

            if ($now <= $deadline) {
                continue;
            }

            $this->fallbackToEmail(id: $this->idOf(object: $message), message: $message, reason: '5-day-unread');
            $count++;
        }//end foreach

        return $count;
    }//end processFallbackQueue()

    /**
     * Handle an inbound reply: create a Contactmoment on the same zaak.
     *
     * The reply is scoped to the parent message (IDOR-safe): the new
     * Contactmoment inherits the parent's zaak and contactmoment linkage, so a
     * forged parentMessageId cannot attach a reply to an unrelated citizen's
     * zaak.
     *
     * @param string            $parentMessageId The parent message ID.
     * @param string            $logiusReplyId   The Logius reply ID.
     * @param string            $bodyText        The reply body text.
     * @param array<int, mixed> $attachments     The reply attachments.
     *
     * @return array<string, mixed> The created reply record.
     *
     * @throws BerichtenboxException When the parent message is unknown.
     */
    public function handleInboundReply(
        string $parentMessageId,
        string $logiusReplyId,
        string $bodyText,
        array $attachments=[]
    ): array {
        $parent = $this->fetchObject(schemaKey: 'berichtenboxMessage_schema', id: $parentMessageId);
        if ($parent === null) {
            throw new BerichtenboxException(message: 'Parent Berichtenbox message not found.');
        }

        $reply = [
            'parentMessageId' => $parentMessageId,
            'logiusReplyId'   => $logiusReplyId,
            'receivedAt'      => $this->now(),
            'bsn'             => (string) ($parent['bsn'] ?? ''),
            'bsnHash'         => (string) ($parent['bsnHash'] ?? ''),
            'bodyText'        => $bodyText,
            'attachments'     => array_values($attachments),
        ];

        $savedReply = $this->createObject(schemaKey: 'berichtenboxReply_schema', object: $reply);
        $replyId    = $this->idOf(object: $savedReply);

        try {
            $contactmomentId = $this->createReplyContactmoment(parent: $parent, bodyText: $bodyText);
            $this->patchObject(
                schemaKey: 'berichtenboxReply_schema',
                id: $replyId,
                update: [
                    'processedAt'            => $this->now(),
                    'createdContactmomentId' => $contactmomentId,
                ]
            );
            $this->auditLogger->log(messageId: $replyId, event: 'reply-received', payloadBody: $bodyText);
            $savedReply['processedAt']            = $this->now();
            $savedReply['createdContactmomentId'] = $contactmomentId;
        } catch (Throwable $e) {
            $this->patchObject(
                schemaKey: 'berichtenboxReply_schema',
                id: $replyId,
                update: ['processingError' => $e->getMessage()]
            );
            $this->auditLogger->log(messageId: $replyId, event: 'processing-error', payloadBody: $bodyText, reason: $e->getMessage());
            throw new BerichtenboxException(message: 'Failed to process inbound reply.');
        }//end try

        return $savedReply;
    }//end handleInboundReply()

    /**
     * Crypto-shred all BSN material for a citizen (AVG Art. 17 erasure).
     *
     * Decrypts nothing it cannot prove ownership of: it matches on the keyed BSN
     * hash, then overwrites the encrypted BSN with an undecryptable shred value,
     * leaving the audit trail intact.
     *
     * @param string $bsn The plaintext BSN to erase.
     *
     * @return int The number of records shredded.
     */
    public function cryptoShred(string $bsn): int
    {
        $this->assertBsn(bsn: $bsn);
        $bsnHash = $this->encryptionService->hashBsn($bsn);
        $shred   = $this->encryptionService->shred();
        $count   = 0;

        foreach (['berichtenboxMessage_schema', 'berichtenboxReply_schema', 'mailboxResolution_schema'] as $schemaKey) {
            $rows = $this->findBySchema(schemaKey: $schemaKey, filters: ['bsnHash' => $bsnHash]);
            foreach ($rows as $row) {
                $this->patchObject(schemaKey: $schemaKey, id: $this->idOf(object: $row), update: ['bsn' => $shred]);
                $count++;
            }
        }

        $this->logger->info('Berichtenbox: crypto-shredded citizen records', ['count' => $count, 'bsn' => EncryptionService::mask(bsn: $bsn)]);

        return $count;
    }//end cryptoShred()

    /**
     * Create a Contactmoment for an inbound reply, scoped to the parent's zaak.
     *
     * @param array<string, mixed> $parent   The parent message.
     * @param string               $bodyText The reply body.
     *
     * @return string The created contactmoment ID.
     */
    private function createReplyContactmoment(array $parent, string $bodyText): string
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'contactmoment_schema', '');
        if ($register === '' || $schema === '') {
            throw new RuntimeException('Contactmoment register or schema not configured.');
        }

        $contactmoment = [
            'subject'         => 'Re: '.(string) ($parent['subject'] ?? ''),
            'summary'         => $bodyText,
            'channel'         => 'berichtenbox',
            'outcome'         => 'opvolging-nodig',
            'channelMetadata' => [
                'source'          => 'berichtenbox',
                'parentMessageId' => $this->idOf(object: $parent),
                'zaakId'          => (string) ($parent['zaakId'] ?? ''),
            ],
            'contactedAt'     => $this->now(),
        ];

        $saved = $this->getObjectService()->saveObject(object: $contactmoment, extend: [], register: $register, schema: $schema, uuid: null);
        $id    = $this->idOf(object: $this->toArray(object: $saved));

        // Route the new contactmoment to the original handling ambtenaar via
        // skill-routing when that service is present (deferred until the
        // routing app is installed; see RoutingService for the in-app router).
        $this->routeContactmoment(contactmomentId: $id, parent: $parent);

        return $id;
    }//end createReplyContactmoment()

    /**
     * Route a reply contactmoment to the original ambtenaar via skill-routing.
     *
     * Best-effort: a missing routing service must not fail reply ingestion.
     *
     * @param string               $contactmomentId The contactmoment ID.
     * @param array<string, mixed> $parent          The parent message.
     *
     * @return void
     */
    private function routeContactmoment(string $contactmomentId, array $parent): void
    {
        try {
            if ($this->container->has(RoutingService::class) === true) {
                $routing = $this->container->get(RoutingService::class);
                if (method_exists($routing, 'routeContactmoment') === true) {
                    $routing->routeContactmoment(
                            $contactmomentId,
                            [
                                'source'          => 'berichtenbox',
                                'parentMessageId' => $this->idOf(object: $parent),
                            ]
                            );
                }
            }
        } catch (Throwable $e) {
            $this->logger->warning('Berichtenbox: reply routing skipped', ['exception' => $e->getMessage()]);
        }
    }//end routeContactmoment()

    /**
     * Assert a BSN is structurally valid (11-proef), without logging it.
     *
     * @param string $bsn The BSN.
     *
     * @return void
     *
     * @throws BerichtenboxException When the BSN is invalid.
     */
    private function assertBsn(string $bsn): void
    {
        if (preg_match('/^[0-9]{9}$/', $bsn) !== 1) {
            throw new BerichtenboxException(message: 'BSN must be 9 digits.');
        }

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $weight = (9 - $i);
            if ($i === 8) {
                $weight = -1;
            }

            $sum += ((int) $bsn[$i] * $weight);
        }

        if (($sum % 11) !== 0) {
            throw new BerichtenboxException(message: 'BSN failed the 11-proef checksum.');
        }
    }//end assertBsn()

    // ---- OpenRegister helpers -------------------------------------------------

    /**
     * Find messages by filters.
     *
     * @param array<string, mixed> $filters The filters.
     * @param int                  $limit   The result limit.
     *
     * @return array<int, array<string, mixed>> The messages.
     */
    private function findMessages(array $filters, int $limit): array
    {
        return $this->findBySchema(schemaKey: 'berichtenboxMessage_schema', filters: $filters, limit: $limit);
    }//end findMessages()

    /**
     * Find objects of a schema by filters.
     *
     * @param string               $schemaKey The schema config key.
     * @param array<string, mixed> $filters   The filters.
     * @param int|null             $limit     Optional limit.
     *
     * @return array<int, array<string, mixed>> The objects.
     */
    private function findBySchema(string $schemaKey, array $filters, ?int $limit=null): array
    {
        [$register, $schema] = $this->config(schemaKey: $schemaKey);

        $config = ['filters' => array_merge(['register' => $register, 'schema' => $schema], $filters)];
        if ($limit !== null) {
            $config['limit'] = $limit;
        }

        try {
            $results = $this->getObjectService()->findAll(config: $config);
        } catch (Throwable $e) {
            $this->logger->warning('Berichtenbox: query failed', ['schema' => $schemaKey, 'exception' => $e->getMessage()]);
            return [];
        }

        $out = [];
        foreach (($results ?? []) as $result) {
            $out[] = $this->toArray(object: $result);
        }

        return $out;
    }//end findBySchema()

    /**
     * Fetch a single object by id.
     *
     * @param string $schemaKey The schema config key.
     * @param string $id        The object ID.
     *
     * @return array<string, mixed>|null The object or null.
     */
    private function fetchObject(string $schemaKey, string $id): ?array
    {
        [$register, $schema] = $this->config(schemaKey: $schemaKey);

        try {
            $object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
        } catch (Throwable $e) {
            return null;
        }

        if ($object === null) {
            return null;
        }

        return $this->toArray(object: $object);
    }//end fetchObject()

    /**
     * Create an object of a schema.
     *
     * @param string               $schemaKey The schema config key.
     * @param array<string, mixed> $object    The object data.
     *
     * @return array<string, mixed> The created object as an array.
     */
    private function createObject(string $schemaKey, array $object): array
    {
        [$register, $schema] = $this->config(schemaKey: $schemaKey);
        $saved = $this->getObjectService()->saveObject(object: $object, extend: [], register: $register, schema: $schema, uuid: null);

        return $this->toArray(object: $saved);
    }//end createObject()

    /**
     * Patch a message object (merge update onto existing fields).
     *
     * @param string               $id     The message ID.
     * @param array<string, mixed> $update The fields to update.
     *
     * @return void
     */
    private function patchMessage(string $id, array $update): void
    {
        $this->patchObject(schemaKey: 'berichtenboxMessage_schema', id: $id, update: $update);
    }//end patchMessage()

    /**
     * Patch any object (merge update onto existing fields), preserving id.
     *
     * @param string               $schemaKey The schema config key.
     * @param string               $id        The object ID.
     * @param array<string, mixed> $update    The fields to update.
     *
     * @return void
     */
    private function patchObject(string $schemaKey, string $id, array $update): void
    {
        [$register, $schema] = $this->config(schemaKey: $schemaKey);

        try {
            $existing = $this->fetchObject(schemaKey: $schemaKey, id: $id) ?? [];
            unset($existing['@self']);
            $merged = array_merge($existing, $update);

            $this->getObjectService()->saveObject(
                object: $merged,
                extend: [],
                register: $register,
                schema: $schema,
                uuid: $id
            );
        } catch (Throwable $e) {
            $this->logger->error('Berichtenbox: object patch failed', ['id' => $id, 'exception' => $e->getMessage()]);
        }
    }//end patchObject()

    /**
     * Resolve a burger email address for a message.
     *
     * Reads the linked Contactmoment's client/contact email when available.
     *
     * @param array<string, mixed> $message The message.
     *
     * @return string The email address, or empty string.
     */
    private function resolveBurgerEmail(array $message): string
    {
        $explicit = (string) ($message['fallbackEmail'] ?? '');
        if ($explicit !== '') {
            return $explicit;
        }

        $contactmomentId = (string) ($message['contactmomentId'] ?? '');
        if ($contactmomentId === '') {
            return '';
        }

        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'contactmoment_schema', '');
        if ($register === '' || $schema === '') {
            return '';
        }

        try {
            $contactmoment = $this->getObjectService()->find(id: $contactmomentId, register: $register, schema: $schema);
        } catch (Throwable $e) {
            return '';
        }

        if ($contactmoment === null) {
            return '';
        }

        $cmData = $this->toArray(object: $contactmoment);

        return (string) ($cmData['email'] ?? ($cmData['channelMetadata']['email'] ?? ''));
    }//end resolveBurgerEmail()

    /**
     * Resolve the register + a schema id by config key.
     *
     * @param string $schemaKey The schema config key.
     *
     * @return array{0: string, 1: string} The [register, schema] tuple.
     *
     * @throws RuntimeException When not configured.
     */
    private function config(string $schemaKey): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
        if ($register === '' || $schema === '') {
            throw new RuntimeException('Berichtenbox register or schema not configured: '.$schemaKey);
        }

        return [$register, $schema];
    }//end config()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return object The ObjectService.
     *
     * @throws RuntimeException When OpenRegister is unavailable.
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (Throwable $e) {
            throw new RuntimeException('OpenRegister service is not available.');
        }
    }//end getObjectService()

    /**
     * Extract the id/uuid from an object array.
     *
     * @param array<string, mixed> $object The object.
     *
     * @return string The id.
     */
    private function idOf(array $object): string
    {
        if (isset($object['@self']['id']) === true) {
            return (string) $object['@self']['id'];
        }

        if (isset($object['@self']['uuid']) === true) {
            return (string) $object['@self']['uuid'];
        }

        return (string) ($object['id'] ?? ($object['uuid'] ?? ''));
    }//end idOf()

    /**
     * Normalise an OR object into a plain array.
     *
     * @param mixed $object The OR object.
     *
     * @return array<string, mixed> The object as an array.
     */
    private function toArray(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serialized = $object->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        return (array) $object;
    }//end toArray()

    /**
     * Current time as an ISO 8601 string.
     *
     * @return string The timestamp.
     */
    private function now(): string
    {
        return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
    }//end now()

    /**
     * Generate a v4 UUID.
     *
     * @return string The UUID.
     */
    private function uuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }//end uuid()
}//end class
