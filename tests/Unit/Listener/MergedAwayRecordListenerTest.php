<?php

/**
 * Unit tests for MergedAwayRecordListener.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Event\ObjectsMergedEvent;
use OCA\Pipelinq\Listener\MergedAwayRecordListener;
use OCA\Pipelinq\Service\Merge\MergedAwayStamp;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every merged-away record of a merge is stamped, and a failure never reaches the merge.
 *
 * @spec openspec/specs/master-data-management/spec.md#requirement-req-mdm-013-mdm-steward-ui-deep-linked-to-openregister
 */
class MergedAwayRecordListenerTest extends TestCase {
	/**
	 * Both merged-away records are stamped, the second even when the first fails.
	 *
	 * @return void
	 */
	public function testEveryMergedAwayRecordIsStamped(): void {
		$stamped = [];
		$stamp = $this->createMock(originalClassName: MergedAwayStamp::class);
		$stamp->method('apply')->willReturnCallback(
			static function (string $uuid) use (&$stamped): void {
				$stamped[] = $uuid;
				if ($uuid === 'a') {
					throw new RuntimeException(message: 'gone');
				}
			}
		);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$listener = new MergedAwayRecordListener(stamp: $stamp, logger: $logger);

		$listener->handle(event: new ObjectsMergedEvent(survivorUuid: 's', mergedFromUuids: ['a', 'b'], mergeOperationId: 'op-1'));
		$listener->handle(event: new Event());

		$this->assertSame(expected: ['a', 'b'], actual: $stamped);
	}//end testEveryMergedAwayRecordIsStamped()
}//end class
