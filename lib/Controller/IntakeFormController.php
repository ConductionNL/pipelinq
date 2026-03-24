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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\IntakeFormService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

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
     */
    public function __construct(
        IRequest $request,
        private IntakeFormService $intakeFormService,
        private IURLGenerator $urlGenerator,
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
     */
    public function embed(string $id): JSONResponse
    {
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
     * @return DataDownloadResponse The CSV download response.
     *
     * @NoAdminRequired
     */
    public function export(string $id): DataDownloadResponse
    {
        // In production, fetch form and submissions from OpenRegister.
        $csv = $this->intakeFormService->exportCsv(submissions: [], fields: []);

        return new DataDownloadResponse(
            data: $csv,
            filename: 'submissions-'.$id.'.csv',
            contentType: 'text/csv'
        );
    }//end export()
}//end class
