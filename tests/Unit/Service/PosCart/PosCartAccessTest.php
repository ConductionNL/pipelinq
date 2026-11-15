<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/till-server-priced-lines/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\PosCart;

use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Service\PosCart\PosCartAccess;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Only someone who may change a sale reprices it, and only while it is open.
 */
class PosCartAccessTest extends TestCase {
	public function testTheCashierMayChangeTheirOpenSale(): void {
		$this->access(uid: 'pieter')->assertMayChange(sale: ['cashier' => 'pieter', 'status' => 'parked']);

		$this->addToAssertionCount(1);
	}//end testTheCashierMayChangeTheirOpenSale()

	public function testAnotherUserOutsideThePosGroupIsRefused(): void {
		$this->expectException(OCSForbiddenException::class);

		$this->access(uid: 'sanne')->assertMayChange(sale: ['cashier' => 'pieter', 'status' => 'draft']);
	}//end testAnotherUserOutsideThePosGroupIsRefused()

	public function testNobodySignedInIsRefused(): void {
		$this->expectException(OCSForbiddenException::class);

		$this->access(uid: null)->assertMayChange(sale: ['cashier' => '', 'status' => 'draft']);
	}//end testNobodySignedInIsRefused()

	public function testAConfirmedSaleIsNoLongerChanged(): void {
		$this->expectException(OCSBadRequestException::class);

		$this->access(uid: 'pieter')->assertMayChange(sale: ['cashier' => 'pieter', 'status' => 'confirmed']);
	}//end testAConfirmedSaleIsNoLongerChanged()

	/**
	 * The access rule for a signed-in user who is in no group.
	 *
	 * @param string|null $uid The user, or null for nobody signed in.
	 *
	 * @return PosCartAccess The rule.
	 */
	private function access(?string $uid): PosCartAccess {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('pos');
		return new PosCartAccess(policy: new PosAccessPolicy(appConfig: $appConfig, groupManager: $this->groupManager()), userSession: $session);
	}//end access()

	/**
	 * A group manager in which nobody is an admin or in a group.
	 *
	 * @return IGroupManager The double.
	 */
	private function groupManager(): IGroupManager {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturn(false);
		return $groups;
	}//end groupManager()
}//end class
