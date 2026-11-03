<?php

/**
 * Compares a golden test's outcomes with its recorded JSON file.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Support;

/**
 * Case-by-case comparison against a golden file. When the environment
 * variable GOLDEN_RECORD is set the file is rewritten and the test is skipped,
 * so a recording run never passes as a comparison.
 */
trait ComparesGolden {
	/**
	 * Compare (or record) the outcomes of every case.
	 *
	 * @param string               $file   The golden JSON file.
	 * @param array<string, mixed> $actual Case name => outcome.
	 *
	 * @return void
	 */
	private function assertGolden(string $file, array $actual): void {
		$json = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION))."\n";
		if (getenv('GOLDEN_RECORD') !== false) {
			file_put_contents($file, $json);
			$this->markTestSkipped('recorded '.basename($file));
		}

		$expected = json_decode((string)file_get_contents($file), true);
		$actual = json_decode($json, true);
		foreach ($expected as $name => $want) {
			$this->assertSame($want, ($actual[$name] ?? null), (string)$name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end assertGolden()
}//end trait
