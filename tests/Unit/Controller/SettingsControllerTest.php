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
     * Test that the settings read payload no longer carries the removed
     * REST API token / OAuth admin maps (remove-dead-rest-api-auth).
     *
     * @return void
     *
     * @spec openspec/changes/remove-dead-rest-api-auth/tasks.md#task-4.2
     */
    public function testIndexExcludesRemovedTokenAndOauthMaps(): void
    {
        $this->settingsService->method('getSettings')->willReturn(['register' => '1']);

        $response = $this->controller->index();

        $data = $response->getData();
        $this->assertArrayNotHasKey(key: 'apiTokens', array: $data);
        $this->assertArrayNotHasKey(key: 'oauthConfig', array: $data);
    }//end testIndexExcludesRemovedTokenAndOauthMaps()
}//end class
