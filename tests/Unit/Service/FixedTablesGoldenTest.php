<?php

/**
 * Golden test for the shipped default sales stages and the segment signal catalogue.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\Marketing\SegmentSignalService;
use OCA\Pipelinq\Service\PipelineStageData;
use PHPUnit\Framework\TestCase;

/**
 * Pins, value for value and in order, the default sales pipeline (with and
 * without a view id) and the segment signal catalogue.
 */
class FixedTablesGoldenTest extends TestCase {
	/**
	 * Compare both tables with the recorded golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$signals = (new \ReflectionClass(SegmentSignalService::class))->newInstanceWithoutConstructor();
		$actual = [
			'sales pipeline' => (new PipelineStageData())->getSalesPipelineData(),
			'sales pipeline with view' => (new PipelineStageData())->getSalesPipelineData('view-1'),
			'signal catalogue' => $signals->catalogue(),
		];

		$golden = __DIR__ . '/FixedTablesGolden.json';
		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (getenv('FIXED_TABLES_GOLDEN_RECORD') !== false) {
			file_put_contents($golden, json_encode($actual, $flags) . "\n");
			$this->markTestSkipped(message: 'recorded');
		}

		$this->assertSame(expected: (string)file_get_contents($golden), actual: json_encode($actual, $flags) . "\n");
	}//end testGolden()
}//end class
