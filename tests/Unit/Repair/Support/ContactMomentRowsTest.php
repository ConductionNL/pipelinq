<?php

/**
 * Behavioural tests for the ContactMomentRows trait.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Util
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair\Support;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\TicketServiceInterface;
use OCA\Pipelinq\Repair\Support\ContactMomentRows;
use PHPUnit\Framework\TestCase;

/**
 * Contact-moment tickets are read by type, up to the batch limit, without RBAC.
 *
 * @covers \OCA\Pipelinq\Repair\Support\ContactMomentRows
 */
class ContactMomentRowsTest extends TestCase {

	/**
	 * @return void
	 */
	public function testRead(): void {
		$seen = [];
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->expects($this->once())->method('findAll')->willReturnCallback(
			static function (mixed ...$args) use (&$seen): array {
				$seen = $args;
				return [['id' => 'm1']];
			}
		);
		$host = new class {
			use ContactMomentRows;

			private const BATCH_LIMIT = 9;

			/**
			 * @param ObjectServiceInterface $os The object service.
			 * @return array<mixed>
			 */
			public function read(ObjectServiceInterface $os): array {
				return $this->contactMomentRows(objectService: $os, register: 'r', schema: 's');
			}
		};

		$this->assertSame([['id' => 'm1']], $host->read($objectService));
		$this->assertSame(
			['filters' => ['register' => 'r', 'schema' => 's', 'ticketType' => TicketServiceInterface::TYPE_CONTACTMOMENT], 'limit' => 9],
			$seen[0]
		);
		$this->assertContains(false, $seen);
	}//end testRead()
}//end class
