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
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for managing intake forms (embed code, submissions, export).
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
     * @param IAppConfig         $appConfig         The app configuration.
     * @param IUserSession       $userSession       The user session.
     * @param LoggerInterface    $logger            The logger.
     *
     * @spec openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-3
     */
    public function __construct(
        IRequest $request,
        private IntakeFormService $intakeFormService,
        private IURLGenerator $urlGenerator,
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private IUserSession $userSession,
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
     *
     * @spec openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-3
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
     * Get submissions for a form.
     *
     * @param string $id The form ID.
     *
     * @return JSONResponse The list of submissions.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-3
     */
    public function submissions(string $id): JSONResponse
    {
        $authResponse = $this->authorizeFormAccess(id: $id);
        if ($authResponse !== null) {
            return $authResponse;
        }

        try {
            $submissions = $this->getSubmissionsForForm(formId: $id);
            return new JSONResponse(['results' => $submissions]);
        } catch (\Exception $e) {
            $this->logger->error(
                message: 'Failed to fetch submissions',
                context: ['formId' => $id, 'error' => $e->getMessage()]
            );
            return new JSONResponse(
                ['error' => 'Failed to fetch submissions'],
                500
            );
        }
    }//end submissions()

    /**
     * Export form submissions as CSV.
     *
     * @param string $id The form ID.
     *
     * @return DataDownloadResponse The CSV download response.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-3
     */
    public function export(string $id): DataDownloadResponse
    {
        $authResponse = $this->authorizeFormAccess(id: $id);
        if ($authResponse !== null) {
            $statusCode = $authResponse->getStatus();
            $data       = $authResponse->getData();
            if (is_array($data)) {
                return new DataDownloadResponse(
                    data: json_encode($data),
                    filename: 'error.json',
                    contentType: 'application/json'
                );
            }
        }

        try {
            // Get form and submissions from OpenRegister.
            $form        = $this->intakeFormService->getFormData(formId: $id);
            $submissions = $this->getSubmissionsForForm(formId: $id);

            $csv = $this->intakeFormService->exportCsv(
                submissions: $submissions,
                fields: $form['fields'] ?? []
            );

            return new DataDownloadResponse(
                data: $csv,
                filename: 'submissions-'.$id.'.csv',
                contentType: 'text/csv'
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: 'Failed to export submissions',
                context: ['formId' => $id, 'error' => $e->getMessage()]
            );
            return new DataDownloadResponse(
                data: 'Failed to export submissions.',
                filename: 'error.txt',
                contentType: 'text/plain'
            );
        }//end try
    }//end export()

    /**
     * Get all submissions for a form from OpenRegister.
     *
     * @param string $id The form ID.
     *
     * @return array The array of submissions.
     *
     * @throws \Exception If unable to fetch submissions.
     */
    private function getSubmissionsForForm(string $id): array
    {
        try {
            $objectService = $this->getObjectService();
            $register      = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
            $schema        = $this->appConfig->getValueString(Application::APP_ID, 'intakeSubmission_schema', '');

            if ($register === '' || $schema === '') {
                return [];
            }

            $results = $objectService->findObjects(
                register: $register,
                schema: $schema,
                params: ['form' => $id]
            );

            if (is_array($results) === true) {
                return $results;
            }

            return [];
        } catch (\Exception $e) {
            $this->logger->warning(
                message: 'Failed to fetch submissions for form',
                context: ['formId' => $id, 'error' => $e->getMessage()]
            );
            throw $e;
        }//end try
    }//end getSubmissionsForForm()

    /**
     * Authorize form access for the current user.
     *
     * @param string $id The form ID.
     *
     * @return JSONResponse|null Returns a JSONResponse with 403 status if unauthorized, null if authorized.
     *
     * @spec openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-3
     */
    private function authorizeFormAccess(string $id): ?JSONResponse
    {
        try {
            $user = $this->userSession->getUser();
            if ($user === null) {
                return new JSONResponse(
                    ['error' => 'Unauthorized'],
                    403
                );
            }

            $form = $this->intakeFormService->getFormData(formId: $id);
            if ($form === null) {
                return new JSONResponse(
                    ['error' => 'Form not found'],
                    404
                );
            }

            $notifyUser    = $form['notifyUser'] ?? '';
            $currentUserId = $user->getUID();

            if ($notifyUser !== $currentUserId) {
                $this->logger->warning(
                    message: 'Unauthorized form access attempt',
                    context: ['formId' => $id, 'userId' => $currentUserId]
                );
                return new JSONResponse(
                    ['error' => 'Forbidden'],
                    403
                );
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->error(
                message: 'Authorization check failed',
                context: ['formId' => $id, 'error' => $e->getMessage()]
            );
            return new JSONResponse(
                ['error' => 'Failed to authorize access'],
                500
            );
        }//end try
    }//end authorizeFormAccess()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return object The object service.
     *
     * @throws \RuntimeException If OpenRegister is not available.
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Exception $e) {
            throw new \RuntimeException('OpenRegister service is not available.');
        }
    }//end getObjectService()
}//end class
