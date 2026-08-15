<?php

/**
 * Unit tests for PointsRuleEngine.
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
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\PointsRuleEngine;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PointsRuleEngine.
 */
class PointsRuleEngineTest extends TestCase {
	private PointsRuleEngine $engine;

	protected function setUp(): void {
		$container = $this->createMock(ContainerInterface::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->engine = new PointsRuleEngine(
			appConfig: $appConfig,
			logger: $logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);
	}//end setUp()

	public function testCalculatePointsFixedFormula(): void {
		$points = $this->engine->calculatePoints(
			formula: ['type' => 'fixed', 'value' => 50],
			amount: 0
		);
		$this->assertSame(50, $points);
	}//end testCalculatePointsFixedFormula()

	public function testCalculatePointsPercentageFormula(): void {
		$points = $this->engine->calculatePoints(
			formula: ['type' => 'percentage', 'value' => 1],
			amount: 45.50
		);
		$this->assertSame(45, $points, 'Percentage formule must floor 45.5 -> 45');
	}//end testCalculatePointsPercentageFormula()

	public function testCalculatePointsAppliesMultiplier(): void {
		$points = $this->engine->calculatePoints(
			formula: ['type' => 'percentage', 'value' => 1],
			amount: 100,
			multiplier: 1.25
		);
		$this->assertSame(125, $points);
	}//end testCalculatePointsAppliesMultiplier()

	public function testCalculatePointsSteppedFormula(): void {
		$points = $this->engine->calculatePoints(
			formula: [
				'type' => 'stepped',
				'brackets' => [
					['amount' => 0,   'points' => 5],
					['amount' => 100, 'points' => 50],
					['amount' => 250, 'points' => 200],
				],
			],
			amount: 175
		);
		$this->assertSame(50, $points);
	}//end testCalculatePointsSteppedFormula()

	public function testEvaluateConditionEmptyConditionMatches(): void {
		$this->assertTrue(
			$this->engine->evaluateCondition(condition: [], context: ['category' => 'food'])
		);
	}//end testEvaluateConditionEmptyConditionMatches()

	public function testEvaluateConditionExcludeCategoryFiltersOut(): void {
		$this->assertFalse(
			$this->engine->evaluateCondition(
				condition: ['excludeCategory' => ['gift-card']],
				context: ['category' => 'gift-card']
			)
		);
	}//end testEvaluateConditionExcludeCategoryFiltersOut()

	public function testEvaluateConditionIncludeCategoryMatches(): void {
		$this->assertTrue(
			$this->engine->evaluateCondition(
				condition: ['category' => ['food', 'drink']],
				context: ['category' => 'food']
			)
		);
	}//end testEvaluateConditionIncludeCategoryMatches()

	public function testEvaluateConditionDayOfWeek(): void {
		// Pick a known Tuesday.
		$this->assertTrue(
			$this->engine->evaluateCondition(
				condition: ['dayOfWeek' => 'tuesday'],
				context: ['timestamp' => '2026-05-19T10:00:00Z']
			)
		);
		$this->assertFalse(
			$this->engine->evaluateCondition(
				condition: ['dayOfWeek' => 'tuesday'],
				context: ['timestamp' => '2026-05-20T10:00:00Z'] // Wednesday
			)
		);
	}//end testEvaluateConditionDayOfWeek()

	public function testEvaluateConditionTimeRange(): void {
		$this->assertTrue(
			$this->engine->evaluateCondition(
				condition: ['timeRange' => '14:00-18:00'],
				context: ['timestamp' => '2026-05-19T15:00:00Z']
			)
		);
		$this->assertFalse(
			$this->engine->evaluateCondition(
				condition: ['timeRange' => '14:00-18:00'],
				context: ['timestamp' => '2026-05-19T13:00:00Z']
			)
		);
	}//end testEvaluateConditionTimeRange()

	public function testApplyMaxPerCustomerCaps(): void {
		$this->assertSame(
			0,
			$this->engine->applyMaxPerCustomer(earnedInPeriod: 100, pointsToAward: 50, max: 100),
			'When max is reached, award 0'
		);
		$this->assertSame(
			20,
			$this->engine->applyMaxPerCustomer(earnedInPeriod: 80, pointsToAward: 50, max: 100),
			'When remaining < requested, award the remaining quota'
		);
		$this->assertSame(
			50,
			$this->engine->applyMaxPerCustomer(earnedInPeriod: 0, pointsToAward: 50, max: null),
			'When max is null, award the full amount'
		);
	}//end testApplyMaxPerCustomerCaps()

	public function testGetHighestPriorityRuleReturnsFirst(): void {
		$rules = [
			['name' => 'A', 'priority' => 5],
			['name' => 'B', 'priority' => 1],
		];
		$this->assertSame('A', $this->engine->getHighestPriorityRule($rules)['name']);
		$this->assertNull($this->engine->getHighestPriorityRule([]));
	}//end testGetHighestPriorityRuleReturnsFirst()
}//end class
