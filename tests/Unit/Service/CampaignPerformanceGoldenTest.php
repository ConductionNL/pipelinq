<?php

/**
 * Golden test for a blast's campaign performance record.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
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

use OCA\Pipelinq\Service\AttributionService;
use OCA\Pipelinq\Service\BlastService;
use OCA\Pipelinq\Service\CampaignLinkDecorator;
use OCA\Pipelinq\Service\CampaignPerformanceService;
use OCA\Pipelinq\Service\CampaignSiteTraffic;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * Runs forBlast() over scripted blasts, windows and portal rollups and
 * compares the record, every findAll() call and every warning with
 * CampaignPerformanceGolden.json. Set CAMPAIGN_PERFORMANCE_GOLDEN_RECORD=1
 * to rewrite the golden file.
 *
 * @covers \OCA\Pipelinq\Service\CampaignPerformanceService
 * @covers \OCA\Pipelinq\Service\CampaignSiteTraffic
 * @uses   \OCA\Pipelinq\Service\CampaignLinkDecorator
 * @uses   \OCA\Pipelinq\Util\PayloadToArray
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 * @uses \OCA\Pipelinq\Util\EntityPayload
 * @uses \OCA\Pipelinq\Util\AnyPayloadToArray
 * @uses \OCA\Pipelinq\Util\CastingPayloadToArray
 * @uses \OCA\Pipelinq\Util\NullablePayloadToArray
 * @uses \OCA\Pipelinq\Util\OptionalPayloadToArray
 * @uses \OCA\Pipelinq\Util\SerialisedToArray
 */
class CampaignPerformanceGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/CampaignPerformanceGolden.json';

	/**
	 * Fixed clock: 2026-09-04T10:00:00Z.
	 */
	private const NOW = 1788516000;

	/**
	 * Calls recorded during one case, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $events = [];

	/**
	 * Every case's setup.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function definitions(): array {
		$blast = [
			'uuid'   => 'blast-1',
			'name'   => 'Spring newsletter 2026',
			'sentAt' => '2026-09-01T08:00:00Z',
			'totals' => ['sent' => 100, 'delivered' => 95, 'opened' => 40, 'clicked' => 12],
		];
		$base  = [
			'blast'     => $blast,
			'from'      => null,
			'to'        => null,
			'portal'    => ' gemeente-portal ',
			'installed' => true,
			'container' => 'ok',
			'pages'     => 'mixed',
			'deals'     => ['dealCount' => 2, 'attributedValue' => 1500.5, 'currency' => 'EUR'],
		];

		return [
			'unknown blast'              => array_merge($base, ['blast' => null]),
			'no portal'                  => array_merge($base, ['portal' => '  ']),
			'portaliq missing'           => array_merge($base, ['installed' => false]),
			'object service unavailable' => array_merge($base, ['container' => 'throw']),
			'findAll fails'              => array_merge($base, ['pages' => 'throw']),
			'findAll not iterable'       => array_merge($base, ['pages' => 'scalar']),
			'mixed rollups'              => $base,
			'two pages'                  => array_merge($base, ['pages' => 'two pages']),
			'page cap'                   => array_merge($base, ['pages' => 'always full']),
			'no deals keys'              => array_merge($base, ['deals' => []]),
			'blast without totals'       => array_merge($base, ['blast' => ['uuid' => 'b2', 'name' => '']]),
			'explicit window'            => array_merge($base, ['from' => '2026-08-01', 'to' => '2026-08-15T12:00:00Z']),
			'invalid dates'              => array_merge($base, ['from' => '2026-02-30', 'to' => 'tomorrow']),
			'start after end'            => array_merge($base, ['from' => '2026-09-10', 'to' => '2026-09-02']),
			'window longer than a year'  => array_merge($base, ['from' => '2024-01-01', 'to' => '2026-01-01']),
			'scheduled date fallback'    => array_merge(
				$base,
				['blast' => ['uuid' => 'b3', 'name' => 'Autumn', 'sentAt' => 'x', 'scheduledFor' => '2026-08-20']]
			),
			'created date fallback'      => array_merge($base, ['blast' => ['uuid' => 'b4', 'name' => 'Winter', 'createdAt' => '2026-07-01T00:00:00Z']]),
			'no blast dates'             => array_merge($base, ['blast' => ['uuid' => 'b5', 'name' => 'Undated']]),
			'window just over a year'    => array_merge($base, ['from' => '2025-01-01', 'to' => '2026-01-15']),
			'padded blast name'          => array_merge($base, ['blast' => array_merge($blast, ['name' => ' Spring newsletter 2026 '])]),
		];
	}//end definitions()

	/**
	 * The case names.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$names = [];
		foreach (array_keys(self::definitions()) as $name) {
			$names[$name] = [$name];
		}

		return $names;
	}//end cases()

	/**
	 * The rollup rows of the mixed script.
	 *
	 * @return array<int, mixed>
	 */
	private static function mixedRows(): array {
		return [
			[
				'date'      => '2026-09-01',
				'campaigns' => [
					['campaign' => 'spring-newsletter-2026', 'source' => 'newsletter', 'medium' => 'email', 'sessions' => 7],
					['campaign' => 'SPRING NEWSLETTER 2026', 'source' => 'newsletter', 'medium' => 'email', 'sessions' => 3, 'pageViews' => 9],
					['campaign' => 'other', 'source' => 'x', 'medium' => 'y', 'sessions' => 50],
					['source' => 'no-campaign', 'medium' => 'x', 'sessions' => 6],
					new stdClass(),
					'not an array',
				],
			],
			[
				'date'      => '2026-09-02',
				'campaigns' => [
					['campaign' => 'spring-newsletter-2026', 'source' => 'social', 'medium' => 'cpc', 'sessions' => 12, 'formSubmits' => 2],
					['campaign' => 'Spring Newsletter 2026', 'source' => 'newsletter', 'medium' => 'email', 'sessions' => 2, 'formSubmits' => 3],
					['campaign' => 'spring-newsletter-2026', 'sessions' => 1],
				],
			],
			['date' => '2026-09-03', 'campaigns' => 'not a list'],
			['date' => '2026-09-04'],
			new class {
				/**
				 * The serialised rollup.
				 *
				 * @return array<string, mixed>
				 */
				public function jsonSerialize(): array {
					$entry = ['campaign' => 'spring-newsletter-2026', 'source' => 'newsletter', 'medium' => 'email', 'sessions' => 4];
					return ['date' => '2026-09-04', 'campaigns' => [$entry]];
				}//end jsonSerialize()
			},
		];
	}//end mixedRows()

	/**
	 * The page findAll() answers for a script at an offset.
	 *
	 * @param string $script The page script.
	 * @param int    $offset The offset.
	 *
	 * @return mixed
	 */
	public static function page(string $script, int $offset): mixed {
		$row = ['date' => '2026-09-02', 'campaigns' => [['campaign' => 'spring-newsletter-2026', 'source' => 's', 'medium' => 'm', 'sessions' => 1]]];
		if ($script === 'throw') {
			throw new RuntimeException('findAll failed');
		}

		if ($script === 'scalar') {
			return 'not iterable';
		}

		if ($script === 'two pages') {
			$count = 5;
			if ($offset === 0) {
				$count = 200;
			}

			return array_fill(0, $count, $row);
		}

		if ($script === 'always full') {
			return array_fill(0, 200, $row);
		}

		return self::mixedRows();
	}//end page()

	/**
	 * Record one call.
	 *
	 * @param string               $call The call name.
	 * @param array<string, mixed> $args The arguments by name.
	 *
	 * @return void
	 */
	public function recordCall(string $call, array $args): void {
		$this->events[] = ['call' => $call, 'args' => $args];
	}//end recordCall()

	/**
	 * Mocks for every collaborator.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function collaborators(array $case): array {
		$test          = $this;
		$objectService = new class ($test, $case['pages']) {
			/**
			 * @param CampaignPerformanceGoldenTest $test   The recording test.
			 * @param string                        $script The page script.
			 */
			public function __construct(private CampaignPerformanceGoldenTest $test, private string $script) {
			}//end __construct()

			/**
			 * Answer a page.
			 *
			 * @param array<string, mixed> $config        The query.
			 * @param bool                 $_rbac         RBAC flag.
			 * @param bool                 $_multitenancy Multitenancy flag.
			 *
			 * @return mixed
			 */
			public function findAll(array $config, bool $_rbac=true, bool $_multitenancy=true): mixed {
				$this->test->recordCall(call: 'findAll', args: ['config' => $config, '_rbac' => $_rbac, '_multitenancy' => $_multitenancy]);
				return CampaignPerformanceGoldenTest::page(script: $this->script, offset: (int) $config['offset']);
			}//end findAll()
		};

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default='') use ($case): string {
				if ($key === 'blast.traffic_portal') {
					return $case['portal'];
				}

				return $default;
			}
		);

		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$blastService = $this->createMock(originalClassName: BlastService::class);
		$blastService->method('getBlastById')->willReturn($case['blast']);

		$attribution = $this->createMock(originalClassName: AttributionService::class);
		$attribution->method('getBlastAttributionSummary')->willReturn($case['deals']);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($case, $objectService): object {
				$this->recordCall(call: 'container.get', args: ['id' => $id]);
				if ($case['container'] === 'throw') {
					throw new RuntimeException('no ObjectService');
				}

				return $objectService;
			}
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message, array $context=[]): void {
				$this->recordCall(call: 'log.warning', args: ['message' => (string) $message, 'context' => $context]);
			}
		);

		return [
			'installed'    => $case['installed'],
			'container'    => $container,
			'appConfig'    => $appConfig,
			'time'         => $time,
			'blastService' => $blastService,
			'attribution'  => $attribution,
			'decorator'    => new CampaignLinkDecorator(appConfig: $appConfig),
			'logger'       => $logger,
		];
	}//end collaborators()

	/**
	 * Build the service, with the Portaliq probe answered by the case.
	 *
	 * @param array<string, mixed> $c The collaborators.
	 *
	 * @return CampaignPerformanceService
	 */
	private function makeService(array $c): CampaignPerformanceService {
		$traffic = new class ($c['installed'], $c['container'], $c['appConfig'], $c['decorator'], $c['logger']) extends CampaignSiteTraffic {
			/**
			 * @param bool  $installed What the Portaliq probe answers.
			 * @param mixed ...$args   The parent's arguments.
			 */
			public function __construct(private bool $installed, ...$args) {
				parent::__construct(...$args);
			}//end __construct()

			/**
			 * The scripted Portaliq probe.
			 *
			 * @return bool
			 */
			public function isPortaliqInstalled(): bool {
				return $this->installed;
			}//end isPortaliqInstalled()
		};

		return new CampaignPerformanceService(time: $c['time'], blastService: $c['blastService'], attributionService: $c['attribution'], traffic: $traffic);
	}//end makeService()

	/**
	 * The record and the calls match the golden.
	 *
	 * @param string $name The case name.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testThePerformanceRecordMatchesTheGolden(string $name): void {
		$case         = self::definitions()[$name];
		$this->events = [];
		$record       = $this->makeService(c: $this->collaborators(case: $case))->forBlast(blastId: 'blast-1', from: $case['from'], to: $case['to']);
		$actual       = json_decode((string) json_encode(['record' => $record, 'events' => $this->events]), true);

		if (getenv('CAMPAIGN_PERFORMANCE_GOLDEN_RECORD') !== false) {
			$golden = [];
			if (file_exists(self::GOLDEN) === true) {
				$golden = json_decode((string) file_get_contents(self::GOLDEN), true);
			}

			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(
				self::GOLDEN,
				json_encode($golden, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n"
			);
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true);
		$this->assertArrayHasKey(key: $name, array: $golden);
		$this->assertSame(expected: $golden[$name], actual: $actual);
	}//end testThePerformanceRecordMatchesTheGolden()
}//end class
