<?php

/**
 * Behavioural tests for the SocialSpendBudget trait.
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

namespace OCA\Pipelinq\Tests\Unit\Service\Support;

use OCA\Pipelinq\Service\BudgetService;
use OCA\Pipelinq\Service\Social\SocialNetworkAdapter;
use OCA\Pipelinq\Service\Support\SocialSpendBudget;
use PHPUnit\Framework\TestCase;

/**
 * Free calls fit; charged ones ask the budget for the tenant and network.
 *
 * @covers \OCA\Pipelinq\Service\Support\SocialSpendBudget
 */
class SocialSpendBudgetTest extends TestCase {

	/**
	 * @return void
	 */
	public function testWithinBudget(): void {
		$budget = $this->createMock(BudgetService::class);
		$budget->expects($this->exactly(2))->method('canSend')
			->with('t1', 'x', 0.5)
			->willReturnOnConsecutiveCalls(true, false);
		$adapter = $this->createStub(SocialNetworkAdapter::class);
		$adapter->method('network')->willReturn('x');

		$host = new class($budget) {
			use SocialSpendBudget;

			/**
			 * @param BudgetService $budget The budget.
			 */
			public function __construct(private BudgetService $budget) {
			}

			/**
			 * @param SocialNetworkAdapter $adapter The adapter.
			 * @param float                $cost    The cost.
			 * @return bool
			 */
			public function fits(SocialNetworkAdapter $adapter, float $cost): bool {
				return $this->withinBudget(adapter: $adapter, cost: $cost);
			}

			/**
			 * @return string
			 */
			private function getTenantId(): string {
				return 't1';
			}
		};

		$this->assertTrue($host->fits($adapter, 0.0));
		$this->assertTrue($host->fits($adapter, 0.5));
		$this->assertFalse($host->fits($adapter, 0.5));
	}//end testWithinBudget()
}//end class
