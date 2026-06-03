<?php

/**
 * Pipelinq BerichtenboxWebhookController.
 *
 * Receives Logius Berichtenbox callbacks (read receipts and inbound replies).
 * These endpoints are public (Logius is an anonymous external caller and cannot
 * present a Nextcloud session) but are NOT open: every request body is verified
 * against the Logius webhook HMAC signature before any processing, and an
 * unsigned or mismatched request is rejected with 401 (ADR-005). Generic error
 * responses are returned to avoid leaking internal state.
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
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#REQ-INBOUND-005
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\BerichtenboxService;
use OCA\Pipelinq\Service\LogiusConnector;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Logius webhook receiver for read receipts and inbound replies.
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#REQ-INBOUND-005
 */
class BerichtenboxWebhookController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest            $request             The request.
     * @param BerichtenboxService $berichtenboxService The core service.
     * @param LogiusConnector     $logiusConnector     The Logius connector.
     * @param LoggerInterface     $logger              The logger.
     */
    public function __construct(
        IRequest $request,
        private BerichtenboxService $berichtenboxService,
        private LogiusConnector $logiusConnector,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Handle a read-receipt callback.
     *
     * @return JSONResponse The result.
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function readReceipt(): JSONResponse
    {
        $raw     = $this->rawBody();
        $payload = $this->verifiedPayload(raw: $raw);
        if ($payload === null) {
            return new JSONResponse(['error' => 'invalid signature'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $logiusMessageId = (string) ($payload['logiusMessageId'] ?? ($payload['message-id'] ?? ''));
            $readAt          = (string) ($payload['readAt'] ?? '');
            if ($logiusMessageId === '' || $readAt === '') {
                return new JSONResponse(['error' => 'bad request'], Http::STATUS_BAD_REQUEST);
            }

            $this->berichtenboxService->handleReadReceipt(logiusMessageId: $logiusMessageId, readAt: $readAt);

            return new JSONResponse(['success' => true]);
        } catch (Throwable $e) {
            $this->logger->error('Berichtenbox: read-receipt webhook failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(['error' => 'processing error'], Http::STATUS_BAD_REQUEST);
        }
    }//end readReceipt()

    /**
     * Handle an inbound-reply callback.
     *
     * @return JSONResponse The result, including the created contactmoment ID.
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function inboundReply(): JSONResponse
    {
        $raw     = $this->rawBody();
        $payload = $this->verifiedPayload(raw: $raw);
        if ($payload === null) {
            return new JSONResponse(['error' => 'invalid signature'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $parentMessageId = (string) ($payload['parentMessageId'] ?? '');
            $logiusReplyId   = (string) ($payload['logiusReplyId'] ?? '');
            $bodyText        = (string) ($payload['bodyText'] ?? '');
            $attachments     = (array) ($payload['attachments'] ?? []);

            if ($parentMessageId === '' || $bodyText === '') {
                return new JSONResponse(['error' => 'bad request'], Http::STATUS_BAD_REQUEST);
            }

            $reply = $this->berichtenboxService->handleInboundReply(
                parentMessageId: $parentMessageId,
                logiusReplyId: $logiusReplyId,
                bodyText: $bodyText,
                attachments: $attachments
            );

            return new JSONResponse(
                [
                    'success'         => true,
                    'contactmomentId' => ($reply['createdContactmomentId'] ?? null),
                ]
            );
        } catch (Throwable $e) {
            $this->logger->error('Berichtenbox: inbound-reply webhook failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(['error' => 'processing error'], Http::STATUS_BAD_REQUEST);
        }//end try
    }//end inboundReply()

    /**
     * Read the raw request body for signature verification.
     *
     * Protected so tests can supply a fixed body without the PHP input stream.
     *
     * @return string The raw body.
     */
    protected function rawBody(): string
    {
        $raw = file_get_contents('php://input');
        if ($raw === false) {
            return '';
        }

        return $raw;
    }//end rawBody()

    /**
     * Verify the webhook signature and decode the JSON payload.
     *
     * @param string $raw The raw request body.
     *
     * @return array<string, mixed>|null The decoded payload, or null when invalid.
     */
    private function verifiedPayload(string $raw): ?array
    {
        $signature = (string) $this->request->getHeader('X-Logius-Signature');
        if ($this->logiusConnector->verifyWebhookSignature(rawBody: $raw, providedSig: $signature) === false) {
            $this->logger->warning('Berichtenbox: rejected webhook with invalid signature');
            return null;
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded) === false) {
            return null;
        }

        return $decoded;
    }//end verifiedPayload()
}//end class
