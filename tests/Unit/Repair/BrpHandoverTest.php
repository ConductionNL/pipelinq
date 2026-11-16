<?php

/**
 * Unit tests for BrpHandover.
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

use OCA\Pipelinq\Event\BrpConnectionHandoverEvent;
use OCA\Pipelinq\Repair\BrpHandover;
use PHPUnit\Framework\TestCase;

/**
 * A handover carries its event, values and problems unchanged.
 */
class BrpHandoverTest extends TestCase {

	/**
	 * Every value given is the value read back.
	 *
	 * @return void
	 */
	public function testCarriesItsValues(): void {
		$event = new BrpConnectionHandoverEvent(settings: [], secrets: []);
		$handover = new BrpHandover(event: $event, values: ['brp_key' => 'x'], problems: ['brp_key' => 'unreadable']);

		self::assertSame($event, $handover->event);
		self::assertSame(['brp_key' => 'x'], $handover->values);
		self::assertSame(['brp_key' => 'unreadable'], $handover->problems);
	}//end testCarriesItsValues()
}//end class
