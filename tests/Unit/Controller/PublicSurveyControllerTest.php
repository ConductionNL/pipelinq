<?php

/**
 * Unit tests for PublicSurveyController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
     * Build an ObjectService mock with findAll returning the given items.
     *
     * @param array<int, mixed> $items Items to return in findAll.
     *
     * @return \OCA\OpenRegister\Service\ObjectService&MockObject The mock.
     */
    private function buildObjectServiceMock(array $items): \OCA\OpenRegister\Service\ObjectService
    {
        $mock       = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $entityMock = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
        $entityMock->method('getUuid')->willReturn('new-response-uuid');
        $mock->method('findAll')->willReturn(['results' => $items]);
        $mock->method('saveObject')->willReturn($entityMock);
        return $mock;
    }//end buildObjectServiceMock()

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
        $this->settingsService->method('getSettings')->willReturn([
            'register'      => 'reg-id',
            'survey_schema' => 'schema-id',
        ]);

        $objectServiceMock = $this->buildObjectServiceMock([]);
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
        $this->settingsService->method('getSettings')->willReturn([
            'register'      => 'reg-id',
            'survey_schema' => 'schema-id',
        ]);

        $objectServiceMock = $this->buildObjectServiceMock([
            ['id' => '1', 'status' => 'closed', 'token' => 'tok'],
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
        $this->settingsService->method('getSettings')->willReturn([
            'register'      => 'reg-id',
            'survey_schema' => 'schema-id',
        ]);

        $objectServiceMock = $this->buildObjectServiceMock([
            ['id' => '1', 'title' => 'My Survey', 'status' => 'active', 'token' => 'tok'],
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
        $this->settingsService->method('getSettings')->willReturn([
            'register'                => 'reg-id',
            'survey_schema'           => 'schema-id',
            'surveyResponse_schema'   => 'response-schema-id',
        ]);

        $objectServiceMock = $this->buildObjectServiceMock([
            ['id' => '1', 'status' => 'active', 'token' => 'tok'],
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
        // Survey found but no surveyResponse_schema configured.
        $this->settingsService->method('getSettings')->willReturn([
            'register'      => 'reg-id',
            'survey_schema' => 'schema-id',
        ]);

        $objectServiceMock = $this->buildObjectServiceMock([
            ['id' => '1', 'status' => 'active', 'token' => 'tok'],
        ]);
        $this->container->method('get')->willReturn($objectServiceMock);
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);
        $this->request->method('getParams')->willReturn(['answers' => ['some-uuid' => 'yes']]);
        $this->request->method('getRemoteAddress')->willReturn('127.0.0.1');

        $response = $this->buildController()->submit(token: 'tok');

        $this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
    }//end testSubmitReturns503WhenNotConfigured()

    /**
     * Test that the question-ID allowlist strips unknown answer keys when
     * questions have IDs, and retains known keys.
     *
     * The survey has two questions with known UUIDs. The submission includes
     * those two keys plus an extra attacker-injected key. After the allowlist
     * filter only the two known keys must survive in the saved response.
     *
     * @return void
     */
    public function testSubmitAllowlistStripsUnknownAnswerKeys(): void
    {
        $knownId1 = '11111111-1111-1111-1111-111111111111';
        $knownId2 = '22222222-2222-2222-2222-222222222222';
        $unknownId = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

        $survey = [
            'id'        => 'survey-uuid',
            'status'    => 'active',
            'token'     => 'tok',
            'questions' => [
                ['id' => $knownId1, 'type' => 'rating',   'text' => 'Q1'],
                ['id' => $knownId2, 'type' => 'open_text', 'text' => 'Q2'],
            ],
        ];

        $this->settingsService->method('getSettings')->willReturn([
            'register'              => 'reg-id',
            'survey_schema'         => 'schema-id',
            'surveyResponse_schema' => 'response-schema-id',
        ]);

        // Capture the data passed to saveObject so we can assert on it.
        $savedData         = null;
        $entityMock        = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
        $objectServiceMock = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $objectServiceMock->method('findAll')->willReturn(['results' => [$survey]]);
        $objectServiceMock->method('saveObject')
            ->willReturnCallback(
                function () use (&$savedData) {
                    $savedData  = func_get_arg(0);
                    $entityMock = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
                    $entityMock->method('getUuid')->willReturn('new-uuid');
                    return $entityMock;
                }
            );

        $this->container->method('get')->willReturn($objectServiceMock);
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);

        $this->request->method('getRemoteAddress')->willReturn('127.0.0.1');
        $this->request->method('getParams')->willReturn([
            'answers' => [
                $knownId1  => '4',
                $knownId2  => 'Great service!',
                $unknownId => 'injected',
            ],
        ]);

        $response = $this->buildController()->submit(token: 'tok');

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());

        // The saved answers must only contain the two known question IDs.
        $this->assertIsArray($savedData);
        $this->assertArrayHasKey('answers', $savedData);
        $this->assertArrayHasKey($knownId1, $savedData['answers']);
        $this->assertArrayHasKey($knownId2, $savedData['answers']);
        $this->assertArrayNotHasKey($unknownId, $savedData['answers'], 'Unknown answer key must be stripped by the allowlist');
        $this->assertCount(2, $savedData['answers']);
    }//end testSubmitAllowlistStripsUnknownAnswerKeys()

    /**
     * Test that the allowlist is skipped (permissive) when ALL questions lack
     * an 'id' field (legacy survey data).
     *
     * All answer keys must pass through unchanged in this scenario.
     *
     * @return void
     */
    public function testSubmitAllowlistPermissiveForLegacySurveyWithoutQuestionIds(): void
    {
        $survey = [
            'id'        => 'survey-uuid',
            'status'    => 'active',
            'token'     => 'tok',
            'questions' => [
                ['type' => 'rating',    'text' => 'Q1'],
                ['type' => 'open_text', 'text' => 'Q2'],
            ],
        ];

        $this->settingsService->method('getSettings')->willReturn([
            'register'              => 'reg-id',
            'survey_schema'         => 'schema-id',
            'surveyResponse_schema' => 'response-schema-id',
        ]);

        $savedData          = null;
        $entityMock2        = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
        $objectServiceMock2 = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $objectServiceMock2->method('findAll')->willReturn(['results' => [$survey]]);
        $objectServiceMock2->method('saveObject')
            ->willReturnCallback(
                function () use (&$savedData) {
                    $savedData  = func_get_arg(0);
                    $entityMock = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
                    $entityMock->method('getUuid')->willReturn('new-uuid');
                    return $entityMock;
                }
            );

        $this->container->method('get')->willReturn($objectServiceMock2);
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);

        $this->request->method('getRemoteAddress')->willReturn('127.0.0.1');
        $this->request->method('getParams')->willReturn([
            'answers' => [
                'q1' => '4',
                'q2' => 'Great service!',
            ],
        ]);

        $response = $this->buildController()->submit(token: 'tok');

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());

        // All answers must pass through when no question has an id (legacy).
        $this->assertIsArray($savedData);
        $this->assertArrayHasKey('answers', $savedData);
        $this->assertCount(2, $savedData['answers']);
        $this->assertArrayHasKey('q1', $savedData['answers']);
        $this->assertArrayHasKey('q2', $savedData['answers']);
    }//end testSubmitAllowlistPermissiveForLegacySurveyWithoutQuestionIds()
}//end class
