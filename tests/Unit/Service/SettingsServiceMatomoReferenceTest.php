<?php

/**
 * Tests for the Matomo credential-reference rule in SettingsService.
 *
 * `matomo.credential_ref` holds a credential UUID and never a token (ADR-064,
 * rule 2 of the marketing architecture). The single most likely way that rule
 * gets broken is somebody pasting the `token_auth` from Matomo's own settings
 * page into a field that accepts any string, and nothing about that would look
 * wrong afterwards. So it is refused at the write, and this is where that is
 * asserted.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\DefaultPipelineService;
use OCA\Pipelinq\Service\DefaultSkillService;
use OCA\Pipelinq\Service\Matomo\MatomoReportService;
use OCA\Pipelinq\Service\SettingsLoadService;
use OCA\Pipelinq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * @covers \OCA\Pipelinq\Service\SettingsService
 * @uses \OCA\Pipelinq\Service\Matomo\MatomoReportService
 */
class SettingsServiceMatomoReferenceTest extends TestCase {

	/**
	 * In-memory app config.
	 *
	 * @var array<string, string>
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
		parent::setUp();
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->store[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool {
				$this->store[$key] = $value;
				return true;
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
	 * A 32-character hexadecimal value is a Matomo token, and it is refused
	 * with a message that says what to do instead.
	 *
	 * @return void
	 */
	public function testARawTokenIsRefusedWithAMessageNamingTheBroker(): void {
		$this->store[MatomoReportService::CREDENTIAL_KEY] = 'b7f4a9c1-2d3e-4f56-8a90-1b2c3d4e5f60';

		try {
			$this->service->updateSettings([MatomoReportService::CREDENTIAL_KEY => str_repeat('a1', 16)]);
			$this->fail('a raw Matomo token must be refused');
		} catch (UnexpectedValueException $e) {
			$this->assertStringContainsString('credential broker', $e->getMessage());
		}

		$this->assertSame(
			'b7f4a9c1-2d3e-4f56-8a90-1b2c3d4e5f60',
			$this->store[MatomoReportService::CREDENTIAL_KEY],
			'the refused write must not change the stored value'
		);
	}//end testARawTokenIsRefusedWithAMessageNamingTheBroker()

	/**
	 * A credential reference is accepted, because a reference is not a
	 * secret.
	 *
	 * @return void
	 */
	public function testAReferenceIsAccepted(): void {
		$config = $this->service->updateSettings(
			[MatomoReportService::CREDENTIAL_KEY => 'b7f4a9c1-2d3e-4f56-8a90-1b2c3d4e5f60']
		);

		$this->assertSame('b7f4a9c1-2d3e-4f56-8a90-1b2c3d4e5f60', $config[MatomoReportService::CREDENTIAL_KEY]);
	}//end testAReferenceIsAccepted()

	/**
	 * The refusal does not stop an unrelated write: a settings save that
	 * happens to carry an empty reference field goes through.
	 *
	 * @return void
	 */
	public function testAnEmptyReferenceDoesNotRefuseTheWholeSave(): void {
		$config = $this->service->updateSettings(
			[MatomoReportService::CREDENTIAL_KEY => '', 'matomo.site_id' => '7']
		);

		$this->assertSame('7', $config['matomo.site_id']);
	}//end testAnEmptyReferenceDoesNotRefuseTheWholeSave()

	/**
	 * The new intelligence keys are readable, with their documented
	 * defaults: relevance scoring off, everything else empty.
	 *
	 * @return void
	 */
	public function testTheIntelligenceKeysCarryTheirDocumentedDefaults(): void {
		$config = $this->service->getSettings();

		$this->assertSame('false', $config['competitor.relevance']);
		$this->assertSame('', $config['competitor.egress_source']);
		$this->assertSame('', $config['search.crawl_source']);
		$this->assertSame('', $config[MatomoReportService::SOURCE_KEY]);
	}//end testTheIntelligenceKeysCarryTheirDocumentedDefaults()

	/**
	 * The reference is NOT a secret key, so it is read back rather than
	 * reported as "set". That is the difference between a reference and a
	 * token, and it is worth asserting: turning it into a secret would hide
	 * a misconfiguration from the person fixing it.
	 *
	 * @return void
	 */
	public function testTheReferenceIsReadBackRatherThanHidden(): void {
		$this->service->updateSettings([MatomoReportService::CREDENTIAL_KEY => 'ref-1234']);
		$config = $this->service->getSettings();

		$this->assertSame('ref-1234', $config[MatomoReportService::CREDENTIAL_KEY]);
		$this->assertArrayNotHasKey((MatomoReportService::CREDENTIAL_KEY . '_set'), $config);
	}//end testTheReferenceIsReadBackRatherThanHidden()
}//end class
