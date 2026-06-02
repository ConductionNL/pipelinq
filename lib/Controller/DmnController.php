<?php

/**
 * Pipelinq DmnController.
 *
 * Admin-only REST surface for DMN-style decision tables: evaluate a table
 * against an input map and list the available tables. All evaluation logic
 * lives in DmnDecisionService; the controller validates input, gates on admin,
 * and maps evaluation errors to HTTP 400 with a static message (no stack
 * traces).
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
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\DmnDecisionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for DMN decision evaluation endpoints (admin only).
 *
 * Every endpoint requires a Nextcloud admin (REQ-DMN-005). A bad table id or
 * malformed input surfaces as a 400 with a static message. Tables are scoped to
 * this app's own register/schema inside the service.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Standard NC controller deps.
 *
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.3
 */
class DmnController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest           $request      The request.
     * @param DmnDecisionService $service      The DMN decision service.
     * @param IUserSession       $userSession  The user session.
     * @param IGroupManager      $groupManager The group manager.
     * @param IL10N              $l10n         The localization service.
     * @param LoggerInterface    $logger       The logger.
     */
    public function __construct(
        IRequest $request,
        private DmnDecisionService $service,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Evaluate a decision table (admin only).
     *
     * @return JSONResponse The decision output.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.3
     */
    #[NoAdminRequired]
    public function evaluate(): JSONResponse
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof JSONResponse) {
            return $guard;
        }

        $tableId = (string) $this->request->getParam('decisionTableId', '');
        $input   = $this->request->getParam('inputData', []);
        if (is_array($input) === false) {
            $input = [];
        }

        try {
            $output = $this->service->evaluateDecision(decisionTableId: $tableId, inputData: $input);

            return new JSONResponse(
                [
                    'decisionTableId' => $tableId,
                    'output'          => $output,
                    'evaluatedAt'     => date('c'),
                ]
            );
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('DmnController::evaluate failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['message' => $this->l10n->t('Decision evaluation failed')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end evaluate()

    /**
     * List the available decision tables (admin only).
     *
     * @return JSONResponse The decision table list.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.3
     */
    #[NoAdminRequired]
    public function tables(): JSONResponse
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof JSONResponse) {
            return $guard;
        }

        try {
            return new JSONResponse(['results' => $this->service->listTables()]);
        } catch (\Throwable $e) {
            $this->logger->error('DmnController::tables failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['message' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end tables()

    /**
     * Require an authenticated Nextcloud admin.
     *
     * @return null|JSONResponse A 401/403 response when not an admin, else null.
     */
    private function requireAdmin(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['message' => $this->l10n->t('Authentication required')],
                Http::STATUS_UNAUTHORIZED
            );
        }

        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(
                ['message' => $this->l10n->t('Administrator privileges required')],
                Http::STATUS_FORBIDDEN
            );
        }

        return null;
    }//end requireAdmin()
}//end class
