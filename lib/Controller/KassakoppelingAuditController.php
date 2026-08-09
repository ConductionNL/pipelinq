<?php

/**
 * Pipelinq KassakoppelingAuditController.
 *
 * HTTP edge for the POS Kassakoppeling-compliant audit log. Every action
 * requires an authenticated Nextcloud user (`#[NoAdminRequired]` — POS
 * operators must be able to record actions); the export endpoint adds an
 * admin gate on top so only Nextcloud administrators can pull the
 * Belastingdienst pack. The signature / chain / persistence logic lives in
 * KassakoppelingAuditService — the controller only translates HTTP I/O.
 *
 * ADR-005: error responses carry a generic message and the real exception
 * is logged server-side; no stack traces ever cross the wire.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * Controller for the POS Kassakoppeling audit log endpoints.
 *
 * Endpoint summary:
 *
 *   - POST   /api/kassakoppeling/audit              create (NoAdminRequired)
 *   - GET    /api/kassakoppeling/audit              index  (NoAdminRequired)
 *   - GET    /api/kassakoppeling/audit/export       export (admin guard in body)
 *   - GET    /api/kassakoppeling/audit/{id}         show   (NoAdminRequired)
 *   - POST   /api/kassakoppeling/audit/{id}/verify  verify (NoAdminRequired)
 *
 * The `export` route is declared BEFORE the `{id}` wildcard so the Symfony
 * router never mistakes "export" for an id (ADR-016).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Standard NC controller
 *  collaborator surface (request / session / l10n / group manager / logger
 *  + the audit service).
 *
 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#3.1
 */
class KassakoppelingAuditController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                   $request      The request.
     * @param KassakoppelingAuditService $service      The audit service.
     * @param IUserSession               $userSession  The user session.
     * @param IGroupManager              $groupManager The group manager (admin gate).
     * @param IL10N                      $l10n         The localisation service.
     * @param LoggerInterface            $logger       The logger.
     */
    public function __construct(
        IRequest $request,
        private KassakoppelingAuditService $service,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Create a new audit entry.
     *
     * Reads the entry fields from the request body, defaults operatorId to
     * the acting user when not supplied (so the typical "POS operator logs
     * their own action" path is one parameter shorter), and persists through
     * the service. Returns 201 with the persisted entry on success.
     *
     * @return JSONResponse The persisted entry, or an error.
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

        $data = [
            'operatorId'      => (string) $this->request->getParam('operatorId', $uid),
            'registerNumber'  => (string) $this->request->getParam('registerNumber', ''),
            'action'          => (string) $this->request->getParam('action', ''),
            'amount'          => $this->request->getParam('amount', 0),
            'itemCount'       => $this->request->getParam('itemCount', null),
            'taxAmount'       => $this->request->getParam('taxAmount', null),
            'timestamp'       => (string) $this->request->getParam('timestamp', ''),
            'transactionUuid' => $this->request->getParam('transactionUuid', null),
            'description'     => $this->request->getParam('description', null),
        ];

        return $this->run(
            action: fn (): array => ['entry' => $this->service->createEntry(data: $data)],
            label: 'create',
            successStatus: Http::STATUS_CREATED
        );
    }//end create()

    /**
     * List audit entries.
     *
     * Reads whitelisted filters from the query string and returns the
     * matching entries (ascending by timestamp).
     *
     * @return JSONResponse The entry list, or an error.
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
            'from'           => (string) $this->request->getParam('from', ''),
            'to'             => (string) $this->request->getParam('to', ''),
        ];

        return $this->run(
            action: fn (): array => ['entries' => $this->service->listEntries(filters: $filters)],
            label: 'index'
        );
    }//end index()

    /**
     * Fetch a single audit entry.
     *
     * @param string $id The entry uuid (route placeholder).
     *
     * @return JSONResponse The entry, or an error.
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

        return $this->run(
            action: fn (): array => ['entry' => $this->service->getEntry(id: $id)],
            label: 'show'
        );
    }//end show()

    /**
     * Re-verify an entry's signature + chain hash.
     *
     * @param string $id The entry uuid (route placeholder).
     *
     * @return JSONResponse The verification result, or an error.
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

        return $this->run(
            action: fn (): array => $this->service->verifyEntry(id: $id),
            label: 'verify'
        );
    }//end verify()

    /**
     * Export a date-range slice as a Belastingdienst-ready file.
     *
     * Admin-only, now enforced at both layers. The route previously carried
     * #[NoAdminRequired] as a sibling of the other endpoints on this
     * controller, leaving the in-body IGroupManager::isAdmin check as the only
     * thing enforcing REQ-AUDIT-005-03. That check remains, but the framework
     * now rejects a non-admin first — this endpoint streams the full audit
     * trail to the tax authority, and it is the one place on this controller
     * where a mis-edit to the body would be worst.
     * Returns the XML / JSON file as a DataDownloadResponse with a
     * Belastingdienst-friendly filename.
     *
     * @auth admin-only Streams the complete Belastingdienst audit export for the instance; the body additionally enforces it with an isAdmin() check (REQ-AUDIT-005-03).
     *
     * @return DataDownloadResponse|JSONResponse The export, or an error.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#3.1
     */
    public function export(): DataDownloadResponse|JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        if ($this->groupManager->isAdmin($uid) === false) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Alleen administrators kunnen exporteren naar de Belastingdienst.')],
                Http::STATUS_FORBIDDEN
            );
        }

        $fromDate = (string) $this->request->getParam('from', '');
        $toDate   = (string) $this->request->getParam('to', '');
        $format   = (string) $this->request->getParam('format', 'xml');
        if ($fromDate === '' || $toDate === '') {
            return new JSONResponse(
                ['error' => $this->l10n->t('Parameters "from" en "to" zijn verplicht (YYYY-MM-DD).')],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $payload = $this->service->exportForBelastingdienst(
                fromDate: $fromDate,
                toDate: $toDate,
                format: $format
            );
        } catch (OCSBadRequestException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error('KassakoppelingAuditController::export failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('Belastingdienst export mislukt.')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        return new DataDownloadResponse(
            (string) $payload['body'],
            (string) $payload['filename'],
            (string) $payload['contentType']
        );
    }//end export()

    /**
     * Require an authenticated user, returning their UID.
     *
     * Returns a 401 JSONResponse when no user is in the session.
     *
     * @return string|JSONResponse The user UID, or a 401 response.
     */
    private function requireUserId(): string|JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Authenticatie vereist.')],
                Http::STATUS_UNAUTHORIZED
            );
        }

        return $user->getUID();
    }//end requireUserId()

    /**
     * Shared service-call wrapper with ADR-005 error mapping.
     *
     * Maps service-level OCS exceptions to HTTP status codes (404 not
     * found, 422 bad input, 403 forbidden, 500 fallback) and logs the real
     * exception server-side.
     *
     * @param callable $action        The action to run.
     * @param string   $label         A short label for log context.
     * @param int      $successStatus The success HTTP status (default 200).
     *
     * @return JSONResponse The response.
     */
    private function run(callable $action, string $label, int $successStatus=Http::STATUS_OK): JSONResponse
    {
        try {
            return new JSONResponse($action(), $successStatus);
        } catch (OCSNotFoundException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (OCSBadRequestException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error(
                'KassakoppelingAuditController::'.$label.' failed',
                ['exception' => $e->getMessage()]
            );
            return new JSONResponse(
                ['error' => $this->l10n->t('Onverwachte fout in de audit log.')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end run()
}//end class
