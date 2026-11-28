<?php

/**
 * Characterisation tests for SatisfactionAggregationService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\SatisfactionAggregationService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the satisfaction panel: windows, NPS, rating, trend, verbatims and
 * the client read.
 */
class SatisfactionAggregationCharacterisationTest extends TestCase {
	private const NOW = '2026-06-30T12:00:00+00:00';

	/**
	 * Service over the given config and findAll() behaviour.
	 *
	 * @param bool $configured Whether register and schema are set.
	 * @param mixed $rows Rows to return, or a Throwable to throw.
	 *
	 * @return SatisfactionAggregationService
	 */
	private function service(bool $configured = true, mixed $rows = []): SatisfactionAggregationService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => $configured === true ? 'id-' . $key : ''
		);
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config) use ($rows): array {
				if ($rows instanceof \Throwable) {
					throw $rows;
				}

				return array_map(static fn ($r) => $r, $rows === 'echo' ? [$config] : $rows);
			}
		);

		return new SatisfactionAggregationService($config, $objects, $this->createMock(LoggerInterface::class));
	}

	/**
	 * Days before NOW as an ISO string.
	 *
	 * @param int $days Days back.
	 *
	 * @return string
	 */
	private function ago(int $days): string {
		return (new DateTimeImmutable(self::NOW))->modify('-' . $days . ' days')->format(DATE_ATOM);
	}

	/**
	 * Empty input yields the fixed empty panel.
	 *
	 * @return void
	 */
	public function testEmptyPanel(): void {
		$this->assertSame(
			['empty' => true, 'responseCount' => 0, 'nps' => null, 'averageRating' => null, 'trend' => 'unknown', 'verbatims' => []],
			$this->service()->summarise(responses: [], now: new DateTimeImmutable(self::NOW))
		);
	}

	/**
	 * The full panel over mixed responses, with an improving trend.
	 *
	 * @return void
	 */
	public function testFullPanelTrendUp(): void {
		$responses = [
			['npsScore' => 10, 'averageRating' => 4, 'submittedAt' => $this->ago(1), 'verbatim' => 'great', 'classification' => 'praise'],
			['npsScore' => '9', 'averageRating' => '5', 'submittedAt' => $this->ago(10), 'verbatim' => '  '],
			['npsScore' => 7, 'averageRating' => 'n/a', 'submittedAt' => $this->ago(20), 'verbatim' => 'ok'],
			['npsScore' => 3, 'submittedAt' => $this->ago(100), 'verbatim' => 'bad'],
			['npsScore' => 8, 'submittedAt' => $this->ago(150)],
			['npsScore' => 0, 'averageRating' => 1, 'submittedAt' => $this->ago(181), 'verbatim' => 'old'],
			['npsScore' => 'x', 'submittedAt' => 'garbage'],
			['averageRating' => 2.333, 'submittedAt' => ''],
		];
		$panel = $this->service()->summarise(responses: $responses, now: new DateTimeImmutable(self::NOW));

		$this->assertSame(
			[
				'empty' => false,
				'responseCount' => 8,
				'nps' => 0.0,
				'averageRating' => 3.08,
				'trend' => 'up',
				'verbatims' => [
					['text' => 'great', 'submittedAt' => $this->ago(1), 'classification' => 'praise'],
					['text' => 'ok', 'submittedAt' => $this->ago(20), 'classification' => ''],
					['text' => 'bad', 'submittedAt' => $this->ago(100), 'classification' => ''],
				],
			],
			$panel
		);
	}

	/**
	 * Trend is down, flat or unknown depending on the two windows.
	 *
	 * @return void
	 */
	public function testTrendOutcomes(): void {
		$now = new DateTimeImmutable(self::NOW);
		$cases = [
			'down' => [['npsScore' => 0, 'submittedAt' => $this->ago(5)], ['npsScore' => 10, 'submittedAt' => $this->ago(95)]],
			'flat' => [['npsScore' => 10, 'submittedAt' => $this->ago(5)], ['npsScore' => 9, 'submittedAt' => $this->ago(95)]],
			'unknown' => [['npsScore' => 10, 'submittedAt' => $this->ago(5)], ['npsScore' => 10, 'submittedAt' => $this->ago(200)]],
			'boundary' => [['npsScore' => 10, 'submittedAt' => $this->ago(90)], ['npsScore' => 0, 'submittedAt' => $this->ago(180)]],
		];
		$trends = array_map(
			fn (array $r): string => $this->service()->summarise(responses: $r, now: $now)['trend'],
			$cases
		);

		$this->assertSame(['down' => 'down', 'flat' => 'flat', 'unknown' => 'unknown', 'boundary' => 'up'], $trends);
	}

	/**
	 * NPS bands: 9-10 promote, 0-6 detract, 7-8 are passive; nobody answering is null.
	 *
	 * @return void
	 */
	public function testNpsBands(): void {
		$service = $this->service();
		$scores = static fn (array $s): array => array_map(static fn ($v): array => ['npsScore' => $v], $s);

		$this->assertSame(20.0, $service->npsOf(responses: $scores([9, 6, 7, 10, 8])));
		$this->assertSame(0.0, $service->npsOf(responses: $scores([9, 5.5])));
		$this->assertSame(-100.0, $service->npsOf(responses: $scores([0, '6'])));
		$this->assertNull($service->npsOf(responses: $scores([null, 'x'])));
	}

	/**
	 * The client read: the filter it sends, the rows it keeps, and the
	 * fallbacks to [] for blank client, missing config and a failing read.
	 *
	 * @return void
	 */
	public function testForClientRead(): void {
		$now = new DateTimeImmutable(self::NOW);
		$echo = $this->service(rows: 'echo')->forClient(clientId: ' c-1 ', now: $now);
		$this->assertSame(1, $echo['responseCount']);

		$json = new class () implements \JsonSerializable {
			public function jsonSerialize(): mixed {
				return ['npsScore' => 10];
			}
		};
		$scalar = new class () implements \JsonSerializable {
			public function jsonSerialize(): mixed {
				return 'x';
			}
		};
		$kept = $this->service(rows: [['npsScore' => 0], $json, $scalar, new \stdClass()])->forClient(clientId: 'c-1', now: $now);
		$this->assertSame([2, 0.0], [$kept['responseCount'], $kept['nps']]);

		$this->assertTrue($this->service(rows: [['npsScore' => 1]])->forClient(clientId: '  ', now: $now)['empty']);
		$this->assertTrue($this->service(configured: false, rows: [['npsScore' => 1]])->forClient(clientId: 'c', now: $now)['empty']);
		$this->assertTrue($this->service(rows: new RuntimeException('down'))->forClient(clientId: 'c', now: $now)['empty']);
	}

	/**
	 * The filter sent to findAll() for a client.
	 *
	 * @return void
	 */
	public function testForClientFilter(): void {
		$seen = [];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key): string => 'id-' . $key);
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config) use (&$seen): array {
				$seen = $config;
				return [];
			}
		);
		(new SatisfactionAggregationService($config, $objects, $this->createMock(LoggerInterface::class)))->forClient(clientId: ' c-1 ');

		$this->assertSame(
			['filters' => ['register' => 'id-register', 'schema' => 'id-surveyResponse_schema', 'clientRef' => 'c-1'], 'limit' => 500],
			$seen
		);
	}
}
