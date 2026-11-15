<?php

/**
 * Behavioural tests for the FirstAdminUser trait.
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

namespace OCA\Pipelinq\Tests\Unit\Util;

use OCA\Pipelinq\Util\FirstAdminUser;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * The first admin is the acting user; no group or no members is null.
 *
 * @covers \OCA\Pipelinq\Util\FirstAdminUser
 */
class FirstAdminUserTest extends TestCase {

	/**
	 * Build a host over the given admin group.
	 *
	 * @param IGroup|null $group The admin group.
	 *
	 * @return object The host.
	 */
	private function host(?IGroup $group): object {
		$groups = $this->createStub(IGroupManager::class);
		$groups->method('get')->willReturn($group);

		return new class($groups) {
			use FirstAdminUser;

			/**
			 * @param IGroupManager $groupManager The groups.
			 */
			public function __construct(private IGroupManager $groupManager) {
			}

			/**
			 * @return IUser|null
			 */
			public function admin(): ?IUser {
				return $this->actingAdmin();
			}
		};
	}//end host()

	/**
	 * @return void
	 */
	public function testActingAdmin(): void {
		$first = $this->createStub(IUser::class);
		$group = $this->createStub(IGroup::class);
		$group->method('getUsers')->willReturn(['alice' => $first, 'bob' => $this->createStub(IUser::class)]);
		$this->assertSame($first, $this->host($group)->admin());

		$empty = $this->createStub(IGroup::class);
		$empty->method('getUsers')->willReturn([]);
		$this->assertNull($this->host($empty)->admin());
		$this->assertNull($this->host(null)->admin());
	}//end testActingAdmin()
}//end class
