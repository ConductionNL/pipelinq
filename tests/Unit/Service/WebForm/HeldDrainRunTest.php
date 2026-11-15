<?php

/**
 * Unit tests for HeldDrainRun: one drain's settings and report.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\WebForm
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

namespace OCA\Pipelinq\Tests\Unit\Service\WebForm;

use OCA\Pipelinq\Service\WebForm\HeldDrainRun;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What a drain reads its key from and how its report grows.
 */
#[CoversClass(HeldDrainRun::class)]
class HeldDrainRunTest extends TestCase {

	/**
	 * A new run has an empty report; entries and pending counts add up.
	 *
	 * @return void
	 */
	public function testTheReportGrows(): void {
		$run = new HeldDrainRun(register: 'r', schema: 's', keyProperty: 'source', openFilter: ['status' => 'open'], apply: true);
		$this->assertSame(expected: ['delivered' => [], 'refused' => [], 'unmapped' => [], 'pending' => 0], actual: $run->report);

		$run->add(list: 'unmapped', entry: ['id' => 'h-1', 'key' => '']);
		$run->stillHeld();

		$this->assertSame(expected: [['id' => 'h-1', 'key' => '']], actual: $run->report['unmapped']);
		$this->assertSame(expected: 1, actual: $run->report['pending']);
	}//end testTheReportGrows()

	/**
	 * The key is read from the configured property, empty when it is missing.
	 *
	 * @return void
	 */
	public function testTheKeyComesFromTheKeyProperty(): void {
		$run = new HeldDrainRun(register: 'r', schema: 's', keyProperty: 'source', openFilter: [], apply: false);

		$this->assertSame(expected: 'contact', actual: $run->keyOf(held: ['source' => 'contact']));
		$this->assertSame(expected: '', actual: $run->keyOf(held: []));
	}//end testTheKeyComesFromTheKeyProperty()
}//end class
