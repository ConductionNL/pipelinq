<?php

/**
 * Pipelinq WhatsAppService.
 *
 * Vendor-neutral WhatsApp orchestration: template/free-form send with session-
 * window, consent and budget enforcement, and inbound webhook handling.
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
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\Service\Messaging\ChannelProviderInterface;
use OCA\Pipelinq\Service\Messaging\InboundMessage;
use OCA\Pipelinq\Service\Messaging\MessagingResult;
use OCA\Pipelinq\Service\Messaging\ProviderConfigService;
use OCA\Pipelinq\Service\Messaging\SendResult;
use OCP\AppFramework\Http;
use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * WhatsApp send/receive orchestration (REQ-001/002/003/005/006).
 *
 * Send order of checks: consent → budget → (template approval + parameter
 * validation | session-window) → provider failover send → persist + budget
 * record. Inbound: signature is verified by the controller; this service maps
 * the normalised payload to a contact + contactmoment, applies opt-out keyword
 * handling, and dispatches a message-received event for KCC routing.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   — central orchestrator wiring many collaborators
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.StaticAccess)             — MessagingResult/SendResult expose only named factories
 * @spec                                             openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.1
 */
class WhatsAppService
{
    /**
     * The WhatsApp provider kinds, highest priority first.
     *
     * @var string[]
     */
    private const KINDS = ['whatsapp-cloud-api', 'whatsapp-bsp'];

    /**
     * Constructor.
     *
     * @param ProviderConfigService    $providerConfig  Provider resolution + client building.
     * @param TemplateService          $templateService Template resolution + validation.
     * @param ConsentService           $consentService  Consent enforcement.
     * @param BudgetService            $budgetService   Budget enforcement.
     * @param MessageLogService        $messageLog      Message persistence + session window.
     * @param MessagingContactResolver $contactResolver Contact resolution.
     * @param IEventDispatcher         $eventDispatcher Event dispatcher for KCC routing.
     * @param LoggerInterface          $logger          The logger.
     */
    public function __construct(
        private ProviderConfigService $providerConfig,
        private TemplateService $templateService,
        private ConsentService $consentService,
        private BudgetService $budgetService,
        private MessageLogService $messageLog,
        private MessagingContactResolver $contactResolver,
        private IEventDispatcher $eventDispatcher,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Send an approved template message to a contact.
     *
     * @param string             $contactId  The recipient contact id.
     * @param string             $templateId The template id.
     * @param array<int, string> $parameters Positional placeholder values.
     *
     * @return MessagingResult The send outcome.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.1
     */
    public function sendTemplate(string $contactId, string $templateId, array $parameters): MessagingResult
    {
        $to = $this->contactResolver->phoneForContact(contactId: $contactId);
        if ($to === null || $to === '') {
            return MessagingResult::fail(statusCode: Http::STATUS_BAD_REQUEST, errorCode: 'invalidRequest');
        }

        if ($this->consentService->canSend(contactId: $contactId, channel: 'whatsapp') === false) {
            return MessagingResult::fail(statusCode: Http::STATUS_FORBIDDEN, errorCode: 'consentMissing');
        }

        $template = $this->templateService->find(templateId: $templateId);
        if ($template === null) {
            return MessagingResult::fail(statusCode: Http::STATUS_BAD_REQUEST, errorCode: 'invalidRequest');
        }

        if ($this->templateService->isApproved(template: $template) === false) {
            return MessagingResult::fail(statusCode: Http::STATUS_UNPROCESSABLE_ENTITY, errorCode: 'templateNotApproved');
        }

        $validation = $this->templateService->validateParameters(template: $template, parameters: $parameters);
        if ($validation['valid'] === false) {
            return MessagingResult::fail(
                statusCode: Http::STATUS_UNPROCESSABLE_ENTITY,
                errorCode: 'templateParameterMismatch',
                detail: ['expected' => $validation['expected'], 'given' => $validation['given']]
            );
        }

        return $this->dispatchSend(
            contactId: $contactId,
            to: $to,
            templateId: $templateId,
            template: $template,
            parameters: $parameters,
            body: null
        );
    }//end sendTemplate()

    /**
     * Send a free-form (session) message to a contact.
     *
     * @param string $contactId The recipient contact id.
     * @param string $body      The free-form text.
     *
     * @return MessagingResult The send outcome.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.2
     */
    public function sendFreeForm(string $contactId, string $body): MessagingResult
    {
        $to = $this->contactResolver->phoneForContact(contactId: $contactId);
        if ($to === null || $to === '' || trim($body) === '') {
            return MessagingResult::fail(statusCode: Http::STATUS_BAD_REQUEST, errorCode: 'invalidRequest');
        }

        if ($this->consentService->canSend(contactId: $contactId, channel: 'whatsapp') === false) {
            return MessagingResult::fail(statusCode: Http::STATUS_FORBIDDEN, errorCode: 'consentMissing');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($this->messageLog->isWindowOpen(contactId: $contactId, channel: 'whatsapp', now: $now) === false) {
            return MessagingResult::fail(statusCode: Http::STATUS_CONFLICT, errorCode: 'sessionWindowExpired');
        }

        return $this->dispatchSend(
            contactId: $contactId,
            to: $to,
            templateId: null,
            template: null,
            parameters: [],
            body: $body
        );
    }//end sendFreeForm()

    /**
     * Handle a verified inbound WhatsApp message (signature checked upstream).
     *
     * @param InboundMessage $inbound    The normalised inbound message.
     * @param string         $providerId The provider id that received it.
     *
     * @return string|null The persisted message id, or null on failure.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.6
     */
    public function handleInbound(InboundMessage $inbound, string $providerId): ?string
    {
        $resolution = $this->contactResolver->resolveForInbound(fromNumber: $inbound->fromNumber);
        if ($resolution === null) {
            return null;
        }

        $contactId = $resolution['contactId'];

        $messageId = $this->persistInbound(inbound: $inbound, contactId: $contactId, providerId: $providerId);

        $keyword = $this->consentService->classifyKeyword(body: $inbound->body);
        if ($keyword === 'opt-out') {
            $this->consentService->recordOptOut(
                contactId: $contactId,
                channel: 'whatsapp',
                source: 'keyword-stop',
                evidence: 'Inbound WhatsApp body matched opt-out keyword (case-insensitive)'
            );
        } else if ($keyword === 'opt-in') {
            $this->consentService->recordOptIn(
                contactId: $contactId,
                channel: 'whatsapp',
                source: 'chat-reply',
                evidence: 'Inbound WhatsApp body matched opt-in keyword (case-insensitive)'
            );
        }

        $this->dispatchReceivedEvent(contactId: $contactId, channel: 'whatsapp', inbound: $inbound);

        return $messageId;
    }//end handleInbound()

    /**
     * Run consent-passed/window-passed send through provider failover + persist.
     *
     * @param string                    $contactId  The contact id.
     * @param string                    $to         The recipient E.164 number.
     * @param string|null               $templateId The template id (template send).
     * @param array<string, mixed>|null $template   The template object (template send).
     * @param array<int, string>        $parameters Positional template parameters.
     * @param string|null               $body       The free-form body (free-form send).
     *
     * @return MessagingResult The send outcome.
     */
    private function dispatchSend(
        string $contactId,
        string $to,
        ?string $templateId,
        ?array $template,
        array $parameters,
        ?string $body
    ): MessagingResult {
        $providers = $this->providerConfig->activeProviders(kinds: self::KINDS);
        if ($providers === []) {
            return MessagingResult::fail(statusCode: Http::STATUS_BAD_GATEWAY, errorCode: 'providerUnavailable');
        }

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

            $result     = $this->callProvider(
                client: $client,
                to: $to,
                template: $template,
                parameters: $parameters,
                body: $body
            );
            $lastResult = $result;

            if ($result->success === true) {
                $messageId = $this->persistOutbound(
                    contactId: $contactId,
                    to: $to,
                    providerId: $providerId,
                    templateId: $templateId,
                    externalMessageId: $result->externalMessageId
                );
                $this->budgetService->recordSend(providerId: $providerId);
                return MessagingResult::sent(externalMessageId: $result->externalMessageId, messageId: $messageId);
            }

            if ($result->transientFailure === false) {
                break;
            }
        }//end foreach

        return $this->failureFor(result: $lastResult);
    }//end dispatchSend()

    /**
     * Invoke the provider client for the appropriate send mode.
     *
     * @param ChannelProviderInterface  $client     The configured client.
     * @param string                    $to         The recipient E.164 number.
     * @param array<string, mixed>|null $template   The template object, when template send.
     * @param array<int, string>        $parameters The template parameters.
     * @param string|null               $body       The free-form body, when free-form send.
     *
     * @return SendResult The provider send result.
     */
    private function callProvider(
        ChannelProviderInterface $client,
        string $to,
        ?array $template,
        array $parameters,
        ?string $body
    ): SendResult {
        if ($template !== null) {
            return $client->sendTemplate(
                $to,
                (string) ($template['externalId'] ?? ''),
                (string) ($template['language'] ?? 'nl'),
                array_values($parameters)
            );
        }

        return $client->sendFreeForm($to, (string) $body);
    }//end callProvider()

    /**
     * Map the final provider failure to a MessagingResult.
     *
     * @param SendResult|null $result The last provider result.
     *
     * @return MessagingResult The mapped failure.
     */
    private function failureFor(?SendResult $result): MessagingResult
    {
        if ($result !== null && $result->transientFailure === false) {
            return MessagingResult::fail(statusCode: Http::STATUS_BAD_GATEWAY, errorCode: 'deliveryFailed');
        }

        return MessagingResult::fail(statusCode: Http::STATUS_BAD_GATEWAY, errorCode: 'providerUnavailable');
    }//end failureFor()

    /**
     * Persist an outbound message as a contactmoment.
     *
     * @param string      $contactId         The contact id.
     * @param string      $to                The recipient E.164.
     * @param string      $providerId        The sending provider id.
     * @param string|null $templateId        The template id, when template send.
     * @param string|null $externalMessageId The provider message id.
     *
     * @return string|null The persisted message id, or null.
     */
    private function persistOutbound(
        string $contactId,
        string $to,
        string $providerId,
        ?string $templateId,
        ?string $externalMessageId
    ): ?string {
        $saved = $this->messageLog->log(
            fields: [
                'channel'           => 'whatsapp',
                'direction'         => 'outbound',
                'client'            => $contactId,
                'providerId'        => $providerId,
                'toAddress'         => $to,
                'templateId'        => $templateId,
                'externalMessageId' => $externalMessageId,
                'deliveryStatus'    => 'queued',
            ]
        );

        return $this->idOf(object: $saved);
    }//end persistOutbound()

    /**
     * Persist an inbound message as a contactmoment.
     *
     * @param InboundMessage $inbound    The normalised inbound message.
     * @param string         $contactId  The resolved contact id.
     * @param string         $providerId The receiving provider id.
     *
     * @return string|null The persisted message id, or null.
     */
    private function persistInbound(InboundMessage $inbound, string $contactId, string $providerId): ?string
    {
        $saved = $this->messageLog->log(
            fields: [
                'channel'           => 'whatsapp',
                'direction'         => 'inbound',
                'client'            => $contactId,
                'providerId'        => $providerId,
                'fromAddress'       => $inbound->fromNumber,
                'toAddress'         => $inbound->toNumber,
                'summary'           => $inbound->body,
                'externalMessageId' => $inbound->externalMessageId,
            ]
        );

        return $this->idOf(object: $saved);
    }//end persistInbound()

    /**
     * Dispatch a message-received event for KCC routing.
     *
     * @param string         $contactId The contact id.
     * @param string         $channel   The channel.
     * @param InboundMessage $inbound   The inbound message.
     *
     * @return void
     */
    private function dispatchReceivedEvent(string $contactId, string $channel, InboundMessage $inbound): void
    {
        try {
            $this->eventDispatcher->dispatchTyped(
                new GenericEvent(
                    'OCA\Pipelinq\MessageReceived',
                    [
                        'contactId' => $contactId,
                        'channel'   => $channel,
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
