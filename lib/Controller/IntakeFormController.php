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
 * @spec openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-3.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\IntakeFormService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppManager;
use OCP\IRequest;
use OCP\IServerContainer;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

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
     * @param IAppManager       $appManager        The app manager.
     * @param IServerContainer  $container         The server container.
     * @param LoggerInterface   $logger            The logger.
     */
    public function __construct(
        IRequest $request,
        private IntakeFormService $intakeFormService,
        private IURLGenerator $urlGenerator,
        private IAppManager $appManager,
        private IServerContainer $container,
        private LoggerInterface $logger,
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
     * List form submissions.
     *
     * @param string $id The form ID.
     *
     * @return JSONResponse The list of submissions.
     *
     * @NoAdminRequired
     */
    public function index(string $id): JSONResponse
    {
        try {
            $objectService = $this->getObjectService();
            if ($objectService === null) {
                return new JSONResponse(
                    ['error' => 'Service unavailable'],
                    503
                );
            }

            // Verify form exists and user can access it
            $form = $objectService->findObject('pipelinq', 'intakeForm', $id);
            if ($form === null) {
                return new JSONResponse(['error' => 'Form not found'], 404);
            }

            // Fetch submissions for this form
            $result = $objectService->findObjects(
                register: 'pipelinq',
                schema: 'intakeSubmission',
                params: [
                    'form' => $id,
                    '_limit' => 100,
                    '_offset' => 0,
                ]
            );

            return new JSONResponse($result);
        } catch (\Exception $e) {
            $this->logger->error('Error fetching submissions: '.$e->getMessage());
            return new JSONResponse(['error' => 'Internal server error'], 500);
        }//end try
    }//end index()

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
        try {
            $objectService = $this->getObjectService();
            if ($objectService === null) {
                $csv = 'Error: Service unavailable';
                return new DataDownloadResponse(
                    data: $csv,
                    filename: 'error.csv',
                    contentType: 'text/csv'
                );
            }

            // Fetch form
            $form = $objectService->findObject('pipelinq', 'intakeForm', $id);
            if ($form === null) {
                $csv = 'Error: Form not found';
                return new DataDownloadResponse(
                    data: $csv,
                    filename: 'error.csv',
                    contentType: 'text/csv'
                );
            }

            // Fetch all submissions for this form
            $result = $objectService->findObjects(
                register: 'pipelinq',
                schema: 'intakeSubmission',
                params: [
                    'form' => $id,
                    '_limit' => 1000,
                ]
            );

            $submissions = $result['results'] ?? [];
            $fields = $form['fields'] ?? [];

            $csv = $this->intakeFormService->exportCsv(submissions: $submissions, fields: $fields);

            return new DataDownloadResponse(
                data: $csv,
                filename: 'submissions-'.$id.'.csv',
                contentType: 'text/csv'
            );
        } catch (\Exception $e) {
            $this->logger->error('Error exporting submissions: '.$e->getMessage());
            $csv = 'Error: '.$e->getMessage();
            return new DataDownloadResponse(
                data: $csv,
                filename: 'error.csv',
                contentType: 'text/csv'
            );
        }//end try
    }//end export()

    /**
     * Get the ObjectService from the container.
     *
     * @return \OCA\OpenRegister\Service\ObjectService|null The ObjectService if available.
     */
    private function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService
    {
        if (in_array('openregister', $this->appManager->getInstalledApps(), true)) {
            try {
                return $this->container->get('OCA\OpenRegister\Service\ObjectService');
            } catch (\Exception $e) {
                $this->logger->error('Failed to get ObjectService: '.$e->getMessage());
                return null;
            }
        }

        return null;
    }//end getObjectService()
}//end class
