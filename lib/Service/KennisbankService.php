<?php

/**
 * Pipelinq KennisbankService.
 *
 * Service for knowledge base article queries and feedback management.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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

namespace OCA\Pipelinq\Service;

use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Service for knowledge base operations.
 *
 * Handles public article queries, feedback submission, and score recalculation.
 * Articles are stored as OpenRegister objects with the kennisartikel schema.
 */
class KennisbankService
{
    /**
     * Fields to exclude from public API responses.
     *
     * @var array<string>
     */
    private const PUBLIC_EXCLUDED_FIELDS = [
        'author',
        'lastUpdatedBy',
        'zaaktypeLinks',
        'usefulnessScore',
    ];

    /**
     * Constructor.
     *
     * @param IUserSession    $userSession The user session.
     * @param LoggerInterface $logger      The logger.
     */
    public function __construct(
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get published public articles.
     *
     * Returns articles with status=gepubliceerd and visibility=openbaar,
     * with internal fields stripped for public consumption.
     *
     * @param string|null $search   Search query string.
     * @param string|null $category Category UUID filter.
     * @param int         $limit    Maximum results to return.
     * @param int         $offset   Offset for pagination.
     *
     * @return array{articles: array<mixed>, total: int} Articles and total count.
     */
    public function getPublicArticles(
        ?string $search = null,
        ?string $category = null,
        int $limit = 20,
        int $offset = 0,
    ): array {
        // This method returns the filter parameters for use by the controller.
        // The actual OpenRegister query is performed by the controller using
        // the ObjectService or direct API calls.
        $filters = [
            'status' => 'gepubliceerd',
            'visibility' => 'openbaar',
        ];

        if ($category !== null && $category !== '') {
            $filters['categories'] = $category;
        }

        return [
            'filters' => $filters,
            'search' => $search,
            'limit' => $limit,
            'offset' => $offset,
            'excludeFields' => self::PUBLIC_EXCLUDED_FIELDS,
        ];
    }//end getPublicArticles()

    /**
     * Strip internal fields from an article for public display.
     *
     * @param array<string, mixed> $article The article data.
     *
     * @return array<string, mixed> The article with internal fields removed.
     */
    public function stripInternalFields(array $article): array
    {
        foreach (self::PUBLIC_EXCLUDED_FIELDS as $field) {
            unset($article[$field]);
        }

        return $article;
    }//end stripInternalFields()

    /**
     * Validate feedback data.
     *
     * @param string      $articleId The article UUID.
     * @param string      $rating    The rating value (nuttig or niet_nuttig).
     * @param string|null $comment   Optional improvement suggestion.
     *
     * @return array{valid: bool, errors: array<string>} Validation result.
     */
    public function validateFeedback(
        string $articleId,
        string $rating,
        ?string $comment = null,
    ): array {
        $errors = [];

        if (trim($articleId) === '') {
            $errors[] = 'Article ID is required';
        }

        if (in_array($rating, ['nuttig', 'niet_nuttig'], true) === false) {
            $errors[] = 'Rating must be "nuttig" or "niet_nuttig"';
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            $errors[] = 'Authentication required';
        }

        return [
            'valid' => count($errors) === 0,
            'errors' => $errors,
        ];
    }//end validateFeedback()

    /**
     * Build feedback object data for OpenRegister storage.
     *
     * @param string      $articleId The article UUID.
     * @param string      $rating    The rating value.
     * @param string|null $comment   Optional improvement suggestion.
     *
     * @return array<string, mixed> The feedback object data.
     */
    public function buildFeedbackData(
        string $articleId,
        string $rating,
        ?string $comment = null,
    ): array {
        $user = $this->userSession->getUser();

        $data = [
            'article' => $articleId,
            'rating' => $rating,
            'agent' => $user !== null ? $user->getUID() : '',
            'status' => 'nieuw',
        ];

        if ($comment !== null && trim($comment) !== '') {
            $data['comment'] = $comment;
        }

        return $data;
    }//end buildFeedbackData()

    /**
     * Calculate the usefulness score for an article based on feedback.
     *
     * @param int $positiveCount Number of "nuttig" ratings.
     * @param int $negativeCount Number of "niet_nuttig" ratings.
     *
     * @return float The usefulness score as a percentage (0-100).
     */
    public function calculateUsefulnessScore(
        int $positiveCount,
        int $negativeCount,
    ): float {
        $total = $positiveCount + $negativeCount;

        if ($total === 0) {
            return 0.0;
        }

        return round(($positiveCount / $total) * 100, 1);
    }//end calculateUsefulnessScore()
}//end class
