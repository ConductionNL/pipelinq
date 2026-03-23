<?php
declare(strict_types=1);
namespace OCA\Pipelinq\Tests\Unit\Settings;

use OCA\Pipelinq\Service\SettingsService;
use OCA\Pipelinq\Settings\AdminSettings;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use PHPUnit\Framework\TestCase;

class AdminSettingsTest extends TestCase
{
    public function testGetFormReturnsTemplateResponse(): void
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getSettings')->willReturn([]);
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getAppVersion')->willReturn('1.0.0');
        $admin = new AdminSettings($settingsService, $appManager);
        $this->assertInstanceOf(TemplateResponse::class, $admin->getForm());
    }
    public function testGetSectionReturnsPipelinq(): void
    {
        $settingsService = $this->createMock(SettingsService::class);
        $appManager = $this->createMock(IAppManager::class);
        $admin = new AdminSettings($settingsService, $appManager);
        $this->assertSame('pipelinq', $admin->getSection());
    }
    public function testGetPriorityReturnsInt(): void
    {
        $settingsService = $this->createMock(SettingsService::class);
        $appManager = $this->createMock(IAppManager::class);
        $admin = new AdminSettings($settingsService, $appManager);
        $this->assertIsInt($admin->getPriority());
    }
}
