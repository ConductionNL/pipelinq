<?php

/**
 * Pipelinq MessagingWebhookController.
 *
 * Inbound webhook surface for WhatsApp (Meta) and SMS (Twilio,
 * MessageBird, CM.com). Each endpoint forwards the raw body +
 * signature header to the appropriate adapter, which verifies the
 * provider HMAC before persisting anything. Signature failure
 * returns 400 BAD_REQUEST per the pipelinq Hydra gate.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#6.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\SmsAdapter;
use OCA\Pipelinq\Service\WhatsAppAdapter;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for /api/messaging-webhooks/{provider}.
 *
 * Endpoints are `#[PublicPage]` + `#[NoCSRFRequired]` because
 * external providers cannot present an NC session or CSRF token;
 * authenticity is enforced by HMAC signature verification inside
 * the adapter.
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#6.1
 */
class MessagingWebhookController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest        $request         Request.
     * @param WhatsAppAdapter $whatsAppAdapter WhatsApp adapter.
     * @param SmsAdapter      $smsAdapter      SMS adapter.
     * @param LoggerInterface $logger          Logger.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#6.1
     */
    public function __construct(
        IRequest $request,
        private WhatsAppAdapter $whatsAppAdapter,
        private SmsAdapter $smsAdapter,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * POST /api/messaging-webhooks/whatsapp/{providerId}.
     *
     * @param string $providerId channelProvider UUID.
     *
     * @return JSONResponse Outcome.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#6.1
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function whatsapp(string $providerId): JSONResponse
    {
        $rawBody   = $this->readRawBody();
        $signature = (string) $this->request->getHeader('X-Hub-Signature-256');

        try {
            $result = $this->whatsAppAdapter->handleInboundWebhook(
                rawBody: $rawBody,
                signature: $signature,
                providerId: $providerId,
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'MessagingWebhookController.whatsapp: processing failed',
                ['providerId' => $providerId, 'exception' => $e->getMessage()]
            );
            return new JSONResponse(['error' => 'processingFailed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return $this->respondForResult(result: $result);
    }//end whatsapp()

    /**
     * POST /api/messaging-webhooks/sms/{providerId}.
     *
     * @param string $providerId channelProvider UUID.
     *
     * @return JSONResponse Outcome.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#6.1
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function sms(string $providerId): JSONResponse
    {
        $rawBody      = $this->readRawBody();
        $signatureRaw = $this->request->getHeader('X-Twilio-Signature');
        if ($signatureRaw === '') {
            $signatureRaw = $this->request->getHeader('messagebird-signature');
        }

        if ($signatureRaw === '') {
            $signatureRaw = $this->request->getHeader('X-Cmcom-Signature');
        }

        $signature = (string) $signatureRaw;

        try {
            $result = $this->smsAdapter->handleInboundWebhook(
                rawBody: $rawBody,
                signature: $signature,
                providerId: $providerId,
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'MessagingWebhookController.sms: processing failed',
                ['providerId' => $providerId, 'exception' => $e->getMessage()]
            );
            return new JSONResponse(['error' => 'processingFailed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return $this->respondForResult(result: $result);
    }//end sms()

    /**
     * Translate an adapter result into an HTTP response.
     *
     * - `received` → 200 OK.
     * - `invalidSignature` → 400 BAD_REQUEST (pipelinq Hydra gate
     *   for webhook signature failures).
     * - everything else → 422 UNPROCESSABLE_ENTITY.
     *
     * @param array<string, mixed> $result Adapter result.
     *
     * @return JSONResponse Response.
     */
    private function respondForResult(array $result): JSONResponse
    {
        $status = (string) ($result['status'] ?? '');
        if ($status === 'received') {
            return new JSONResponse($result, Http::STATUS_OK);
        }

        if ($status === 'invalidSignature') {
            return new JSONResponse(['error' => 'invalidSignature'], Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse($result, Http::STATUS_UNPROCESSABLE_ENTITY);
    }//end respondForResult()

    /**
     * Read the raw request body (Stream → string).
     *
     * @return string Raw body or empty.
     */
    private function readRawBody(): string
    {
        $body = @file_get_contents('php://input');
        if ($body === false) {
            return '';
        }

        return $body;
    }//end readRawBody()
}//end class
