<?php

/**
 * Pipelinq PublicSurveyController.
 *
 * Controller for public (unauthenticated) survey access and response submission.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\PublicShareController;
use OCP\IRequest;
use OCP\ISession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Public controller for survey response collection.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class PublicSurveyController extends PublicShareController
{

    /**
     * The OpenRegister object service.
     *
     * @var \OCA\OpenRegister\Service\ObjectService|null The object service.
     */
    private ?\OCA\OpenRegister\Service\ObjectService $objectService = null;

    /**
     * Constructor.
     *
     * @param IRequest           $request         The request.
     * @param ISession           $session         The session.
     * @param ContainerInterface $container       The DI container.
     * @param IAppManager        $appManager      The app manager.
     * @param SettingsService    $settingsService The settings service.
     * @param LoggerInterface    $logger          The logger.
     */
    public function __construct(
        IRequest $request,
        ISession $session,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request, $session);
    }//end __construct()

    /**
     * Get the password hash for this share.
     *
     * @return string The share token.
     */
    protected function getPasswordHash(): string
    {
        return '';
    }//end getPasswordHash()

    /**
     * Whether the share is password-protected.
     *
     * @return bool Always false for public surveys.
     */
    public function isPasswordProtected(): bool
    {
        return false;
    }//end isPasswordProtected()

    /**
     * Whether the share is valid.
     *
     * @return bool Always true (validation happens in methods).
     */
    public function isValidToken(): bool
    {
        return true;
    }//end isValidToken()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return \OCA\OpenRegister\Service\ObjectService The object service.
     *
     * @throws \RuntimeException If OpenRegister is not available.
     */
    private function getObjectService(): \OCA\OpenRegister\Service\ObjectService
    {
        if ($this->objectService !== null) {
            return $this->objectService;
        }

        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            $this->objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            return $this->objectService;
        }

        throw new \RuntimeException('OpenRegister service is not available.');
    }//end getObjectService()

    /**
     * Get a survey by its public access token.
     *
     * @param string $token The survey token.
     *
     * @return JSONResponse The survey data or error response.
     *
     * @PublicPage
     * @NoCSRFRequired
     * @BruteForceProtection(action=pipelinq_survey)
     */
    public function show(string $token): JSONResponse
    {
        try {
            $survey = $this->findSurveyByToken($token);

            if ($survey === null) {
                $response = new JSONResponse(
                    ['error' => 'Survey not found'],
                    Http::STATUS_NOT_FOUND
                );
                $response->throttle();
                return $response;
            }

            $surveyData = is_array($survey) ? $survey : (method_exists($survey, 'jsonSerialize') ? $survey->jsonSerialize() : (array) $survey);

            if (($surveyData['status'] ?? 'draft') !== 'active') {
                return new JSONResponse(
                    ['error' => 'This survey is no longer accepting responses'],
                    Http::STATUS_GONE
                );
            }

            $activeUntil = $surveyData['activeUntil'] ?? null;
            if ($activeUntil !== null && $activeUntil !== '' && strtotime($activeUntil) < time()) {
                return new JSONResponse(
                    ['error' => 'This survey is no longer accepting responses'],
                    Http::STATUS_GONE
                );
            }

            unset($surveyData['createdBy'], $surveyData['linkedEntityId']);

            return new JSONResponse($surveyData);
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to load public survey',
                ['exception' => $e->getMessage(), 'token' => $token]
            );
            return new JSONResponse(
                ['error' => 'Failed to load survey'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end show()

    /**
     * Submit a response to a survey.
     *
     * @param string $token The survey token.
     *
     * @return JSONResponse The created response or error.
     *
     * @PublicPage
     * @BruteForceProtection(action=pipelinq_survey_submit)
     */
    public function submit(string $token): JSONResponse
    {
        try {
            $survey = $this->findSurveyByToken($token);

            if ($survey === null) {
                $response = new JSONResponse(
                    ['error' => 'Survey not found'],
                    Http::STATUS_NOT_FOUND
                );
                $response->throttle();
                return $response;
            }

            $surveyData = is_array($survey) ? $survey : (method_exists($survey, 'jsonSerialize') ? $survey->jsonSerialize() : (array) $survey);

            if (($surveyData['status'] ?? 'draft') !== 'active') {
                return new JSONResponse(
                    ['error' => 'This survey is no longer accepting responses'],
                    Http::STATUS_GONE
                );
            }

            $activeUntil = $surveyData['activeUntil'] ?? null;
            if ($activeUntil !== null && $activeUntil !== '' && strtotime($activeUntil) < time()) {
                return new JSONResponse(
                    ['error' => 'This survey is no longer accepting responses'],
                    Http::STATUS_GONE
                );
            }

            $body    = $this->request->getParams();
            $answers = $body['answers'] ?? [];

            if (empty($answers) === true || is_array($answers) === false) {
                return new JSONResponse(
                    ['error' => 'Answers are required'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            $questions   = $surveyData['questions'] ?? [];
            $requiredIds = [];
            foreach ($questions as $q) {
                if (($q['required'] ?? true) === true) {
                    $requiredIds[] = $q['id'];
                }
            }

            $answeredIds = array_column($answers, 'questionId');
            $missing     = array_diff($requiredIds, $answeredIds);
            if (empty($missing) === false) {
                return new JSONResponse(
                    ['error' => 'Please answer all required questions', 'missing' => array_values($missing)],
                    Http::STATUS_BAD_REQUEST
                );
            }

            $settings         = $this->settingsService->getSettings();
            $registerId       = $settings['register'] ?? '';
            $responseSchemaId = $settings['surveyResponse_schema'] ?? '';

            if ($registerId === '' || $responseSchemaId === '') {
                return new JSONResponse(
                    ['error' => 'Survey system is not configured'],
                    Http::STATUS_SERVICE_UNAVAILABLE
                );
            }

            $ipHash = hash('sha256', $this->request->getRemoteAddress());

            $surveyId     = $surveyData['id'] ?? '';
            $responseData = [
                'surveyId'     => $surveyId,
                'answers'      => $answers,
                'respondentId' => $body['respondentId'] ?? null,
                'entityType'   => $body['entityType'] ?? null,
                'entityId'     => $body['entityId'] ?? null,
                'completedAt'  => (new \DateTime())->format('c'),
                'ipHash'       => $ipHash,
            ];

            $objectService = $this->getObjectService();
            $created       = $objectService->saveObject(
                $registerId,
                $responseSchemaId,
                $responseData,
            );

            return new JSONResponse(
                ['message' => 'Thank you for your feedback!', 'id' => $created->getUuid()],
                Http::STATUS_CREATED
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to submit survey response',
                ['exception' => $e->getMessage(), 'token' => $token]
            );
            return new JSONResponse(
                ['error' => 'Failed to submit response'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end submit()

    /**
     * Find a survey object by its public token.
     *
     * @param string $token The survey token.
     *
     * @return mixed The survey data or null.
     */
    private function findSurveyByToken(string $token): mixed
    {
        $settings     = $this->settingsService->getSettings();
        $registerId   = $settings['register'] ?? '';
        $surveySchema = $settings['survey_schema'] ?? '';

        if ($registerId === '' || $surveySchema === '') {
            return null;
        }

        $objectService = $this->getObjectService();
        $results       = $objectService->getObjects(
            $registerId,
            $surveySchema,
            ['token' => $token, '_limit' => 1],
        );

        $items = $results['results'] ?? $results ?? [];
        if (empty($items) === true) {
            return null;
        }

        return is_array($items) ? $items[0] : $items;
    }//end findSurveyByToken()
}//end class
