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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-41
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-42
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\IntakeFormService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Public controller for intake form rendering and submission.
 *
 * All endpoints are public (no authentication required) and include
 * CORS headers for cross-origin embedding.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @spec                                           openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-31
 */
class PublicFormController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest           $request           The request.
     * @param IntakeFormService  $intakeFormService The intake form service.
     * @param IAppConfig         $appConfig         The app config.
     * @param ContainerInterface $container         The DI container.
     * @param IAppManager        $appManager        The app manager.
     * @param LoggerInterface    $logger            The logger.
     */
    public function __construct(
        IRequest $request,
        private IntakeFormService $intakeFormService,
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private IAppManager $appManager,
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
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-41
     */
    public function show(string $id): JSONResponse
    {
        try {
            $objectService = $this->getObjectService();
            $config        = $this->getFormConfig();

            if ($config === null) {
                $response = new JSONResponse(['error' => 'Form service not available'], 503);
                return $this->addCorsHeaders(response: $response);
            }

            $form = $objectService->find($id, []);
            if ($form === null || ($form['isActive'] ?? false) !== true) {
                $response = new JSONResponse(['error' => 'Form not found'], 404);
                return $this->addCorsHeaders(response: $response);
            }

            // Return only the fields safe for public consumption.
            $publicForm = [
                'id'             => $id,
                'fields'         => $form['fields'] ?? [],
                'successMessage' => $form['successMessage'] ?? '',
                'isActive'       => true,
            ];

            $response = new JSONResponse($publicForm);
        } catch (\Exception $e) {
            $this->logger->error('PublicFormController::show failed: '.$e->getMessage());
            $response = new JSONResponse(['error' => 'Form not available'], 500);
        }//end try

        return $this->addCorsHeaders(response: $response);
    }//end show()

    /**
     * Process a public form submission.
     *
     * Validates the submission, checks for spam (honeypot) and rate limiting,
     * then creates contact and lead entities in Pipelinq via ObjectService.
     *
     * @param string $id The form ID.
     *
     * @return JSONResponse The submission result.
     *
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-42
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
            $objectService = $this->getObjectService();
            $config        = $this->getFormConfig();

            if ($config === null || $objectService === null) {
                $response = new JSONResponse(
                    ['success' => false, 'message' => 'Service temporarily unavailable.'],
                    503
                );
                return $this->addCorsHeaders(response: $response);
            }

            $registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
            $leadSchema = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');

            if ($registerId === '' || $leadSchema === '') {
                $this->logger->error('PublicFormController: register or lead_schema not configured');
                $response = new JSONResponse(
                    ['success' => false, 'message' => 'Service not properly configured.'],
                    500
                );
                return $this->addCorsHeaders(response: $response);
            }

            // Load the form schema so we can whitelist submission keys.
            $form       = $objectService->find($id, []);
            $formFields = [];
            if ($form !== null && is_array($form['fields'] ?? null) === true) {
                foreach ($form['fields'] as $field) {
                    if (isset($field['name']) === true) {
                        $formFields[] = $field['name'];
                    }
                }
            }

            // Build lead data from submission (whitelist against declared form fields).
            $leadData = $this->buildLeadData(submission: $submission, formId: $id, allowedFields: $formFields);

            $saved = $objectService->saveObject(
                $leadData,
                [],
                $registerId,
                $leadSchema,
                null
            );

            if ($saved === null) {
                throw new RuntimeException('Failed to persist submission');
            }

            $response = new JSONResponse(
                [
                    'success' => true,
                    'message' => 'Thank you for your submission.',
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error('PublicFormController::submit failed: '.$e->getMessage());
            $response = new JSONResponse(
                ['success' => false, 'message' => 'Submission could not be processed.'],
                500
            );
        }//end try

        return $this->addCorsHeaders(response: $response);
    }//end submit()

    /**
     * Build lead data from a raw submission, whitelisting against declared form fields.
     *
     * Only keys that appear in the form's own `fields[]` array are copied.
     * Internal and system-reserved keys are always excluded.
     * `status` is always set last from a fixed value to prevent injection.
     *
     * When `$allowedFields` is empty (form schema unavailable) the method
     * falls back to stripping underscore-prefixed keys only, maintaining
     * backward compatibility with unconfigured environments.
     *
     * @param array<string, mixed> $submission    The submitted form data.
     * @param string               $formId        The form ID.
     * @param string[]             $allowedFields Declared field names from the form schema.
     *
     * @return array<string, mixed> The lead data for OpenRegister.
     */
    private function buildLeadData(array $submission, string $formId, array $allowedFields=[]): array
    {
        // Status / source / formId are always controlled server-side.
        $reserved = ['status', 'source', 'formId', 'id', 'uuid'];

        $data = ['source' => 'public_form', 'formId' => $formId];

        foreach ($submission as $key => $value) {
            // Skip honeypot and framework-internal fields.
            if (str_starts_with($key, '_') === true) {
                continue;
            }

            // Skip server-side reserved keys regardless of allowedFields.
            if (in_array($key, $reserved, true) === true) {
                continue;
            }

            // If the form schema is known, only accept declared fields.
            if (empty($allowedFields) === false && in_array($key, $allowedFields, true) === false) {
                continue;
            }

            $data[$key] = $value;
        }

        // Always set status last so it cannot be overridden via the payload.
        $data['status'] = 'nieuw';

        return $data;
    }//end buildLeadData()

    /**
     * Get the OpenRegister ObjectService, or null if unavailable.
     *
     * @return object|null The ObjectService instance or null.
     */
    private function getObjectService(): ?object
    {
        if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
            return null;
        }

        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Exception $e) {
            $this->logger->error('PublicFormController: failed to load ObjectService: '.$e->getMessage());
            return null;
        }
    }//end getObjectService()

    /**
     * Get the app form configuration from app config.
     *
     * @return array<string, string>|null Config array or null if incomplete.
     */
    private function getFormConfig(): ?array
    {
        $register   = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $leadSchema = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');

        if ($register === '' || $leadSchema === '') {
            return null;
        }

        return ['register' => $register, 'lead_schema' => $leadSchema];
    }//end getFormConfig()

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
