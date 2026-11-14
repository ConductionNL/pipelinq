<?php
/**
 * Example data for PqWeekoverzicht and PqZRapport: the weekly review carries a
 * direction per highlight, and the failed Z-report Z-2026-0279 carries why and
 * when its raise to shillinq failed.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The weekly review's highlight directions and Z-2026-0279's failure.
 */
class ZuiddrechtReviewAndZReportFieldsTest extends TestCase {

	/**
	 * A file of the Zuiddrecht set as an array.
	 *
	 * @param string $file The path below example-sets/.
	 *
	 * @return array<string, mixed> The decoded file.
	 */
	private function read(string $file): array {
		$data = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/'.$file), true);
		$this->assertIsArray(actual: $data);

		return $data;
	}//end read()

	/**
	 * One direction per highlight, in order: two up, Mastodon down.
	 *
	 * @return void
	 */
	public function testEachHighlightHasItsDirection(): void {
		$review = array_column($this->read(file: 'zuiddrecht.d/58-weekly-review.json')['objects'], 'data', 'key')['weekoverzicht-28-sep'];
		$this->assertSame(expected: ['up', 'up', 'down'], actual: $review['highlightDirections']);
		$this->assertCount(expectedCount: count($review['highlights']), haystack: $review['highlightDirections']);
	}//end testEachHighlightHasItsDirection()

	/**
	 * Z-2026-0279 failed because shillinq did not respond, at 23:00 on its report day.
	 *
	 * @return void
	 */
	public function testFailedZReportSaysWhyAndWhen(): void {
		$report = array_column($this->read(file: 'zuiddrecht.json')['objects'], 'data', 'key')['z-rapport-0279'];
		$this->assertSame(expected: 'failed', actual: $report['bookkeepingStatus']);
		$this->assertSame(expected: 'unreachable', actual: $report['bookkeepingFailureReason']);
		$this->assertSame(expected: '{{date:-1}}', actual: $report['reportDate']);
		$this->assertSame(expected: '{{datetime:-1 23:00}}', actual: $report['bookkeepingFailedAt']);
	}//end testFailedZReportSaysWhyAndWhen()
}//end class
