<?php

/**
 * Pipelinq IntakeFormController.
 *
 * Controller for authenticated intake form management.
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-43
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-44
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\IntakeFormService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Controller for managing intake forms (embed code, submissions, export).
 */
class IntakeFormController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest          $request           The request.
     * @param IntakeFormService $intakeFormService The intake form service.
     * @param IURLGenerator     $urlGenerator      The URL generator.
     * @param IUserSession      $userSession       The user session.
     */
    public function __construct(
        IRequest $request,
        private IntakeFormService $intakeFormService,
        private IURLGenerator $urlGenerator,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Get embed code snippets for a form.
     *
     * @param string $id The form ID.
     *
     * @return JSONResponse The embed code (iframe and JS snippet).
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-43
     */
    public function embed(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $baseUrl = $this->urlGenerator->getAbsoluteURL('/');

        return new JSONResponse(
                [
                    'iframe' => $this->intakeFormService->generateIframeEmbed(formId: $id, baseUrl: $baseUrl),
                    'js'     => $this->intakeFormService->generateJsEmbed(formId: $id, baseUrl: $baseUrl),
                ]
                );
    }//end embed()

    /**
     * Export form submissions as CSV.
     *
     * @param string $id The form ID.
     *
     * @return DataDownloadResponse|JSONResponse The CSV download or 501 stub response.
     *
     * @NoAdminRequired
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) Route-bound {id}; consumed once OR submission retrieval is wired.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-44
     */
    public function export(string $id): DataDownloadResponse|JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        // CSV export requires OpenRegister data integration.
        // Returning 501 until OR submission retrieval is wired.
        return new JSONResponse(['message' => 'Export not yet implemented'], Http::STATUS_NOT_IMPLEMENTED);
    }//end export()
}//end class
