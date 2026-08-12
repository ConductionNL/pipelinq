<?php

/**
 * Phase-0 provisioning regression test for SettingsLoadService.
 *
 * Locks that SettingsLoadService::SCHEMA_SLUGS includes the POS object types
 * (posTransaction / posTransactionLine) and billingCategory, so that
 * loadSettings() writes their `<slug>_schema` app-config keys when the
 * OpenRegister import returns those schemas. Without these slugs in the list the
 * POS register is imported but never linked, leaving the `*_schema` config keys
 * blank — the deployed-box state the runtime slug-fallback was added to survive.
 *
 * SCHEMA_SLUGS is private, so this asserts the behaviour (the config keys get
 * written) rather than the constant directly — a stronger lock that the slug is
 * actually provisioned end-to-end through loadSettings().
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCA\Pipelinq\Service\SettingsLoadService;
use OCA\Pipelinq\Service\SettingsMapBuilder;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests that loadSettings() provisions the POS + billingCategory schema keys.
 */
class SettingsLoadServicePosSchemasTest extends TestCase {
	/**
	 * Writes the `<slug>_schema` app-config key for billingCategory and the POS
	 * object types when the OpenRegister import returns them.
	 *
	 * @return void
	 */
	public function testProvisionsPosAndBillingSchemaConfigKeys(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('1.0.0');

		$fileLoader = $this->createMock(ConfigFileLoaderService::class);
		$fileLoader->method('loadConfigurationFile')->willReturn(['stub' => true]);
		$fileLoader->method('ensureSourceType')->willReturnArgument(0);

		// Real map builder so the slug => id map is derived exactly as production.
		$mapBuilder = new SettingsMapBuilder();

		// The import returns the pipelinq register and the three schemas under
		// test (plus an unrelated one to prove only listed slugs are written).
		$configService = new class {
			/**
			 * Echo a fixed import result for the test.
			 *
			 * @param string $appId The app id.
			 * @param array<string, mixed> $data The config data.
			 * @param string $version The app version.
			 * @param bool $force Force flag.
			 *
			 * @return array<string, mixed>
			 */
			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				return [
					'registers' => [
						['slug' => 'pipelinq', 'id' => 16],
					],
					'schemas' => [
						['slug' => 'billingCategory', 'id' => 30],
						['slug' => 'posTransaction', 'id' => 41],
						['slug' => 'posTransactionLine', 'id' => 42],
						['slug' => 'notProvisioned', 'id' => 99],
					],
					'views' => [],
				];
			}//end importFromApp()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($configService);

		// Capture every setValueString(app, key, value) call.
		$written = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$written): bool {
				$written[$key] = $value;
				return true;
			}
		);

		$service = new SettingsLoadService($appConfig, $appManager, $container, $mapBuilder, $fileLoader);
		$service->loadSettings();

		// The billingCategory + POS types are provisioned with their numeric ids.
		$this->assertArrayHasKey('billingCategory_schema', $written);
		$this->assertSame('30', $written['billingCategory_schema']);
		$this->assertArrayHasKey('posTransaction_schema', $written);
		$this->assertSame('41', $written['posTransaction_schema']);
		$this->assertArrayHasKey('posTransactionLine_schema', $written);
		$this->assertSame('42', $written['posTransactionLine_schema']);

		// The register id is provisioned too.
		$this->assertSame('16', $written['register']);

		// A schema slug NOT in SCHEMA_SLUGS is never written.
		$this->assertArrayNotHasKey('notProvisioned_schema', $written);
	}//end testProvisionsPosAndBillingSchemaConfigKeys()
}//end class
