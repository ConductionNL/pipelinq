<?php

/**
 * Tests for the OpenRegisterServiceLookup trait: services only when OpenRegister is installed.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;


use OCA\Pipelinq\Controller\OpenRegisterServiceLookup;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\Pipelinq\Controller\OpenRegisterServiceLookup
 */
class OpenRegisterServiceLookupTest extends TestCase {

	/**
	 * With OpenRegister installed the object service comes from the container.
	 *
	 * @return void
	 */
	public function testTheObjectServiceComesFromTheContainer(): void {
		$service = $this->createMock(\OCA\OpenRegister\Contract\ObjectServiceInterface::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->once())
			->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($service);

		$this->assertSame(expected: $service, actual: $this->host(apps: ['files', 'openregister'], container: $container)->getObjectService());
	}//end testTheObjectServiceComesFromTheContainer()

	/**
	 * With OpenRegister installed the configuration service comes from the container.
	 *
	 * @return void
	 */
	public function testTheConfigurationServiceComesFromTheContainer(): void {
		$service = $this->createMock(\OCA\OpenRegister\Service\ConfigurationService::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->once())
			->method('get')
			->with('OCA\OpenRegister\Service\ConfigurationService')
			->willReturn($service);

		$this->assertSame(expected: $service, actual: $this->host(apps: ['openregister'], container: $container)->getConfigurationService());
	}//end testTheConfigurationServiceComesFromTheContainer()

	/**
	 * Without OpenRegister the object service is refused and the container is never asked.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheObjectServiceIsRefused(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister service is not available.');
		$this->host(apps: ['files'], container: $container)->getObjectService();
	}//end testWithoutOpenRegisterTheObjectServiceIsRefused()

	/**
	 * Without OpenRegister the configuration service is refused and the container is never asked.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheConfigurationServiceIsRefused(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Configuration service is not available.');
		$this->host(apps: [], container: $container)->getConfigurationService();
	}//end testWithoutOpenRegisterTheConfigurationServiceIsRefused()

	/**
	 * A class using the trait over the given installed apps and container.
	 *
	 * @param array<int, string> $apps      The installed apps.
	 * @param ContainerInterface $container The container double.
	 *
	 * @return object The trait host.
	 */
	private function host(array $apps, ContainerInterface $container): object {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn($apps);

		return new class($container, $appManager) {
			use OpenRegisterServiceLookup;

			/**
			 * Constructor.
			 *
			 * @param ContainerInterface $container  The container.
			 * @param IAppManager        $appManager The app manager.
			 */
			public function __construct(
				private readonly ContainerInterface $container,
				private readonly IAppManager $appManager,
			) {
			}//end __construct()
		};
	}//end host()
}//end class
