<?php

/**
 * Unit tests for PipelineStageEntry.
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

use OCA\Pipelinq\Listener\PipelineStageEntry;
use PHPUnit\Framework\TestCase;

/**
 * A stage entry carries its pipeline, id and stage unchanged.
 */
class PipelineStageEntryTest extends TestCase {

	/**
	 * Every value given is the value read back.
	 *
	 * @return void
	 */
	public function testCarriesItsValues(): void {
		$entry = new PipelineStageEntry(pipeline: ['stages' => []], pipelineId: 'p-1', stageName: 'Won');

		self::assertSame(['stages' => []], $entry->pipeline);
		self::assertSame('p-1', $entry->pipelineId);
		self::assertSame('Won', $entry->stageName);
	}//end testCarriesItsValues()
}//end class
