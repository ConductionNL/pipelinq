<?php

/**
 * Unit tests for CampaignReportService.
 *
 * Covers:
 * - the whole report comes back from one call, with every section present
 * - reach per channel comes from the blasts, engagement from the touchpoints
 * - a channel with no reach figure reports null, not zero
 * - a cost nobody recorded is absent, not zero
 * - the attributed total is the same under every model
 * - the window defaults to the campaign's own dates and never runs past today
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
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\CampaignAttributionService;
use OCA\Pipelinq\Service\CampaignLinkDecorator;
use OCA\Pipelinq\Service\CampaignReportService;
use OCA\Pipelinq\Service\CampaignService;
use OCA\Pipelinq\Service\ShillinqInvoiceReader;
use OCA\Pipelinq\Service\TouchpointService;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/InMemoryListObjectStore.php';

/**
 * Tests for CampaignReportService.
 *
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
 */
class CampaignReportServiceTest extends TestCase {

	/**
	 * Fixed clock: 2026-11-14T10:00:00Z.
	 *
	 * @var int
	 */
	private const NOW = 1794650400;

	/**
	 * The store every read comes from.
	 *
	 * @var InMemoryListObjectStore
	 */
	private InMemoryListObjectStore $store;

	/**
	 * The rows a full campaign is made of.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function seed(): array {
		return [
			'campaign' => [
				[
					'uuid' => 'camp-1',
					'name' => 'Webinar AI voor gemeenten',
					'goal' => 'Vijftig aanmeldingen.',
					'status' => 'running',
					'utmCampaign' => 'webinar-ai-voor-gemeenten',
					'utmSource' => 'nieuwsbrief',
					'utmMedium' => 'email',
					'startsAt' => '2026-10-01',
					'endsAt' => '2026-11-30',
					'budgetEur' => 2500,
					'costs' => [['channel' => 'linkedin', 'amountEur' => 180.5, 'note' => 'Gesponsorde post.']],
					'attribution' => ['model' => 'linear'],
				],
			],
			'blast' => [
				['uuid' => 'blast-1', 'campaignId' => 'camp-1', 'channel' => 'email', 'totals' => ['sent' => 200, 'delivered' => 190, 'opened' => 80]],
			],
			'touchpoint' => [
				['uuid' => 'tp-1', 'campaignId' => 'camp-1', 'leadId' => 'lead-1', 'kind' => 'click', 'channel' => 'email', 'occurredAt' => '2026-10-14T10:21:33+00:00'],
				['uuid' => 'tp-2', 'campaignId' => 'camp-1', 'leadId' => 'lead-1', 'kind' => 'submit', 'channel' => 'social', 'occurredAt' => '2026-10-21T09:47:12+00:00'],
			],
			'lead' => [['uuid' => 'lead-1', 'campaignId' => 'camp-1', 'status' => 'won', 'value' => 3000]],
		];
	}//end seed()

	/**
	 * Build a report service over an in-memory store.
	 *
	 * @param array<string, array<int, array<string, mixed>>>|null $seed Rows to start from.
	 *
	 * @return CampaignReportService
	 */
	private function build(?array $seed = null): CampaignReportService {
		$this->store = new InMemoryListObjectStore(($seed ?? $this->seed()));

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnArgument(2);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$reader = new class () extends ShillinqInvoiceReader {

			/**
			 * Construct without dependencies: shillinq never answers here.
			 */
			public function __construct() {
			}//end __construct()

			/**
			 * @return bool
			 */
			public function isAvailable(): bool {
				return false;
			}//end isAvailable()

			/**
			 * @param string $customerRef The customer.
			 * @param string $from Window start.
			 * @param string $to Window end.
			 *
			 * @return array<int, array<string, mixed>> Always empty.
			 */
			public function paidInvoicesFor(string $customerRef, string $from, string $to): array {
				return [];
			}//end paidInvoicesFor()
		};

		$touchpoints = new TouchpointService($this->store);

		return new CampaignReportService(
			new CampaignService($this->store, $appConfig, new CampaignLinkDecorator($appConfig)),
			new CampaignAttributionService($this->store, $touchpoints, $reader),
			$this->store,
			$time
		);
	}//end build()

	/**
	 * @return void
	 */
	public function testAnUnknownCampaignAnswersNull(): void {
		$this->assertNull($this->build()->forCampaign(campaignId: 'nope'));
	}//end testAnUnknownCampaignAnswersNull()

	/**
	 * @return void
	 */
	public function testOneCallCarriesEverySection(): void {
		$report = $this->build()->forCampaign(campaignId: 'camp-1');

		foreach (['campaign', 'window', 'channels', 'engagement', 'leads', 'totals', 'models', 'cost'] as $section) {
			$this->assertArrayHasKey($section, $report);
		}

		$this->assertSame('webinar-ai-voor-gemeenten', $report['campaign']['utmCampaign']);
		$this->assertSame('linear', $report['campaign']['defaultModel']);
	}//end testOneCallCarriesEverySection()

	/**
	 * @return void
	 */
	public function testReachComesFromTheBlastsAndEngagementFromTheTouchpoints(): void {
		$report = $this->build()->forCampaign(campaignId: 'camp-1');

		$channels = array_column($report['channels'], null, 'channel');
		$this->assertSame(190, $channels['email']['reach']);
		$this->assertSame(80, $channels['email']['opened']);
		$this->assertSame(1, $channels['email']['click']);

		$this->assertSame(['click' => 1, 'visit' => 0, 'submit' => 1, 'reply' => 0], $report['engagement']);
	}//end testReachComesFromTheBlastsAndEngagementFromTheTouchpoints()

	/**
	 * A channel with no reach figure reports null. Zero would read as
	 * "nobody was reached", which is a different and wrong claim.
	 *
	 * @return void
	 */
	public function testAChannelWithoutABlastReportsNullReach(): void {
		$report = $this->build()->forCampaign(campaignId: 'camp-1');

		$channels = array_column($report['channels'], null, 'channel');
		$this->assertNull($channels['social']['reach']);
		$this->assertSame(1, $channels['social']['submit']);
	}//end testAChannelWithoutABlastReportsNullReach()

	/**
	 * @return void
	 */
	public function testARecordedCostIsSummedAndAnUnrecordedOneIsAbsent(): void {
		$report = $this->build()->forCampaign(campaignId: 'camp-1');

		$this->assertSame(180.5, $report['cost']['totalEur']);
		$this->assertSame(2500.0, $report['cost']['budgetEur']);

		$seed = $this->seed();
		unset($seed['campaign'][0]['costs'], $seed['campaign'][0]['budgetEur']);
		$bare = $this->build(seed: $seed)->forCampaign(campaignId: 'camp-1');

		$this->assertNull($bare['cost']['totalEur']);
		$this->assertNull($bare['cost']['budgetEur']);
		$this->assertSame([], $bare['cost']['recorded']);
	}//end testARecordedCostIsSummedAndAnUnrecordedOneIsAbsent()

	/**
	 * @return void
	 */
	public function testTheTotalIsTheSameUnderEveryModel(): void {
		$report = $this->build()->forCampaign(campaignId: 'camp-1');

		$this->assertSame(3000.0, $report['models']['first']['total']);
		$this->assertSame(3000.0, $report['models']['last']['total']);
		$this->assertSame(3000.0, $report['models']['linear']['total']);
		$this->assertSame(3000.0, $report['totals']['attributedValue']);

		$this->assertNotSame($report['models']['first']['byTouchpoint'], $report['models']['linear']['byTouchpoint']);
	}//end testTheTotalIsTheSameUnderEveryModel()

	/**
	 * @return void
	 */
	public function testTheWindowDefaultsToTheCampaignDatesAndStopsAtToday(): void {
		$report = $this->build()->forCampaign(campaignId: 'camp-1');

		$this->assertSame('2026-10-01', $report['window']['from']);
		$this->assertSame('2026-11-14', $report['window']['to']);

		$asked = $this->build()->forCampaign(campaignId: 'camp-1', from: '2026-10-10', to: '2026-10-20');
		$this->assertSame(['from' => '2026-10-10', 'to' => '2026-10-20'], $asked['window']);
	}//end testTheWindowDefaultsToTheCampaignDatesAndStopsAtToday()
}//end class
