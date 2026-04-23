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
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for managing intake forms (embed code, submissions, export).
 *
 * @spec openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-3-2
 */
class IntakeFormController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest           $request           The request.
     * @param IntakeFormService  $intakeFormService The intake form service.
     * @param IURLGenerator      $urlGenerator      The URL generator.
     * @param ContainerInterface $container         The DI container.
     * @param IAppConfig         $appConfig         The app config.
     * @param IAppManager        $appManager        The app manager.
     * @param LoggerInterface    $logger            The logger.
     */
    public function __construct(
        IRequest $request,
        private IntakeFormService $intakeFormService,
        private IURLGenerator $urlGenerator,
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private IAppManager $appManager,
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
     * @spec            openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-3-2
     */
    public function embed(string $id): JSONResponse
    {
        try {
            // Verify form ownership/access (basic auth check).
            $objectService = $this->getObjectService();
            $config        = $this->appConfig->getValueString('pipelinq', 'register', '');

            $form = $objectService->findOne(
                register: $config,
                schema: 'intakeForm',
                id: $id,
            );

            if ($form === null) {
                return new JSONResponse(['error' => 'Form not found'], 404);
            }

            $baseUrl = $this->urlGenerator->getAbsoluteURL('/');

            return new JSONResponse(
                [
                    'iframe' => $this->intakeFormService->generateIframeEmbed(
                        formId: $id,
                        baseUrl: $baseUrl
                    ),
                    'js'     => $this->intakeFormService->generateJsEmbed(
                        formId: $id,
                        baseUrl: $baseUrl
                    ),
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error('Get embed code error: '.$e->getMessage());
            return new JSONResponse(['error' => 'Failed to generate embed code'], 500);
        }//end try
    }//end embed()

    /**
     * Export form submissions as CSV.
     *
     * @param string $id The form ID.
     *
     * @return DataDownloadResponse The CSV download response.
     *
     * @NoAdminRequired
     * @spec            openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-3-2
     */
    public function export(string $id): DataDownloadResponse
    {
        try {
            // Fetch form and submissions from OpenRegister.
            $objectService = $this->getObjectService();
            $config        = $this->appConfig->getValueString('pipelinq', 'register', '');

            $form = $objectService->findOne(
                register: $config,
                schema: 'intakeForm',
                id: $id,
            );

            if ($form === null) {
                return new DataDownloadResponse(
                    data: 'Form not found',
                    filename: 'error.txt',
                    contentType: 'text/plain'
                );
            }

            // Fetch all submissions for this form.
            $result = $objectService->findAll(
                register: $config,
                schema: 'intakeSubmission',
                filters: ['form' => $id],
            );

            $submissions = $result['results'] ?? [];
            $csv         = $this->intakeFormService->exportCsv(
                submissions: $submissions,
                fields: $form['fields'] ?? []
            );

            return new DataDownloadResponse(
                data: $csv,
                filename: 'submissions-'.$id.'.csv',
                contentType: 'text/csv'
            );
        } catch (\Exception $e) {
            $this->logger->error('Export submissions error: '.$e->getMessage());
            return new DataDownloadResponse(
                data: 'Error exporting submissions',
                filename: 'error.txt',
                contentType: 'text/plain'
            );
        }//end try
    }//end export()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return mixed The ObjectService.
     *
     * @throws \RuntimeException If not available.
     */
    private function getObjectService(): mixed
    {
        if ($this->appManager->isEnabledForUser('openregister') === true) {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        }

        throw new \RuntimeException('OpenRegister service is not available.');
    }//end getObjectService()
}//end class
