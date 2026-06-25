<?php

/**
 * Pipelinq LoyaltyGdprController.
 *
 * REST API for GDPR (AVG) data subject access (export) and deletion. Default
 * admin-only via NC SecurityMiddleware (no NoAdminRequired attribute) — only
 * Nextcloud administrators can act on a customer's behalf.
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
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-010
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\LoyaltyGdprService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * GDPR REST controller.
 *
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-010
 */
class LoyaltyGdprController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest           $request     The request.
     * @param LoyaltyGdprService $gdprService The GDPR service.
     * @param IUserSession       $userSession The user session.
     */
    public function __construct(
        IRequest $request,
        private LoyaltyGdprService $gdprService,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * GET export of all loyalty data for a klantId.
     *
     * @param string $klantId The Nextcloud contact UID.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-010-02
     */
    public function export(string $klantId): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse($this->gdprService->getCustomerLoyaltyData(klantId: $klantId));
    }//end export()

    /**
     * DELETE loyalty data (soft-anonymisation).
     *
     * @param string $klantId The Nextcloud contact UID.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-010-03
     */
    public function delete(string $klantId): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse(
            ['summary' => $this->gdprService->deleteLoyaltyData(klantId: $klantId)]
        );
    }//end delete()
}//end class
