<?php

/**
 * Pipelinq KlantbeeldController.
 *
 * Read endpoint for the consolidated klantbeeld-360 summary (klantbeeld-360-activation).
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/klantbeeld-360-activation/specs/klantbeeld-360/spec.md#requirement-consolidated-klantbeeld-summary
 * @spec openspec/changes/klantbeeld-360-activation/specs/klantbeeld-360/spec.md#requirement-klantbeeld-access-is-logged-doelbinding-mvp
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use DateTimeImmutable;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\KlantbeeldSummaryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for the klantbeeld-360 consolidated summary endpoint.
 *
 * @spec openspec/changes/klantbeeld-360-activation/specs/klantbeeld-360/spec.md#requirement-consolidated-klantbeeld-summary
 */
class KlantbeeldController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                 $request        The request.
     * @param KlantbeeldSummaryService $summaryService The klantbeeld summary aggregator.
     * @param IUserSession             $userSession    Current user.
     * @param IAppConfig               $appConfig      App config (register/schema resolution for the read guard).
     * @param ContainerInterface       $container      DI container (OpenRegister ObjectService).
     * @param LoggerInterface          $logger         Logger — also the doelbinding access-log sink
     *                                                 (MVP).
     */
    public function __construct(
        IRequest $request,
        private KlantbeeldSummaryService $summaryService,
        private IUserSession $userSession,
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * GET /api/klantbeeld/summary?clientId=... — the consolidated klantbeeld summary.
     *
     * Per-object read guard (no IDOR): the caller must be able to READ the
     * client object itself — resolved through OpenRegister's ObjectService,
     * which applies the caller's RBAC. A client the caller cannot read (hidden,
     * wrong tenant, deleted) 404s exactly like any other RBAC-denied read,
     * never leaking whether the id exists. On success, every access is logged
     * (doelbinding, MVP) with the acting user, the client id, and the time,
     * reusing the app's existing audit/logging facility (design.md's
     * provisional resolution — no new OR schema for the MVP).
     *
     * @return JSONResponse The summary, or an error response.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/klantbeeld-360-activation/specs/klantbeeld-360/spec.md#requirement-consolidated-klantbeeld-summary
     * @spec openspec/changes/klantbeeld-360-activation/specs/klantbeeld-360/spec.md#requirement-klantbeeld-access-is-logged-doelbinding-mvp
     */
    public function summary(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $clientId = (string) $this->request->getParam('clientId', '');
        if ($clientId === '') {
            return new JSONResponse(['message' => 'Missing required parameter: clientId'], Http::STATUS_BAD_REQUEST);
        }

        if ($this->canReadClient(clientId: $clientId) === false) {
            return new JSONResponse(['message' => 'Client not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $summary = $this->summaryService->getSummary(clientId: $clientId);
        } catch (Throwable $e) {
            $this->logger->error(
                'KlantbeeldController: summary failed',
                ['clientId' => $clientId, 'exception' => $e->getMessage()]
            );
            return new JSONResponse(['message' => 'Operation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $this->logAccess(actor: $user->getUID(), clientId: $clientId);

        return new JSONResponse($summary);
    }//end summary()

    /**
     * Per-object read guard: true only when the client object resolves through
     * OpenRegister's RBAC-scoped ObjectService::find(). Fails closed — an OR
     * outage or missing config denies the read rather than granting it, since
     * this is the caller's only defense against reading another client's data.
     *
     * @param string $clientId The client UUID.
     *
     * @return bool True when the caller may read this client.
     */
    private function canReadClient(string $clientId): bool
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');
        if ($register === '' || $schema === '') {
            return false;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $object        = $objectService->find($clientId, $register, $schema);
        } catch (Throwable $e) {
            $this->logger->warning(
                'KlantbeeldController: client read-guard failed',
                ['clientId' => $clientId, 'exception' => $e->getMessage()]
            );
            return false;
        }

        return $object !== null;
    }//end canReadClient()

    /**
     * Log a klantbeeld access (doelbinding, MVP) via the app's standard
     * logger — {@see LoggerInterface} is the app's existing general-purpose
     * audit facility (used the same way across the app for auditable events);
     * design.md's Open Questions section resolves the access-log medium to
     * this for the MVP, deferring a dedicated queryable access-report OR
     * object to a follow-up.
     *
     * @param string $actor    Acting user UID.
     * @param string $clientId The accessed client's UUID.
     *
     * @return void
     */
    private function logAccess(string $actor, string $clientId): void
    {
        $this->logger->info(
            'Klantbeeld accessed',
            [
                'audit'    => 'klantbeeld-access',
                'actor'    => $actor,
                'clientId' => $clientId,
                'time'     => (new DateTimeImmutable('now'))->format(DATE_ATOM),
            ]
        );
    }//end logAccess()
}//end class
