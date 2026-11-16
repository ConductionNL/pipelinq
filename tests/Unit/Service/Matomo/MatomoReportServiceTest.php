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
	 * @param string $siteId The configured site id setting.
	 *
	 * @return MatomoReportService
	 */
	private function service(
		bool $sourceConfigured,
		string $credentialRef,
		string $credentialStatus,
		array $campaigns = [],
		string $body = '[]',
		string $siteId = '7',
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
			static function (string $app, string $key, string $default = '') use ($credentialRef, $siteId): string {
				if ($key === MatomoReportService::CREDENTIAL_KEY) {
					return $credentialRef;
				}

				if ($key === MatomoReportService::SITE_KEY) {
					return $siteId;
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
	 * Without a configured site (or only whitespace) the first site, `1`, is read.
	 *
	 * @return void
	 */
	public function testRequestsDefaultToSiteOneWhenNoSiteIsConfigured(): void {
		foreach (['', '   '] as $siteId) {
			$this->requests = [];
			$this->service(sourceConfigured: true, credentialRef: 'c-1', credentialStatus: 'active', siteId: $siteId)
				->report(from: '2026-08-01', to: '2026-08-31');

			$this->assertCount(3, $this->requests);
			foreach ($this->requests as $request) {
				$this->assertSame('1', $request['config']['query']['idSite'], 'site setting ' . var_export($siteId, true));
			}
		}
	}//end testRequestsDefaultToSiteOneWhenNoSiteIsConfigured()

	/**
	 * A body that is not a JSON array leaves every report empty rather than failing.
	 *
	 * @return void
	 */
	public function testANonJsonAnswerGivesEmptyReports(): void {
		foreach (['not json', '"text"', ''] as $body) {
			$report = $this->service(
				sourceConfigured: true,
				credentialRef: 'c-1',
				credentialStatus: 'active',
				campaigns: [['id' => 'camp-1', 'name' => 'Webinar', 'utmCampaign' => 'webinar-ai']],
				body: $body
			)->report(from: '2026-08-01', to: '2026-08-31');

			$this->assertSame(
				[
					'connected' => true,
					'reason' => '',
					'from' => '2026-08-01',
					'to' => '2026-08-31',
					'campaigns' => [],
					'referrerTypes' => [],
					'goals' => [],
				],
				$report,
				'body ' . var_export($body, true)
			);
		}
	}//end testANonJsonAnswerGivesEmptyReports()

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
	/**
	 * Every refusal, exactly, and none for an active credential.
	 *
	 * @return void
	 */
	public function testEveryRefusal(): void {
		$this->assertSame(
			['connected' => false, 'failure' => EgressResult::NOT_CONFIGURED, 'reason' => 'No Matomo source is configured under ' . MatomoReportService::SOURCE_KEY . '.'],
			$this->service(sourceConfigured: false, credentialRef: '', credentialStatus: '')->refusal()
		);
		$this->assertSame(
			['connected' => false, 'failure' => EgressResult::NOT_CONFIGURED, 'reason' => 'No credential is referenced under ' . MatomoReportService::CREDENTIAL_KEY . '.'],
			$this->service(sourceConfigured: true, credentialRef: '', credentialStatus: 'active')->refusal()
		);
		$this->assertSame(
			['connected' => false, 'failure' => EgressResult::NOT_CONFIGURED, 'reason' => 'The referenced credential could not be read from the broker.'],
			$this->service(sourceConfigured: true, credentialRef: 'c-1', credentialStatus: '')->refusal()
		);
		$this->assertSame(
			['connected' => false, 'failure' => 'relink_needed', 'reason' => 'The Matomo credential is revoked. Reconnect it in the credential broker.'],
			$this->service(sourceConfigured: true, credentialRef: 'c-1', credentialStatus: 'revoked')->refusal()
		);
		$this->assertNull($this->service(sourceConfigured: true, credentialRef: 'c-1', credentialStatus: 'active')->refusal());
	}//end testEveryRefusal()

	/**
	 * Every matching rule: labels and utm values are trimmed and compared
	 * case-insensitively; a campaign without a utm value is never matched; a
	 * later campaign on the same value wins; the id falls back to @self;
	 * non-list rows are skipped; missing counts are zero.
	 *
	 * @return void
	 */
	public function testEveryMatchingRule(): void {
		$report = $this->service(
			sourceConfigured: true,
			credentialRef: 'c-1',
			credentialStatus: 'active',
			campaigns: [
				['id' => 'old', 'name' => 'Old', 'utmCampaign' => 'Zomer'],
				['@self' => ['id' => 'new'], 'name' => 'Nieuw', 'utmCampaign' => ' zomer '],
				['id' => 'blank', 'name' => 'Blank', 'utmCampaign' => ' '],
			],
			body: '[{"label":" ZOMER ","nb_visits":"5"}, 7, {"label":""}]'
		)->report(from: '2026-08-01', to: '2026-08-31');

		$this->assertSame(
			[
				['campaign' => 'ZOMER', 'visits' => 5, 'actions' => 0, 'matched' => true, 'campaignId' => 'new', 'campaignName' => 'Nieuw'],
				['campaign' => '', 'visits' => 0, 'actions' => 0, 'matched' => false, 'campaignId' => '', 'campaignName' => ''],
			],
			$report['campaigns']
		);
	}//end testEveryMatchingRule()
	/**
	 * A refused report carries the refusal, the window and three empty reports, in that order.
	 *
	 * @return void
	 */
	public function testARefusedReportHasTheFullShape(): void {
		$report = $this->service(sourceConfigured: true, credentialRef: 'c-1', credentialStatus: 'revoked')
			->report(from: '2026-08-01', to: '2026-08-31');

		$this->assertSame(
			[
				'connected' => false,
				'failure' => 'relink_needed',
				'reason' => 'The Matomo credential is revoked. Reconnect it in the credential broker.',
				'from' => '2026-08-01',
				'to' => '2026-08-31',
				'campaigns' => [],
				'referrerTypes' => [],
				'goals' => [],
			],
			$report
		);
	}//end testARefusedReportHasTheFullShape()

	/**
	 * Each report holds the answer to its own method; referrer types and goals
	 * pass through untouched, and every request reads a 100-row range.
	 *
	 * @return void
	 */
	public function testEachReportHoldsTheAnswerToItsOwnMethod(): void {
		$bodies = [
			'Referrers.getCampaigns' => '[{"label":"webinar-ai","nb_visits":3,"nb_actions":9}]',
			'Referrers.getReferrerType' => '[{"label":"Search Engines","nb_visits":40}]',
			'Goals.get' => '{"nb_conversions":4,"revenue":120.5}',
		];
		$queries = [];

		$egress = $this->createMock(ConnectorEgress::class);
		$egress->method('isConfigured')->willReturn(true);
		$egress->method('read')->willReturnCallback(
			static function (string $configKey, string $endpoint, array $config = []) use ($bodies, &$queries): EgressResult {
				$queries[] = $config['query'];

				return EgressResult::success(body: $bodies[$config['query']['method']]);
			}
		);

		$credentials = $this->createMock(BrokerCredentialReader::class);
		$credentials->expects($this->once())->method('status')->with('c-1')->willReturn('active');

		$campaignService = $this->createMock(CampaignService::class);
		$campaignService->method('all')->willReturn([['id' => 'camp-1', 'name' => 'Webinar', 'utmCampaign' => 'webinar-ai']]);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				MatomoReportService::CREDENTIAL_KEY => '  c-1  ',
				MatomoReportService::SITE_KEY => ' 12 ',
				default => $default,
			}
		);

		$service = new MatomoReportService(
			egress: $egress,
			credentials: $credentials,
			campaigns: $campaignService,
			appConfig: $appConfig
		);

		$this->assertSame('c-1', $service->credentialRef());

		$report = $service->report(from: '2026-08-01', to: '2026-08-31');

		$this->assertTrue($report['connected']);
		$this->assertSame(
			[['campaign' => 'webinar-ai', 'visits' => 3, 'actions' => 9, 'matched' => true, 'campaignId' => 'camp-1', 'campaignName' => 'Webinar']],
			$report['campaigns']
		);
		$this->assertSame([['label' => 'Search Engines', 'nb_visits' => 40]], $report['referrerTypes']);
		$this->assertSame(['nb_conversions' => 4, 'revenue' => 120.5], $report['goals']);

		$this->assertCount(3, $queries);
		foreach ($queries as $query) {
			$this->assertSame('range', $query['period']);
			$this->assertSame('100', $query['filter_limit']);
			$this->assertSame('12', $query['idSite']);
		}
	}//end testEachReportHoldsTheAnswerToItsOwnMethod()

	/**
	 * A failed call leaves its report empty instead of failing the whole read.
	 *
	 * @return void
	 */
	public function testAFailedCallGivesAnEmptyReport(): void {
		$egress = $this->createMock(ConnectorEgress::class);
		$egress->method('isConfigured')->willReturn(true);
		$egress->method('read')->willReturn(EgressResult::failed(failure: EgressResult::NOT_CONFIGURED, reason: 'gone'));

		$credentials = $this->createMock(BrokerCredentialReader::class);
		$credentials->method('status')->willReturn('active');

		$campaignService = $this->createMock(CampaignService::class);
		$campaignService->method('all')->willReturn([]);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('c-1');

		$report = (new MatomoReportService(
			egress: $egress,
			credentials: $credentials,
			campaigns: $campaignService,
			appConfig: $appConfig
		))->report(from: '2026-08-01', to: '2026-08-31');

		$this->assertTrue($report['connected']);
		$this->assertSame([], $report['campaigns']);
		$this->assertSame([], $report['referrerTypes']);
		$this->assertSame([], $report['goals']);
	}//end testAFailedCallGivesAnEmptyReport()
}//end class
