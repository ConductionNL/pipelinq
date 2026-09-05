<?php

/**
 * Tests for MatomoReportService.
 *
 * Two things are asserted that a Matomo instance could not tell us anyway:
 * that a dead or absent credential is REPORTED rather than allowed to surface
 * as a 401 in a call log, and that every request this service makes is a read.
 * The third is the credential-reference rule itself, which is what keeps a
 * token out of an app-config value.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Matomo
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Matomo;

use OCA\Pipelinq\Service\CampaignService;
use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use OCA\Pipelinq\Service\Egress\EgressResult;
use OCA\Pipelinq\Service\Matomo\MatomoReportService;
use OCA\Pipelinq\Service\Social\BrokerCredentialReader;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Service\Matomo\MatomoReportService
 * @uses \OCA\Pipelinq\Service\Egress\EgressResult
 */
class MatomoReportServiceTest extends TestCase {

	/**
	 * The last request configuration the seam was handed, per method.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $requests = [];

	/**
	 * Build a service whose egress, credential status and campaigns the test
	 * decides.
	 *
	 * @param bool $sourceConfigured Whether a source id is set.
	 * @param string $credentialRef The configured reference.
	 * @param string $credentialStatus What the broker says about it.
	 * @param array<int, array<string, mixed>> $campaigns Our own campaigns.
	 * @param string $body What Matomo answers.
	 *
	 * @return MatomoReportService
	 */
	private function service(
		bool $sourceConfigured,
		string $credentialRef,
		string $credentialStatus,
		array $campaigns = [],
		string $body = '[]',
	): MatomoReportService {
		$egress = $this->createMock(ConnectorEgress::class);
		$egress->method('isConfigured')->willReturn($sourceConfigured);
		$egress->method('read')->willReturnCallback(
			function (string $configKey, string $endpoint, array $config = [], string $method = 'GET') use ($body): EgressResult {
				$this->requests[] = ['endpoint' => $endpoint, 'config' => $config, 'method' => $method];

				return EgressResult::success(body: $body);
			}
		);

		$credentials = $this->createMock(BrokerCredentialReader::class);
		$credentials->method('status')->willReturn($credentialStatus);

		$campaignService = $this->createMock(CampaignService::class);
		$campaignService->method('all')->willReturn($campaigns);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($credentialRef): string {
				if ($key === MatomoReportService::CREDENTIAL_KEY) {
					return $credentialRef;
				}

				if ($key === MatomoReportService::SITE_KEY) {
					return '7';
				}

				return $default;
			}
		);

		return new MatomoReportService(
			egress: $egress,
			credentials: $credentials,
			campaigns: $campaignService,
			appConfig: $appConfig
		);
	}//end service()

	/**
	 * Without a source there is nothing to read and nothing is attempted.
	 *
	 * @return void
	 */
	public function testReportsNotConfiguredWithoutASource(): void {
		$report = $this->service(sourceConfigured: false, credentialRef: 'c-1', credentialStatus: 'active')
			->report(from: '2026-08-01', to: '2026-08-31');

		$this->assertFalse($report['connected']);
		$this->assertSame(EgressResult::NOT_CONFIGURED, $report['failure']);
		$this->assertSame([], $this->requests);
	}//end testReportsNotConfiguredWithoutASource()

	/**
	 * Without a credential reference there is nothing to read either.
	 *
	 * @return void
	 */
	public function testReportsNotConfiguredWithoutAReference(): void {
		$report = $this->service(sourceConfigured: true, credentialRef: '', credentialStatus: 'active')
			->report(from: '2026-08-01', to: '2026-08-31');

		$this->assertFalse($report['connected']);
		$this->assertStringContainsString(MatomoReportService::CREDENTIAL_KEY, $report['reason']);
	}//end testReportsNotConfiguredWithoutAReference()

	/**
	 * A credential that is not active is reported, and no call is made.
	 *
	 * @return void
	 */
	public function testRefusesWhenTheCredentialIsNotActive(): void {
		$report = $this->service(sourceConfigured: true, credentialRef: 'c-1', credentialStatus: 'relink_needed')
			->report(from: '2026-08-01', to: '2026-08-31');

		$this->assertFalse($report['connected']);
		$this->assertSame('relink_needed', $report['failure']);
		$this->assertStringContainsString('Reconnect', $report['reason']);
	}//end testRefusesWhenTheCredentialIsNotActive()

	/**
	 * When it refuses, nothing leaves the instance.
	 *
	 * @return void
	 */
	public function testMakesNoCallWhenItRefuses(): void {
		$this->service(sourceConfigured: true, credentialRef: 'c-1', credentialStatus: 'expired')
			->report(from: '2026-08-01', to: '2026-08-31');

		$this->assertSame([], $this->requests);
	}//end testMakesNoCallWhenItRefuses()

	/**
	 * Every request is a GET carrying the read parameters, the configured
	 * site and JSON.
	 *
	 * @return void
	 */
	public function testEveryRequestIsAGetWithAReadMethod(): void {
		$this->service(sourceConfigured: true, credentialRef: 'c-1', credentialStatus: 'active')
			->report(from: '2026-08-01', to: '2026-08-31');

		$this->assertCount(3, $this->requests);
		$methods = [];
		foreach ($this->requests as $request) {
			$this->assertSame('GET', $request['method']);
			$this->assertSame(MatomoReportService::ENDPOINT, $request['endpoint']);
			$this->assertSame('API', $request['config']['query']['module']);
			$methods[] = $request['config']['query']['method'];
		}

		$this->assertSame(array_values(MatomoReportService::REPORTS), $methods);
		foreach ($methods as $method) {
			$this->assertStringContainsString('.get', $method, 'every Matomo method must be a read');
		}
	}//end testEveryRequestIsAGetWithAReadMethod()

	/**
	 * The request carries `format=JSON`, the configured site and the window.
	 *
	 * @return void
	 */
	public function testRequestsCarryFormatJsonAndTheConfiguredSite(): void {
		$this->service(sourceConfigured: true, credentialRef: 'c-1', credentialStatus: 'active')
			->report(from: '2026-08-01', to: '2026-08-31');

		$query = $this->requests[0]['config']['query'];
		$this->assertSame('JSON', $query['format']);
		$this->assertSame('7', $query['idSite']);
		$this->assertSame('2026-08-01,2026-08-31', $query['date']);
	}//end testRequestsCarryFormatJsonAndTheConfiguredSite()

	/**
	 * A campaign row whose label equals one of our `utmCampaign` values is
	 * matched onto that campaign.
	 *
	 * @return void
	 */
	public function testMatchesACampaignRowOntoOurUtmCampaign(): void {
		$report = $this->service(
			sourceConfigured: true,
			credentialRef: 'c-1',
			credentialStatus: 'active',
			campaigns: [['id' => 'camp-1', 'name' => 'Webinar', 'utmCampaign' => 'webinar-ai']],
			body: '[{"label":"webinar-ai","nb_visits":42,"nb_actions":90}]'
		)->report(from: '2026-08-01', to: '2026-08-31');

		$this->assertTrue($report['campaigns'][0]['matched']);
		$this->assertSame('camp-1', $report['campaigns'][0]['campaignId']);
		$this->assertSame('Webinar', $report['campaigns'][0]['campaignName']);
		$this->assertSame(42, $report['campaigns'][0]['visits']);
	}//end testMatchesACampaignRowOntoOurUtmCampaign()

	/**
	 * A row matching nothing is kept and marked, because it is usually spend
	 * outside the tool.
	 *
	 * @return void
	 */
	public function testKeepsAnUnmatchedRowAndMarksIt(): void {
		$report = $this->service(
			sourceConfigured: true,
			credentialRef: 'c-1',
			credentialStatus: 'active',
			campaigns: [],
			body: '[{"label":"iemand-anders","nb_visits":3}]'
		)->report(from: '2026-08-01', to: '2026-08-31');

		$this->assertCount(1, $report['campaigns']);
		$this->assertFalse($report['campaigns'][0]['matched']);
		$this->assertSame('iemand-anders', $report['campaigns'][0]['campaign']);
	}//end testKeepsAnUnmatchedRowAndMarksIt()

	/**
	 * Matomo's own token shape is recognised, so it can be refused before it
	 * is stored in a setting.
	 *
	 * @return void
	 */
	public function testRecognisesARawMatomoToken(): void {
		$this->assertTrue(MatomoReportService::looksLikeAToken(value: str_repeat('a1', 16)));
		$this->assertTrue(MatomoReportService::looksLikeAToken(value: strtoupper(str_repeat('bc', 16))));
		$this->assertFalse(MatomoReportService::looksLikeAToken(value: 'b7f4a9c1-2d3e-4f56-8a90-1b2c3d4e5f60'));
		$this->assertFalse(MatomoReportService::looksLikeAToken(value: ''));
		$this->assertFalse(MatomoReportService::looksLikeAToken(value: str_repeat('a1', 15)));
	}//end testRecognisesARawMatomoToken()

	/**
	 * No connector ships for a source this change put out of scope. A
	 * half-built optional connector costs a full review and ships a surface
	 * nobody has credentials for.
	 *
	 * @return void
	 */
	public function testNoGa4OrBingOrDataForSeoConnectorShips(): void {
		$directory = (__DIR__ . '/../../../../lib/Service');
		$this->assertDirectoryExists($directory);

		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
		foreach ($iterator as $file) {
			if ($file->isFile() === false || $file->getExtension() !== 'php') {
				continue;
			}

			$name = $file->getFilename();
			$this->assertStringNotContainsStringIgnoringCase('Ga4', $name);
			$this->assertStringNotContainsStringIgnoringCase('BingWebmaster', $name);
			$this->assertStringNotContainsStringIgnoringCase('DataForSeo', $name);
		}
	}//end testNoGa4OrBingOrDataForSeoConnectorShips()
}//end class
