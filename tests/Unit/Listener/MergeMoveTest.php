<?php

/**
 * Unit tests for MergeMove.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\Pipelinq\Listener\MergeMove;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Which record merged away into which.
 */
#[CoversClass(MergeMove::class)]
class MergeMoveTest extends TestCase {

	/**
	 * The record and both uuids are kept as given.
	 *
	 * @return void
	 */
	public function testKeepsWhatItIsGiven(): void {
		$record = $this->createMock(originalClassName: ObjectEntityInterface::class);
		$move = new MergeMove(record: $record, fromUuid: 'from-1', intoUuid: 'into-1');

		$this->assertSame(expected: [$record, 'from-1', 'into-1'], actual: [$move->record, $move->fromUuid, $move->intoUuid]);
	}//end testKeepsWhatItIsGiven()
}//end class
