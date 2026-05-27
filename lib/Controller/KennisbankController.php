<?php

/**
 * Pipelinq KennisbankController.
 *
 * Controller for knowledge base public API and feedback endpoints.
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-32
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-33
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\KennisbankService;
use OCA\Pipelinq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for knowledge base public API and feedback.
 *
 * Provides public (unauthenticated) endpoints for citizen-facing article access
 * and authenticated endpoints for agent feedback submission.
 */
class KennisbankController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest           $request           The request.
     * @param KennisbankService  $kennisbankService The kennisbank service.
     * @param IUserSession       $userSession       The user session.
     * @param IL10N              $l10n              The localization service.
     * @param SettingsService    $settingsService   The settings service.
     * @param ContainerInterface $container         The DI container.
     * @param IAppManager        $appManager        The app manager.
     * @param LoggerInterface    $logger            The logger.
     */
    public function __construct(
        IRequest $request,
        private KennisbankService $kennisbankService,
        private IUserSession $userSession,
        private IL10N $l10n,
        private SettingsService $settingsService,
        private ContainerInterface $container,
        private IAppManager $appManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List published public articles.
     *
     * Returns published articles with visibility "openbaar", with internal
     * fields stripped for public consumption.
     *
     * @return JSONResponse The response containing public articles query parameters.
     *
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-32
     */
    public function publicIndex(): JSONResponse
    {
        try {
            $search   = $this->request->getParam('search');
            $category = $this->request->getParam('category');
            $limit    = (int) $this->request->getParam('limit', '20');
            $offset   = (int) $this->request->getParam('offset', '0');

            $queryParams = $this->kennisbankService->getPublicArticles(
                search: $search,
                category: $category,
                limit: $limit,
                offset: $offset,
            );

            return new JSONResponse($queryParams);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to fetch articles')],
                500,
            );
        }
    }//end publicIndex()

    /**
     * Get a single published public article.
     *
     * @param string $id The article object ID.
     *
     * @return JSONResponse The response containing the article.
     *
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-32
     */
    public function publicShow(string $id): JSONResponse
    {
        if (trim($id) === '') {
            return new JSONResponse(
                ['error' => $this->l10n->t('Article ID is required')],
                400,
            );
        }

        // The actual article fetch is done via OpenRegister API.
        // This endpoint provides the validation and field stripping logic.
        return new JSONResponse(
                [
                    'id'                 => $id,
                    'excludeFields'      => ['author', 'lastUpdatedBy', 'zaaktypeLinks', 'usefulnessScore'],
                    'requiredStatus'     => 'gepubliceerd',
                    'requiredVisibility' => 'openbaar',
                ]
                );
    }//end publicShow()

    /**
     * Submit feedback on an article.
     *
     * Creates a kennisfeedback object linked to the article.
     *
     * @return JSONResponse The response containing the feedback data.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-33
     */
    public function submitFeedback(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
        }

        $articleId = $this->request->getParam('articleId', '');
        $rating    = $this->request->getParam('rating', '');
        $comment   = $this->request->getParam('comment');

        $validation = $this->kennisbankService->validateFeedback(
            articleId: $articleId,
            rating: $rating,
            comment: $comment,
        );

        if ($validation['valid'] === false) {
            return new JSONResponse(
                ['errors' => $validation['errors']],
                400,
            );
        }

        try {
            $feedbackData = $this->kennisbankService->buildFeedbackData(
                articleId: $articleId,
                rating: $rating,
                comment: $comment,
            );

            $saved = $this->persistFeedback(feedbackData: $feedbackData);

            return new JSONResponse(
                    [
                        'feedback' => $saved ?? $feedbackData,
                        'schema'   => 'kennisfeedback',
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error('KennisbankController::submitFeedback failed', ['exception' => $e]);
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to submit feedback')],
                500,
            );
        }//end try
    }//end submitFeedback()

    /**
     * Persist feedback data via OpenRegister ObjectService.
     *
     * Returns the saved object or null if OpenRegister is unavailable.
     *
     * @param array $feedbackData The feedback data to persist.
     *
     * @return array|null The saved object or null.
     */
    private function persistFeedback(array $feedbackData): ?array
    {
        if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
            $this->logger->warning('KennisbankController: OpenRegister not available, feedback not persisted');
            return null;
        }

        try {
            $settings = $this->settingsService->getSettings();
            $register = $settings['register'] ?? '';
            $schema   = $settings['kennisfeedback_schema'] ?? '';

            if ($register === '' || $schema === '') {
                $this->logger->warning('KennisbankController: register or kennisfeedback_schema not configured');
                return null;
            }

            /*
             * @var \OCA\OpenRegister\Service\ObjectService $objectService
             */
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            return $objectService->saveObject($feedbackData, [], $register, $schema, null);
        } catch (\Exception $e) {
            $this->logger->error('KennisbankController: failed to persist feedback', ['exception' => $e]);
            return null;
        }//end try
    }//end persistFeedback()
}//end class
