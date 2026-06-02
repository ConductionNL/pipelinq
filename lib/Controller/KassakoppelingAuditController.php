<?php

/**
 * Pipelinq KassakoppelingAuditController.
 *
 * REST endpoints for the Belastingdienst Kassakoppeling append-only audit log:
 * create (POST only), list, show, manual verify and the admin-gated
 * Belastingdienst export. The log is append-only — there is no update / delete
 * route, and an explicit method rejects PUT/PATCH with 405. All business logic,
 * signing and authorization live in the service + PosAccessPolicy.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
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
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Service\KassakoppelingAuditService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for the Kassakoppeling audit-log endpoints.
 *
 * Authorization: create / list / show / verify are POS-operator capabilities
 * (authenticated + member of the POS group or admin, enforced by
 * PosAccessPolicy::isPosUser — fail closed). The Belastingdienst export is
 * restricted to POS managers / NC admins (PosAccessPolicy::isManager). Reads are
 * scoped to this app's own register + schema inside the service, so an id from
 * another app resolves to a 404 (no IDOR). Errors are logged server-side and the
 * client only ever sees a mapped status with a generic message (ADR-005).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Thin controller wiring the
 *  collaborators an audit endpoint legitimately needs (service, policy, session,
 *  l10n, logger).
 *
 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#3.1
 */
class KassakoppelingAuditController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                   $request     The request.
     * @param KassakoppelingAuditService $service     The audit service.
     * @param PosAccessPolicy            $policy      The shared POS access policy.
     * @param IUserSession               $userSession The user session.
     * @param IL10N                      $l10n        The localization service.
     * @param LoggerInterface            $logger      The logger.
     */
    public function __construct(
        IRequest $request,
        private KassakoppelingAuditService $service,
        private PosAccessPolicy $policy,
        private IUserSession $userSession,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Record a new audit entry (POS operator).
     *
     * The operatorId is taken from the authenticated session, never the client
     * body, so an operator cannot forge another user's identity. The signature,
     * hash chain and timestamp are computed server-side.
     *
     * @return JSONResponse The created entry (201) or an error.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#3.1
     */
    #[NoAdminRequired]
    public function create(): JSONResponse
    {
        $uid = $this->requirePosUser();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        $data = [
            'registerNumber'  => (string) $this->request->getParam('registerNumber', ''),
            'action'          => (string) $this->request->getParam('action', ''),
            'amount'          => $this->request->getParam('amount'),
            'itemCount'       => $this->request->getParam('itemCount'),
            'taxAmount'       => $this->request->getParam('taxAmount'),
            'transactionUuid' => $this->request->getParam('transactionUuid'),
            'description'     => $this->request->getParam('description'),
        ];

        try {
            $entry = $this->service->createEntry(data: $data, operatorId: $uid);
            return new JSONResponse(['entry' => $entry], Http::STATUS_CREATED);
        } catch (OCSBadRequestException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (OCSNotFoundException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->failure(label: 'create', e: $e);
        }//end try
    }//end create()

    /**
     * List audit entries with optional filters (POS operator).
     *
     * @return JSONResponse The matching entries or an error.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#3.1
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $uid = $this->requirePosUser();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        $filters = [
            'registerNumber' => (string) $this->request->getParam('register', ''),
            'operatorId'     => (string) $this->request->getParam('operator', ''),
            'action'         => (string) $this->request->getParam('action', ''),
            'fromDate'       => (string) $this->request->getParam('from', ''),
            'toDate'         => (string) $this->request->getParam('to', ''),
        ];

        try {
            $entries = $this->service->listEntries(filters: $filters);
            return new JSONResponse(['entries' => $entries, 'total' => count($entries)]);
        } catch (\Throwable $e) {
            return $this->failure(label: 'index', e: $e);
        }
    }//end index()

    /**
     * Show a single audit entry (POS operator).
     *
     * @param string $id The entry UUID.
     *
     * @return JSONResponse The entry or an error.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#3.1
     */
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        $uid = $this->requirePosUser();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        try {
            $entry = $this->service->getEntry(id: $id);
            return new JSONResponse(['entry' => $entry]);
        } catch (OCSNotFoundException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->failure(label: 'show', e: $e);
        }
    }//end show()

    /**
     * Manually verify an entry's signature + chain link (POS operator).
     *
     * @param string $id The entry UUID.
     *
     * @return JSONResponse The verification result or an error.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#3.1
     */
    #[NoAdminRequired]
    public function verify(string $id): JSONResponse
    {
        $uid = $this->requirePosUser();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        try {
            $verified = $this->service->verifyEntry(id: $id);
            return new JSONResponse(['verified' => $verified]);
        } catch (OCSNotFoundException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->failure(label: 'verify', e: $e);
        }
    }//end verify()

    /**
     * Export the audit log for the Belastingdienst (POS manager / admin only).
     *
     * @return DataDownloadResponse|JSONResponse The XML/JSON download or an error.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#3.1
     */
    #[NoAdminRequired]
    public function exportBelastingdienst(): DataDownloadResponse|JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Authentication required')],
                Http::STATUS_UNAUTHORIZED
            );
        }

        if ($this->policy->isManager(userId: $user->getUID()) === false) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Only a manager may export the audit log')],
                Http::STATUS_FORBIDDEN
            );
        }

        $fromDate = (string) $this->request->getParam('fromDate', '');
        $toDate   = (string) $this->request->getParam('toDate', '');
        $format   = strtolower((string) $this->request->getParam('format', 'xml'));
        if ($format !== 'json') {
            $format = 'xml';
        }

        try {
            $body = $this->service->exportForBelastingdienst(fromDate: $fromDate, toDate: $toDate, format: $format);
        } catch (\Throwable $e) {
            return $this->failure(label: 'export', e: $e);
        }

        $contentType = 'application/xml';
        if ($format === 'json') {
            $contentType = 'application/json';
        }

        $filename = 'kassakoppeling-export-'.$this->dateLabel(value: $fromDate, fallback: 'begin')
            .'-to-'.$this->dateLabel(value: $toDate, fallback: 'now').'.'.$format;

        return new DataDownloadResponse($body, $filename, $contentType);
    }//end exportBelastingdienst()

    /**
     * Reject any mutation of an audit entry (append-only ledger).
     *
     * The audit log is append-only: entries are created via POST and never
     * updated or deleted. This handler is wired to PUT/PATCH/DELETE so an
     * attempted mutation returns a clear 405 rather than a generic 404.
     *
     * @param string $id The entry UUID (unused; present for the route).
     *
     * @return JSONResponse Always 405 Method Not Allowed.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#3.1
     */
    #[NoAdminRequired]
    public function update(string $id): JSONResponse
    {
        unset($id);

        return new JSONResponse(
            ['error' => $this->l10n->t('Audit entries are append-only and cannot be modified')],
            Http::STATUS_METHOD_NOT_ALLOWED
        );
    }//end update()

    /**
     * Require an authenticated POS operator, returning their UID.
     *
     * Returns 401 when unauthenticated and 403 when the user is not a POS
     * operator / admin (fail closed). Object-level reads are then scoped to this
     * app's own schema inside the service, preventing IDOR.
     *
     * @return string|JSONResponse The acting user UID, or an error response.
     */
    private function requirePosUser(): string|JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Authentication required')],
                Http::STATUS_UNAUTHORIZED
            );
        }

        $uid = $user->getUID();
        if ($this->policy->isPosUser(userId: $uid) === false) {
            return new JSONResponse(
                ['error' => $this->l10n->t('POS access is required')],
                Http::STATUS_FORBIDDEN
            );
        }

        return $uid;
    }//end requirePosUser()

    /**
     * Log a real error server-side and return a generic 500 to the client.
     *
     * @param string     $label A short label for log context.
     * @param \Throwable $e     The caught throwable.
     *
     * @return JSONResponse The generic 500 response.
     */
    private function failure(string $label, \Throwable $e): JSONResponse
    {
        if ($e instanceof OCSForbiddenException) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }

        $this->logger->error(
            'KassakoppelingAuditController::'.$label.' failed',
            ['exception' => $e->getMessage()]
        );

        return new JSONResponse(
            ['error' => $this->l10n->t('Operation failed')],
            Http::STATUS_INTERNAL_SERVER_ERROR
        );
    }//end failure()

    /**
     * Build a filesystem-safe date label for the export filename.
     *
     * @param string $value    The date value.
     * @param string $fallback The label when the value is empty.
     *
     * @return string The sanitised label.
     */
    private function dateLabel(string $value, string $fallback): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $fallback;
        }

        $safe = preg_replace('/[^0-9A-Za-z-]/', '', substr($trimmed, 0, 10));
        if ($safe === null || $safe === '') {
            return $fallback;
        }

        return $safe;
    }//end dateLabel()
}//end class
