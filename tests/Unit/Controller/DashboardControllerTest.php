<?php

/**
 * Unit tests for DashboardController.
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

use OCA\Pipelinq\Controller\DashboardController;
use OCA\Pipelinq\Service\Settings\MenuStructure;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DashboardController.
 */
class DashboardControllerTest extends TestCase {
	/**
	 * Test page returns TemplateResponse.
	 *
	 * @return void
	 */
	public function testPageReturnsTemplateResponse(): void {
		$request = $this->createMock(IRequest::class);
		$controller = new DashboardController(
			$request,
			$this->createMock(IInitialState::class),
			$this->createMock(IAppConfig::class),
			new MenuStructure(),
			$this->createMock(IAppManager::class),
		);

		$response = $controller->page();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('index', $response->getTemplateName());
	}//end testPageReturnsTemplateResponse()

	/**
	 * The page hands the installed version to the frontend, which the user
	 * settings footer prints (it read "pipelinq 0.1.0" from package.json).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/simple-tour-and-readable-labels/specs/navigation-ia/spec.md#requirement-the-user-settings-show-the-installed-app-version-req-nia-108
	 */
	public function testPageProvidesTheInstalledVersion(): void {
		$provided = [];
		$initialState = $this->createMock(IInitialState::class);
		$initialState->method('provideInitialState')->willReturnCallback(
			function (string $key, mixed $value) use (&$provided): void {
				$provided[$key] = $value;
			}
		);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->expects($this->once())
			->method('getAppVersion')
			->with('pipelinq')
			->willReturn('0.5.13-beta');

		$controller = new DashboardController(
			$this->createMock(IRequest::class),
			$initialState,
			$this->createMock(IAppConfig::class),
			new MenuStructure(),
			$appManager,
		);
		$controller->page();

		$this->assertSame('0.5.13-beta', ($provided['version'] ?? null));
	}//end testPageProvidesTheInstalledVersion()
}//end class
