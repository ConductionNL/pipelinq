<?php

/**
 * Tests for BookingEventPush.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\BookingEventPush;
use PHPUnit\Framework\TestCase;

/**
 * BookingEventPush carries the shared push values unchanged.
 */
final class BookingEventPushTest extends TestCase {
	/**
	 * Every value reads back as given.
	 *
	 * @return void
	 */
	public function testCarriesValues(): void {
		$leaf = new \stdClass();
		$push = new BookingEventPush(leaf: $leaf, register: 3, schema: 7, bookingId: 'b-1', event: ['summary' => 'S']);

		$this->assertSame([$leaf, 3, 7, 'b-1', ['summary' => 'S']], [$push->leaf, $push->register, $push->schema, $push->bookingId, $push->event]);
	}//end testCarriesValues()
}//end class
