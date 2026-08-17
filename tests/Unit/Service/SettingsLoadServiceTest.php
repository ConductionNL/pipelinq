<?php

/**
 * Unit tests for SettingsLoadService.
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

use OCA\OpenRegister\Service\ConfigurationService;
use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCA\Pipelinq\Service\SchemaMapService;
use OCA\Pipelinq\Service\SettingsLoadService;
use OCA\Pipelinq\Service\SettingsMapBuilder;
use OCA\Pipelinq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for SettingsLoadService.
 */
class SettingsLoadServiceTest extends TestCase {
	/**
	 * Test loadSettings calls configuration service.
	 *
	 * @return void
	 */
	public function testLoadSettingsCallsConfigurationService(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appManager = $this->createMock(IAppManager::class);
		$mapBuilder = new SettingsMapBuilder();
		$fileLoader = $this->createMock(ConfigFileLoaderService::class);

		$fileLoader->method('loadConfigurationFile')->willReturn(['key' => 'val']);
		$fileLoader->method('ensureSourceType')->willReturnArgument(0);
		$appManager->method('getAppVersion')->willReturn('1.0.0');

		// ConfigurationService is now a constructor-injected, concretely typed
		// dependency (ADR-083) rather than a container lookup, so the in-test
		// anonymous class this used to pass no longer satisfies the type-hint.
		$configService = $this->createMock(ConfigurationService::class);
		$configService->method('importFromApp')->willReturn(
			['registers' => [], 'schemas' => [], 'views' => []]
		);

		$appConfig->method('setValueString');

		$service = new SettingsLoadService(
			appConfig: $appConfig,
			appManager: $appManager,
			mapBuilder: $mapBuilder,
			fileLoader: $fileLoader,
			configurationService: $configService,
		);
		$result = $service->loadSettings();

		$this->assertIsArray($result);
		$this->assertArrayHasKey('registers', $result);
	}//end testLoadSettingsCallsConfigurationService()

	/**
	 * Every reader of the loyalty-account schema must spell the key the writer emits.
	 *
	 * This is the regression the loyalty rename shipped halfway. The writer DERIVES
	 * `<slug>_schema` from the schema slug, so moving the slug to
	 * `customerLoyaltyAccount` moved a PERSISTED app-config key — while three of the
	 * four readers, which spell the key as a bare literal, stayed on
	 * `klantLoyaltyAccount_schema`. Both halves keep compiling and every unit test
	 * keeps passing; the app just reads an empty schema id at runtime and refuses
	 * its own OpenRegister calls. Intent cannot hold a derived key still — only a
	 * pin can, which is why SCHEMA_CONFIG_KEYS exists.
	 *
	 * A quoted-token search does not catch it either: `klantLoyaltyAccount_schema`
	 * is a bare identifier key, and `\b` does not match across the `_`.
	 *
	 * Asserted against the writer's own emitted key rather than a hard-coded
	 * string, so re-pinning the key moves the readers with it or fails here.
	 *
	 * @return void
	 */
	public function testLoyaltyAccountSchemaKeyAgreesAcrossEveryReader(): void {
		$pinned = (array)(new ReflectionClass(SettingsLoadService::class))->getConstant('SCHEMA_CONFIG_KEYS');
		$emitted = ($pinned['customerLoyaltyAccount'] ?? 'customerLoyaltyAccount_schema');

		$writer = (new ReflectionClass(SettingsLoadService::class))->getFileName();
		$this->assertIsString($writer);
		$lib = dirname($writer, 2);

		$readers = [
			$lib . '/Service/SchemaMapService.php',
			$lib . '/Service/SettingsService.php',
			$lib . '/Service/LoyaltyAccountService.php',
			$lib . '/Service/LoyaltyReportingService.php',
		];

		foreach ($readers as $reader) {
			// Positive control: a path typo would otherwise read as agreement.
			$this->assertFileExists($reader);
			$source = (string)file_get_contents($reader);

			$this->assertStringContainsString(
				"'" . $emitted . "'",
				$source,
				basename($reader) . " does not spell the app-config key the loader writes ('{$emitted}')"
			);

			$stale = 'customerLoyaltyAccount_schema';
			if ($emitted !== $stale) {
				$this->assertStringNotContainsString(
					"'" . $stale . "'",
					$source,
					basename($reader) . ' reads the slug-derived key; the persisted key is ' . $emitted
				);
			}
		}
	}//end testLoyaltyAccountSchemaKeyAgreesAcrossEveryReader()

	/**
	 * The loyalty account key is pinned, not derived from the slug.
	 *
	 * `klantLoyaltyAccount_schema` is live persisted state on existing installs.
	 * The English-vocabulary pass moved the SLUG to `customerLoyaltyAccount`; the
	 * key must not follow it, because nothing migrates the existing row.
	 *
	 * @return void
	 */
	public function testLoyaltyAccountConfigKeyDoesNotFollowTheSlug(): void {
		$pinned = (array)(new ReflectionClass(SettingsLoadService::class))->getConstant('SCHEMA_CONFIG_KEYS');

		$this->assertArrayHasKey('customerLoyaltyAccount', $pinned);
		$this->assertSame('klantLoyaltyAccount_schema', $pinned['customerLoyaltyAccount']);

		// The reader side resolves that same key to the renamed entity type.
		$mapping = (array)(new ReflectionClass(SchemaMapService::class))->getConstant('SCHEMA_MAPPING');
		$this->assertSame('customerLoyaltyAccount', ($mapping['klantLoyaltyAccount_schema'] ?? null));
	}//end testLoyaltyAccountConfigKeyDoesNotFollowTheSlug()
}//end class
