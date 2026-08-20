<?php

/**
 * Unit tests for GiftCardService's fail-closed handling of an unconfigured
 * register / giftCard_schema.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\GiftCardService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * An empty register or schema id must never reach OpenRegister.
 *
 * ObjectService skips setRegister()/setSchema() for an empty value, so a call
 * carrying '' does not fail — it runs under whatever register/schema context
 * an earlier call in the same request left on the shared, request-scoped
 * instance. The refusal is therefore asserted on the ObjectService itself
 * never being reached, not merely on the return value.
 */
class GiftCardServiceConfigGuardTest extends TestCase {
	/**
	 * Build the service over a config map.
	 *
	 * @param array<string, string> $config The app-config contents.
	 * @param ObjectServiceInterface $object The OpenRegister ObjectService mock.
	 *
	 * @return GiftCardService
	 */
	private function buildService(array $config, ObjectServiceInterface $object): GiftCardService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($object);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($config): string {
				return ($config[$key] ?? $default);
			}
		);

		return new GiftCardService($appConfig,
			$this->createMock(LoggerInterface::class),
			objectService: $object,
		);
	}//end buildService()

	/**
	 * A missing register refuses the read and never reaches OpenRegister.
	 *
	 * @return void
	 */
	public function testMissingRegisterRefusesWithoutTouchingOpenRegister(): void {
		$object = $this->createMock(ObjectServiceInterface::class);
		$object->expects($this->never())->method('find');
		$object->expects($this->never())->method('findAll');
		$object->expects($this->never())->method('saveObject');

		$service = $this->buildService(['giftCard_schema' => 'sch-card'], $object);

		$this->assertNull($service->getCard('card-1'));
	}//end testMissingRegisterRefusesWithoutTouchingOpenRegister()

	/**
	 * A missing schema refuses the read and never reaches OpenRegister.
	 *
	 * @return void
	 */
	public function testMissingSchemaRefusesWithoutTouchingOpenRegister(): void {
		$object = $this->createMock(ObjectServiceInterface::class);
		$object->expects($this->never())->method('find');
		$object->expects($this->never())->method('findAll');
		$object->expects($this->never())->method('saveObject');

		$service = $this->buildService(['register' => 'reg-1'], $object);

		$this->assertNull($service->getCard('card-1'));
	}//end testMissingSchemaRefusesWithoutTouchingOpenRegister()

	/**
	 * A blank register is logged, so an unprovisioned instance is visible
	 * rather than degrading to a silent empty surface.
	 *
	 * @return void
	 */
	public function testUnconfiguredRegisterIsLogged(): void {
		$object = $this->createMock(ObjectServiceInterface::class);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($object);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				return $default;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->atLeastOnce())->method('warning');

		$service = new GiftCardService($appConfig, $logger,
			objectService: $object,
		);

		$this->assertNull($service->getCard('card-1'));
	}//end testUnconfiguredRegisterIsLogged()

	/**
	 * A fully configured instance does reach OpenRegister — the negative
	 * assertions above would otherwise pass for the wrong reason.
	 *
	 * @return void
	 */
	public function testConfiguredInstanceReachesOpenRegister(): void {
		$object = $this->createMock(ObjectServiceInterface::class);
		$object->expects($this->once())->method('find')->willReturn(null);

		$service = $this->buildService(
			[
				'register' => 'reg-1',
				'giftCard_schema' => 'sch-card',
			],
			$object
		);

		$this->assertNull($service->getCard('card-1'));
	}//end testConfiguredInstanceReachesOpenRegister()
}//end class
