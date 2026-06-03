<?php

/**
 * Pipelinq SmsService.
 *
 * Vendor-neutral SMS orchestration: priority-ordered provider failover with
 * consent and budget enforcement, caller-pinned provider hints, and inbound
 * webhook handling.
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
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Service\Messaging\InboundMessage;
use OCA\Pipelinq\Service\Messaging\MessagingResult;
use OCA\Pipelinq\Service\Messaging\ProviderConfigService;
use OCA\Pipelinq\Service\Messaging\SendResult;
use OCP\AppFramework\Http;
use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * SMS send/receive orchestration (REQ-003/004/005/006).
 *
 * Send order: consent → priority-ordered providers (or caller-pinned hint) →
 * per-provider budget gate → send (failover on transient/5xx) → persist +
 * budget record. When all providers fail, the message is persisted as failed,
 * the administrator is notified, and a system note is recorded.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — central orchestrator wiring many collaborators
 * @SuppressWarnings(PHPMD.StaticAccess)           — MessagingResult/SendResult expose only named factories
 * @spec                                           openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-3.1
 */
class SmsService
{
    /**
     * The SMS provider kind.
     *
     * @var string
     */
    private const KIND = 'sms';

    /**
     * Constructor.
     *
     * @param ProviderConfigService    $providerConfig      Provider resolution + client building.
     * @param ConsentService           $consentService      Consent enforcement.
     * @param BudgetService            $budgetService       Budget enforcement.
     * @param MessageLogService        $messageLog          Message persistence.
     * @param MessagingContactResolver $contactResolver     Contact resolution.
     * @param NotificationService      $notificationService Admin notifications.
     * @param IGroupManager            $groupManager        The group manager (resolves admins).
     * @param IEventDispatcher         $eventDispatcher     Event dispatcher for KCC routing.
     * @param LoggerInterface          $logger              The logger.
     */
    public function __construct(
        private ProviderConfigService $providerConfig,
        private ConsentService $consentService,
        private BudgetService $budgetService,
        private MessageLogService $messageLog,
        private MessagingContactResolver $contactResolver,
        private NotificationService $notificationService,
        private IGroupManager $groupManager,
        private IEventDispatcher $eventDispatcher,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Send an SMS to a contact, with optional caller-pinned provider hint.
     *
     * @param string      $contactId    The recipient contact id.
     * @param string      $body         The SMS text.
     * @param string|null $providerHint Optional vendor slug to pin (no failover).
     *
     * @return MessagingResult The send outcome.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-3.1
     */
    public function send(string $contactId, string $body, ?string $providerHint=null): MessagingResult
    {
        $to = $this->contactResolver->phoneForContact(contactId: $contactId);
        if ($to === null || $to === '' || trim($body) === '') {
            return MessagingResult::fail(statusCode: Http::STATUS_BAD_REQUEST, errorCode: 'invalidRequest');
        }

        if ($this->consentService->canSend(contactId: $contactId, channel: 'sms') === false) {
            return MessagingResult::fail(statusCode: Http::STATUS_FORBIDDEN, errorCode: 'consentMissing');
        }

        $providers = $this->resolveProviders(providerHint: $providerHint);
        if ($providers === []) {
            return MessagingResult::fail(statusCode: Http::STATUS_BAD_GATEWAY, errorCode: 'providerUnavailable');
        }

        return $this->attempt(contactId: $contactId, to: $to, body: $body, providers: $providers, pinned: ($providerHint !== null));
    }//end send()

    /**
     * Handle a verified inbound SMS (signature checked upstream).
     *
     * @param InboundMessage $inbound    The normalised inbound message.
     * @param string         $providerId The provider id that received it.
     *
     * @return string|null The persisted message id, or null on failure.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-3.5
     */
    public function handleInbound(InboundMessage $inbound, string $providerId): ?string
    {
        $resolution = $this->contactResolver->resolveForInbound(fromNumber: $inbound->fromNumber);
        if ($resolution === null) {
            return null;
        }

        $contactId = $resolution['contactId'];
        $messageId = $this->idOf(
            object: $this->messageLog->log(
                fields: [
                    'channel'           => 'sms',
                    'direction'         => 'inbound',
                    'client'            => $contactId,
                    'providerId'        => $providerId,
                    'fromAddress'       => $inbound->fromNumber,
                    'toAddress'         => $inbound->toNumber,
                    'summary'           => $inbound->body,
                    'externalMessageId' => $inbound->externalMessageId,
                ]
            )
        );

        $keyword = $this->consentService->classifyKeyword(body: $inbound->body);
        if ($keyword === 'opt-out') {
            $this->consentService->recordOptOut(
                contactId: $contactId,
                channel: 'sms',
                source: 'keyword-stop',
                evidence: 'Inbound SMS body matched opt-out keyword (case-insensitive)'
            );
        } else if ($keyword === 'opt-in') {
            $this->consentService->recordOptIn(
                contactId: $contactId,
                channel: 'sms',
                source: 'chat-reply',
                evidence: 'Inbound SMS body matched opt-in keyword (case-insensitive)'
            );
        }

        $this->dispatchReceivedEvent(contactId: $contactId, inbound: $inbound);

        return $messageId;
    }//end handleInbound()

    /**
     * Attempt the send across the resolved providers.
     *
     * @param string                           $contactId The contact id.
     * @param string                           $to        The recipient E.164.
     * @param string                           $body      The SMS text.
     * @param array<int, array<string, mixed>> $providers The ordered providers.
     * @param bool                             $pinned    Whether the provider is caller-pinned.
     *
     * @return MessagingResult The send outcome.
     */
    private function attempt(string $contactId, string $to, string $body, array $providers, bool $pinned): MessagingResult
    {
        $lastResult = null;
        foreach ($providers as $provider) {
            $providerId = $this->providerConfig->providerId(provider: $provider);
            if ($this->budgetService->canSend(providerId: $providerId) === false) {
                $this->budgetService->notifyExceeded(providerId: $providerId);
                return MessagingResult::fail(statusCode: Http::STATUS_FORBIDDEN, errorCode: 'budgetExceeded');
            }

            $client = $this->providerConfig->buildClient(provider: $provider);
            if ($client === null) {
                continue;
            }

            $result     = $client->sendFreeForm($to, $body);
            $lastResult = $result;

            if ($result->success === true) {
                $messageId = $this->idOf(
                    object: $this->messageLog->log(
                        fields: [
                            'channel'           => 'sms',
                            'direction'         => 'outbound',
                            'client'            => $contactId,
                            'providerId'        => $providerId,
                            'toAddress'         => $to,
                            'summary'           => $body,
                            'externalMessageId' => $result->externalMessageId,
                            'deliveryStatus'    => 'queued',
                        ]
                    )
                );
                $this->budgetService->recordSend(providerId: $providerId);
                return MessagingResult::sent(externalMessageId: $result->externalMessageId, messageId: $messageId);
            }

            if ($pinned === true || $result->transientFailure === false) {
                break;
            }
        }//end foreach

        return $this->allFailed(contactId: $contactId, to: $to, body: $body, lastResult: $lastResult);
    }//end attempt()

    /**
     * Persist a failed send, notify admins, and return the failure result.
     *
     * @param string          $contactId  The contact id.
     * @param string          $to         The recipient E.164.
     * @param string          $body       The SMS text.
     * @param SendResult|null $lastResult The last provider result.
     *
     * @return MessagingResult The failure result.
     */
    private function allFailed(string $contactId, string $to, string $body, ?SendResult $lastResult): MessagingResult
    {
        $this->messageLog->log(
            fields: [
                'channel'        => 'sms',
                'direction'      => 'outbound',
                'client'         => $contactId,
                'toAddress'      => $to,
                'summary'        => $body,
                'deliveryStatus' => 'failed',
                'notes'          => 'SMS-verzending mislukt op alle providers',
            ]
        );

        $this->notifyAdmins(to: $to);

        $code = 'providerUnavailable';
        if ($lastResult !== null && $lastResult->transientFailure === false) {
            $code = 'deliveryFailed';
        }

        return MessagingResult::fail(statusCode: Http::STATUS_BAD_GATEWAY, errorCode: $code);
    }//end allFailed()

    /**
     * Resolve the ordered provider list, honouring a caller-pinned hint.
     *
     * @param string|null $providerHint The pinned vendor slug, or null.
     *
     * @return array<int, array<string, mixed>> The ordered providers.
     */
    private function resolveProviders(?string $providerHint): array
    {
        $providers = $this->providerConfig->activeProviders(kinds: [self::KIND]);
        if ($providerHint === null) {
            return $providers;
        }

        foreach ($providers as $provider) {
            if (strtolower((string) ($provider['vendor'] ?? '')) === strtolower($providerHint)) {
                return [$provider];
            }
        }

        return [];
    }//end resolveProviders()

    /**
     * Notify administrators that all SMS providers failed.
     *
     * @param string $to The recipient number (non-sensitive context).
     *
     * @return void
     */
    private function notifyAdmins(string $to): void
    {
        try {
            $adminGroup = $this->groupManager->get('admin');
            if ($adminGroup === null) {
                return;
            }

            foreach ($adminGroup->getUsers() as $admin) {
                $this->notificationService->sendNotification(
                    $admin->getUID(),
                    'sms_all_providers_failed',
                    ['to' => $to],
                    'contactmoment',
                    ''
                );
            }
        } catch (Throwable $e) {
            $this->logger->warning('SMS failure notification failed', ['exception' => $e->getMessage()]);
        }
    }//end notifyAdmins()

    /**
     * Dispatch a message-received event for KCC routing.
     *
     * @param string         $contactId The contact id.
     * @param InboundMessage $inbound   The inbound message.
     *
     * @return void
     */
    private function dispatchReceivedEvent(string $contactId, InboundMessage $inbound): void
    {
        try {
            $this->eventDispatcher->dispatchTyped(
                new GenericEvent(
                    'OCA\Pipelinq\MessageReceived',
                    [
                        'contactId' => $contactId,
                        'channel'   => 'sms',
                        'from'      => $inbound->fromNumber,
                    ]
                )
            );
        } catch (Throwable $e) {
            $this->logger->warning('Message-received event dispatch failed', ['exception' => $e->getMessage()]);
        }
    }//end dispatchReceivedEvent()

    /**
     * Extract the OR object id from a saved object.
     *
     * @param array<string, mixed>|null $object The saved object.
     *
     * @return string|null The id, or null.
     */
    private function idOf(?array $object): ?string
    {
        if ($object === null) {
            return null;
        }

        $self = ($object['@self'] ?? []);
        if (is_array($self) === true && (string) ($self['id'] ?? '') !== '') {
            return (string) $self['id'];
        }

        $id = (string) ($object['id'] ?? '');
        if ($id === '') {
            return null;
        }

        return $id;
    }//end idOf()
}//end class
