<?php

/**
 * Pipelinq PublicSurveyController.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Residual after the per-method
 *  complexity was burned down into small single-responsibility validators; the
 *  remaining class WMC is the sum of those readable helpers, not one hot method.
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
        parent::__construct(appName: Application::APP_ID, request: $request, session: $session);
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
    protected function isPasswordProtected(): bool
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
     * @spec                                         openspec/changes/reverse-2026-05-26-be-public-survey/tasks.md#task-1
     */
    public function show(string $token): JSONResponse
    {
        // IsValidToken() is required by PublicShareController and always returns
        // true for this controller (tokens are validated via OR lookup below).
        if ($this->isValidToken() === false) {
            return new JSONResponse(['error' => 'Invalid token'], Http::STATUS_NOT_FOUND);
        }

        try {
            $survey = $this->findSurveyByToken(token: $token);
            if ($survey === null) {
                $resp = new JSONResponse(
                    ['error' => 'Survey not found'],
                    Http::STATUS_NOT_FOUND,
                );
                $resp->throttle();
                return $resp;
            }

            $data = (array) $survey;
            if (is_array($survey) === true) {
                $data = $survey;
            }

            $closed = $this->checkSurveyAcceptingResponses(data: $data);
            if ($closed !== null) {
                return $closed;
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
     * @spec                                                openspec/changes/reverse-2026-05-26-be-public-survey/tasks.md#task-2
     */
    public function submit(string $token): JSONResponse
    {
        try {
            $survey = $this->findSurveyByToken(token: $token);
            if ($survey === null) {
                $resp = new JSONResponse(
                    ['error' => 'Survey not found'],
                    Http::STATUS_NOT_FOUND,
                );
                $resp->throttle();
                return $resp;
            }

            $data = (array) $survey;
            if (is_array($survey) === true) {
                $data = $survey;
            }

            // Replicate the status / activeUntil checks from show() so an
            // expired or inactive survey cannot still accept submissions via a
            // direct POST.
            $closed = $this->checkSurveyAcceptingResponses(data: $data);
            if ($closed !== null) {
                return $closed;
            }

            $answers = $this->extractValidAnswers(data: $data);
            if ($answers instanceof JSONResponse) {
                return $answers;
            }

            $settings         = $this->settingsService->getSettings();
            $registerId       = $settings['register'] ?? '';
            $responseSchemaId = $settings['surveyResponse_schema'] ?? '';
            if ($registerId === '' || $responseSchemaId === '') {
                return new JSONResponse(['error' => 'Survey system is not configured'], Http::STATUS_SERVICE_UNAVAILABLE);
            }

            // RespondentId / entityType / entityId are server-derived or omitted;
            // never trust values from the anonymous submission body.
            $responseData = [
                'surveyId'    => $data['id'] ?? '',
                'answers'     => $answers,
                'completedAt' => (new \DateTime())->format('c'),
                'ipHash'      => hash('sha256', $this->request->getRemoteAddress()),
            ];

            $created = $this->getObjectService()->saveObject(
                $responseData,
                [],
                $registerId,
                $responseSchemaId,
                null,
            );

            return new JSONResponse(
                ['message' => 'Thank you for your feedback!', 'id' => $this->extractCreatedId(created: $created)],
                Http::STATUS_CREATED,
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed to submit survey response', ['exception' => $e->getMessage()]);
            return new JSONResponse(['error' => 'Failed to submit response'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try
    }//end submit()

    /**
     * Determine whether a survey is still accepting responses.
     *
     * Checks the `status` and `activeUntil` fields. Returns a ready-to-send
     * 410 Gone JSONResponse when the survey is closed, or null when it is open.
     *
     * @param array<string, mixed> $data The survey data.
     *
     * @return JSONResponse|null A 410 response when closed, null when open.
     */
    private function checkSurveyAcceptingResponses(array $data): ?JSONResponse
    {
        if (($data['status'] ?? 'draft') !== 'active') {
            return new JSONResponse(
                ['error' => 'This survey is no longer accepting responses'],
                Http::STATUS_GONE,
            );
        }

        $until = $data['activeUntil'] ?? null;
        if ($until !== null && $until !== '' && strtotime($until) < time()) {
            return new JSONResponse(
                ['error' => 'This survey is no longer accepting responses'],
                Http::STATUS_GONE,
            );
        }

        return null;
    }//end checkSurveyAcceptingResponses()

    /**
     * Extract, allowlist and validate the submitted answers.
     *
     * Returns the sanitised answers array on success, or a ready-to-send
     * JSONResponse describing the first validation failure.
     *
     * @param array<string, mixed> $data The survey data (questions allowlist source).
     *
     * @return array<string, mixed>|JSONResponse Sanitised answers, or an error response.
     */
    private function extractValidAnswers(array $data): array|JSONResponse
    {
        $body    = $this->request->getParams();
        $answers = $body['answers'] ?? [];
        if (empty($answers) === true || is_array($answers) === false) {
            return new JSONResponse(['error' => 'Answers are required'], Http::STATUS_BAD_REQUEST);
        }

        // Cap the total answers payload to 64 KiB to prevent DOS-via-blob.
        if (strlen((string) json_encode($answers)) > 65536) {
            return new JSONResponse(['error' => 'Answers payload too large'], Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
        }

        $answers = $this->applyQuestionAllowlist(answers: $answers, data: $data);

        $invalid = $this->validateAnswerValues(answers: $answers);
        if ($invalid !== null) {
            return $invalid;
        }

        return $answers;
    }//end extractValidAnswers()

    /**
     * Enforce per-answer scalar-value caps to prevent nested-blob injection.
     *
     * Flat arrays (multi-select) are allowed but nested objects/arrays are
     * rejected; string values are capped at 4 KiB.
     *
     * @param array<string, mixed> $answers The allowlisted answers.
     *
     * @return JSONResponse|null A 400 response on the first invalid value, null when all valid.
     */
    private function validateAnswerValues(array $answers): ?JSONResponse
    {
        foreach ($answers as $key => $value) {
            if (is_array($value) === true) {
                // Allow flat arrays (multi-select) but forbid nested objects.
                foreach ($value as $item) {
                    if (is_array($item) === true || is_object($item) === true) {
                        return new JSONResponse(['error' => 'Invalid answer value for question '.$key], Http::STATUS_BAD_REQUEST);
                    }
                }

                continue;
            }

            if (is_string($value) === true && strlen($value) > 4096) {
                return new JSONResponse(['error' => 'Answer value too long for question '.$key], Http::STATUS_BAD_REQUEST);
            }
        }

        return null;
    }//end validateAnswerValues()

    /**
     * Strip answers keyed by question IDs the survey does not declare.
     *
     * Unknown keys (attacker-injected fields) are dropped. Legacy surveys where
     * ALL questions lack an 'id' field are handled permissively (allowlist
     * skipped) to preserve backward compatibility. Any survey with at least one
     * question carrying an 'id' engages the allowlist.
     *
     * @param array<string, mixed> $answers The raw submitted answers.
     * @param array<string, mixed> $data    The survey data (questions source).
     *
     * @return array<string, mixed> The allowlisted answers.
     */
    private function applyQuestionAllowlist(array $answers, array $data): array
    {
        $questions       = $data['questions'] ?? [];
        $questionIds     = [];
        $questionsWithId = 0;
        $totalQuestions  = 0;
        if (is_array($questions) === true) {
            foreach ($questions as $question) {
                if (is_array($question) === false) {
                    continue;
                }

                $totalQuestions++;
                if (isset($question['id']) === true) {
                    $questionIds[] = (string) $question['id'];
                    $questionsWithId++;
                }
            }
        }

        // Engage the allowlist only when at least one question carries an ID.
        // If every question lacks an ID (legacy data), fall through permissively.
        $allLegacy = ($totalQuestions > 0 && $questionsWithId === 0);
        if ($allLegacy === false && empty($questionIds) === false) {
            return array_intersect_key($answers, array_flip($questionIds));
        }

        return $answers;
    }//end applyQuestionAllowlist()

    /**
     * Derive the created object's identifier from a saveObject() result.
     *
     * @param mixed $created The object or array returned by saveObject().
     *
     * @return string The UUID/id, or empty string when none could be derived.
     */
    private function extractCreatedId(mixed $created): string
    {
        if (is_object($created) === true && method_exists($created, 'getUuid') === true) {
            return (string) $created->getUuid();
        }

        if (is_array($created) === true) {
            return (string) ($created['id'] ?? $created['uuid'] ?? '');
        }

        return '';
    }//end extractCreatedId()

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

        $results = $this->getObjectService()->findAll(
            [
                'filters' => [
                    'register' => $regId,
                    'schema'   => $schemaId,
                    'token'    => $token,
                ],
                'limit'   => 1,
            ]
        );
        $items   = $results['results'] ?? [];
        if (empty($items) === true) {
            return null;
        }

        if (is_array($items) === true) {
            return $items[0];
        }

        return $items;
    }//end findSurveyByToken()
}//end class
