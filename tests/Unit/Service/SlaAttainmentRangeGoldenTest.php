<?php

/**
 * Golden test for SlaAttainmentService::resolveBucketRange().
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
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md#requirement-attainment-reporting
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Pipelinq\Service\SlaAttainmentService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The range (or the error) of every bucket for given and malformed values,
 * compared with SlaAttainmentRangeGolden.json, plus the default ranges
 * compared with the current day, ISO week, month and quarter. Set
 * SLA_RANGE_GOLDEN_RECORD=1 to rewrite the file, then run once more without it.
 *
 * @covers \OCA\Pipelinq\Service\SlaAttainmentService
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md#requirement-attainment-reporting
 */
class SlaAttainmentRangeGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/SlaAttainmentRangeGolden.json';

	private const VALUES = [
		'day' => ['date', ['2026-10-01', '2024-02-29', '2026-12-31', '2026-1-01', '01-10-2026', 'x', '2026-10-01T00:00']],
		'week' => ['week', ['2026-W01', '2026-W1', '2026-W53', '2020-W53', '2026-W40', '2026W40', '2026-w40', '2026-W123']],
		'month' => ['month', ['2026-10', '2026-12', '2026-02', '2026-1', '2026/10', '202610']],
		'quarter' => ['quarter', ['2026-Q1', '2026-Q2', '2026-Q3', '2026-Q4', '2026-Q5', '2026-Q0', '2026Q1', '2026-q1']],
	];

	/**
	 * Resolve every value of every bucket and compare with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$service = $this->service();
		$zone = date_default_timezone_get();
		date_default_timezone_set('Pacific/Auckland');
		$actual = [];
		foreach (self::VALUES as $bucket => [$key, $values]) {
			foreach ($values as $value) {
				$actual[$bucket . ' ' . $value] = $this->resolve(service: $service, call: [$bucket, [$key => $value]]);
			}
		}

		$actual['unknown bucket'] = $this->resolve(service: $service, call: ['year', []]);
		date_default_timezone_set($zone);

		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if (getenv('SLA_RANGE_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, json_encode($actual, $flags) . "\n");
			$this->markTestSkipped(message: 'recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(expected: $expected, actual: $actual);
	}//end testGolden()

	/**
	 * Without a value each bucket covers the current period, in UTC.
	 *
	 * @return void
	 */
	public function testDefaultsCoverTheCurrentPeriod(): void {
		$service = $this->service();
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$quarter = sprintf('%s-%02d-01', $now->format('Y'), ((intdiv(((int)$now->format('n') - 1), 3) * 3) + 1));
		$expected = [
			'day' => [$now->format('Y-m-d') . 'T00:00:00+00:00', '+1 day'],
			'week' => [$now->setISODate((int)$now->format('o'), (int)$now->format('W'))->format('Y-m-d') . 'T00:00:00+00:00', '+7 days'],
			'month' => [$now->format('Y-m') . '-01T00:00:00+00:00', '+1 month'],
			'quarter' => [$quarter . 'T00:00:00+00:00', '+3 months'],
		];

		foreach ($expected as $bucket => [$start, $length]) {
			[$from, $to] = $service->resolveBucketRange(bucket: $bucket, params: []);
			$this->assertSame(expected: $start, actual: $from->format(DateTimeInterface::ATOM), message: $bucket);
			$end = $from->modify($length)->format(DateTimeInterface::ATOM);
			$this->assertSame(expected: $end, actual: $to->format(DateTimeInterface::ATOM), message: $bucket);
		}
	}//end testDefaultsCoverTheCurrentPeriod()

	/**
	 * One call: the range as ATOM strings and its time zone, or the error.
	 *
	 * @param SlaAttainmentService $service The service.
	 * @param array{0: string, 1: array<string, string>} $call The bucket and the parameters.
	 *
	 * @return array<string, string>
	 */
	private function resolve(SlaAttainmentService $service, array $call): array {
		try {
			[$from, $to] = $service->resolveBucketRange(bucket: $call[0], params: $call[1]);
			return ['start' => $from->format(DateTimeInterface::ATOM), 'end' => $to->format(DateTimeInterface::ATOM), 'tz' => $from->getTimezone()->getName()];
		} catch (Throwable $e) {
			return ['threw' => get_class($e), 'message' => $e->getMessage()];
		}
	}//end resolve()

	/**
	 * A service with inert collaborators.
	 *
	 * @return SlaAttainmentService
	 */
	private function service(): SlaAttainmentService {
		return new SlaAttainmentService(
			container: $this->createMock(originalClassName: ContainerInterface::class),
			appConfig: $this->createMock(originalClassName: IAppConfig::class),
			ticketService: $this->createMock(originalClassName: TicketService::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end service()
}//end class
