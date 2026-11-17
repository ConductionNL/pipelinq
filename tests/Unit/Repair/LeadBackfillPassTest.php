<?php

/**
 * Unit tests for LeadBackfillPass.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
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

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\Pipelinq\Repair\LeadBackfillPass;
use OCA\Pipelinq\Repair\Support\SchemaWriteTarget;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * LeadBackfillPass carries its values unchanged.
 */
class LeadBackfillPassTest extends TestCase {

	/**
	 * Every constructor value is readable as given.
	 *
	 * @return void
	 */
	public function testKeepsTheValuesItIsGiven(): void {
		$target = new SchemaWriteTarget(objectService: new \stdClass(), register: '5', schema: '7', actingAdmin: null);
		$pipeline = 'pipe-1';
		$pipelines = [['id' => 'pipe-1']];
		$output = $this->createMock(IOutput::class);
		$value = new LeadBackfillPass(target: $target, pipeline: $pipeline, pipelines: $pipelines, output: $output);

		self::assertSame($target, $value->target);
		self::assertSame($pipeline, $value->pipeline);
		self::assertSame($pipelines, $value->pipelines);
		self::assertSame($output, $value->output);
	}//end testKeepsTheValuesItIsGiven()
}//end class
