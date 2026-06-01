<?php

/**
 * SettingsController Unit Tests
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/admin-settings/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Controller\SettingsController;
use OCA\Pipelinq\Service\ApiAuthService;
use OCA\Pipelinq\Service\ObjectenAccessService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SettingsController.
 *
 * @spec openspec/changes/admin-settings/tasks.md#task-3
 */
class SettingsControllerTest extends TestCase
{

    private SettingsController $controller;

    private IRequest&MockObject $request;

    private IAppConfig&MockObject $appConfig;

    private IGroupManager&MockObject $groupManager;

    private IUserSession&MockObject $userSession;

    private ApiAuthService&MockObject $apiAuthService;

    private ObjectenAccessService&MockObject $objectenAccessService;

    private LoggerInterface&MockObject $logger;


    protected function setUp(): void
    {
        parent::setUp();

        $this->request               = $this->createMock(IRequest::class);
        $this->appConfig             = $this->createMock(IAppConfig::class);
        $this->groupManager          = $this->createMock(IGroupManager::class);
        $this->userSession           = $this->createMock(IUserSession::class);
        $this->apiAuthService        = $this->createMock(ApiAuthService::class);
        $this->objectenAccessService = $this->createMock(ObjectenAccessService::class);
        $this->logger                = $this->createMock(LoggerInterface::class);

        $this->controller = new SettingsController(
            appName: Application::APP_ID,
            request: $this->request,
            appConfig: $this->appConfig,
            groupManager: $this->groupManager,
            userSession: $this->userSession,
            apiAuthService: $this->apiAuthService,
            objectenAccessService: $this->objectenAccessService,
            logger: $this->logger,
        );

    }//end setUp()


    /**
     * Test that index returns combined settings including objectenAccess, apiTokens, oauthConfig, mcpConfig.
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-3.5
     */
    public function testIndexReturnsAllSettings(): void
    {
        $this->objectenAccessService->method('getAccessMap')->willReturn(['client' => ['sales']]);
        $this->apiAuthService->method('listTokens')->willReturn([['id' => 'tok1', 'label' => 'Test', 'created' => '2026-01-01', 'lastUsed' => null]]);
        $this->apiAuthService->method('getOAuthConfig')->willReturn(['clientId' => 'cid', 'hasSecret' => false]);
        $this->apiAuthService->method('getMcpConfig')->willReturn(['endpoint' => 'https://mcp.example.com', 'authMode' => 'apikey']);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertArrayHasKey('objectenAccess', $data);
        $this->assertArrayHasKey('apiTokens', $data);
        $this->assertArrayHasKey('oauthConfig', $data);
        $this->assertArrayHasKey('mcpConfig', $data);

    }//end testIndexReturnsAllSettings()


    /**
     * Test that generateToken returns 400 when label is empty.
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-3.2
     */
    public function testGenerateTokenReturnsBadRequestForEmptyLabel(): void
    {
        $response = $this->controller->generateToken(label: '');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());

    }//end testGenerateTokenReturnsBadRequestForEmptyLabel()


    /**
     * Test that generateToken delegates to service and returns the result.
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-3.2
     */
    public function testGenerateTokenDelegatesToService(): void
    {
        $tokenResult = ['id' => 'uuid1', 'token' => 'plaintext', 'label' => 'Test', 'created' => '2026-01-01'];
        $this->apiAuthService->expects($this->once())
            ->method('generateToken')
            ->with(label: 'Test Token')
            ->willReturn($tokenResult);

        $response = $this->controller->generateToken(label: 'Test Token');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals($tokenResult, $response->getData());

    }//end testGenerateTokenDelegatesToService()


    /**
     * Test that revokeToken delegates to service.
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-3.2
     */
    public function testRevokeTokenDelegatesToService(): void
    {
        $this->apiAuthService->expects($this->once())
            ->method('revokeToken')
            ->with(id: 'token-uuid-1');

        $response = $this->controller->revokeToken(id: 'token-uuid-1');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());

    }//end testRevokeTokenDelegatesToService()


    /**
     * Test that saveOAuth delegates to ApiAuthService and returns oauthConfig without secret.
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-3.3
     */
    public function testSaveOAuthDelegatesToService(): void
    {
        $this->apiAuthService->expects($this->once())
            ->method('saveOAuthConfig')
            ->with(config: $this->arrayHasKey('clientId'));

        $this->apiAuthService->method('getOAuthConfig')
            ->willReturn(['clientId' => 'cid', 'hasSecret' => true]);

        $response = $this->controller->saveOAuth(
            clientId: 'cid',
            clientSecret: 'secret',
            tokenEndpoint: 'https://auth.example.com/token',
            authEndpoint: 'https://auth.example.com/authorize',
            scopes: 'openid',
            idTokenForwarding: true,
        );

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertArrayHasKey('oauthConfig', $data);
        $this->assertArrayNotHasKey('clientSecret', $data['oauthConfig']);

    }//end testSaveOAuthDelegatesToService()


    /**
     * Test that saveObjectenAccess returns 400 for missing schemaSlug.
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-3.1
     */
    public function testSaveObjectenAccessReturnsBadRequestForEmptySlug(): void
    {
        $response = $this->controller->saveObjectenAccess(schemaSlug: '');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());

    }//end testSaveObjectenAccessReturnsBadRequestForEmptySlug()


    /**
     * Test that saveMcp delegates to ApiAuthService and returns mcpConfig without secrets.
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-3.4
     */
    public function testSaveMcpDelegatesToService(): void
    {
        $this->apiAuthService->expects($this->once())
            ->method('saveMcpConfig');

        $this->apiAuthService->method('getMcpConfig')
            ->willReturn(['endpoint' => 'https://mcp.example.com', 'authMode' => 'apikey', 'hasApiKey' => true]);

        $response = $this->controller->saveMcp(
            endpoint: 'https://mcp.example.com',
            authMode: 'apikey',
            apiKey: 'secret',
        );

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertArrayHasKey('mcpConfig', $data);
        $this->assertArrayNotHasKey('apiKey', $data['mcpConfig']);

    }//end testSaveMcpDelegatesToService()


}//end class
