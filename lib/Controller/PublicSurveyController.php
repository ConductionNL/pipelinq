<?php

/**
 * Pipelinq PublicSurveyController.
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
     * @return string Empty string (no password).
     */
    protected function getPasswordHash(): string
    {
        return '';
    }//end getPasswordHash()

    /**
     * Whether the share is password-protected.
     *
     * @return bool Always false.
     */
    public function isPasswordProtected(): bool
    {
        return false;
    }//end isPasswordProtected()

    /**
     * Whether the share token is valid.
     *
     * @return bool Always true (validated in methods).
     */
    public function isValidToken(): bool
    {
        return true;
    }//end isValidToken()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return \OCA\OpenRegister\Service\ObjectService The service.
     *
     * @throws \RuntimeException If not available.
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
     * Show a survey by token.
     *
     * @param string $token The survey token.
     *
     * @return JSONResponse Survey data or error.
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
                $resp = new JSONResponse(['error' => 'Survey not found'], Http::STATUS_NOT_FOUND);
                $resp->throttle();
                return $resp;
            }

            $data = is_array($survey) ? $survey : (array) $survey;
            if (($data['status'] ?? 'draft') !== 'active') {
                return new JSONResponse(['error' => 'This survey is no longer accepting responses'], Http::STATUS_GONE);
            }

            $until = $data['activeUntil'] ?? null;
            if ($until !== null && $until !== '' && strtotime($until) < time()) {
                return new JSONResponse(['error' => 'This survey is no longer accepting responses'], Http::STATUS_GONE);
            }

            unset($data['createdBy'], $data['linkedEntityId']);
            return new JSONResponse($data);
        } catch (\Exception $e) {
            $this->logger->error('Failed to load public survey', ['exception' => $e->getMessage()]);
            return new JSONResponse(['error' => 'Failed to load survey'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try
    }//end show()

    /**
     * Submit a survey response.
     *
     * @param string $token The survey token.
     *
     * @return JSONResponse Created response or error.
     *
     * @PublicPage
     * @BruteForceProtection(action=pipelinq_survey_submit)
     */
    public function submit(string $token): JSONResponse
    {
        try {
            $survey = $this->findSurveyByToken($token);
            if ($survey === null) {
                $resp = new JSONResponse(['error' => 'Survey not found'], Http::STATUS_NOT_FOUND);
                $resp->throttle();
                return $resp;
            }

            $data = is_array($survey) ? $survey : (array) $survey;
            if (($data['status'] ?? 'draft') !== 'active') {
                return new JSONResponse(['error' => 'This survey is no longer accepting responses'], Http::STATUS_GONE);
            }

            $body    = $this->request->getParams();
            $answers = $body['answers'] ?? [];
            if (empty($answers) === true || is_array($answers) === false) {
                return new JSONResponse(['error' => 'Answers are required'], Http::STATUS_BAD_REQUEST);
            }

            $settings         = $this->settingsService->getSettings();
            $registerId       = $settings['register'] ?? '';
            $responseSchemaId = $settings['surveyResponse_schema'] ?? '';
            if ($registerId === '' || $responseSchemaId === '') {
                return new JSONResponse(['error' => 'Survey system is not configured'], Http::STATUS_SERVICE_UNAVAILABLE);
            }

            $responseData = [
                'surveyId'     => $data['id'] ?? '',
                'answers'      => $answers,
                'respondentId' => $body['respondentId'] ?? null,
                'entityType'   => $body['entityType'] ?? null,
                'entityId'     => $body['entityId'] ?? null,
                'completedAt'  => (new \DateTime())->format('c'),
                'ipHash'       => hash('sha256', $this->request->getRemoteAddress()),
            ];

            $created = $this->getObjectService()->saveObject($registerId, $responseSchemaId, $responseData);
            return new JSONResponse(['message' => 'Thank you for your feedback!', 'id' => $created->getUuid()], Http::STATUS_CREATED);
        } catch (\Exception $e) {
            $this->logger->error('Failed to submit survey response', ['exception' => $e->getMessage()]);
            return new JSONResponse(['error' => 'Failed to submit response'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try
    }//end submit()

    /**
     * Find survey by token.
     *
     * @param string $token The token.
     *
     * @return mixed Survey data or null.
     */
    private function findSurveyByToken(string $token): mixed
    {
        $settings = $this->settingsService->getSettings();
        $regId    = $settings['register'] ?? '';
        $schemaId = $settings['survey_schema'] ?? '';
        if ($regId === '' || $schemaId === '') {
            return null;
        }

        $results = $this->getObjectService()->getObjects($regId, $schemaId, ['token' => $token, '_limit' => 1]);
        $items   = $results['results'] ?? $results ?? [];
        if (empty($items) === true) {
            return null;
        }

        return is_array($items) ? $items[0] : $items;
    }//end findSurveyByToken()
}//end class
