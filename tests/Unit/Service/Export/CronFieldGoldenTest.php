<?php

/**
 * Golden test for the field parsing of CronExpressionHelper.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Export
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
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-004-01
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Export;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\Service\Export\CronExpressionHelper;
use PHPUnit\Framework\TestCase;

/**
 * Whether each field part is valid, and the minutes of one week that each
 * valid expression fires at, compared with CronFieldGolden.json. Set
 * CRON_FIELD_GOLDEN_RECORD=1 to rewrite the file, then run once more without it.
 *
 * @covers \OCA\Pipelinq\Service\Export\CronExpressionHelper
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-004-01
 */
class CronFieldGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/CronFieldGolden.json';

	private const MINUTE_PARTS = [
		'*', '*/1', '*/0', '*/x', '*/15', '*/60', '*/', '/5', '5', '59', '60', '05', '-1', 'a',
		'10-20', '20-10', '10-20/5', '10-20/0', '0-59/7', '0-60', '10-', '-10', '1-2-3', 'x-5', '5-x',
		'1,2', '1,,2', '1,*', '*/5,7', '58-59,0-1', '', '1.5', '+3', ' 5', '*/1x', '*/+2', '0', '0-5',
	];

	private const SCHEDULES = [
		'*/20 * * * *', '7 */5 * * *', '0 9-17/4 * * *', '30 22 * * 1-5', '15 0 * * 0', '15 0 * * 7',
		'0 12 1-7 * 1', '0 12 2 * *', '0 0 * 3 *', '45 6,18 * * 6', '0 0 31 * *', '5-10/2 3 * * *',
		'0 4 */2 * *', '0 4 * * */3', '0 4 3-5 * 2-3', '0 0 * * 5-7', '0 0 1 3 0', '*/59 23 * * *', '0 * * * *',
	];

	/**
	 * Validate every part and walk every schedule over one week.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$cron = new CronExpressionHelper();
		$actual = ['valid' => [], 'fires' => []];
		foreach (self::MINUTE_PARTS as $part) {
			$actual['valid']['[' . $part . '] minute'] = $cron->isValid(expression: $part . ' * * * *');
			$actual['valid']['[' . $part . '] hour'] = $cron->isValid(expression: '0 ' . $part . ' * * *');
			$actual['valid']['[' . $part . '] dow'] = $cron->isValid(expression: '0 0 * * ' . $part);
			$actual['valid']['[' . $part . '] dom'] = $cron->isValid(expression: '0 0 ' . $part . ' * *');
		}

		$start = new DateTimeImmutable('2026-02-27 00:00:00', new DateTimeZone('UTC'));
		foreach (self::SCHEDULES as $schedule) {
			$fires = [];
			for ($minute = 0; $minute < (9 * 24 * 60); $minute++) {
				$when = $start->modify('+' . $minute . ' minutes');
				if ($cron->isDue(expression: $schedule, when: $when) === true) {
					$fires[] = $when->format('D d H:i');
				}
			}

			$actual['fires'][$schedule] = [count($fires), array_slice($fires, 0, 12), array_slice($fires, -4)];
		}

		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (getenv('CRON_FIELD_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, json_encode($actual, $flags) . "\n");
			$this->markTestSkipped(message: 'recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		foreach (['valid', 'fires'] as $section) {
			foreach ($expected[$section] as $name => $want) {
				$this->assertSame(expected: $want, actual: ($actual[$section][$name] ?? null), message: $section . ' ' . $name);
			}

			$this->assertSame(expected: array_keys($expected[$section]), actual: array_keys($actual[$section]));
		}
	}//end testGolden()
}//end class
