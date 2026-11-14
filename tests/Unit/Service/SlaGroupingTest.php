<?php

/**
 * Unit tests for SlaGrouping.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\SlaGrouping;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SlaGrouping: the group keys a tracked SLA subject falls under, per groupBy mode, and the group names.
 *
 * @covers \OCA\Pipelinq\Service\SlaGrouping
 */
class SlaGroupingTest extends TestCase {

	/**
	 * A subject with the four parts keysFor() destructures.
	 *
	 * @param array<string,mixed> $parts Overrides.
	 *
	 * @return array<string,mixed>
	 */
	private function subject(array $parts=[]): array {
		return array_merge(['policyId' => 'p1', 'kinds' => ['response', 'resolution'], 'tracked' => [], 'event' => []], $parts);
	}//end subject()

	/**
	 * Policy, target and unknown modes.
	 *
	 * @return void
	 */
	public function testPolicyTargetAndDefaultKeys(): void {
		self::assertSame(expected: ['p1'], actual: (new SlaGrouping(context: ['groupBy' => 'policy']))->keysFor(subject: $this->subject()));
		self::assertSame(
			expected: ['unspecified'],
			actual: (new SlaGrouping(context: ['groupBy' => 'policy']))->keysFor(subject: $this->subject(parts: ['policyId' => '']))
		);
		self::assertSame(
			expected: ['response', 'resolution'],
			actual: (new SlaGrouping(context: ['groupBy' => 'target']))->keysFor(subject: $this->subject())
		);
		self::assertSame(expected: ['all'], actual: (new SlaGrouping(context: ['groupBy' => 'other']))->keysFor(subject: $this->subject()));
	}//end testPolicyTargetAndDefaultKeys()

	/**
	 * The tier comes from the event, then the tracked slaTier, then customerTier, then the policy tier; '*' means none.
	 *
	 * @return void
	 */
	public function testTierKeyFallsBackInOrder(): void {
		$policies = [
			'p1' => ['customerTier' => 'gold'],
			'p2' => ['customerTier' => '*'],
		];
		$grouping = new SlaGrouping(context: ['groupBy' => 'tier', 'policies' => $policies]);

		self::assertSame(
			expected: ['vip'],
			actual: $grouping->keysFor(subject: $this->subject(parts: ['event' => ['customerTier' => 'vip'], 'tracked' => ['slaTier' => 'silver']]))
		);
		self::assertSame(
			expected: ['silver'],
			actual: $grouping->keysFor(subject: $this->subject(
				parts: ['event' => ['customerTier' => ''], 'tracked' => ['slaTier' => 'silver', 'customerTier' => 'bronze']]
			))
		);
		self::assertSame(
			expected: ['bronze'],
			actual: $grouping->keysFor(subject: $this->subject(parts: ['tracked' => ['customerTier' => 'bronze']]))
		);
		self::assertSame(expected: ['gold'], actual: $grouping->keysFor(subject: $this->subject()));
		self::assertSame(expected: ['unspecified'], actual: $grouping->keysFor(subject: $this->subject(parts: ['policyId' => 'p2'])));
		self::assertSame(
			expected: ['unspecified'],
			actual: $grouping->keysFor(subject: $this->subject(parts: ['policyId' => 'missing', 'tracked' => ['slaTier' => 3]]))
		);
	}//end testTierKeyFallsBackInOrder()

	/**
	 * Team and customer prefer the event over the tracked record.
	 *
	 * @return void
	 */
	public function testTeamAndCustomerKeys(): void {
		$team     = new SlaGrouping(context: ['groupBy' => 'team']);
		$customer = new SlaGrouping(context: ['groupBy' => 'customer']);

		self::assertSame(
			expected: ['support'],
			actual: $team->keysFor(subject: $this->subject(parts: ['event' => ['team' => 'support'], 'tracked' => ['team' => 'sales']]))
		);
		self::assertSame(expected: ['sales'], actual: $team->keysFor(subject: $this->subject(parts: ['tracked' => ['team' => 'sales']])));
		self::assertSame(expected: ['unspecified'], actual: $team->keysFor(subject: $this->subject()));
		self::assertSame(
			expected: ['org-1'],
			actual: $customer->keysFor(subject: $this->subject(parts: ['event' => ['organisationId' => 'org-1'], 'tracked' => ['client' => 'c-1']]))
		);
		self::assertSame(expected: ['c-1'], actual: $customer->keysFor(subject: $this->subject(parts: ['tracked' => ['client' => 'c-1']])));
	}//end testTeamAndCustomerKeys()

	/**
	 * Names come from the policy or client lookup, else the key itself.
	 *
	 * @return void
	 */
	public function testNameOf(): void {
		$policies = new SlaGrouping(context: ['groupBy' => 'policy', 'policies' => ['p1' => ['name' => 'Gold SLA'], 'p2' => []]]);
		$clients  = new SlaGrouping(context: ['groupBy' => 'customer', 'clients' => ['c-1' => 'Acme']]);
		$teams    = new SlaGrouping(context: ['groupBy' => 'team']);

		self::assertSame(expected: 'Gold SLA', actual: $policies->nameOf(key: 'p1'));
		self::assertSame(expected: 'p2', actual: $policies->nameOf(key: 'p2'));
		self::assertSame(expected: 'Acme', actual: $clients->nameOf(key: 'c-1'));
		self::assertSame(expected: 'c-2', actual: $clients->nameOf(key: 'c-2'));
		self::assertSame(expected: 'support', actual: $teams->nameOf(key: 'support'));
	}//end testNameOf()
}//end class
