<?php

/**
 * Unit tests for KennisbankService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\KennisbankService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for KennisbankService.
 */
class KennisbankServiceTest extends TestCase
{

    /**
     * The user session mock.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession $userSession;

    /**
     * The logger mock.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * The service under test.
     *
     * @var KennisbankService
     */
    private KennisbankService $service;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger      = $this->createMock(LoggerInterface::class);

        $this->service = new KennisbankService(
            userSession: $this->userSession,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Test getPublicArticles returns correct filters with defaults.
     *
     * @return void
     */
    public function testGetPublicArticlesReturnsDefaultFilters(): void
    {
        $result = $this->service->getPublicArticles();

        $this->assertSame('gepubliceerd', $result['filters']['status']);
        $this->assertSame('openbaar', $result['filters']['visibility']);
        $this->assertNull($result['search']);
        $this->assertSame(20, $result['limit']);
        $this->assertSame(0, $result['offset']);
    }//end testGetPublicArticlesReturnsDefaultFilters()

    /**
     * Test getPublicArticles with search query and category.
     *
     * @return void
     */
    public function testGetPublicArticlesWithSearchAndCategory(): void
    {
        $result = $this->service->getPublicArticles(
            search: 'paspoort',
            category: 'cat-uuid',
            limit: 10,
            offset: 5,
        );

        $this->assertSame('paspoort', $result['search']);
        $this->assertSame('cat-uuid', $result['filters']['categories']);
        $this->assertSame(10, $result['limit']);
        $this->assertSame(5, $result['offset']);
    }//end testGetPublicArticlesWithSearchAndCategory()

    /**
     * Test getPublicArticles excludes empty category.
     *
     * @return void
     */
    public function testGetPublicArticlesIgnoresEmptyCategory(): void
    {
        $result = $this->service->getPublicArticles(category: '');

        $this->assertArrayNotHasKey('categories', $result['filters']);
    }//end testGetPublicArticlesIgnoresEmptyCategory()

    /**
     * Test stripInternalFields removes the correct fields.
     *
     * @return void
     */
    public function testStripInternalFieldsRemovesCorrectFields(): void
    {
        $article = [
            'id'              => '1',
            'title'           => 'Article',
            'body'            => 'Content',
            'author'          => 'secret-user',
            'lastUpdatedBy'   => 'another-user',
            'zaaktypeLinks'   => ['link1'],
            'usefulnessScore' => 85.5,
        ];

        $stripped = $this->service->stripInternalFields($article);

        $this->assertArrayHasKey('id', $stripped);
        $this->assertArrayHasKey('title', $stripped);
        $this->assertArrayHasKey('body', $stripped);
        $this->assertArrayNotHasKey('author', $stripped);
        $this->assertArrayNotHasKey('lastUpdatedBy', $stripped);
        $this->assertArrayNotHasKey('zaaktypeLinks', $stripped);
        $this->assertArrayNotHasKey('usefulnessScore', $stripped);
    }//end testStripInternalFieldsRemovesCorrectFields()

    /**
     * Test validateFeedback with valid data.
     *
     * @return void
     */
    public function testValidateFeedbackWithValidData(): void
    {
        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);

        $result = $this->service->validateFeedback(
            articleId: 'article-uuid',
            rating: 'nuttig',
        );

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }//end testValidateFeedbackWithValidData()

    /**
     * Test validateFeedback with invalid data.
     *
     * @return void
     */
    public function testValidateFeedbackWithInvalidData(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $result = $this->service->validateFeedback(
            articleId: '',
            rating: 'invalid',
        );

        $this->assertFalse($result['valid']);
        $this->assertCount(3, $result['errors']);
    }//end testValidateFeedbackWithInvalidData()

    /**
     * Test buildFeedbackData builds correct object.
     *
     * @return void
     */
    public function testBuildFeedbackDataBuildsCorrectObject(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('agent-01');
        $this->userSession->method('getUser')->willReturn($user);

        $data = $this->service->buildFeedbackData(
            articleId: 'article-uuid',
            rating: 'niet_nuttig',
            comment: 'Tarieven kloppen niet',
        );

        $this->assertSame('article-uuid', $data['article']);
        $this->assertSame('niet_nuttig', $data['rating']);
        $this->assertSame('agent-01', $data['agent']);
        $this->assertSame('nieuw', $data['status']);
        $this->assertSame('Tarieven kloppen niet', $data['comment']);
    }//end testBuildFeedbackDataBuildsCorrectObject()

    /**
     * Test buildFeedbackData omits empty comment.
     *
     * @return void
     */
    public function testBuildFeedbackDataOmitsEmptyComment(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('agent-01');
        $this->userSession->method('getUser')->willReturn($user);

        $data = $this->service->buildFeedbackData(
            articleId: 'article-uuid',
            rating: 'nuttig',
            comment: '   ',
        );

        $this->assertArrayNotHasKey('comment', $data);
    }//end testBuildFeedbackDataOmitsEmptyComment()

    /**
     * Test calculateUsefulnessScore with various inputs.
     *
     * @return void
     */
    public function testCalculateUsefulnessScoreWithZeroRatings(): void
    {
        $this->assertSame(0.0, $this->service->calculateUsefulnessScore(0, 0));
    }//end testCalculateUsefulnessScoreWithZeroRatings()

    /**
     * Test calculateUsefulnessScore with all positive ratings.
     *
     * @return void
     */
    public function testCalculateUsefulnessScoreAllPositive(): void
    {
        $this->assertSame(100.0, $this->service->calculateUsefulnessScore(50, 0));
    }//end testCalculateUsefulnessScoreAllPositive()

    /**
     * Test calculateUsefulnessScore with mixed ratings.
     *
     * @return void
     */
    public function testCalculateUsefulnessScoreMixed(): void
    {
        $this->assertSame(90.0, $this->service->calculateUsefulnessScore(45, 5));
    }//end testCalculateUsefulnessScoreMixed()

    /**
     * Test calculateUsefulnessScore with all negative ratings.
     *
     * @return void
     */
    public function testCalculateUsefulnessScoreAllNegative(): void
    {
        $this->assertSame(0.0, $this->service->calculateUsefulnessScore(0, 10));
    }//end testCalculateUsefulnessScoreAllNegative()
}//end class
