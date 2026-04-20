<?php

/**
 * Unit tests for PublicSurveyController.
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

use OCA\Pipelinq\Controller\PublicSurveyController;
use OCA\Pipelinq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\ISession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PublicSurveyController.
 */
class PublicSurveyControllerTest extends TestCase
{
    /**
     * The request mock.
     *
     * @var IRequest&MockObject
     */
    private IRequest $request;

    /**
     * The session mock.
     *
     * @var ISession&MockObject
     */
    private ISession $session;

    /**
     * The container mock.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface $container;

    /**
     * The app manager mock.
     *
     * @var IAppManager&MockObject
     */
    private IAppManager $appManager;

    /**
     * The settings service mock.
     *
     * @var SettingsService&MockObject
     */
    private SettingsService $settingsService;

    /**
     * The logger mock.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request         = $this->createMock(IRequest::class);
        $this->session         = $this->createMock(ISession::class);
        $this->container       = $this->createMock(ContainerInterface::class);
        $this->appManager      = $this->createMock(IAppManager::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger          = $this->createMock(LoggerInterface::class);
    }//end setUp()

    /**
     * Build the controller under test.
     *
     * @return PublicSurveyController
     */
    private function buildController(): PublicSurveyController
    {
        return new PublicSurveyController(
            request: $this->request,
            session: $this->session,
            container: $this->container,
            appManager: $this->appManager,
            settingsService: $this->settingsService,
            logger: $this->logger,
        );
    }//end buildController()

    /**
     * Test that isPasswordProtected always returns false.
     *
     * @return void
     */
    public function testIsPasswordProtectedReturnsFalse(): void
    {
        $controller = $this->buildController();
        $ref        = new \ReflectionMethod($controller, 'isPasswordProtected');
        $ref->setAccessible(true);

        $this->assertFalse($ref->invoke($controller));
    }//end testIsPasswordProtectedReturnsFalse()

    /**
     * Test that isValidToken always returns true.
     *
     * @return void
     */
    public function testIsValidTokenAlwaysReturnsTrue(): void
    {
        $controller = $this->buildController();
        $ref        = new \ReflectionMethod($controller, 'isValidToken');
        $ref->setAccessible(true);

        $this->assertTrue($ref->invoke($controller));
    }//end testIsValidTokenAlwaysReturnsTrue()

    /**
     * Test that show returns 404 when survey is not found.
     *
     * @return void
     */
    public function testShowReturns404WhenSurveyNotFound(): void
    {
        $this->markTestSkipped('See https://github.com/ConductionNL/pipelinq/issues/286 — ObjectService API mismatch.');

        $this->settingsService->method('getSettings')->willReturn([
            'register'      => 'reg-id',
            'survey_schema' => 'schema-id',
        ]);

        $objectServiceMock = $this->getMockBuilder(\stdClass::class)->addMethods(['getObjects'])->getMock();
        $objectServiceMock->method('getObjects')->willReturn(['results' => []]);
        $this->container->method('get')->willReturn($objectServiceMock);
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);

        $response = $this->buildController()->show(token: 'bad-token');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testShowReturns404WhenSurveyNotFound()

    /**
     * Test that show returns 410 for an inactive survey.
     *
     * @return void
     */
    public function testShowReturns410ForInactiveSurvey(): void
    {
        $this->markTestSkipped('See https://github.com/ConductionNL/pipelinq/issues/286 — ObjectService API mismatch.');

        $this->settingsService->method('getSettings')->willReturn([
            'register'      => 'reg-id',
            'survey_schema' => 'schema-id',
        ]);

        $objectServiceMock = $this->getMockBuilder(\stdClass::class)->addMethods(['getObjects'])->getMock();
        $objectServiceMock->method('getObjects')->willReturn([
            'results' => [['id' => '1', 'status' => 'closed', 'token' => 'tok']],
        ]);
        $this->container->method('get')->willReturn($objectServiceMock);
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);

        $response = $this->buildController()->show(token: 'tok');

        $this->assertSame(Http::STATUS_GONE, $response->getStatus());
    }//end testShowReturns410ForInactiveSurvey()

    /**
     * Test that show returns 200 for an active survey.
     *
     * @return void
     */
    public function testShowReturnsActiveSurvey(): void
    {
        $this->markTestSkipped('See https://github.com/ConductionNL/pipelinq/issues/286 — ObjectService API mismatch.');

        $this->settingsService->method('getSettings')->willReturn([
            'register'      => 'reg-id',
            'survey_schema' => 'schema-id',
        ]);

        $objectServiceMock = $this->getMockBuilder(\stdClass::class)->addMethods(['getObjects'])->getMock();
        $objectServiceMock->method('getObjects')->willReturn([
            'results' => [['id' => '1', 'title' => 'My Survey', 'status' => 'active', 'token' => 'tok']],
        ]);
        $this->container->method('get')->willReturn($objectServiceMock);
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);

        $response = $this->buildController()->show(token: 'tok');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('My Survey', $response->getData()['title']);
    }//end testShowReturnsActiveSurvey()

    /**
     * Test that submit returns 400 when answers are missing.
     *
     * @return void
     */
    public function testSubmitReturns400WhenAnswersMissing(): void
    {
        $this->markTestSkipped('See https://github.com/ConductionNL/pipelinq/issues/286 — ObjectService API mismatch.');

        $this->settingsService->method('getSettings')->willReturn([
            'register'                => 'reg-id',
            'survey_schema'           => 'schema-id',
            'surveyResponse_schema'   => 'response-schema-id',
        ]);

        $objectServiceMock = $this->getMockBuilder(\stdClass::class)->addMethods(['getObjects'])->getMock();
        $objectServiceMock->method('getObjects')->willReturn([
            'results' => [['id' => '1', 'status' => 'active', 'token' => 'tok']],
        ]);
        $this->container->method('get')->willReturn($objectServiceMock);
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);

        $this->request->method('getParams')->willReturn([]);

        $response = $this->buildController()->submit(token: 'tok');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testSubmitReturns400WhenAnswersMissing()

    /**
     * Test that submit returns 503 when survey system is not configured.
     *
     * @return void
     */
    public function testSubmitReturns503WhenNotConfigured(): void
    {
        $this->markTestSkipped('See https://github.com/ConductionNL/pipelinq/issues/286 — ObjectService API mismatch.');

        // Survey found but no surveyResponse_schema configured.
        $this->settingsService->method('getSettings')->willReturn([
            'register'      => 'reg-id',
            'survey_schema' => 'schema-id',
        ]);

        $objectServiceMock = $this->getMockBuilder(\stdClass::class)->addMethods(['getObjects'])->getMock();
        $objectServiceMock->method('getObjects')->willReturn([
            'results' => [['id' => '1', 'status' => 'active', 'token' => 'tok']],
        ]);
        $this->container->method('get')->willReturn($objectServiceMock);
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);
        $this->request->method('getParams')->willReturn(['answers' => [['question' => 'Q1', 'answer' => 'A1']]]);
        $this->request->method('getRemoteAddress')->willReturn('127.0.0.1');

        $response = $this->buildController()->submit(token: 'tok');

        $this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
    }//end testSubmitReturns503WhenNotConfigured()
}//end class
