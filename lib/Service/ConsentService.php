<?php

/**
 * Pipelinq ConsentService.
 *
 * Per-contact, per-channel messaging consent (WhatsApp / SMS) — the
 * compliance gate that ConsentService.canSend() places in front of
 * every outbound send. Records are append-only: each opt-in /
 * opt-out event is a new messagingConsentRecord row so the audit
 * trail survives erasure-of-state and the latest record wins.
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
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#4.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ConsentService — opt-in / opt-out compliance for WhatsApp + SMS.
 *
 * Public entry points:
 * - canSend(contactId, channel) — gate evaluated before every
 *   provider call.
 * - recordOptIn(contactId, channel, source, evidence) — append an
 *   opted-in row.
 * - recordOptOut(contactId, channel, source, evidence) — append an
 *   opted-out row.
 * - deleteForContact(contactId) — GDPR erasure hook called by
 *   ClientManagementIntegration when a Contact is deleted.
 * - isOptOutKeyword(body) — pure, case-insensitive STOP / STOPALL /
 *   UITSCHRIJVEN detection.
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#4.1
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Consent lifecycle (opt-in/opt-out/erasure/keyword detection) is one cohesive responsibility.
 */
class ConsentService
{
    /**
     * Default messagingConsentRecord schema slug.
     */
    private const DEFAULT_SCHEMA_SLUG = 'messagingConsentRecord';

    /**
     * Default pipelinq register slug.
     */
    private const DEFAULT_REGISTER_SLUG = 'pipelinq';

    /**
     * Opt-out keywords (case-insensitive exact match on a trimmed body).
     *
     * @var array<int, string>
     */
    private const OPT_OUT_KEYWORDS = ['stop', 'stopall', 'uitschrijven'];

    /**
     * Opt-in keywords (case-insensitive exact match on a trimmed body).
     *
     * @var array<int, string>
     */
    private const OPT_IN_KEYWORDS = ['ja', 'yes', 'start'];

    /**
     * Constructor.
     *
     * @param ContainerInterface $container DI container.
     * @param IAppConfig         $appConfig App config.
     * @param LoggerInterface    $logger    Logger.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#4.1
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether the contact may receive a message on the given channel.
     *
     * Picks the most recent messagingConsentRecord for the pair and
     * returns true unless its state is `opted-out`. An absent record
     * is treated as `unknown` → allowed (matches GDPR
     * legitimate-interest defaults; explicit opt-out is still
     * required to block).
     *
     * @param string $contactId Contact UUID.
     * @param string $channel   `whatsapp` or `sms`.
     *
     * @return bool True if sending is allowed.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#4.1
     */
    public function canSend(string $contactId, string $channel): bool
    {
        if ($contactId === '' || $channel === '') {
            return false;
        }

        $latest = $this->loadLatestRecord(contactId: $contactId, channel: $channel);
        if ($latest === null) {
            // No record on file → allowed; agents may opt the contact
            // in explicitly via the UI when GDPR consent is required.
            return true;
        }

        $state = (string) ($latest['state'] ?? 'unknown');
        return ($state !== 'opted-out');
    }//end canSend()

    /**
     * Whether a business-initiated message may be sent on the channel.
     *
     * Business-initiated messages (WhatsApp template sends outside the 24h
     * session window, including SLA escalations) require an explicit
     * `opted-in` record — Meta business-messaging policy. Unlike
     * {@see canSend()}, an absent or `unknown` record does NOT pass: only a
     * latest `opted-in` record allows the send.
     *
     * @param string $contactId Contact UUID.
     * @param string $channel   `whatsapp` or `sms`.
     *
     * @return bool True only when the latest record is `opted-in`.
     *
     * @spec openspec/changes/outbound-messaging-provider-wiring/specs/outbound-messaging/spec.md#requirement-req-om-005--consent-gating-and-recording
     */
    public function canSendBusinessInitiated(string $contactId, string $channel): bool
    {
        if ($contactId === '' || $channel === '') {
            return false;
        }

        $latest = $this->loadLatestRecord(contactId: $contactId, channel: $channel);
        if ($latest === null) {
            return false;
        }

        return ((string) ($latest['state'] ?? 'unknown')) === 'opted-in';
    }//end canSendBusinessInitiated()

    /**
     * The latest consent state for one (contactId, channel) pair.
     *
     * Returns `opted-in` / `opted-out` when a record exists, otherwise
     * `unknown`. Used by the send surface to display consent state (REQ-OM-005)
     * without deciding whether a send is allowed — that stays with
     * {@see canSend()} / {@see canSendBusinessInitiated()}.
     *
     * @param string $contactId Contact UUID.
     * @param string $channel   `whatsapp` or `sms`.
     *
     * @return string `opted-in` / `opted-out` / `unknown`.
     *
     * @spec openspec/changes/outbound-messaging-provider-wiring/specs/outbound-messaging/spec.md#requirement-req-om-005--consent-gating-and-recording
     */
    public function latestState(string $contactId, string $channel): string
    {
        if ($contactId === '' || $channel === '') {
            return 'unknown';
        }

        $latest = $this->loadLatestRecord(contactId: $contactId, channel: $channel);
        if ($latest === null) {
            return 'unknown';
        }

        $state = (string) ($latest['state'] ?? 'unknown');
        if ($state === '') {
            return 'unknown';
        }

        return $state;
    }//end latestState()

    /**
     * Append an `opted-in` consent record.
     *
     * @param string $contactId  Contact UUID.
     * @param string $channel    `whatsapp` or `sms`.
     * @param string $source     Enum value (webform / chat-reply / ...).
     * @param string $evidence   Free-text audit-trail evidence.
     * @param string $legalBasis GDPR legal basis (consent / legitimate-interest / ...).
     *
     * @return array<string, mixed>|null Saved row or null on failure.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#4.3
     */
    public function recordOptIn(
        string $contactId,
        string $channel,
        string $source,
        string $evidence,
        string $legalBasis='consent'
    ): ?array {
        return $this->appendRecord(
            contactId: $contactId,
            channel: $channel,
            state: 'opted-in',
            source: $source,
            evidence: $evidence,
            legalBasis: $legalBasis,
        );
    }//end recordOptIn()

    /**
     * Append an `opted-out` consent record.
     *
     * @param string $contactId  Contact UUID.
     * @param string $channel    `whatsapp` or `sms`.
     * @param string $source     Enum value (keyword-stop / admin-override / ...).
     * @param string $evidence   Free-text audit-trail evidence.
     * @param string $legalBasis GDPR legal basis (consent / legitimate-interest / ...).
     *
     * @return array<string, mixed>|null Saved row or null on failure.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#4.2
     */
    public function recordOptOut(
        string $contactId,
        string $channel,
        string $source,
        string $evidence,
        string $legalBasis='consent'
    ): ?array {
        return $this->appendRecord(
            contactId: $contactId,
            channel: $channel,
            state: 'opted-out',
            source: $source,
            evidence: $evidence,
            legalBasis: $legalBasis,
        );
    }//end recordOptOut()

    /**
     * Whether a message body is an exact opt-out keyword.
     *
     * @param string $body Inbound message body.
     *
     * @return bool True for STOP / STOPALL / UITSCHRIJVEN (case-insensitive).
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#4.2
     */
    public function isOptOutKeyword(string $body): bool
    {
        $normalised = strtolower(trim($body));
        return in_array($normalised, self::OPT_OUT_KEYWORDS, true);
    }//end isOptOutKeyword()

    /**
     * Whether a message body is an exact opt-in keyword.
     *
     * @param string $body Inbound message body.
     *
     * @return bool True for JA / YES / START (case-insensitive).
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#4.3
     */
    public function isOptInKeyword(string $body): bool
    {
        $normalised = strtolower(trim($body));
        return in_array($normalised, self::OPT_IN_KEYWORDS, true);
    }//end isOptInKeyword()

    /**
     * Delete every consent record for one contact (GDPR Art. 17).
     *
     * Called by ClientManagementIntegration when a contact deletion
     * propagates. Returns the number of rows actually deleted.
     *
     * @param string $contactId Contact UUID.
     *
     * @return int Rows deleted.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#4.4
     */
    public function deleteForContact(string $contactId): int
    {
        if ($contactId === '') {
            return 0;
        }

        $rows = $this->loadAllRecords(contactId: $contactId);
        if ($rows === []) {
            return 0;
        }

        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return 0;
        }

        $register = $this->getRegisterSlug();
        $schema   = $this->getSchemaSlug();
        $deleted  = 0;

        foreach ($rows as $row) {
            $id = $this->extractId(payload: $row);
            if ($id === '') {
                continue;
            }

            try {
                if (method_exists($objectService, 'deleteObject') === true) {
                    $objectService->deleteObject($id, $register, $schema);
                    $deleted++;
                }
            } catch (Throwable $e) {
                $this->logger->warning(
                    'ConsentService.deleteForContact: delete failed',
                    ['contactId' => $contactId, 'id' => $id, 'exception' => $e->getMessage()]
                );
            }
        }//end foreach

        return $deleted;
    }//end deleteForContact()

    /**
     * Append a new consent record (immutable history).
     *
     * @param string $contactId  Contact UUID.
     * @param string $channel    `whatsapp` or `sms`.
     * @param string $state      `opted-in` / `opted-out` / `unknown`.
     * @param string $source     Enum value.
     * @param string $evidence   Audit evidence.
     * @param string $legalBasis GDPR legal basis.
     *
     * @return array<string, mixed>|null Saved row.
     */
    private function appendRecord(
        string $contactId,
        string $channel,
        string $state,
        string $source,
        string $evidence,
        string $legalBasis='consent'
    ): ?array {
        if ($contactId === '' || $channel === '' || $state === '') {
            return null;
        }

        $basis = $legalBasis;
        if ($basis === '') {
            $basis = 'consent';
        }

        $payload = [
            'contactId'  => $contactId,
            'channel'    => $channel,
            'state'      => $state,
            'source'     => $source,
            'evidence'   => $evidence,
            'recordedAt' => $this->nowIso(),
            'legalBasis' => $basis,
        ];

        return $this->saveObject(payload: $payload);
    }//end appendRecord()

    /**
     * Most recent consent record for one (contactId, channel) pair.
     *
     * @param string $contactId Contact UUID.
     * @param string $channel   Channel.
     *
     * @return array<string, mixed>|null Latest record or null.
     */
    private function loadLatestRecord(string $contactId, string $channel): ?array
    {
        $rows = $this->loadAllRecords(contactId: $contactId);
        if ($rows === []) {
            return null;
        }

        $matching = [];
        foreach ($rows as $row) {
            if ((string) ($row['channel'] ?? '') === $channel) {
                $matching[] = $row;
            }
        }

        if ($matching === []) {
            return null;
        }

        usort(
            $matching,
            static function (array $a, array $b): int {
                return strcmp((string) ($b['recordedAt'] ?? ''), (string) ($a['recordedAt'] ?? ''));
            }
        );

        return $matching[0];
    }//end loadLatestRecord()

    /**
     * Every record for one contact (used by erasure + latest-of).
     *
     * @param string $contactId Contact UUID.
     *
     * @return array<int, array<string, mixed>> Rows.
     */
    private function loadAllRecords(string $contactId): array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return [];
        }

        try {
            $rows = $objectService->findAll(
                config: [
                    'filters' => [
                        'contactId' => $contactId,
                        'register'  => $this->getRegisterSlug(),
                        'schema'    => $this->getSchemaSlug(),
                    ],
                ]
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'ConsentService.loadAllRecords: findAll failed',
                ['contactId' => $contactId, 'exception' => $e->getMessage()]
            );
            return [];
        }

        $out = [];
        foreach (($rows ?? []) as $row) {
            $out[] = $this->toArray(value: $row);
        }

        return $out;
    }//end loadAllRecords()

    /**
     * Persist a payload via OpenRegister.
     *
     * @param array<string, mixed> $payload Payload.
     *
     * @return array<string, mixed>|null Saved row or null.
     */
    private function saveObject(array $payload): ?array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return null;
        }

        try {
            $saved = $objectService->saveObject(
                object: $payload,
                register: $this->getRegisterSlug(),
                schema: $this->getSchemaSlug(),
                uuid: null,
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'ConsentService.saveObject: save failed',
                ['exception' => $e->getMessage()]
            );
            return null;
        }

        return $this->toArray(value: $saved);
    }//end saveObject()

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
                'ConsentService.getObjectService: OpenRegister unavailable',
                ['exception' => $e->getMessage()]
            );
            return null;
        }
    }//end getObjectService()

    /**
     * Normalise an OR entity to a plain array.
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
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential key-lookup fallbacks; extraction adds no clarity.
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
     * Register slug (app-config overridable).
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
     * Schema slug (app-config overridable).
     *
     * @return string Slug.
     */
    private function getSchemaSlug(): string
    {
        $slug = $this->appConfig->getValueString(
            Application::APP_ID,
            'messagingConsentRecord_schema',
            ''
        );

        if ($slug !== '') {
            return $slug;
        }

        return self::DEFAULT_SCHEMA_SLUG;
    }//end getSchemaSlug()

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
