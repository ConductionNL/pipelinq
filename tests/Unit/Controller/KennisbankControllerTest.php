<?php

/**
 * Unit tests for KennisbankController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
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

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\KennisbankController;
use OCA\Pipelinq\Service\KennisbankService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for KennisbankController.
 */
class KennisbankControllerTest extends TestCase
{

    /**
     * The request mock.
     *
     * @var IRequest&MockObject
     */
    private IRequest $request;

    /**
     * The kennisbank service mock.
     *
     * @var KennisbankService&MockObject
     */
    private KennisbankService $kennisbankService;

    /**
     * The localization mock.
     *
     * @var IL10N&MockObject
     */
    private IL10N $l10n;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request           = $this->createMock(IRequest::class);
        $this->kennisbankService = $this->createMock(KennisbankService::class);
        $this->l10n = $this->createMock(IL10N::class);

        $this->l10n->method('t')->willReturnCallback(
            static function (string $text): string {
                return $text;
            },
        );
    }//end setUp()

    /**
     * Build the controller under test.
     *
     * @return KennisbankController
     */
    private function buildController(): KennisbankController
    {
        return new KennisbankController(
            request: $this->request,
            kennisbankService: $this->kennisbankService,
            l10n: $this->l10n,
        );
    }//end buildController()

    /**
     * Test publicIndex returns query parameters.
     *
     * @return void
     */
    public function testPublicIndexReturnsQueryParams(): void
    {
        $this->request->method('getParam')->willReturnMap(
                [
                    ['search', null, 'paspoort'],
                    ['category', null, null],
                    ['limit', '20', '10'],
                    ['offset', '0', '0'],
                ]
                );

        $this->kennisbankService->method('getPublicArticles')->willReturn(
                [
                    'filters'       => ['status' => 'gepubliceerd', 'visibility' => 'openbaar'],
                    'search'        => 'paspoort',
                    'limit'         => 10,
                    'offset'        => 0,
                    'excludeFields' => ['author'],
                ]
                );

        $response = $this->buildController()->publicIndex();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame('paspoort', $response->getData()['search']);
    }//end testPublicIndexReturnsQueryParams()

    /**
     * Test publicShow returns 400 for empty ID.
     *
     * @return void
     */
    public function testPublicShowReturns400ForEmptyId(): void
    {
        $response = $this->buildController()->publicShow(id: '');

        $this->assertSame(400, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
    }//end testPublicShowReturns400ForEmptyId()

    /**
     * Test publicShow returns validation data for valid ID.
     *
     * @return void
     */
    public function testPublicShowReturnsDataForValidId(): void
    {
        $response = $this->buildController()->publicShow(id: 'abc-123');

        $this->assertSame(200, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('abc-123', $data['id']);
        $this->assertSame('gepubliceerd', $data['requiredStatus']);
        $this->assertSame('openbaar', $data['requiredVisibility']);
    }//end testPublicShowReturnsDataForValidId()

    /**
     * Test submitFeedback returns 400 for invalid data.
     *
     * @return void
     */
    public function testSubmitFeedbackReturns400ForInvalidData(): void
    {
        $this->request->method('getParam')->willReturnMap(
                [
                    ['articleId', '', ''],
                    ['rating', '', 'invalid'],
                    ['comment', null, null],
                ]
                );

        $this->kennisbankService->method('validateFeedback')->willReturn(
                [
                    'valid'  => false,
                    'errors' => ['Article ID is required', 'Rating must be "nuttig" or "niet_nuttig"'],
                ]
                );

        $response = $this->buildController()->submitFeedback();

        $this->assertSame(400, $response->getStatus());
        $this->assertCount(2, $response->getData()['errors']);
    }//end testSubmitFeedbackReturns400ForInvalidData()

    /**
     * Test submitFeedback returns feedback data for valid input.
     *
     * @return void
     */
    public function testSubmitFeedbackReturnsDataForValidInput(): void
    {
        $this->request->method('getParam')->willReturnMap(
                [
                    ['articleId', '', 'article-uuid'],
                    ['rating', '', 'nuttig'],
                    ['comment', null, null],
                ]
                );

        $this->kennisbankService->method('validateFeedback')->willReturn(
                [
                    'valid'  => true,
                    'errors' => [],
                ]
                );

        $this->kennisbankService->method('buildFeedbackData')->willReturn(
                [
                    'article' => 'article-uuid',
                    'rating'  => 'nuttig',
                    'agent'   => 'user-01',
                    'status'  => 'nieuw',
                ]
                );

        $response = $this->buildController()->submitFeedback();

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('kennisfeedback', $response->getData()['schema']);
        $this->assertSame('nuttig', $response->getData()['feedback']['rating']);
    }//end testSubmitFeedbackReturnsDataForValidInput()

    /**
     * Test publicIndex returns 500 on exception.
     *
     * @return void
     */
    public function testPublicIndexReturns500OnException(): void
    {
        $this->request->method('getParam')->willThrowException(new \RuntimeException('fail'));

        $response = $this->buildController()->publicIndex();

        $this->assertSame(500, $response->getStatus());
    }//end testPublicIndexReturns500OnException()
}//end class
