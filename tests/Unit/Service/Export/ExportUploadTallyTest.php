<?php

/**
 * Unit tests for ExportUploadTally.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Export;

use OCA\Pipelinq\Service\Export\ExportUploadTally;
use PHPUnit\Framework\TestCase;

/**
 * ExportUploadTally turns per-file outcomes into the UploadResult envelope.
 */
class ExportUploadTallyTest extends TestCase {

	/**
	 * No files at all is a run where everything succeeded.
	 *
	 * @return void
	 */
	public function testAnEmptyRunSucceeded(): void {
		$result = (new ExportUploadTally())->result(total: 0);

		self::assertSame(
			[
				'status' => 'all_succeeded',
				'manifest' => [],
				'file_count' => 0,
				'byte_count' => 0,
				'destination_ack' => null,
				'error_message' => null,
			],
			$result
		);
	}//end testAnEmptyRunSucceeded()

	/**
	 * One upload and one failure make a partial run that keeps both entries in order.
	 *
	 * @return void
	 */
	public function testAMixedRunIsPartial(): void {
		$tally = new ExportUploadTally();
		$tally->uploaded(entry: ['path' => '/a.csv', 'size_bytes' => 10], ack: 'ack-a');
		$tally->failed(entry: ['path' => '/b.csv', 'size_bytes' => 7], error: 'boom');

		$result = $tally->result(total: 2);

		self::assertSame('partial', $result['status']);
		self::assertSame(
			[
				['path' => '/a.csv', 'size_bytes' => 10, 'upload_status' => 'success', 'destination_ack' => 'ack-a'],
				['path' => '/b.csv', 'size_bytes' => 7, 'upload_status' => 'failed', 'error' => 'boom'],
			],
			$result['manifest']
		);
		self::assertSame(1, $result['file_count']);
		self::assertSame(10, $result['byte_count']);
		self::assertSame('ack-a', $result['destination_ack']);
		self::assertSame('/b.csv: boom', $result['error_message']);
	}//end testAMixedRunIsPartial()

	/**
	 * A run where every file failed is all_failed and joins the failures.
	 *
	 * @return void
	 */
	public function testEveryFailureIsAllFailed(): void {
		$tally = new ExportUploadTally();
		$tally->failed(entry: ['path' => '/a.csv', 'size_bytes' => 1], error: 'x');
		$tally->failed(entry: ['path' => '/b.csv', 'size_bytes' => 1], error: 'y');

		$result = $tally->result(total: 2);

		self::assertSame('all_failed', $result['status']);
		self::assertSame('/a.csv: x; /b.csv: y', $result['error_message']);
		self::assertNull($result['destination_ack']);
	}//end testEveryFailureIsAllFailed()
}//end class
