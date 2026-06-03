<?php

/**
 * Pipelinq MessageLogService.
 *
 * Logs inbound/outbound WhatsApp and SMS messages as contactmoment objects and
 * answers session-window queries from the message history.
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
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-0.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Messaging\OrSerializeTrait;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Persists and queries messaging contactmomenten (ADR-022).
 *
 * Pipelinq has no separate `message`/`conversation` schema; the omnichannel
 * `contactmoment` is the canonical interaction record (ADR-022 — a contact is a
 * Nextcloud entity, messages are contactmomenten). This service writes one
 * contactmoment per message with the messaging fields (channel, direction,
 * providerId, externalMessageId, deliveryStatus, windowExpiresAt, costEur) and
 * resolves the WhatsApp 24-hour session window from the most recent inbound.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   — coordinates OR objects + config
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) — message mapping + session-window queries are branchy
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)     — defensive field coercion on OR results
 * @spec                                             openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-0.1
 */
class MessageLogService
{
    use OrSerializeTrait;

    /**
     * The WhatsApp customer-service session window length in seconds (24 hours).
     *
     * @var int
     */
    public const SESSION_WINDOW_SECONDS = 86400;

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
     * Log a message as a contactmoment.
     *
     * @param array<string, mixed> $fields The contactmoment fields to persist.
     *
     * @return array<string, mixed>|null The saved contactmoment, or null on failure.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-1.5
     */
    public function log(array $fields): ?array
    {
        [$register, $schema] = $this->registerSchema();
        $objectService       = $this->objectService();
        if ($objectService === null || $register === '' || $schema === '') {
            return null;
        }

        if (isset($fields['subject']) === false || (string) $fields['subject'] === '') {
            $fields['subject'] = $this->deriveSubject(fields: $fields);
        }

        $fields['contactedAt'] = ($fields['contactedAt'] ?? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'));

        try {
            $saved = $objectService->saveObject($fields, [], $register, $schema);
            return $this->serialize(result: $saved);
        } catch (\Exception $e) {
            $this->logger->error('Message log failed', ['exception' => $e->getMessage()]);
            return null;
        }
    }//end log()

    /**
     * Patch an existing message contactmoment by its external message id.
     *
     * @param string               $externalMessageId The provider message id.
     * @param array<string, mixed> $patch             The fields to update.
     *
     * @return bool True when a matching message was updated.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-5.5
     */
    public function updateByExternalId(string $externalMessageId, array $patch): bool
    {
        if ($externalMessageId === '') {
            return false;
        }

        [$register, $schema] = $this->registerSchema();
        $objectService       = $this->objectService();
        if ($objectService === null || $register === '' || $schema === '') {
            return false;
        }

        $match = null;
        foreach ($this->allMessages() as $message) {
            if ((string) ($message['externalMessageId'] ?? '') === $externalMessageId) {
                $match = $message;
                break;
            }
        }

        if ($match === null) {
            return false;
        }

        $id = $this->messageId(message: $match);
        if ($id === '') {
            return false;
        }

        $merged = array_merge($match, $patch);
        unset($merged['@self']);

        try {
            $objectService->saveObject($merged, [], $register, $schema, $id);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Message update failed', ['exception' => $e->getMessage()]);
            return false;
        }
    }//end updateByExternalId()

    /**
     * Messaging messages whose cost is pending currency reconciliation.
     *
     * @return array<int, array<string, mixed>> The messages awaiting conversion.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-7.3
     */
    public function messagesPendingCostReconciliation(): array
    {
        $pending = [];
        foreach ($this->allMessages() as $message) {
            $meta = ($message['channelMetadata'] ?? null);
            if (is_array($meta) === true && ($meta['costCurrencyPending'] ?? false) === true) {
                $pending[] = $message;
            }
        }

        return $pending;
    }//end messagesPendingCostReconciliation()

    /**
     * The timestamp of the most recent inbound message for a contact + channel.
     *
     * @param string $contactId The contact UUID.
     * @param string $channel   The channel.
     *
     * @return \DateTimeImmutable|null The last inbound time, or null when none.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.2
     */
    public function lastInboundAt(string $contactId, string $channel): ?\DateTimeImmutable
    {
        $latest = null;
        foreach ($this->allMessages() as $message) {
            if ((string) ($message['client'] ?? ($message['contactId'] ?? '')) !== $contactId) {
                continue;
            }

            if ((string) ($message['channel'] ?? '') !== $channel) {
                continue;
            }

            if ((string) ($message['direction'] ?? '') !== 'inbound') {
                continue;
            }

            $when = (string) ($message['contactedAt'] ?? '');
            if ($when === '') {
                continue;
            }

            try {
                $moment = new DateTimeImmutable($when);
            } catch (\Exception $e) {
                continue;
            }

            if ($latest === null || $moment > $latest) {
                $latest = $moment;
            }
        }//end foreach

        return $latest;
    }//end lastInboundAt()

    /**
     * Whether a contact's WhatsApp session window is currently open.
     *
     * @param string             $contactId The contact UUID.
     * @param string             $channel   The channel.
     * @param \DateTimeImmutable $now       The current time.
     *
     * @return bool True when within 24 hours of the last inbound.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.2
     */
    public function isWindowOpen(string $contactId, string $channel, \DateTimeImmutable $now): bool
    {
        $lastInbound = $this->lastInboundAt(contactId: $contactId, channel: $channel);
        if ($lastInbound === null) {
            return false;
        }

        return ($now->getTimestamp() - $lastInbound->getTimestamp()) < self::SESSION_WINDOW_SECONDS;
    }//end isWindowOpen()

    /**
     * The window-expiry timestamp (last inbound + 24h), or null when no inbound.
     *
     * @param string $contactId The contact UUID.
     * @param string $channel   The channel.
     *
     * @return string|null The ISO 8601 expiry, or null.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.2
     */
    public function windowExpiresAt(string $contactId, string $channel): ?string
    {
        $lastInbound = $this->lastInboundAt(contactId: $contactId, channel: $channel);
        if ($lastInbound === null) {
            return null;
        }

        return $lastInbound->add(new DateInterval('PT24H'))->format('c');
    }//end windowExpiresAt()

    /**
     * Derive a contactmoment subject from the message fields.
     *
     * @param array<string, mixed> $fields The message fields.
     *
     * @return string The derived subject.
     */
    private function deriveSubject(array $fields): string
    {
        $channel   = (string) ($fields['channel'] ?? 'bericht');
        $direction = (string) ($fields['direction'] ?? '');
        $prefix    = 'Uitgaand';
        if ($direction === 'inbound') {
            $prefix = 'Inkomend';
        }

        return trim($prefix.' '.$channel.'-bericht');
    }//end deriveSubject()

    /**
     * All contactmoment objects on a messaging channel.
     *
     * @return array<int, array<string, mixed>> The messaging contactmomenten.
     */
    private function allMessages(): array
    {
        [$register, $schema] = $this->registerSchema();
        $objectService       = $this->objectService();
        if ($objectService === null || $register === '' || $schema === '') {
            return [];
        }

        try {
            $results = $objectService->findAll(
                ['filters' => ['register' => $register, 'schema' => $schema], 'limit' => 5000]
            );
        } catch (\Exception $e) {
            $this->logger->warning('Message query failed', ['exception' => $e->getMessage()]);
            return [];
        }

        $messages = [];
        foreach ($results as $result) {
            $message = $this->serialize(result: $result);
            $channel = (string) ($message['channel'] ?? '');
            if ($channel === 'whatsapp' || $channel === 'sms') {
                $messages[] = $message;
            }
        }

        return $messages;
    }//end allMessages()

    /**
     * Resolve the configured register + contactmoment schema ids.
     *
     * @return array{0: string, 1: string} The [register, schema] pair.
     */
    private function registerSchema(): array
    {
        return [
            $this->appConfig->getValueString(Application::APP_ID, 'register', ''),
            $this->appConfig->getValueString(Application::APP_ID, 'contactmoment_schema', ''),
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
     * Derive the OR object id for a message contactmoment.
     *
     * @param array<string, mixed> $message The message object.
     *
     * @return string The object id, or empty string.
     */
    private function messageId(array $message): string
    {
        $self = ($message['@self'] ?? []);
        if (is_array($self) === true) {
            return (string) ($self['id'] ?? ($self['uuid'] ?? ''));
        }

        return (string) ($message['id'] ?? '');
    }//end messageId()
}//end class
