<?php

/**
 * Unit tests for CampaignPerformanceService.
 *
 * Covers:
 * - an unknown blast answers null
 * - no portal configured: connected false, reason no_portal, mailbox numbers still present
 * - Portaliq absent: connected false, reason portaliq_missing, nothing read
 * - sessions summed across days for the campaign slug AND the blast name
 * - rows of other campaigns ignored; pageViews/formSubmits null unless carried
 * - the window defaults to the send date and is clamped
 *
 * Portaliq is never installed here: the availability probe is a protected
 * method overridden by an anonymous subclass, and the object service is a
 * hand-written fake resolved from a mocked container.
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
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-campaign-performance-joins-site-sessions-to-a-blast
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\AttributionService;
use OCA\Pipelinq\Service\BlastService;
use OCA\Pipelinq\Service\CampaignLinkDecorator;
use OCA\Pipelinq\Service\CampaignPerformanceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for CampaignPerformanceService.
 *
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-campaign-performance-joins-site-sessions-to-a-blast
 */
class CampaignPerformanceServiceTest extends TestCase {

	/**
	 * Fixed clock: 2026-09-04T10:00:00Z.
	 *
	 * @var int
	 */
	private const NOW = 1788516000;

	/**
	 * The blast under test.
	 *
	 * @var array<string, mixed>
	 */
	private const BLAST = [
		'uuid' => 'blast-1',
		'name' => 'Spring newsletter 2026',
		'sentAt' => '2026-09-01T08:00:00Z',
		'totals' => ['sent' => 100, 'delivered' => 95, 'opened' => 40, 'clicked' => 12],
	];

	/**
	 * Fake object service recording every findAll call.
	 *
	 * @var object
	 */
	private object $objectService;

	/**
	 * Build a service around fakes.
	 *
	 * @param string $portal The configured `blast.traffic_portal`.
	 * @param bool $installed What the Portaliq probe answers.
	 * @param array<int, array<string, mixed>> $rollups Rows findAll answers.
	 * @param array<string, mixed>|null $blast The blast, null for unknown.
	 *
	 * @return CampaignPerformanceService
	 */
	private function build(string $portal = 'gemeente-portal', bool $installed = true, array $rollups = [], ?array $blast = self::BLAST): CampaignPerformanceService {
		$this->objectService = new class ($rollups) {
			/** @var array<int, array<string, mixed>> */
			public array $calls = [];

			/** @param array<int, array<string, mixed>> $rollups */
			public function __construct(private array $rollups) {
			}//end __construct()

			/** @return array<int, array<string, mixed>> */
			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->calls[] = ['config' => $config, '_rbac' => $_rbac, '_multitenancy' => $_multitenancy];
				return $this->rollups;
			}//end findAll()
		};

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($portal): string {
				return ($key === 'blast.traffic_portal') ? $portal : $default;
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$blastService = $this->createMock(BlastService::class);
		$blastService->method('getBlastById')->willReturn($blast);

		$attribution = $this->createMock(AttributionService::class);
		$attribution->method('getBlastAttributionSummary')->willReturn(['blastId' => 'blast-1', 'dealCount' => 2, 'attributedValue' => 1500.5, 'currency' => 'EUR']);

		$container = $this->createMock(ContainerInterface::class);
		$objectService = $this->objectService;
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService): object {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		return new class ($installed, $container, $appConfig, $time, $blastService, $attribution, new CampaignLinkDecorator($appConfig), $this->createMock(LoggerInterface::class)) extends CampaignPerformanceService {
			public function __construct(private bool $installed, ...$args) {
				parent::__construct(...$args);
			}//end __construct()

			protected function isPortaliqInstalled(): bool {
				return $this->installed;
			}//end isPortaliqInstalled()
		};
	}//end build()

	/**
	 * A rollup row for one day with the given campaign entries.
	 *
	 * @param string $date The day.
	 * @param array<int, array<string, mixed>> $campaigns The campaigns[] rows.
	 *
	 * @return array<string, mixed>
	 */
	private function rollup(string $date, array $campaigns): array {
		return ['portal' => 'gemeente-portal', 'date' => $date, 'sessions' => 500, 'campaigns' => $campaigns];
	}//end rollup()

	/**
	 * @return void
	 */
	public function testUnknownBlastAnswersNull(): void {
		$this->assertNull($this->build(blast: null)->forBlast(blastId: 'nope'));
	}//end testUnknownBlastAnswersNull()

	/**
	 * @return void
	 */
	public function testReportsNoPortalAndStillCarriesTheMailboxNumbers(): void {
		$record = $this->build(portal: '')->forBlast(blastId: 'blast-1');

		$this->assertNotNull($record);
		$this->assertFalse($record['connected']);
		$this->assertSame('no_portal', $record['reason']);
		$this->assertNull($record['site']);
		$this->assertSame('spring-newsletter-2026', $record['campaign']);
		$this->assertSame(['sent' => 100, 'delivered' => 95, 'opened' => 40, 'clicked' => 12], $record['email']);
		$this->assertSame(2, $record['deals']['dealCount']);
		$this->assertSame(1500.5, $record['deals']['attributedValue']);
		$this->assertSame([], $this->objectService->calls);
	}//end testReportsNoPortalAndStillCarriesTheMailboxNumbers()

	/**
	 * @return void
	 */
	public function testReportsPortaliqMissingWhenTheAppIsNotInstalled(): void {
		$record = $this->build(installed: false)->forBlast(blastId: 'blast-1');

		$this->assertFalse($record['connected']);
		$this->assertSame('portaliq_missing', $record['reason']);
		$this->assertSame('gemeente-portal', $record['portal']);
		$this->assertNull($record['site']);
		$this->assertSame([], $this->objectService->calls);
	}//end testReportsPortaliqMissingWhenTheAppIsNotInstalled()

	/**
	 * @return void
	 */
	public function testSumsSessionsOfMatchingCampaignRowsAcrossDays(): void {
		$rollups = [
			$this->rollup('2026-09-01', [['campaign' => 'spring-newsletter-2026', 'source' => 'email', 'medium' => 'email', 'sessions' => 10]]),
			$this->rollup('2026-09-02', [['campaign' => 'spring-newsletter-2026', 'source' => 'email', 'medium' => 'email', 'sessions' => 7]]),
			$this->rollup('2026-09-03', [['campaign' => 'spring-newsletter-2026', 'source' => 'linkedin', 'medium' => 'social', 'sessions' => 3]]),
		];
		$record = $this->build(rollups: $rollups)->forBlast(blastId: 'blast-1');

		$this->assertTrue($record['connected']);
		$this->assertSame(20, $record['site']['sessions']);
		$this->assertSame(3, $record['site']['days']);
		$this->assertNull($record['site']['pageViews']);
		$this->assertNull($record['site']['formSubmits']);
		$this->assertSame([['source' => 'email', 'medium' => 'email', 'sessions' => 17], ['source' => 'linkedin', 'medium' => 'social', 'sessions' => 3]], $record['site']['sources']);
	}//end testSumsSessionsOfMatchingCampaignRowsAcrossDays()

	/**
	 * @return void
	 */
	public function testMatchesTheBlastNameThatEmailEventsCarry(): void {
		$rollups = [$this->rollup('2026-09-01', [['campaign' => 'Spring newsletter 2026', 'source' => 'email', 'medium' => 'email', 'sessions' => 4]])];
		$record = $this->build(rollups: $rollups)->forBlast(blastId: 'blast-1');

		$this->assertSame(4, $record['site']['sessions']);
	}//end testMatchesTheBlastNameThatEmailEventsCarry()

	/**
	 * @return void
	 */
	public function testIgnoresRowsOfOtherCampaigns(): void {
		$rollups = [
			$this->rollup('2026-09-01', [
				['campaign' => 'other', 'source' => 'email', 'medium' => 'email', 'sessions' => 99],
				['campaign' => 'spring-newsletter-2026', 'source' => 'email', 'medium' => 'email', 'sessions' => 1, 'pageViews' => 5, 'formSubmits' => 2],
			]),
			['portal' => 'gemeente-portal', 'date' => '2026-09-02'],
		];
		$record = $this->build(rollups: $rollups)->forBlast(blastId: 'blast-1');

		$this->assertSame(1, $record['site']['sessions']);
		$this->assertSame(5, $record['site']['pageViews']);
		$this->assertSame(2, $record['site']['formSubmits']);
		$this->assertSame(1, $record['site']['days']);
	}//end testIgnoresRowsOfOtherCampaigns()

	/**
	 * @return void
	 */
	public function testReadsThePortalRollupsWithoutRbacOverTheWindow(): void {
		$record = $this->build()->forBlast(blastId: 'blast-1');

		$this->assertSame(['from' => '2026-09-01', 'to' => '2026-09-04'], $record['window']);
		$this->assertCount(1, $this->objectService->calls);
		$call = $this->objectService->calls[0];
		$this->assertFalse($call['_rbac']);
		$this->assertFalse($call['_multitenancy']);
		$this->assertSame('portaliq', $call['config']['filters']['register']);
		$this->assertSame('portalTrafficDaily', $call['config']['filters']['schema']);
		$this->assertSame('gemeente-portal', $call['config']['filters']['portal']);
		$this->assertSame(['gte' => '2026-09-01', 'lt' => '2026-09-05'], $call['config']['filters']['date']);
	}//end testReadsThePortalRollupsWithoutRbacOverTheWindow()

	/**
	 * @return void
	 */
	public function testExplicitWindowWinsAndIsClamped(): void {
		$service = $this->build();

		$this->assertSame(['from' => '2026-08-01', 'to' => '2026-08-15'], $service->forBlast(blastId: 'blast-1', from: '2026-08-01', to: '2026-08-15')['window']);
		$this->assertSame(['from' => '2026-08-15', 'to' => '2026-08-15'], $service->forBlast(blastId: 'blast-1', from: '2026-09-01', to: '2026-08-15')['window']);
		$this->assertSame(['from' => '2025-09-03', 'to' => '2026-09-04'], $service->forBlast(blastId: 'blast-1', from: '2020-01-01')['window']);
		$this->assertSame(['from' => '2026-09-01', 'to' => '2026-09-04'], $service->forBlast(blastId: 'blast-1', from: 'garbage', to: '2026-02-30')['window']);
	}//end testExplicitWindowWinsAndIsClamped()
}//end class
