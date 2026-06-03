<?php

/**
 * Pipelinq ConsentService.
 *
 * Messaging opt-in/opt-out consent enforcement and append-only audit logging.
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
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-4.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Messaging\OrSerializeTrait;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Enforces and records messaging consent (REQ-005).
 *
 * Consent is stored as an append-only series of `consentRecord` objects per
 * contact and channel; the effective state is the most-recently-recorded entry.
 * Opt-out keywords (STOP / STOPALL / UITSCHRIJVEN, case-insensitive, exact)
 * trigger an automatic opt-out; opt-in keywords (JA / START / AANMELDEN) recorded
 * via reply re-enable sends without destroying the opt-out history
 * (Telecommunicatiewet Art. 11.7 / DDMA / GDPR Art. 7).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — coordinates OR objects + config
 * @spec                                           openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-4.1
 */
class ConsentService
{
    use OrSerializeTrait;

    /**
     * Exact opt-out keywords (compared case-insensitively, trimmed).
     *
     * @var string[]
     */
    private const OPT_OUT_KEYWORDS = ['stop', 'stopall', 'uitschrijven'];

    /**
     * Exact opt-in keywords (compared case-insensitively, trimmed).
     *
     * @var string[]
     */
    private const OPT_IN_KEYWORDS = ['ja', 'start', 'aanmelden'];

    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container (resolves OpenRegister).
     * @param IAppConfig         $appConfig The app config (register/schema ids).
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether the organisation may send to this contact on this channel.
     *
     * Returns false only when the latest consent record for the contact+channel
     * is `opted-out`. Absence of any record is treated as sendable (the caller
     * is responsible for the prior opt-in capture); an explicit `opted-out`
     * blocks the send (REQ-005).
     *
     * @param string $contactId The contact UUID.
     * @param string $channel   The channel ('whatsapp'|'sms').
     *
     * @return bool True when sending is permitted.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-4.1
     */
    public function canSend(string $contactId, string $channel): bool
    {
        $latest = $this->latestState(contactId: $contactId, channel: $channel);

        return $latest !== 'opted-out';
    }//end canSend()

    /**
     * Append an opt-out consent record for a contact and channel.
     *
     * @param string $contactId The contact UUID.
     * @param string $channel   The channel.
     * @param string $source    The capture source (e.g. 'keyword-stop').
     * @param string $evidence  Free-text evidence backing the opt-out.
     *
     * @return array<string, mixed>|null The created record, or null on failure.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-4.1
     */
    public function recordOptOut(string $contactId, string $channel, string $source, string $evidence): ?array
    {
        return $this->append(
            contactId: $contactId,
            channel: $channel,
            state: 'opted-out',
            source: $source,
            evidence: $evidence
        );
    }//end recordOptOut()

    /**
     * Append an opt-in consent record for a contact and channel.
     *
     * @param string $contactId The contact UUID.
     * @param string $channel   The channel.
     * @param string $source    The capture source (e.g. 'chat-reply').
     * @param string $evidence  Free-text evidence backing the opt-in.
     *
     * @return array<string, mixed>|null The created record, or null on failure.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-4.3
     */
    public function recordOptIn(string $contactId, string $channel, string $source, string $evidence): ?array
    {
        return $this->append(
            contactId: $contactId,
            channel: $channel,
            state: 'opted-in',
            source: $source,
            evidence: $evidence
        );
    }//end recordOptIn()

    /**
     * Classify an inbound message body as an opt-out, opt-in, or neither.
     *
     * @param string $body The inbound message body.
     *
     * @return string One of 'opt-out', 'opt-in', or 'none'.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-4.2
     */
    public function classifyKeyword(string $body): string
    {
        $normalised = strtolower(trim($body));
        if (in_array($normalised, self::OPT_OUT_KEYWORDS, true) === true) {
            return 'opt-out';
        }

        if (in_array($normalised, self::OPT_IN_KEYWORDS, true) === true) {
            return 'opt-in';
        }

        return 'none';
    }//end classifyKeyword()

    /**
     * Delete all consent records for a contact (GDPR erasure).
     *
     * @param string $contactId The contact UUID being erased.
     *
     * @return int The number of consent records deleted.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-4.4
     */
    public function deleteForContact(string $contactId): int
    {
        [$register, $schema] = $this->registerSchema();
        if ($register === '' || $schema === '') {
            return 0;
        }

        $objectService = $this->objectService();
        if ($objectService === null) {
            return 0;
        }

        $deleted = 0;
        foreach ($this->recordsFor(contactId: $contactId, channel: null) as $record) {
            $id = $this->recordId(record: $record);
            if ($id === '') {
                continue;
            }

            try {
                $objectService->deleteObject($id, $register, $schema);
                $deleted++;
            } catch (\Exception $e) {
                $this->logger->warning('Consent record deletion failed', ['exception' => $e->getMessage()]);
            }
        }

        return $deleted;
    }//end deleteForContact()

    /**
     * The most-recently-recorded consent state for a contact+channel.
     *
     * @param string $contactId The contact UUID.
     * @param string $channel   The channel.
     *
     * @return string The latest state, or 'unknown' when no record exists.
     */
    public function latestState(string $contactId, string $channel): string
    {
        $records = $this->recordsFor(contactId: $contactId, channel: $channel);
        if ($records === []) {
            return 'unknown';
        }

        usort(
            $records,
            static fn(array $a, array $b): int => ((string) ($a['recordedAt'] ?? '')) <=> ((string) ($b['recordedAt'] ?? ''))
        );

        $last = end($records);

        return (string) ($last['state'] ?? 'unknown');
    }//end latestState()

    /**
     * Append a consent record via the OpenRegister save API.
     *
     * @param string $contactId The contact UUID.
     * @param string $channel   The channel.
     * @param string $state     The consent state.
     * @param string $source    The capture source.
     * @param string $evidence  The evidence text.
     *
     * @return array<string, mixed>|null The created record, or null on failure.
     */
    private function append(string $contactId, string $channel, string $state, string $source, string $evidence): ?array
    {
        [$register, $schema] = $this->registerSchema();
        if ($register === '' || $schema === '') {
            return null;
        }

        $objectService = $this->objectService();
        if ($objectService === null) {
            return null;
        }

        $object = [
            'contactId'  => $contactId,
            'channel'    => $channel,
            'state'      => $state,
            'source'     => $source,
            'recordedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
            'evidence'   => $evidence,
            'legalBasis' => 'consent',
        ];

        try {
            $saved = $objectService->saveObject($object, [], $register, $schema);
            if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
                $serialized = $saved->jsonSerialize();
                if (is_array($serialized) === true) {
                    return $serialized;
                }

                return $object;
            }

            if (is_array($saved) === true) {
                return $saved;
            }

            return $object;
        } catch (\Exception $e) {
            $this->logger->error('Consent record append failed', ['exception' => $e->getMessage()]);
            return null;
        }
    }//end append()

    /**
     * Fetch consent records for a contact (optionally filtered by channel).
     *
     * @param string      $contactId The contact UUID.
     * @param string|null $channel   The channel filter, or null for all channels.
     *
     * @return array<int, array<string, mixed>> The matching consent records.
     */
    private function recordsFor(string $contactId, ?string $channel): array
    {
        [$register, $schema] = $this->registerSchema();
        if ($register === '' || $schema === '') {
            return [];
        }

        $objectService = $this->objectService();
        if ($objectService === null) {
            return [];
        }

        try {
            $results = $objectService->findAll(
                ['filters' => ['register' => $register, 'schema' => $schema], 'limit' => 1000]
            );
        } catch (\Exception $e) {
            $this->logger->warning('Consent record query failed', ['exception' => $e->getMessage()]);
            return [];
        }

        $records = [];
        foreach ($results as $result) {
            $record = $this->serialize(result: $result);
            if ((string) ($record['contactId'] ?? '') !== $contactId) {
                continue;
            }

            if ($channel !== null && (string) ($record['channel'] ?? '') !== $channel) {
                continue;
            }

            $records[] = $record;
        }

        return $records;
    }//end recordsFor()

    /**
     * Resolve the configured register + consentRecord schema ids.
     *
     * @return array{0: string, 1: string} The [register, schema] pair.
     */
    private function registerSchema(): array
    {
        return [
            $this->appConfig->getValueString(Application::APP_ID, 'register', ''),
            $this->appConfig->getValueString(Application::APP_ID, 'consentRecord_schema', ''),
        ];
    }//end registerSchema()

    /**
     * Resolve the OpenRegister ObjectService, or null when unavailable.
     *
     * @return object|null The ObjectService, or null.
     */
    private function objectService(): ?object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $this->logger->warning('OpenRegister ObjectService unavailable', ['exception' => $e->getMessage()]);
            return null;
        }
    }//end objectService()

    /**
     * Derive the OR object id for a consent record.
     *
     * @param array<string, mixed> $record The consent record.
     *
     * @return string The object id, or empty string.
     */
    private function recordId(array $record): string
    {
        $self = ($record['@self'] ?? []);
        if (is_array($self) === true) {
            return (string) ($self['id'] ?? ($self['uuid'] ?? ''));
        }

        return (string) ($record['id'] ?? '');
    }//end recordId()
}//end class
