<?php

/**
 * Pipelinq MessagingController.
 *
 * HTTP endpoints for WhatsApp/SMS sends and signature-verified inbound webhooks.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.6
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Messaging\InboundMessage;
use OCA\Pipelinq\Service\Messaging\ProviderConfigService;
use OCA\Pipelinq\Service\SmsService;
use OCA\Pipelinq\Service\WhatsAppService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for the messaging channel adapter.
 *
 * Send endpoints are `#[NoAdminRequired]` (any authenticated agent may send;
 * consent/budget gating is enforced in the service layer). The inbound webhook
 * is `#[PublicPage]` + `#[NoCSRFRequired]` but every request is
 * adapter-signature-verified (constant-time) before any processing (ADR-005);
 * an invalid signature yields HTTP 401 with no detail leaked.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @spec                                           openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.6
 */
class MessagingController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest              $request         The request.
     * @param WhatsAppService       $whatsAppService The WhatsApp orchestrator.
     * @param SmsService            $smsService      The SMS orchestrator.
     * @param ProviderConfigService $providerConfig  Provider resolution + secrets.
     * @param LoggerInterface       $logger          The logger.
     */
    public function __construct(
        IRequest $request,
        private WhatsAppService $whatsAppService,
        private SmsService $smsService,
        private ProviderConfigService $providerConfig,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Send a WhatsApp template message.
     *
     * @param string $contactId  The recipient contact id.
     * @param string $templateId The template id.
     * @param array  $parameters Positional placeholder values.
     *
     * @return JSONResponse The send outcome.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.1
     */
    #[NoAdminRequired]
    public function sendWhatsAppTemplate(string $contactId='', string $templateId='', array $parameters=[]): JSONResponse
    {
        $result = $this->whatsAppService->sendTemplate(
            contactId: $contactId,
            templateId: $templateId,
            parameters: array_map(static fn($p): string => (string) $p, array_values($parameters))
        );

        return $this->toResponse(result: $result);
    }//end sendWhatsAppTemplate()

    /**
     * Send a free-form WhatsApp (session) message.
     *
     * @param string $contactId The recipient contact id.
     * @param string $body      The free-form text.
     *
     * @return JSONResponse The send outcome.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.2
     */
    #[NoAdminRequired]
    public function sendWhatsAppMessage(string $contactId='', string $body=''): JSONResponse
    {
        $result = $this->whatsAppService->sendFreeForm(contactId: $contactId, body: $body);

        return $this->toResponse(result: $result);
    }//end sendWhatsAppMessage()

    /**
     * Send an SMS message.
     *
     * @param string      $contactId    The recipient contact id.
     * @param string      $body         The SMS text.
     * @param string|null $providerHint Optional caller-pinned vendor slug.
     *
     * @return JSONResponse The send outcome.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-3.1
     */
    #[NoAdminRequired]
    public function sendSms(string $contactId='', string $body='', ?string $providerHint=null): JSONResponse
    {
        $result = $this->smsService->send(contactId: $contactId, body: $body, providerHint: $providerHint);

        return $this->toResponse(result: $result);
    }//end sendSms()

    /**
     * Signature-verified inbound webhook for a configured provider.
     *
     * @param string $providerId The provider id from the URL.
     *
     * @return JSONResponse The acknowledgement.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.6
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function webhook(string $providerId=''): JSONResponse
    {
        $provider = $this->providerConfig->findActiveById(providerId: $providerId);
        if ($provider === null) {
            // Do not reveal configuration state to an unauthenticated caller.
            return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $client = $this->providerConfig->buildClient(provider: $provider);
        if ($client === null) {
            return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $rawBody = (string) file_get_contents('php://input');
        $headers = $this->lowercaseHeaders();
        $query   = $this->request->getParams();
        $secret  = $this->providerConfig->webhookSecret(provider: $provider);

        if ($client->verifyWebhookSignature($rawBody, $headers, $query, $secret) === false) {
            $this->logger->warning(
                'Messaging webhook rejected: invalid signature',
                ['sourceIp' => $this->request->getRemoteAddress()]
            );
            return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $payload = json_decode($rawBody, true);
        if (is_array($payload) === false) {
            $payload = $this->request->getParams();
        }

        try {
            $this->process(client: $client, payload: $payload, providerId: $providerId);
        } catch (Throwable $e) {
            $this->logger->error('Messaging webhook processing failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(['error' => 'processing_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse(['status' => 'ok']);
    }//end webhook()

    /**
     * Route a verified webhook payload to the appropriate orchestrator.
     *
     * @param object               $client     The configured provider client.
     * @param array<string, mixed> $payload    The decoded webhook body.
     * @param string               $providerId The provider id.
     *
     * @return void
     */
    private function process(object $client, array $payload, string $providerId): void
    {
        foreach ($client->parseInboundMessages($payload) as $inbound) {
            if (($inbound instanceof InboundMessage) === false) {
                continue;
            }

            if ($inbound->channel === 'whatsapp') {
                $this->whatsAppService->handleInbound(inbound: $inbound, providerId: $providerId);
                continue;
            }

            $this->smsService->handleInbound(inbound: $inbound, providerId: $providerId);
        }
    }//end process()

    /**
     * Map a MessagingResult to a JSON response.
     *
     * @param \OCA\Pipelinq\Service\Messaging\MessagingResult $result The send outcome.
     *
     * @return JSONResponse The response.
     */
    private function toResponse(\OCA\Pipelinq\Service\Messaging\MessagingResult $result): JSONResponse
    {
        if ($result->success === true) {
            return new JSONResponse(
                [
                    'status'            => 'sent',
                    'externalMessageId' => $result->externalMessageId,
                    'messageId'         => $result->messageId,
                ]
            );
        }

        $body = ['error' => $result->errorCode];
        if ($result->detail !== []) {
            $body['detail'] = $result->detail;
        }

        return new JSONResponse($body, $result->statusCode);
    }//end toResponse()

    /**
     * Collect request headers as a lower-cased map for signature verification.
     *
     * @return array<string, string> The lower-cased headers.
     */
    private function lowercaseHeaders(): array
    {
        $headers = [];
        foreach (['X-Hub-Signature-256', 'X-Twilio-Signature', 'messagebird-signature', 'X-CM-Signature'] as $name) {
            $value = $this->request->getHeader($name);
            if ($value !== '') {
                $headers[strtolower($name)] = $value;
            }
        }

        return $headers;
    }//end lowercaseHeaders()
}//end class
