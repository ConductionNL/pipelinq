<?php

/**
 * Pipelinq KassakoppelingAuditController.
 *
 * REST API endpoints for the Kassakoppeling POS audit log: create entries,
 * list, show, verify signatures, and export for Belastingdienst.
 *
 * Security model per design.md and ADR-005:
 *  - All endpoints require Nextcloud authentication (no PublicPage).
 *  - Create / list / show / verify: #[NoAdminRequired] — POS operators.
 *  - Export: admin-only enforced via IGroupManager check (non-admin → 403).
 *  - No stack traces in error responses; log real errors server-side.
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
 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#3.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\KassakoppelingAuditService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for Kassakoppeling POS audit log API endpoints.
 *
 * Implements the REST surface defined in design.md:
 *   POST   /api/kassakoppeling/audit          → create
 *   GET    /api/kassakoppeling/audit          → index
 *   GET    /api/kassakoppeling/audit/export   → exportBelastingdienst
 *   GET    /api/kassakoppeling/audit/{id}     → show
 *   POST   /api/kassakoppeling/audit/{id}/verify → verify
 *
 * Note: the export route MUST be registered BEFORE the {id} wildcard in
 * routes.php to prevent "export" being treated as an ID.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)  Wires the four service
 *   collaborators a regulatory-compliance controller legitimately needs
 *   (audit service, group manager, user session, logger + l10n).
 *
 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#3.1
 */
class KassakoppelingAuditController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                    $request      The request.
     * @param KassakoppelingAuditService  $auditService The audit service.
     * @param IGroupManager               $groupManager The group manager (admin check).
     * @param IUserSession                $userSession  The user session.
     * @param IL10N                       $l10n         Localisation.
     * @param LoggerInterface             $logger       The logger.
     */
    public function __construct(
        IRequest $request,
        private KassakoppelingAuditService $auditService,
        private IGroupManager $groupManager,
        private IUserSession $userSession,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Create a new audit log entry.
     *
     * POST /api/kassakoppeling/audit
     * Requires authenticated POS operator (#[NoAdminRequired]).
     *
     * @return JSONResponse 201 with created entry, or error response.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#3.1
     */
    #[NoAdminRequired]
    public function create(): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        $data = $this->request->getParams();

        // Strip Nextcloud internal request params.
        unset($data['_route'], $data['appName']);

        // If operatorId was not supplied, default to the authenticated user.
        if (empty($data['operatorId']) === true) {
            $data['operatorId'] = $uid;
        }

        try {
            $entry = $this->auditService->createEntry($data);
            return new JSONResponse(['entry' => $entry], Http::STATUS_CREATED);
        } catch (OCSBadRequestException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (OCSNotFoundException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            $this->logger->error('KassakoppelingAuditController::create failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end create()

    /**
     * List audit entries with optional filtering.
     *
     * GET /api/kassakoppeling/audit
     * Query params: registerNumber, operatorId, action, fromDate, toDate
     *
     * @return JSONResponse Paginated list of audit entries.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#3.1
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        $filters = [
            'registerNumber' => (string) $this->request->getParam('registerNumber', ''),
            'operatorId'     => (string) $this->request->getParam('operatorId', ''),
            'action'         => (string) $this->request->getParam('action', ''),
            'fromDate'       => (string) $this->request->getParam('fromDate', ''),
            'toDate'         => (string) $this->request->getParam('toDate', ''),
        ];

        // Remove empty filters.
        $filters = array_filter($filters, static fn(string $v): bool => $v !== '');

        try {
            $entries = $this->auditService->listEntries($filters);
            return new JSONResponse(['entries' => $entries, 'count' => count($entries)]);
        } catch (\Throwable $e) {
            $this->logger->error('KassakoppelingAuditController::index failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end index()

    /**
     * Show a single audit entry by ID.
     *
     * GET /api/kassakoppeling/audit/{id}
     *
     * @param string $id The entry UUID.
     *
     * @return JSONResponse The entry data.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#3.1
     */
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        try {
            $entry = $this->auditService->getEntry($id);
            return new JSONResponse(['entry' => $entry]);
        } catch (OCSNotFoundException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            $this->logger->error('KassakoppelingAuditController::show failed', ['id' => $id, 'exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end show()

    /**
     * Trigger signature verification for an entry and update its verified flag.
     *
     * POST /api/kassakoppeling/audit/{id}/verify
     *
     * @param string $id The entry UUID.
     *
     * @return JSONResponse {verified: bool, entryId: string}
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#3.1
     */
    #[NoAdminRequired]
    public function verify(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        try {
            $verified = $this->auditService->verifyEntry($id);
            return new JSONResponse(['verified' => $verified, 'entryId' => $id]);
        } catch (OCSNotFoundException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            $this->logger->error('KassakoppelingAuditController::verify failed', ['id' => $id, 'exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end verify()

    /**
     * Export audit entries for a date range — admin only.
     *
     * GET /api/kassakoppeling/audit/export
     * Query params: fromDate (required), toDate (required), format (xml|json, default: xml)
     *
     * Non-admin users receive HTTP 403. File download with appropriate
     * Content-Type header.
     *
     * @return JSONResponse|DataDownloadResponse The download response or error.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#3.1
     */
    public function exportBelastingdienst(): JSONResponse|DataDownloadResponse
    {
        // Admin-only: check before reading any params.
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
        }

        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(['error' => $this->l10n->t('Admin access required')], Http::STATUS_FORBIDDEN);
        }

        $fromDate = (string) $this->request->getParam('fromDate', '');
        $toDate   = (string) $this->request->getParam('toDate', '');
        $format   = strtolower((string) $this->request->getParam('format', 'xml'));

        if ($fromDate === '' || $toDate === '') {
            return new JSONResponse(
                ['error' => $this->l10n->t('fromDate and toDate are required')],
                Http::STATUS_BAD_REQUEST
            );
        }

        if (in_array($format, ['xml', 'json'], true) === false) {
            $format = 'xml';
        }

        try {
            $content  = $this->auditService->exportForBelastingdienst($fromDate, $toDate, $format);
            $mimeType = ($format === 'json') ? 'application/json' : 'application/xml';
            $filename = sprintf(
                'kassakoppeling-export-%s-to-%s.%s',
                preg_replace('/[^0-9\-]/', '', $fromDate),
                preg_replace('/[^0-9\-]/', '', $toDate),
                $format
            );

            return new DataDownloadResponse($content, $filename, $mimeType);
        } catch (\Throwable $e) {
            $this->logger->error('KassakoppelingAuditController::exportBelastingdienst failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end exportBelastingdienst()

    /**
     * Require an authenticated user, returning their UID.
     *
     * @return string|JSONResponse The acting user UID, or a 401 response.
     */
    private function requireUserId(): string|JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Authentication required')],
                Http::STATUS_UNAUTHORIZED
            );
        }

        return $user->getUID();
    }//end requireUserId()
}//end class
