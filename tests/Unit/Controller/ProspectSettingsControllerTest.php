<?php

/**
 * Unit tests for ProspectSettingsController.
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

use OCA\Pipelinq\Controller\ProspectSettingsController;
use OCA\Pipelinq\Service\IcpConfigService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for ProspectSettingsController.
 */
class ProspectSettingsControllerTest extends TestCase
{
    /**
     * The controller under test.
     *
     * @var ProspectSettingsController
     */
    private ProspectSettingsController $controller;

    /**
     * Mock ICP config service.
     *
     * @var IcpConfigService
     */
    private IcpConfigService $icpConfig;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $request         = $this->createMock(IRequest::class);
        $container       = $this->createMock(ContainerInterface::class);
        $appManager      = $this->createMock(IAppManager::class);
        $this->icpConfig = $this->createMock(IcpConfigService::class);

        $this->controller = new ProspectSettingsController(
            $request,
            $container,
            $appManager,
            $this->icpConfig,
        );
    }//end setUp()

    /**
     * Test index returns ICP settings.
     *
     * @return void
     */
    public function testIndexReturnsSettings(): void
    {
        $this->icpConfig->method('getSettings')->willReturn([
            'sbiCodes' => ['62'],
        ]);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertSame(['62'], $data['sbiCodes']);
    }//end testIndexReturnsSettings()

    /**
     * Test update returns saved status.
     *
     * @return void
     */
    public function testUpdateReturnsSaved(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParams')->willReturn(['sbiCodes' => ['62']]);
        $this->icpConfig->method('saveSettings')->willReturn('abc12345');

        $container  = $this->createMock(ContainerInterface::class);
        $appManager = $this->createMock(IAppManager::class);

        $controller = new ProspectSettingsController(
            $request, $container, $appManager, $this->icpConfig
        );

        $response = $controller->update();

        $data = $response->getData();
        $this->assertSame('saved', $data['status']);
        $this->assertSame('abc12345', $data['icpHash']);
    }//end testUpdateReturnsSaved()

    /**
     * Test update returns 500 on exception.
     *
     * @return void
     */
    public function testUpdateReturns500OnException(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParams')->willReturn([]);
        $this->icpConfig->method('saveSettings')
            ->willThrowException(new \RuntimeException('Save failed'));

        $container  = $this->createMock(ContainerInterface::class);
        $appManager = $this->createMock(IAppManager::class);

        $controller = new ProspectSettingsController(
            $request, $container, $appManager, $this->icpConfig
        );

        $response = $controller->update();

        $this->assertSame(500, $response->getStatus());
    }//end testUpdateReturns500OnException()
}//end class
