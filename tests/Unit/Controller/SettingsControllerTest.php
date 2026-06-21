<?php

/**
 * Unit tests for SettingsController.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\SettingsController;
use OCA\Pipelinq\Service\ApiAuthService;
use OCA\Pipelinq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for SettingsController.
 */
class SettingsControllerTest extends TestCase
{

    /**
     * The controller under test.
     *
     * @var SettingsController
     */
    private SettingsController $controller;

    /**
     * Mock settings service.
     *
     * @var SettingsService
     */
    private SettingsService $settingsService;

    /**
     * Mock API auth service.
     *
     * @var ApiAuthService
     */
    private ApiAuthService $apiAuthService;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $request      = $this->createMock(originalClassName: IRequest::class);
        $container    = $this->createMock(originalClassName: ContainerInterface::class);
        $appManager   = $this->createMock(originalClassName: IAppManager::class);
        $groupManager = $this->createMock(originalClassName: IGroupManager::class);
        $this->settingsService = $this->createMock(originalClassName: SettingsService::class);
        $this->apiAuthService  = $this->createMock(originalClassName: ApiAuthService::class);
        $userSession           = $this->createMock(originalClassName: IUserSession::class);
        $l10n = $this->createMock(originalClassName: IL10N::class);

        $appManager->method('getInstalledApps')->willReturn(['openregister']);
        $groupManager->method('isAdmin')->willReturn(true);

        $user = $this->createMock(originalClassName: IUser::class);
        $user->method('getUID')->willReturn('admin');
        $userSession->method('getUser')->willReturn($user);
        $l10n->method('t')->willReturnArgument(0);
        $logger = $this->createMock(originalClassName: LoggerInterface::class);

        $this->controller = new SettingsController(
            request: $request,
            container: $container,
            appManager: $appManager,
            groupManager: $groupManager,
            settingsService: $this->settingsService,
            apiAuthService: $this->apiAuthService,
            userSession: $userSession,
            l10n: $l10n,
            logger: $logger,
        );
    }//end setUp()

    /**
     * Test index returns settings.
     *
     * @return void
     */
    public function testIndexReturnsSettings(): void
    {
        $this->settingsService->method('getSettings')->willReturn(['register' => '1']);
        $this->apiAuthService->method('listTokens')->willReturn([]);
        $this->apiAuthService->method('getOAuthConfig')->willReturn([]);

        $response = $this->controller->index();

        $this->assertInstanceOf(expected: JSONResponse::class, actual: $response);
        $data = $response->getData();
        $this->assertTrue(condition: $data['success']);
        $this->assertTrue(condition: $data['isAdmin']);
        $this->assertArrayHasKey(key: 'config', array: $data);
    }//end testIndexReturnsSettings()

    /**
     * Test getUserSettings returns user settings.
     *
     * @return void
     */
    public function testGetUserSettingsReturnsSettings(): void
    {
        $this->settingsService->method('getUserSettings')->willReturn(
                [
                    'notify_assignments' => true,
                ]
                );

        $response = $this->controller->getUserSettings();

        $data = $response->getData();
        $this->assertTrue(condition: $data['notify_assignments']);
    }//end testGetUserSettingsReturnsSettings()

    /**
     * Test listTokens returns token list.
     *
     * @return void
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-3.2
     */
    public function testListTokensReturnsTokenList(): void
    {
        $this->apiAuthService->method('listTokens')->willReturn(
                [
                    ['id' => 'uuid1', 'label' => 'Test', 'created' => '2026-01-01', 'lastUsed' => null],
                ]
                );

        $response = $this->controller->listTokens();

        $this->assertInstanceOf(expected: JSONResponse::class, actual: $response);
        $data = $response->getData();
        $this->assertTrue(condition: $data['success']);
        $this->assertCount(expectedCount: 1, haystack: $data['tokens']);
    }//end testListTokensReturnsTokenList()

    /**
     * Test that index returns admin-only data for admin users.
     *
     * @return void
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-3.5
     */
    public function testIndexIncludesAdminDataForAdmins(): void
    {
        $this->settingsService->method('getSettings')->willReturn(['register' => '1']);
        $this->apiAuthService->method('listTokens')->willReturn([]);
        $this->apiAuthService->method('getOAuthConfig')->willReturn(['oauth_client_id' => '']);

        $response = $this->controller->index();

        $data = $response->getData();
        $this->assertArrayHasKey(key: 'apiTokens', array: $data);
        $this->assertArrayHasKey(key: 'oauthConfig', array: $data);
    }//end testIndexIncludesAdminDataForAdmins()
}//end class
