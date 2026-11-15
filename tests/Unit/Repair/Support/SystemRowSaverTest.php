<?php

/**
 * Behavioural tests for the SystemRowSaver trait.
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

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Repair\Support\SystemRowSaver;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * The writer saves by the row's id as the admin, with RBAC and multitenancy off.
 *
 * @covers \OCA\Pipelinq\Repair\Support\SystemRowSaver
 */
class SystemRowSaverTest extends TestCase {

	/**
	 * @return void
	 */
	public function testWriter(): void {
		$admin = $this->createStub(IUser::class);
		$saved = $this->createStub(ObjectEntityInterface::class);
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$seen = [];
		$objectService->expects($this->once())->method('saveObject')->willReturnCallback(
			static function (mixed ...$args) use (&$seen, $saved): ObjectEntityInterface {
				$seen = $args;
				return $saved;
			}
		);

		$host = new class {
			use SystemRowSaver;

			/**
			 * @param ObjectServiceInterface $os    The object service.
			 * @param IUser|null             $admin The admin.
			 * @return callable
			 */
			public function writer(ObjectServiceInterface $os, ?IUser $admin): callable {
				return $this->rowSaver(objectService: $os, register: 'reg', schema: 'sch', actingAdmin: $admin);
			}
		};

		$save = $host->writer($objectService, $admin);
		$this->assertSame($saved, $save(['id' => 'r1', 'a' => 1]));
		$this->assertSame([['id' => 'r1', 'a' => 1], [], 'reg', 'sch', 'r1', false, false], array_slice($seen, 0, 7));
		$this->assertContains($admin, $seen);
	}//end testWriter()
}//end class
