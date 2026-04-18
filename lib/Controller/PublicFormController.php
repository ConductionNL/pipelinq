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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\IntakeFormService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

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
     */
    public function __construct(
        IRequest $request,
        private IntakeFormService $intakeFormService,
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
     *
     * @spec openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-3
     */
    public function show(string $id): JSONResponse
    {
        try {
            $form = $this->intakeFormService->getPublicFormDefinition(formId: $id);
            $response = new JSONResponse($form);
        } catch (\Exception $e) {
            $response = new JSONResponse(
                ['error' => 'Form not found'],
                404
            );
        }

        return $this->addCorsHeaders(response: $response);
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
     *
     * @spec openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-3
     */
    public function submit(string $id): JSONResponse
    {
        $submission = $this->request->getParams();
        $ip         = $this->request->getRemoteAddress();

        // Check honeypot.
        if ($this->intakeFormService->isSpam(submission: $submission) === true) {
            // Silently accept but discard (don't reveal spam detection).
            $response = new JSONResponse(['success' => true, 'message' => 'Thank you for your submission.']);
            return $this->addCorsHeaders(response: $response);
        }

        // Check rate limiting.
        if ($this->intakeFormService->isRateLimited(ip: $ip, formId: $id) === true) {
            $response = new JSONResponse(
                ['success' => false, 'message' => 'Too many submissions. Please try again later.'],
                429
            );
            return $this->addCorsHeaders(response: $response);
        }

        try {
            // Fetch form config from OpenRegister.
            $formData = $this->intakeFormService->getFormData(formId: $id);

            if ($formData === null) {
                $response = new JSONResponse(
                    ['success' => false, 'message' => 'Form not found.'],
                    404
                );
                return $this->addCorsHeaders(response: $response);
            }

            // Check if form is active.
            if (($formData['isActive'] ?? false) === false) {
                $response = new JSONResponse(
                    ['success' => false, 'message' => 'This form is not accepting submissions.'],
                    400
                );
                return $this->addCorsHeaders(response: $response);
            }

            // Process the submission.
            $result = $this->intakeFormService->processSubmission(
                formData: $formData,
                submission: $submission,
                ip: $ip
            );

            $statusCode = $result['success'] === true ? 200 : 400;
            $response = new JSONResponse($result, $statusCode);
        } catch (\Exception $e) {
            $response = new JSONResponse(
                ['success' => false, 'message' => 'An error occurred while processing your submission.'],
                500
            );
        }

        return $this->addCorsHeaders(response: $response);
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
}//end class
