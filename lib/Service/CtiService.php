<?php

/**
 * Pipelinq CtiService.
 *
 * Platform-neutral orchestration for Computer Telephony Integration: inbound
 * webhook routing, screen-pop resolution, contactmoment lifecycle, recording
 * metadata, outbound origination and agent-presence sync.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Cti\AdapterRegistry;
use OCA\Pipelinq\Service\Cti\CtiAdapterInterface;
use OCA\Pipelinq\Service\Cti\CtiCallResult;
use OCA\Pipelinq\Service\Cti\CtiWebhookResult;
use OCA\Pipelinq\Service\Cti\ScreenPopResult;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Core CTI orchestration service.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class CtiService
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container    The DI container (resolves OpenRegister).
     * @param IAppConfig         $appConfig    The app config.
     * @param AdapterRegistry    $registry     The CTI adapter registry.
     * @param PhoneNormaliser    $normaliser   The phone-number normaliser.
     * @param CtiContactMatcher  $matcher      The caller-resolution matcher.
     * @param ICacheFactory      $cacheFactory The distributed-cache factory (rate limiting).
     * @param LoggerInterface    $logger       The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private AdapterRegistry $registry,
        private PhoneNormaliser $normaliser,
        private CtiContactMatcher $matcher,
        private ICacheFactory $cacheFactory,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Maximum inbound webhooks accepted per platform per window (REQ-CTI-007).
     *
     * @var int
     */
    private const RATE_LIMIT_MAX = 100;

    /**
     * Rate-limit window in seconds.
     *
     * @var int
     */
    private const RATE_LIMIT_WINDOW = 1;

    /**
     * Whether inbound webhooks for a platform currently exceed the rate limit.
     *
     * Counts per platform per one-second window. Fails closed only when no
     * cache backend exists at all (returns false there to avoid blocking a
     * correctly-configured instance that simply lacks a cache).
     *
     * @param string $platform The platform slug.
     *
     * @return bool True when over the limit (caller should return HTTP 429).
     */
    public function isWebhookRateLimited(string $platform): bool
    {
        $key = 'cti_wh_'.strtolower($platform);

        if (function_exists('apcu_fetch') === true) {
            $count = apcu_fetch($key);
            if ($count === false) {
                apcu_store($key, 1, self::RATE_LIMIT_WINDOW);
                return false;
            }

            if ($count >= self::RATE_LIMIT_MAX) {
                return true;
            }

            apcu_inc($key);
            return false;
        }

        $cache = $this->getRateLimitCache();
        if ($cache === null) {
            return false;
        }

        $count = (int) ($cache->get($key) ?? 0);
        if ($count >= self::RATE_LIMIT_MAX) {
            return true;
        }

        $cache->set($key, ($count + 1), self::RATE_LIMIT_WINDOW);
        return false;
    }//end isWebhookRateLimited()

    /**
     * Get a distributed cache for rate limiting, or null when unavailable.
     *
     * @return ICache|null The cache, or null.
     */
    private function getRateLimitCache(): ?ICache
    {
        if ($this->cacheFactory->isAvailable() === false) {
            return null;
        }

        return $this->cacheFactory->createDistributed('pipelinq_cti');
    }//end getRateLimitCache()

    /**
     * Resolve the configured CTI adapter config from OpenRegister.
     *
     * @return array<string, mixed> The adapter config object (empty when unconfigured).
     */
    public function getAdapterConfig(): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'ctiAdapterConfig_schema', '');
        if ($register === '' || $schema === '') {
            return [];
        }

        try {
            $objectService = $this->getObjectService();
            $results       = $objectService->findAll(
                ['filters' => ['register' => $register, 'schema' => $schema], 'limit' => 1]
            );
        } catch (Throwable $e) {
            $this->logger->warning('CTI adapter config lookup failed', ['exception' => $e->getMessage()]);
            return [];
        }

        foreach ($results as $result) {
            return $this->serialize(result: $result);
        }

        return [];
    }//end getAdapterConfig()

    /**
     * Resolve the inbound webhook secret for a platform from app config.
     *
     * The secret itself is read from app config (ADR-005); the adapter config
     * object only stores the app-config key name in `webhookSecretRef`, never
     * the secret value.
     *
     * @param string               $platform The platform slug.
     * @param array<string, mixed> $config   The loaded adapter config.
     *
     * @return string The secret, or empty string when unset.
     */
    public function getWebhookSecret(string $platform, array $config): string
    {
        $ref = (string) ($config['webhookSecretRef'] ?? '');
        if ($ref === '') {
            $ref = 'cti_webhook_secret_'.strtolower($platform);
        }

        return $this->appConfig->getValueString(Application::APP_ID, $ref, '');
    }//end getWebhookSecret()

    /**
     * Resolve and configure the adapter for a platform.
     *
     * @param string               $platform The platform slug.
     * @param array<string, mixed> $config   The loaded adapter config.
     *
     * @return CtiAdapterInterface The configured adapter.
     */
    public function getAdapter(string $platform, array $config): CtiAdapterInterface
    {
        $adapter = $this->registry->get($platform);
        $apiBase = (string) ($config['apiBaseUrl'] ?? '');
        if ($apiBase !== '' && method_exists($adapter, 'setApiBaseUrl') === true) {
            $adapter->setApiBaseUrl($apiBase);
        }

        return $adapter;
    }//end getAdapter()

    /**
     * Process an authenticated inbound webhook event.
     *
     * Signature verification is the controller's responsibility (it owns the
     * raw body and headers); this method records the event and dispatches the
     * normalised result to the relevant lifecycle handler.
     *
     * @param string           $platform The platform slug.
     * @param CtiWebhookResult $event    The normalised webhook result.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) — small event-type switch
     * @spec                                         openspec/changes/cti-screenpop-adapter/tasks.md#task-2.1
     */
    public function dispatchEvent(string $platform, CtiWebhookResult $event): void
    {
        try {
            switch ($event->eventType) {
                case 'recording_ready':
                    if ($event->externalCallId !== null && $event->recordingUrl !== null) {
                        $this->attachRecordingByCallId(
                            externalCallId: $event->externalCallId,
                            recordingUrl: $event->recordingUrl,
                            expiresAt: ($event->recordingExpiresAt ?? '')
                        );
                    }
                    break;
                case 'ended':
                    if ($event->externalCallId !== null) {
                        $this->markCallEnded(externalCallId: $event->externalCallId, durationSeconds: $event->durationSeconds);
                    }
                    break;
                case 'presence_changed':
                    if ($event->userId !== null && $event->presenceState !== null) {
                        $this->syncPresence(
                            userId: $event->userId,
                            presenceState: $event->presenceState,
                            extension: ($event->extension ?? ''),
                            platform: $platform
                        );
                    }
                    break;
                default:
                    // The ringing / answered / abandoned / transferred events carry no
                    // server-side mutation here; the agent UI drives screen-pop.
                    break;
            }//end switch

            $this->logEvent(platform: $platform, event: $event, error: null);
        } catch (Throwable $e) {
            $this->logger->error('CTI event dispatch failed', ['exception' => $e->getMessage()]);
            $this->logEvent(platform: $platform, event: $event, error: 'dispatch_failed');
        }//end try
    }//end dispatchEvent()

    /**
     * Resolve a caller number into a screen-pop instruction.
     *
     * @param string $fromNumber The raw caller number.
     *
     * @return ScreenPopResult The screen-pop instruction.
     *
     * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-2.1
     */
    public function initiateScreenPop(string $fromNumber): ScreenPopResult
    {
        $normalised = $this->normaliser->normalise($fromNumber);
        $config     = $this->getAdapterConfig();
        $delayMs    = (int) ($config['screenPopDelayMs'] ?? 0);

        $e164 = (string) ($normalised['e164'] ?? '');
        if ($e164 === '') {
            return new ScreenPopResult(
                action: ScreenPopResult::ACTION_INTAKE,
                e164Number: '',
                rawNumber: $fromNumber,
                matches: [],
                delayMs: $delayMs
            );
        }

        $matches = $this->matcher->findByPhoneNumber($e164);
        $action  = match (count($matches)) {
            0 => ScreenPopResult::ACTION_INTAKE,
            1 => ScreenPopResult::ACTION_NAVIGATE,
            default => ScreenPopResult::ACTION_CHOOSER,
        };

        return new ScreenPopResult(
            action: $action,
            e164Number: $e164,
            rawNumber: $fromNumber,
            matches: $matches,
            delayMs: $delayMs
        );
    }//end initiateScreenPop()

    /**
     * Create a contactmoment in `pending` state for a new call.
     *
     * @param string      $direction      inbound|outbound.
     * @param string      $fromNumber     The from number (E.164 or raw).
     * @param string      $toNumber       The to number (E.164 or raw).
     * @param string      $userId         The agent user UID.
     * @param string|null $externalCallId The platform call identifier, if known.
     * @param string|null $contactId      The matched contact UUID, if any.
     * @param string|null $clientId       The matched client UUID, if any.
     *
     * @return string The created contactmoment UUID.
     *
     * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-2.1
     */
    public function createPendingContactmoment(
        string $direction,
        string $fromNumber,
        string $toNumber,
        string $userId,
        ?string $externalCallId=null,
        ?string $contactId=null,
        ?string $clientId=null
    ): string {
        [$register, $schema] = $this->contactmomentConfig();

        $data = [
            'subject'        => ('Telephony call ('.$direction.')'),
            'channel'        => 'telephony',
            'direction'      => $direction,
            'fromNumber'     => $fromNumber,
            'toNumber'       => $toNumber,
            'agent'          => $userId,
            'callStatus'     => 'pending',
            'startedAt'      => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'externalCallId' => $externalCallId,
        ];

        if ($contactId !== null) {
            $data['contact'] = $contactId;
        }

        if ($clientId !== null) {
            $data['client'] = $clientId;
        }

        $saved = $this->getObjectService()
            ->setRegister($register)
            ->setSchema($schema)
            ->saveObject($data);

        return $this->extractUuid(saved: $saved);
    }//end createPendingContactmoment()

    /**
     * Update a contactmoment with final metadata after the call ends.
     *
     * @param string $contactmomentId The contactmoment UUID.
     * @param int    $durationSeconds The call duration in seconds.
     *
     * @return void
     */
    public function completeContactmoment(string $contactmomentId, int $durationSeconds): void
    {
        [$register, $schema] = $this->contactmomentConfig();
        $existing            = $this->findContactmoment(contactmomentId: $contactmomentId);
        if ($existing === null) {
            return;
        }

        $existing['endedAt']         = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        $existing['durationSeconds'] = $durationSeconds;
        $existing['callStatus']      = 'disposition-pending';

        $this->getObjectService()
            ->setRegister($register)
            ->setSchema($schema)
            ->updateObject(uuid: $contactmomentId, object: $existing);
    }//end completeContactmoment()

    /**
     * Attach recording metadata to a contactmoment (REQ-CTI-005).
     *
     * @param string $contactmomentId The contactmoment UUID.
     * @param string $recordingUrl    The platform recording URL.
     * @param string $expiresAt       The retention expiry (ISO 8601).
     *
     * @return void
     */
    public function attachRecording(string $contactmomentId, string $recordingUrl, string $expiresAt): void
    {
        [$register, $schema] = $this->contactmomentConfig();
        $existing            = $this->findContactmoment(contactmomentId: $contactmomentId);
        if ($existing === null) {
            return;
        }

        $existing['recordingUrl'] = $recordingUrl;
        if ($expiresAt !== '') {
            $existing['recordingRetentionExpiresAt'] = $expiresAt;
        }

        $this->getObjectService()
            ->setRegister($register)
            ->setSchema($schema)
            ->updateObject(uuid: $contactmomentId, object: $existing);
    }//end attachRecording()

    /**
     * Originate an outbound call through the configured adapter (REQ-CTI-003).
     *
     * @param string $userId       The agent user UID.
     * @param string $extension    The agent extension.
     * @param string $targetNumber The number to dial.
     *
     * @return CtiCallResult The origination outcome.
     *
     * @throws RuntimeException When CTI is not configured or click-to-dial is disabled.
     */
    public function originateCall(string $userId, string $extension, string $targetNumber): CtiCallResult
    {
        $config = $this->getAdapterConfig();
        if ($config === []) {
            throw new RuntimeException('CTI is not configured.');
        }

        if (($config['clickToDialEnabled'] ?? false) !== true) {
            throw new RuntimeException('Click-to-dial is disabled.');
        }

        $platform = (string) ($config['platform'] ?? '');
        $adapter  = $this->getAdapter(platform: $platform, config: $config);
        $callerId = (string) ($config['defaultOutboundCallerId'] ?? '');

        $normalisedTarget = $this->normaliser->normalise($targetNumber);
        $dialNumber       = (string) ($normalisedTarget['e164'] ?? $targetNumber);

        $result = $adapter->originateCall(extension: $extension, targetNumber: $dialNumber, callerId: $callerId);

        if ($result->success === true) {
            try {
                $this->createPendingContactmoment(
                    direction: 'outbound',
                    fromNumber: $callerId,
                    toNumber: $dialNumber,
                    userId: $userId,
                    externalCallId: $result->externalCallId
                );
            } catch (Throwable $e) {
                $this->logger->warning('CTI outbound contactmoment creation failed', ['exception' => $e->getMessage()]);
            }
        }

        return $result;
    }//end originateCall()

    /**
     * Whether the agent's presence currently forbids click-to-dial (REQ-CTI-008).
     *
     * @param string $userId The agent user UID.
     *
     * @return bool True when the agent is on-call or in wrap-up.
     */
    public function isClickToDialBlocked(string $userId): bool
    {
        $presence = $this->findPresence(userId: $userId);
        if ($presence === null) {
            return false;
        }

        $state = (string) ($presence['presenceState'] ?? '');

        return in_array($state, ['on-call', 'wrap-up'], true);
    }//end isClickToDialBlocked()

    /**
     * Upsert an agent's presence record (REQ-CTI-008).
     *
     * @param string $userId        The agent user UID.
     * @param string $presenceState The new presence state.
     * @param string $extension     The agent extension.
     * @param string $platform      The reporting platform.
     *
     * @return void
     */
    public function syncPresence(string $userId, string $presenceState, string $extension='', string $platform=''): void
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'ctiAgentPresence_schema', '');
        if ($register === '' || $schema === '') {
            return;
        }

        $existing = $this->findPresence(userId: $userId);
        $data     = [
            'userId'        => $userId,
            'presenceState' => $presenceState,
            'lastUpdatedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        ];

        if ($extension !== '') {
            $data['extension'] = $extension;
        }

        if ($platform !== '') {
            $data['platform'] = $platform;
        }

        $service = $this->getObjectService()->setRegister($register)->setSchema($schema);
        if ($existing !== null && isset($existing['@self']['id']) === true) {
            $service->updateObject(uuid: (string) $existing['@self']['id'], object: array_merge($existing, $data));
            return;
        }

        $service->saveObject($data);
    }//end syncPresence()

    /**
     * Append an inbound webhook event to the event log.
     *
     * @param string           $platform The platform slug.
     * @param CtiWebhookResult $event    The normalised event.
     * @param string|null      $error    The processing error code, or null.
     *
     * @return void
     */
    public function logEvent(string $platform, CtiWebhookResult $event, ?string $error): void
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'ctiEventLog_schema', '');
        if ($register === '' || $schema === '') {
            return;
        }

        try {
            $now = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
            $this->getObjectService()
                ->setRegister($register)
                ->setSchema($schema)
                ->saveObject(
                    [
                        'receivedAt'      => $now,
                        'platform'        => $platform,
                        'eventType'       => $event->eventType,
                        'externalCallId'  => $event->externalCallId,
                        'payloadJson'     => '',
                        'processedAt'     => $now,
                        'processingError' => $error,
                    ]
                );
        } catch (Throwable $e) {
            $this->logger->warning('CTI event log write failed', ['exception' => $e->getMessage()]);
        }//end try
    }//end logEvent()

    /**
     * Record a rejected (e.g. bad-signature) inbound webhook for the audit log.
     *
     * @param string $platform  The platform slug.
     * @param string $eventType The (best-effort) event type.
     * @param string $error     The processing error code.
     *
     * @return void
     */
    public function logRejectedEvent(string $platform, string $eventType, string $error): void
    {
        $this->logEvent(platform: $platform, event: new CtiWebhookResult(eventType: $eventType), error: $error);
    }//end logRejectedEvent()

    /**
     * List recent webhook event-log entries, optionally filtered (REQ-CTI-011).
     *
     * Only entries from the last 30 days are returned, newest first.
     *
     * @param string $platform  Optional platform filter.
     * @param string $eventType Optional event-type filter.
     *
     * @return array<int, array<string, mixed>> The event-log entries.
     */
    public function listEventLog(string $platform='', string $eventType=''): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'ctiEventLog_schema', '');
        if ($register === '' || $schema === '') {
            return [];
        }

        $filters = ['register' => $register, 'schema' => $schema];
        if ($platform !== '') {
            $filters['platform'] = $platform;
        }

        if ($eventType !== '') {
            $filters['eventType'] = $eventType;
        }

        try {
            $results = $this->getObjectService()->findAll(['filters' => $filters, 'limit' => 50]);
        } catch (Throwable $e) {
            $this->logger->warning('CTI event-log query failed', ['exception' => $e->getMessage()]);
            return [];
        }

        $cutoff  = (new DateTimeImmutable('-30 days'))->format(DateTimeInterface::ATOM);
        $entries = [];
        foreach ($results as $result) {
            $entry      = $this->serialize(result: $result);
            $receivedAt = (string) ($entry['receivedAt'] ?? '');
            if ($receivedAt !== '' && $receivedAt < $cutoff) {
                continue;
            }

            $entries[] = $entry;
        }

        usort(
            $entries,
            static fn(array $a, array $b): int => ((string) ($b['receivedAt'] ?? '')) <=> ((string) ($a['receivedAt'] ?? ''))
        );

        return $entries;
    }//end listEventLog()

    /**
     * Locate a contactmoment by the platform's external call identifier.
     *
     * @param string $externalCallId The platform call identifier.
     *
     * @return array<string, mixed>|null The contactmoment, or null when absent.
     */
    public function findContactmomentByCallId(string $externalCallId): ?array
    {
        if ($externalCallId === '') {
            return null;
        }

        [$register, $schema] = $this->contactmomentConfig();

        try {
            $results = $this->getObjectService()->findAll(
                [
                    'filters' => [
                        'register'       => $register,
                        'schema'         => $schema,
                        'externalCallId' => $externalCallId,
                    ],
                    'limit'   => 1,
                ]
            );
        } catch (Throwable $e) {
            $this->logger->warning('CTI contactmoment-by-callid lookup failed', ['exception' => $e->getMessage()]);
            return null;
        }

        foreach ($results as $result) {
            return $this->serialize(result: $result);
        }

        return null;
    }//end findContactmomentByCallId()

    /**
     * Attach a recording to the contactmoment matching an external call id.
     *
     * @param string $externalCallId The platform call identifier.
     * @param string $recordingUrl   The recording URL.
     * @param string $expiresAt      The retention expiry.
     *
     * @return void
     */
    private function attachRecordingByCallId(string $externalCallId, string $recordingUrl, string $expiresAt): void
    {
        $contactmoment = $this->findContactmomentByCallId(externalCallId: $externalCallId);
        if ($contactmoment === null || isset($contactmoment['@self']['id']) === false) {
            return;
        }

        $this->attachRecording(
            contactmomentId: (string) $contactmoment['@self']['id'],
            recordingUrl: $recordingUrl,
            expiresAt: $expiresAt
        );
    }//end attachRecordingByCallId()

    /**
     * Mark the contactmoment for an external call id as ended.
     *
     * @param string   $externalCallId  The platform call identifier.
     * @param int|null $durationSeconds The duration in seconds, if known.
     *
     * @return void
     */
    private function markCallEnded(string $externalCallId, ?int $durationSeconds): void
    {
        $contactmoment = $this->findContactmomentByCallId(externalCallId: $externalCallId);
        if ($contactmoment === null || isset($contactmoment['@self']['id']) === false) {
            return;
        }

        $this->completeContactmoment(
            contactmomentId: (string) $contactmoment['@self']['id'],
            durationSeconds: ($durationSeconds ?? 0)
        );
    }//end markCallEnded()

    /**
     * Find a presence record for a user.
     *
     * @param string $userId The agent user UID.
     *
     * @return array<string, mixed>|null The presence record, or null.
     */
    private function findPresence(string $userId): ?array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'ctiAgentPresence_schema', '');
        if ($register === '' || $schema === '' || $userId === '') {
            return null;
        }

        try {
            $results = $this->getObjectService()->findAll(
                ['filters' => ['register' => $register, 'schema' => $schema, 'userId' => $userId], 'limit' => 1]
            );
        } catch (Throwable $e) {
            $this->logger->warning('CTI presence lookup failed', ['exception' => $e->getMessage()]);
            return null;
        }

        foreach ($results as $result) {
            return $this->serialize(result: $result);
        }

        return null;
    }//end findPresence()

    /**
     * Find a contactmoment by UUID.
     *
     * @param string $contactmomentId The contactmoment UUID.
     *
     * @return array<string, mixed>|null The contactmoment, or null.
     */
    private function findContactmoment(string $contactmomentId): ?array
    {
        [$register, $schema] = $this->contactmomentConfig();

        try {
            $object = $this->getObjectService()->find(id: $contactmomentId, register: $register, schema: $schema);
        } catch (Throwable $e) {
            return null;
        }

        if ($object === null) {
            return null;
        }

        return $this->serialize(result: $object);
    }//end findContactmoment()

    /**
     * Resolve the contactmoment register and schema IDs.
     *
     * @return array{0: string, 1: string} The register and schema IDs.
     *
     * @throws RuntimeException When unconfigured.
     */
    private function contactmomentConfig(): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'contactmoment_schema', '');
        if ($register === '' || $schema === '') {
            throw new RuntimeException('Contactmoment register or schema not configured.');
        }

        return [$register, $schema];
    }//end contactmomentConfig()

    /**
     * Extract a UUID from an OpenRegister save result.
     *
     * @param mixed $saved The saveObject return value.
     *
     * @return string The object UUID.
     */
    private function extractUuid(mixed $saved): string
    {
        $data = $this->serialize(result: $saved);
        if (isset($data['@self']['id']) === true) {
            return (string) $data['@self']['id'];
        }

        if (is_object($saved) === true && method_exists($saved, 'getUuid') === true) {
            return (string) $saved->getUuid();
        }

        return (string) ($data['id'] ?? '');
    }//end extractUuid()

    /**
     * Serialise an OpenRegister result to a plain array.
     *
     * @param mixed $result The raw result.
     *
     * @return array<string, mixed> The serialised result.
     */
    private function serialize(mixed $result): array
    {
        if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
            $serialized = $result->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }

            return [];
        }

        if (is_array($result) === true) {
            return $result;
        }

        return [];
    }//end serialize()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return \OCA\OpenRegister\Service\ObjectService The object service.
     *
     * @throws RuntimeException When OpenRegister is unavailable.
     */
    private function getObjectService(): \OCA\OpenRegister\Service\ObjectService
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (Throwable $e) {
            throw new RuntimeException('OpenRegister service is not available.');
        }
    }//end getObjectService()
}//end class
