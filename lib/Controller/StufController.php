<?php

/**
 * Pipelinq StufController.
 *
 * API endpoints for the StUF ZKN/BG adapter: outbound vrijBericht, verified
 * inbound kennisgeving reception, and admin-only endpoint/audit-log queries.
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
 * @spec openspec/changes/stuf-zkn-bg-adapter/tasks.md#3.1
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#req-stuf-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Exception\StufException;
use OCA\Pipelinq\Service\Stuf\StufAdapterService;
use OCA\Pipelinq\Service\Stuf\StufEndpointRepository;
use OCA\Pipelinq\Service\Stuf\StufInboundProcessor;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for StUF adapter API operations.
 *
 * Auth posture:
 *  - outbound / endpoints / messages: admin-only (#[AuthorizedAdminSetting]).
 *  - inkomend: public route (no user session) but NOT open — every request is
 *    verified against a shared secret before any processing (ADR-005 Rule 3).
 */
class StufController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest               $request          The request.
     * @param StufAdapterService     $adapter          The StUF adapter service.
     * @param StufEndpointRepository $endpointRepo     The endpoint repository.
     * @param StufInboundProcessor   $inboundProcessor The inbound processor.
     * @param IAppConfig             $appConfig        The app config.
     * @param IL10N                  $l10n             The localisation service.
     * @param LoggerInterface        $logger           The logger.
     */
    public function __construct(
        IRequest $request,
        private StufAdapterService $adapter,
        private StufEndpointRepository $endpointRepo,
        private StufInboundProcessor $inboundProcessor,
        private IAppConfig $appConfig,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Send a custom StUF message (vrijBericht).
     *
     * @return JSONResponse The result with referentienummer and stufMessageId.
     *
     * @spec openspec/changes/stuf-zkn-bg-adapter/tasks.md#3.1
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function outbound(): JSONResponse
    {
        $endpointId  = (string) $this->request->getParam('endpointId', '');
        $berichtNaam = (string) $this->request->getParam('berichtNaam', '');
        $payload     = $this->request->getParam('payload', []);

        if ($endpointId === '' || $berichtNaam === '') {
            return new JSONResponse(
                ['error' => $this->l10n->t('endpointId and berichtNaam are required')],
                Http::STATUS_BAD_REQUEST
            );
        }

        if (is_array($payload) === false) {
            $payload = [];
        }

        $endpoint = $this->endpointRepo->findById(endpointId: $endpointId);
        if ($endpoint === null) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Unknown StUF endpoint')],
                Http::STATUS_NOT_FOUND
            );
        }

        try {
            $result = $this->adapter->vrijBericht(name: $berichtNaam, payload: $payload, endpoint: $endpoint);
            return new JSONResponse($result);
        } catch (StufException $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('StUF message could not be sent')],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        } catch (Throwable $e) {
            $this->logger->error('StufController::outbound failed', ['exception' => $e]);
            return new JSONResponse(
                ['error' => $this->l10n->t('Operation failed')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end outbound()

    /**
     * Receive an inbound StUF kennisgeving / response envelope.
     *
     * Public route (no user session) so external zaaksystemen can deliver, but
     * each request MUST present the configured shared secret; unverified callers
     * are rejected before any XML is parsed.
     *
     * @return JSONResponse Minimal acknowledgement.
     *
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/changes/stuf-zkn-bg-adapter/tasks.md#3.3
     * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#req-stuf-001
     */
    public function inkomend(): JSONResponse
    {
        if ($this->verifyInboundSecret() === false) {
            $this->logger->warning('Rejected unverified inbound StUF envelope');
            return new JSONResponse(
                ['error' => $this->l10n->t('Unauthorized')],
                Http::STATUS_UNAUTHORIZED
            );
        }

        $envelopeXml = (string) file_get_contents('php://input');
        if ($envelopeXml === '') {
            $envelopeXml = (string) $this->request->getParam('envelope', '');
        }

        if ($envelopeXml === '') {
            return new JSONResponse(
                ['error' => $this->l10n->t('Empty envelope')],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $this->inboundProcessor->process(envelopeXml: $envelopeXml);
            return new JSONResponse(['received' => true]);
        } catch (StufException $e) {
            // Generic client error — never leak parser internals to an external caller.
            return new JSONResponse(
                ['error' => $this->l10n->t('Envelope could not be processed')],
                Http::STATUS_BAD_REQUEST
            );
        } catch (Throwable $e) {
            $this->logger->error('StufController::inkomend failed', ['exception' => $e]);
            return new JSONResponse(
                ['error' => $this->l10n->t('Operation failed')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end inkomend()

    /**
     * List all configured StufEndpoint profiles (admin only).
     *
     * @return JSONResponse The endpoint list.
     *
     * @spec openspec/changes/stuf-zkn-bg-adapter/tasks.md#3.1
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function endpoints(): JSONResponse
    {
        try {
            return new JSONResponse(['results' => $this->endpointRepo->findAll()]);
        } catch (Throwable $e) {
            $this->logger->error('StufController::endpoints failed', ['exception' => $e]);
            return new JSONResponse(
                ['error' => $this->l10n->t('Operation failed')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end endpoints()

    /**
     * Query the StufMessage audit log with filters (admin only).
     *
     * @return JSONResponse The matching audit-log rows.
     *
     * @spec openspec/changes/stuf-zkn-bg-adapter/tasks.md#3.1
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function messages(): JSONResponse
    {
        $endpointId   = (string) $this->request->getParam('endpointId', '');
        $berichtSoort = (string) $this->request->getParam('berichtSoort', '');
        $status       = (string) $this->request->getParam('status', '');
        $limit        = (int) $this->request->getParam('limit', 50);
        if ($limit < 1 || $limit > 500) {
            $limit = 50;
        }

        try {
            $rows = $this->endpointRepo->findMessages(
                endpointId: $endpointId,
                berichtSoort: $berichtSoort,
                status: $status,
                limit: $limit
            );
            return new JSONResponse(['results' => $rows]);
        } catch (Throwable $e) {
            $this->logger->error('StufController::messages failed', ['exception' => $e]);
            return new JSONResponse(
                ['error' => $this->l10n->t('Operation failed')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end messages()

    /**
     * Verify the inbound shared secret using a constant-time comparison.
     *
     * The expected secret is admin-configured (stuf.inbound.secret). When unset,
     * inbound reception is treated as DISABLED (fail-closed) rather than open.
     *
     * @return bool True when the request presents the correct secret.
     */
    private function verifyInboundSecret(): bool
    {
        $expected = $this->appConfig->getValueString(Application::APP_ID, 'stuf.inbound.secret', '');
        if ($expected === '') {
            // No secret configured: fail closed — never accept anonymous inbound.
            return false;
        }

        $presented = (string) $this->request->getHeader('X-Stuf-Secret');

        return $presented !== '' && hash_equals($expected, $presented);
    }//end verifyInboundSecret()
}//end class
