<?php

/**
 * Golden test for CronExpressionHelper::describe().
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
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Export;

use OCA\Pipelinq\Service\Export\CronExpressionHelper;
use PHPUnit\Framework\TestCase;

/**
 * The preview text of every expression in every locale, compared with
 * CronDescribeGolden.json. Set CRON_DESCRIBE_GOLDEN_RECORD=1 to rewrite the
 * file, then run once more without it.
 *
 * @covers \OCA\Pipelinq\Service\Export\CronExpressionHelper
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002
 */
class CronDescribeGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/CronDescribeGolden.json';

	private const EXPRESSIONS = [
		'', '   ', 'nonsense', '0 2 * *', '0 2 * * * *', '61 2 * * *', '0 24 * * *',
		'@hourly', '@daily', '@weekly', '@monthly', '@yearly', '@DAILY', ' @weekly ',
		'0 * * * *', '5 * * * *', '0 */2 * * *', '0  *  *  *  *', ' 0 * * * * ',
		'0 2 * * *', '30 14 * * *', '7 3 * * *', '05 09 * * *', '0 0 * * *', '59 23 * * *',
		'0 2 * * 0', '15 6 * * 7', '0 2 * * 1', '0 2 * * 1-5', '0 2 * * 0,6',
		'0 2 1 * *', '0 2 * 1 *', '0 2 1 * 0', '0 2 * 6 0', '0 * * * 0', '0 * 1 * *', '0 * * 2 *',
		'0  2 * * 1', "0\t2 * * 1", ' 5  * * * * ', '@Monthly',
		'*/15 2 * * *', '0 1-3 * * *', '1,2 3 * * *', '0 2,4 * * 0', '* * * * *', '0 */6 * * 0',
	];

	private const LOCALES = ['en', 'nl', 'NL', '', 'de'];

	/**
	 * Describe every expression in every locale and compare with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$cron = new CronExpressionHelper();
		$actual = [];
		foreach (self::EXPRESSIONS as $expression) {
			foreach (self::LOCALES as $locale) {
				$actual['[' . $expression . '] ' . $locale] = $cron->describe(expression: $expression, locale: $locale);
			}

			$actual['[' . $expression . '] default'] = $cron->describe(expression: $expression);
		}

		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (getenv('CRON_DESCRIBE_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, json_encode($actual, $flags) . "\n");
			$this->markTestSkipped(message: 'recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		foreach ($expected as $name => $want) {
			$this->assertSame(expected: $want, actual: ($actual[$name] ?? null), message: $name);
		}

		$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
	}//end testGolden()
}//end class
