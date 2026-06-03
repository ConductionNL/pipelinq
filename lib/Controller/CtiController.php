<?php

/**
 * Pipelinq CtiController.
 *
 * API endpoints for Computer Telephony Integration: inbound webhooks, screen-pop
 * lookup, click-to-dial, disposition submission, recording attachment, and the
 * admin configuration / event-log surfaces.
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
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-4.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\CtiDispositionService;
use OCA\Pipelinq\Service\CtiService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for CTI API endpoints.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CtiController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest              $request            The request.
     * @param CtiService            $ctiService         The core CTI service.
     * @param CtiDispositionService $dispositionService The disposition workflow service.
     * @param IGroupManager         $groupManager       The group manager.
     * @param IUserSession          $userSession        The user session.
     * @param IL10N                 $l10n               The localisation service.
     * @param LoggerInterface       $logger             The logger.
     */
    public function __construct(
        IRequest $request,
        private CtiService $ctiService,
        private CtiDispositionService $dispositionService,
        private IGroupManager $groupManager,
        private IUserSession $userSession,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Inbound telephony webhook handler.
     *
     * Public route, but every request is cryptographically verified by the
     * platform adapter before any processing (ADR-005 / REQ-CTI-007). Invalid
     * signatures yield HTTP 401 and a logged rejection; no stack traces are
     * returned to the caller.
     *
     * @param string $platform The platform slug from the URL.
     *
     * @return JSONResponse The acknowledgement.
     *
     * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-4.1
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function webhook(string $platform): JSONResponse
    {
        $platform = strtolower($platform);

        if ($this->ctiService->isWebhookRateLimited($platform) === true) {
            return new JSONResponse(['error' => 'rate_limited'], Http::STATUS_TOO_MANY_REQUESTS);
        }

        $config = $this->ctiService->getAdapterConfig();
        if ($config === [] || strtolower((string) ($config['platform'] ?? '')) !== $platform) {
            // Do not reveal configuration state to an unauthenticated caller.
            return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $adapter = $this->ctiService->getAdapter($platform, $config);
        } catch (Throwable $e) {
            return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $rawBody = (string) file_get_contents('php://input');
        $headers = $this->lowercaseHeaders();
        $query   = $this->request->getParams();
        $secret  = $this->ctiService->getWebhookSecret($platform, $config);

        if ($adapter->verifyWebhookSignature($rawBody, $headers, $query, $secret) === false) {
            $this->ctiService->logRejectedEvent($platform, 'unknown', 'signature_invalid');
            return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $payload = json_decode($rawBody, true);
        if (is_array($payload) === false) {
            $payload = $this->request->getParams();
        }

        try {
            $event = $adapter->handleInboundWebhook($payload);
            $this->ctiService->dispatchEvent($platform, $event);
        } catch (Throwable $e) {
            $this->logger->error('CTI webhook processing failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(['error' => 'processing_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse(['status' => 'ok']);
    }//end webhook()

    /**
     * Trigger a screen-pop lookup for an inbound caller number.
     *
     * @return JSONResponse The screen-pop instruction.
     *
     * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-4.1
     */
    #[NoAdminRequired]
    public function screenPop(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
        }

        $fromNumber = (string) $this->request->getParam('fromNumber', '');
        if ($fromNumber === '') {
            return new JSONResponse(['error' => $this->l10n->t('A caller number is required')], Http::STATUS_BAD_REQUEST);
        }

        try {
            $result = $this->ctiService->initiateScreenPop($fromNumber);
            return new JSONResponse($result->toArray());
        } catch (Throwable $e) {
            $this->logger->error('CTI screen-pop failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(['error' => $this->l10n->t('Screen-pop lookup failed')], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end screenPop()

    /**
     * Initiate an outbound click-to-dial call.
     *
     * @return JSONResponse The origination result.
     *
     * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-4.1
     */
    #[NoAdminRequired]
    public function clickToDial(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
        }

        $extension    = (string) $this->request->getParam('extension', '');
        $targetNumber = (string) $this->request->getParam('targetNumber', '');
        if ($extension === '' || $targetNumber === '') {
            return new JSONResponse(
                ['error' => $this->l10n->t('Extension and target number are required')],
                Http::STATUS_BAD_REQUEST
            );
        }

        if ($this->ctiService->isClickToDialBlocked($user->getUID()) === true) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Cannot initiate call while on another call')],
                Http::STATUS_CONFLICT
            );
        }

        try {
            $result = $this->ctiService->originateCall($user->getUID(), $extension, $targetNumber);
            if ($result->success === false) {
                return new JSONResponse(
                    ['error' => $this->l10n->t('Call could not be initiated')],
                    Http::STATUS_BAD_GATEWAY
                );
            }

            return new JSONResponse(
                [
                    'status'         => 'initiated',
                    'externalCallId' => $result->externalCallId,
                    'message'        => $this->l10n->t('Call initiated — your extension will ring momentarily'),
                ]
            );
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $this->l10n->t('CTI is not configured for click-to-dial')], Http::STATUS_BAD_REQUEST);
        } catch (Throwable $e) {
            $this->logger->error('CTI click-to-dial failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(['error' => $this->l10n->t('Call could not be initiated')], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try
    }//end clickToDial()

    /**
     * Submit a post-call disposition for a contactmoment.
     *
     * @param string $id The contactmoment UUID.
     *
     * @return JSONResponse The updated contactmoment.
     *
     * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-4.1
     */
    #[NoAdminRequired]
    public function disposition(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
        }

        $subject = (string) $this->request->getParam('subject', '');
        $outcome = (string) $this->request->getParam('outcome', '');
        $notes   = (string) $this->request->getParam('notes', '');

        if ($this->dispositionService->isValidOutcome($outcome) === false) {
            return new JSONResponse(['error' => $this->l10n->t('A valid outcome is required')], Http::STATUS_BAD_REQUEST);
        }

        if (trim($subject) === '') {
            return new JSONResponse(['error' => $this->l10n->t('A subject is required')], Http::STATUS_BAD_REQUEST);
        }

        try {
            $updated = $this->dispositionService->processDisposition($id, $subject, $outcome, $notes);
            return new JSONResponse(['contactmoment' => $updated]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $this->l10n->t('Invalid disposition input')], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $this->l10n->t('Contactmoment not found')], Http::STATUS_NOT_FOUND);
        } catch (Throwable $e) {
            $this->logger->error('CTI disposition failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(['error' => $this->l10n->t('Disposition could not be saved')], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try
    }//end disposition()

    /**
     * Read the current CTI configuration (admin only).
     *
     * The webhook secret value is never returned (ADR-005).
     *
     * @return JSONResponse The CTI configuration.
     *
     * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-4.1
     */
    #[NoAdminRequired]
    public function getConfig(): JSONResponse
    {
        $forbidden = $this->requireAdmin();
        if ($forbidden !== null) {
            return $forbidden;
        }

        $config = $this->ctiService->getAdapterConfig();
        unset($config['webhookSecretRef']);

        return new JSONResponse(['config' => $config]);
    }//end getConfig()

    /**
     * View the inbound webhook event log (admin only, 30-day retention).
     *
     * @return JSONResponse The event-log entries.
     *
     * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-4.1
     */
    #[NoAdminRequired]
    public function eventLog(): JSONResponse
    {
        $forbidden = $this->requireAdmin();
        if ($forbidden !== null) {
            return $forbidden;
        }

        $platform  = (string) $this->request->getParam('platform', '');
        $eventType = (string) $this->request->getParam('eventType', '');

        try {
            $events = $this->ctiService->listEventLog($platform, $eventType);
            return new JSONResponse(['events' => $events]);
        } catch (Throwable $e) {
            $this->logger->error('CTI event log read failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(['error' => $this->l10n->t('Event log could not be read')], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end eventLog()

    /**
     * Require the current user to be an administrator.
     *
     * @return JSONResponse|null A 401/403 response when not an admin, else null.
     */
    private function requireAdmin(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
        }

        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(['error' => $this->l10n->t('Administrator access required')], Http::STATUS_FORBIDDEN);
        }

        return null;
    }//end requireAdmin()

    /**
     * Collect request headers as a lower-cased map for signature verification.
     *
     * @return array<string, string> The lower-cased header map.
     */
    private function lowercaseHeaders(): array
    {
        $headers = [];
        foreach (['X-Signature', 'Authorization', 'Validation-Token'] as $name) {
            $value = $this->request->getHeader($name);
            if ($value !== '') {
                $headers[strtolower($name)] = $value;
            }
        }

        return $headers;
    }//end lowercaseHeaders()
}//end class
