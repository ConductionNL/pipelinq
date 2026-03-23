<?php

/**
 * Unit tests for SettingsController.
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
        $request               = $this->createMock(IRequest::class);
        $container             = $this->createMock(ContainerInterface::class);
        $appManager            = $this->createMock(IAppManager::class);
        $groupManager          = $this->createMock(IGroupManager::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $userSession           = $this->createMock(IUserSession::class);
        $l10n                  = $this->createMock(IL10N::class);

        $appManager->method('getInstalledApps')->willReturn(['openregister']);
        $groupManager->method('isAdmin')->willReturn(true);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $userSession->method('getUser')->willReturn($user);
        $l10n->method('t')->willReturnArgument(0);

        $this->controller = new SettingsController(
            $request,
            $container,
            $appManager,
            $groupManager,
            $this->settingsService,
            $userSession,
            $l10n,
        );
    }//end setUp()

    /**
     * Test index returns settings.
     *
     * @return void
     */
    public function testIndexReturnsSettings(): void
    {
        $this->settingsService->method('getSettings')->willReturn([
            'register' => '1',
        ]);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertTrue($data['isAdmin']);
        $this->assertArrayHasKey('config', $data);
    }//end testIndexReturnsSettings()

    /**
     * Test getUserSettings returns user settings.
     *
     * @return void
     */
    public function testGetUserSettingsReturnsSettings(): void
    {
        $this->settingsService->method('getUserSettings')->willReturn([
            'notify_assignments' => true,
        ]);

        $response = $this->controller->getUserSettings();

        $data = $response->getData();
        $this->assertTrue($data['notify_assignments']);
    }//end testGetUserSettingsReturnsSettings()
}//end class
