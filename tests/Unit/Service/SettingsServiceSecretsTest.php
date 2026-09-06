<?php

/**
 * Unit tests for the secret keys of SettingsService.
 *
 * Covers (marketing-campaign-attribution):
 * - getSettings() never returns the Search Console key, only `<key>_set`
 * - updateSettings() stores a non-empty key as sensitive and ignores an empty one
 * - `<key>_clear` deletes the key
 * - the new tunables are read back with their defaults
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\DefaultPipelineService;
use OCA\Pipelinq\Service\DefaultSkillService;
use OCA\Pipelinq\Service\SettingsLoadService;
use OCA\Pipelinq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the secret handling in SettingsService.
 *
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
 */
class SettingsServiceSecretsTest extends TestCase {

	/**
	 * The key under test.
	 *
	 * @var string
	 */
	private const KEY = 'search.gsc.service_account_key';

	/**
	 * In-memory app config: value plus the sensitive flag it was written with.
	 *
	 * @var array<string, array{value: string, sensitive: bool}>
	 */
	private array $store = [];

	/**
	 * Service under test.
	 *
	 * @var SettingsService
	 */
	private SettingsService $service;

	/**
	 * Build the service over an in-memory app config.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->store[$key]['value'] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool {
				$this->store[$key] = ['value' => $value, 'sensitive' => $sensitive];
				return true;
			}
		);
		$appConfig->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				unset($this->store[$key]);
			}
		);

		$this->service = new SettingsService(
			$appConfig,
			$this->createMock(IConfig::class),
			$this->createMock(SettingsLoadService::class),
			$this->createMock(DefaultPipelineService::class),
			$this->createMock(DefaultSkillService::class),
			$this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * @return void
	 */
	public function testNewTunablesReadBackWithDefaults(): void {
		$settings = $this->service->getSettings();

		$this->assertSame('true', $settings['blast.utm_auto']);
		$this->assertSame('', $settings['search.gsc.properties']);
		$this->assertSame('', $settings['search.gsc.last_import_at']);
		$this->assertSame('false', $settings[self::KEY . '_set']);
		$this->assertArrayNotHasKey(self::KEY, $settings);
	}//end testNewTunablesReadBackWithDefaults()

	/**
	 * @return void
	 */
	public function testAKeyIsStoredSensitiveAndNeverEchoedBack(): void {
		$result = $this->service->updateSettings([self::KEY => '{"type":"service_account"}', 'blast.utm_auto' => 'false']);

		$this->assertSame('{"type":"service_account"}', $this->store[self::KEY]['value']);
		$this->assertTrue($this->store[self::KEY]['sensitive']);
		$this->assertSame('true', $result[self::KEY . '_set']);
		$this->assertArrayNotHasKey(self::KEY, $result);
		$this->assertSame('false', $result['blast.utm_auto']);
		$this->assertArrayNotHasKey(self::KEY, $this->service->getSettings());
	}//end testAKeyIsStoredSensitiveAndNeverEchoedBack()

	/**
	 * @return void
	 */
	public function testAnEmptyKeyInThePayloadDoesNotWipeTheStoredOne(): void {
		$this->service->updateSettings([self::KEY => '{"a":1}']);
		$result = $this->service->updateSettings([self::KEY => '  ', 'search.gsc.properties' => 'https://example.org/']);

		$this->assertSame('{"a":1}', $this->store[self::KEY]['value']);
		$this->assertSame('true', $result[self::KEY . '_set']);
		$this->assertSame('https://example.org/', $result['search.gsc.properties']);
	}//end testAnEmptyKeyInThePayloadDoesNotWipeTheStoredOne()

	/**
	 * @return void
	 */
	public function testClearDeletesTheKey(): void {
		$this->service->updateSettings([self::KEY => '{"a":1}']);
		$result = $this->service->updateSettings([self::KEY . '_clear' => 'true', self::KEY => '{"ignored":true}']);

		$this->assertArrayNotHasKey(self::KEY, $this->store);
		$this->assertSame('false', $result[self::KEY . '_set']);
	}//end testClearDeletesTheKey()
}//end class
