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
 * @spec openspec/changes/2026-03-20-kennisbank/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\KennisbankService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Controller for knowledge base public API and feedback.
 *
 * Provides public (unauthenticated) endpoints for citizen-facing article access
 * and authenticated endpoints for agent feedback submission.
 *
 * @spec openspec/changes/2026-03-20-kennisbank/tasks.md#task-2.2
 */
class KennisbankController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest          $request           The request.
     * @param KennisbankService $kennisbankService The kennisbank service.
     * @param IL10N             $l10n              The localization service.
     */
    public function __construct(
        IRequest $request,
        private KennisbankService $kennisbankService,
        private IL10N $l10n,
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
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/changes/2026-03-20-kennisbank/tasks.md#task-2.2
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
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/changes/2026-03-20-kennisbank/tasks.md#task-2.2
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
     * @spec openspec/changes/2026-03-20-kennisbank/tasks.md#task-2.2
     */
    public function submitFeedback(): JSONResponse
    {
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

            return new JSONResponse(
                    [
                        'feedback' => $feedbackData,
                        'schema'   => 'kennisfeedback',
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to submit feedback')],
                500,
            );
        }
    }//end submitFeedback()
}//end class
