<?php

/**
 * Pins the OpenRegister service lookups the settings controllers expose.
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\Pipelinq\Controller\ProspectSettingsController;
use OCA\Pipelinq\Controller\SettingsController;
use OCA\Pipelinq\Service\IcpConfigService;
use OCA\Pipelinq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Both settings controllers answer the same way, installed or not.
 */
class SettingsServiceLookupTest extends TestCase {

	/**
	 * The two controllers under test.
	 *
	 * @return array<string, array{string}>
	 */
	public static function controllers(): array {
		return [
			'settings' => ['settings'],
			'prospect' => ['prospect'],
		];
	}//end controllers()

	/**
	 * Builds one controller over the given installed apps and container.
	 *
	 * @param string             $which     Which controller.
	 * @param array<int, string> $installed Installed app ids.
	 * @param ContainerInterface $container The container.
	 *
	 * @return SettingsController|ProspectSettingsController
	 */
	private function controller(string $which, array $installed, ContainerInterface $container): object {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn($installed);
		$request = $this->createMock(IRequest::class);
		if ($which === 'prospect') {
			return new ProspectSettingsController(
				$request,
				$container,
				$appManager,
				$this->createMock(IcpConfigService::class),
			);
		}

		return new SettingsController(
			request: $request,
			container: $container,
			appManager: $appManager,
			groupManager: $this->createMock(IGroupManager::class),
			settingsService: $this->createMock(SettingsService::class),
			userSession: $this->createMock(IUserSession::class),
			l10n: $this->createMock(IL10N::class),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end controller()

	/**
	 * With OpenRegister installed, each getter asks the container once, by name.
	 *
	 * @param string $which Which controller.
	 *
	 * @return void
	 */
	#[DataProvider('controllers')]
	public function testInstalledReturnsTheContainerServices(string $which): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$config  = $this->createMock(ConfigurationService::class);
		$asked   = [];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use (&$asked, $objects, $config) {
				$asked[] = $id;
				return $id === 'OCA\OpenRegister\Service\ObjectService' ? $objects : $config;
			}
		);
		$controller = $this->controller($which, ['files', 'openregister'], $container);

		$this->assertSame($objects, $controller->getObjectService());
		$this->assertSame($config, $controller->getConfigurationService());
		$this->assertSame($objects, $controller->getObjectService());
		$this->assertSame(
			[
				'OCA\OpenRegister\Service\ObjectService',
				'OCA\OpenRegister\Service\ConfigurationService',
				'OCA\OpenRegister\Service\ObjectService',
			],
			$asked
		);
	}//end testInstalledReturnsTheContainerServices()

	/**
	 * Without OpenRegister the object getter throws its message and never touches the container.
	 *
	 * @param string $which Which controller.
	 *
	 * @return void
	 */
	#[DataProvider('controllers')]
	public function testObjectServiceRefusedWithoutOpenRegister(string $which): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$controller = $this->controller($which, ['files'], $container);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister service is not available.');
		$controller->getObjectService();
	}//end testObjectServiceRefusedWithoutOpenRegister()

	/**
	 * Without OpenRegister the configuration getter throws its own message.
	 *
	 * @param string $which Which controller.
	 *
	 * @return void
	 */
	#[DataProvider('controllers')]
	public function testConfigurationServiceRefusedWithoutOpenRegister(string $which): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$controller = $this->controller($which, [], $container);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Configuration service is not available.');
		$controller->getConfigurationService();
	}//end testConfigurationServiceRefusedWithoutOpenRegister()
}//end class
