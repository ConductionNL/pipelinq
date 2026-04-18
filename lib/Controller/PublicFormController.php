<?php

/**
 * Pipelinq PublicFormController.
 *
 * Controller for public (no-auth) intake form rendering and submission.
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
 * @spec openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\IntakeFormService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IAppManager;
use OCP\IRequest;
use OCP\IServerContainer;
use Psr\Log\LoggerInterface;

/**
 * Public controller for intake form rendering and submission.
 *
 * All endpoints are public (no authentication required) and include
 * CORS headers for cross-origin embedding.
 */
class PublicFormController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest          $request           The request.
     * @param IntakeFormService $intakeFormService The intake form service.
     * @param IAppManager       $appManager        The app manager.
     * @param IServerContainer  $container         The server container.
     * @param LoggerInterface   $logger            The logger.
     */
    public function __construct(
        IRequest $request,
        private IntakeFormService $intakeFormService,
        private IAppManager $appManager,
        private IServerContainer $container,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Get a public form definition for rendering.
     *
     * Returns the form fields and configuration needed to render the form
     * on an external website. Does not expose internal configuration.
     *
     * @param string $id The form ID.
     *
     * @return JSONResponse The public form definition.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function show(string $id): JSONResponse
    {
        try {
            $objectService = $this->getObjectService();
            if ($objectService === null) {
                $response = new JSONResponse(
                    ['error' => 'Service unavailable'],
                    503
                );
                return $this->addCorsHeaders($response);
            }

            $form = $objectService->findObject('pipelinq', 'intakeForm', $id);
            if ($form === null) {
                $response = new JSONResponse(
                    ['error' => 'Form not found'],
                    404
                );
                return $this->addCorsHeaders($response);
            }

            if (!($form['isActive'] ?? false)) {
                $response = new JSONResponse(
                    ['error' => 'Form is not accepting submissions'],
                    403
                );
                return $this->addCorsHeaders($response);
            }

            // Return only public-facing form definition
            $publicForm = [
                'id' => $form['id'] ?? $form['uuid'] ?? $id,
                'name' => $form['name'] ?? '',
                'fields' => $form['fields'] ?? [],
                'successMessage' => $form['successMessage'] ?? 'Thank you for your submission.',
            ];

            $response = new JSONResponse($publicForm);
            return $this->addCorsHeaders($response);
        } catch (\Exception $e) {
            $this->logger->error('Error fetching form: '.$e->getMessage());
            $response = new JSONResponse(
                ['error' => 'Internal server error'],
                500
            );
            return $this->addCorsHeaders($response);
        }//end try
    }//end show()

    /**
     * Process a public form submission.
     *
     * Validates the submission, checks for spam (honeypot) and rate limiting,
     * then creates contact and lead entities in Pipelinq.
     *
     * @param string $id The form ID.
     *
     * @return JSONResponse The submission result.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function submit(string $id): JSONResponse
    {
        try {
            $submission = $this->request->getParams();
            $ip = $this->request->getRemoteAddress();

            // Check honeypot - silently accept but discard
            if ($this->intakeFormService->isSpam(submission: $submission)) {
                $response = new JSONResponse(['success' => true, 'message' => 'Thank you for your submission.']);
                return $this->addCorsHeaders($response);
            }

            // Check rate limiting
            if ($this->intakeFormService->isRateLimited(ip: $ip, formId: $id)) {
                $response = new JSONResponse(
                    ['success' => false, 'message' => 'Too many submissions. Please try again later.'],
                    429
                );
                return $this->addCorsHeaders($response);
            }

            // Fetch form from OpenRegister
            $objectService = $this->getObjectService();
            if ($objectService === null) {
                $response = new JSONResponse(
                    ['success' => false, 'message' => 'Service unavailable'],
                    503
                );
                return $this->addCorsHeaders($response);
            }

            $form = $objectService->findObject('pipelinq', 'intakeForm', $id);
            if ($form === null) {
                $response = new JSONResponse(
                    ['success' => false, 'message' => 'Form not found'],
                    404
                );
                return $this->addCorsHeaders($response);
            }

            if (!($form['isActive'] ?? false)) {
                $response = new JSONResponse(
                    ['success' => false, 'message' => 'Form is not accepting submissions'],
                    403
                );
                return $this->addCorsHeaders($response);
            }

            // Process the submission
            $result = $this->intakeFormService->processSubmission($form, $submission, $ip);
            $statusCode = $result['success'] ? 200 : 400;

            $response = new JSONResponse($result, $statusCode);
            return $this->addCorsHeaders($response);
        } catch (\Exception $e) {
            $this->logger->error('Error processing form submission: '.$e->getMessage());
            $response = new JSONResponse(
                ['success' => false, 'message' => 'Error processing submission'],
                500
            );
            return $this->addCorsHeaders($response);
        }//end try
    }//end submit()

    /**
     * Add CORS headers to allow cross-origin form embedding.
     *
     * @param JSONResponse $response The response to add headers to.
     *
     * @return JSONResponse The response with CORS headers.
     */
    private function addCorsHeaders(JSONResponse $response): JSONResponse
    {
        $response->addHeader('Access-Control-Allow-Origin', '*');
        $response->addHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->addHeader('Access-Control-Allow-Headers', 'Content-Type');
        return $response;
    }//end addCorsHeaders()

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
