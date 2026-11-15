<?php

/**
 * Unit tests for StagedItemWrite.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
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

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\Pipelinq\Listener\StagedItemWrite;
use PHPUnit\Framework\TestCase;

/**
 * A staged write carries its values and names the trimmed pipeline.
 */
class StagedItemWriteTest extends TestCase {

	/**
	 * Every value given is the value read back, and the pipeline id is trimmed.
	 *
	 * @return void
	 */
	public function testCarriesItsValuesAndTrimsThePipeline(): void {
		$write = new StagedItemWrite(schemaId: 's-1', data: ['pipeline' => ' p-1 ', 'stage' => 'New'], old: ['stage' => 'Old']);

		self::assertSame('s-1', $write->schemaId);
		self::assertSame(['pipeline' => ' p-1 ', 'stage' => 'New'], $write->data);
		self::assertSame(['stage' => 'Old'], $write->old);
		self::assertSame('p-1', $write->pipelineId());
	}//end testCarriesItsValuesAndTrimsThePipeline()

	/**
	 * An item without a pipeline names none.
	 *
	 * @return void
	 */
	public function testNoPipelineIsEmpty(): void {
		self::assertSame('', (new StagedItemWrite(schemaId: 's-1', data: [], old: []))->pipelineId());
	}//end testNoPipelineIsEmpty()
}//end class
