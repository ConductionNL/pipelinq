<?php

/**
 * Unit tests for ForecastDealService.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ForecastDealService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the forecast-category lifecycle decision engine.
 */
class ForecastDealServiceTest extends TestCase {
	/**
	 * The app config mock.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * The service under test.
	 *
	 * @var ForecastDealService
	 */
	private ForecastDealService $service;

	/**
	 * Set up the test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueInt')->willReturn(ForecastDealService::COMMIT_THRESHOLD_DEFAULT);
		$this->service = new ForecastDealService(appConfig: $this->appConfig);
	}//end setUp()

	/**
	 * A deal without a forecast category defaults to pipeline.
	 *
	 * @return void
	 */
	public function testApplyDefaultCategorySetsPipeline(): void {
		$result = $this->service->applyDefaultCategory(['title' => 'New deal']);
		$this->assertIsArray($result);
		$this->assertSame('pipeline', $result['forecast_category']);
	}//end testApplyDefaultCategorySetsPipeline()

	/**
	 * Defaulting is idempotent for a deal that already carries a category.
	 *
	 * @return void
	 */
	public function testApplyDefaultCategoryIdempotent(): void {
		$result = $this->service->applyDefaultCategory(['forecast_category' => 'commit']);
		$this->assertNull($result);
	}//end testApplyDefaultCategoryIdempotent()

	/**
	 * A closed deal cannot be moved to an open category.
	 *
	 * @return void
	 */
	public function testValidateTransitionRejectsClosedToOpen(): void {
		$error = $this->service->validateTransition(
			oldData: ['forecast_category' => 'closed_won'],
			newData: ['forecast_category' => 'commit', 'value' => 1000]
		);
		$this->assertSame('forecast.error.closed_deal_locked', $error);
	}//end testValidateTransitionRejectsClosedToOpen()

	/**
	 * A small commit needs no justification.
	 *
	 * @return void
	 */
	public function testValidateTransitionAllowsSmallCommit(): void {
		$error = $this->service->validateTransition(
			oldData: ['forecast_category' => 'pipeline'],
			newData: ['forecast_category' => 'commit', 'value' => 45000]
		);
		$this->assertNull($error);
	}//end testValidateTransitionAllowsSmallCommit()

	/**
	 * A large commit without a justification is rejected.
	 *
	 * @return void
	 */
	public function testValidateTransitionRejectsLargeCommitWithoutJustification(): void {
		$error = $this->service->validateTransition(
			oldData: ['forecast_category' => 'pipeline'],
			newData: ['forecast_category' => 'commit', 'value' => 65000, 'commit_justification' => 'short']
		);
		$this->assertSame('forecast.error.justification_required', $error);
	}//end testValidateTransitionRejectsLargeCommitWithoutJustification()

	/**
	 * A large commit with a sufficient justification is accepted.
	 *
	 * @return void
	 */
	public function testValidateTransitionAllowsLargeCommitWithJustification(): void {
		$error = $this->service->validateTransition(
			oldData: ['forecast_category' => 'pipeline'],
			newData: [
				'forecast_category' => 'commit',
				'value' => 65000,
				'commit_justification' => 'CFO signed the contract draft and budget is approved.',
			]
		);
		$this->assertNull($error);
	}//end testValidateTransitionAllowsLargeCommitWithJustification()

	/**
	 * requiresJustification honours the configured threshold.
	 *
	 * @return void
	 */
	public function testRequiresJustificationAtThreshold(): void {
		$this->assertTrue($this->service->requiresJustification(['value' => 50001]));
		$this->assertFalse($this->service->requiresJustification(['value' => 50000]));
		$this->assertFalse($this->service->requiresJustification(['value' => 0]));
	}//end testRequiresJustificationAtThreshold()

	/**
	 * Reopening a closed deal resets the forecast category to pipeline.
	 *
	 * @return void
	 */
	public function testApplyReopenResetClearsClosedCategory(): void {
		$result = $this->service->applyReopenReset(
			oldData: ['forecast_category' => 'closed_won', 'status' => 'won'],
			newData: ['forecast_category' => 'closed_won', 'status' => 'open']
		);
		$this->assertIsArray($result);
		$this->assertSame('pipeline', $result['forecast_category']);
	}//end testApplyReopenResetClearsClosedCategory()

	/**
	 * No reset applies when the deal stays closed.
	 *
	 * @return void
	 */
	public function testApplyReopenResetNoopWhenStillClosed(): void {
		$result = $this->service->applyReopenReset(
			oldData: ['forecast_category' => 'closed_won', 'status' => 'won'],
			newData: ['forecast_category' => 'closed_won', 'status' => 'won']
		);
		$this->assertNull($result);
	}//end testApplyReopenResetNoopWhenStillClosed()

	/**
	 * isClosedCategory recognises both closed values and nothing else.
	 *
	 * @return void
	 */
	public function testIsClosedCategory(): void {
		$this->assertTrue($this->service->isClosedCategory('closed_won'));
		$this->assertTrue($this->service->isClosedCategory('closed_lost'));
		$this->assertFalse($this->service->isClosedCategory('commit'));
		$this->assertFalse($this->service->isClosedCategory(null));
	}//end testIsClosedCategory()

	/**
	 * The open/closed partition and default are sourced from the lead schema's
	 * `x-pipelinq-forecast-lifecycle` annotation (ADR-031). With the real bundled
	 * schema, behavior is identical to the prior hardcoded constants: a
	 * closed->open move is rejected and the create-default is pipeline.
	 *
	 * @return void
	 */
	public function testPartitionSourcedFromSchema(): void {
		// Default comes from the schema annotation (pipeline).
		$created = $this->service->applyDefaultCategory(['title' => 'X']);
		$this->assertSame('pipeline', $created['forecast_category']);

		// best_case is a schema-declared OPEN category, so closed->best_case locks.
		$error = $this->service->validateTransition(
			oldData: ['forecast_category' => 'closed_lost'],
			newData: ['forecast_category' => 'best_case', 'value' => 100]
		);
		$this->assertSame('forecast.error.closed_deal_locked', $error);

		// closed_won is a schema-declared CLOSED category (not open), so a
		// closed_lost -> closed_won move is NOT a closed->open unlock and passes
		// the lock guard (it is a closed-to-closed correction).
		$ok = $this->service->validateTransition(
			oldData: ['forecast_category' => 'closed_lost'],
			newData: ['forecast_category' => 'closed_won', 'value' => 100]
		);
		$this->assertNull($ok);
	}//end testPartitionSourcedFromSchema()

	/**
	 * When the schema is unreadable the service degrades to its mirrored
	 * fallback constants, so the closed-deal lock never silently disappears.
	 *
	 * @return void
	 */
	public function testPartitionFallsBackWhenSchemaUnreadable(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn(ForecastDealService::COMMIT_THRESHOLD_DEFAULT);
		$service = new ForecastDealService(
			appConfig: $appConfig,
			lifecycleGraph: new \OCA\Pipelinq\Service\Lifecycle\SchemaLifecycleGraph(settingsDir: '/nonexistent/Settings'),
		);

		$this->assertTrue($service->isClosedCategory('closed_won'));
		$error = $service->validateTransition(
			oldData: ['forecast_category' => 'closed_won'],
			newData: ['forecast_category' => 'commit', 'value' => 100]
		);
		$this->assertSame('forecast.error.closed_deal_locked', $error);

		$created = $service->applyDefaultCategory(['title' => 'X']);
		$this->assertSame('pipeline', $created['forecast_category']);
	}//end testPartitionFallsBackWhenSchemaUnreadable()
	/**
	 * A commit or best-case deal whose close date moves past the end of its quarter
	 * needs a reason; with one it passes (REQ-FRC-012).
	 *
	 * @return void
	 */
	public function testASlippedCommitDealNeedsAReason(): void {
		$old = ['forecast_category' => 'commit', 'value' => 100, 'expectedCloseDate' => '2026-03-20'];
		foreach (['commit', 'best_case'] as $category) {
			$new = ['forecast_category' => $category, 'value' => 100, 'expectedCloseDate' => '2026-04-01'];
			$this->assertSame('forecast.error.slip_reason_required', $this->service->validateTransition($old, $new), $category);
			$this->assertSame('forecast.error.slip_reason_required', $this->service->validateTransition($old, $new + ['slip_reason' => ' too short ']), $category);
			$this->assertNull($this->service->validateTransition($old, $new + ['slip_reason' => 'Budget moved to Q2 by the board.']), $category);
		}
	}//end testASlippedCommitDealNeedsAReason()

	/**
	 * A move within the same quarter, an earlier date, a pipeline deal, or a missing date is no slip.
	 *
	 * @return void
	 */
	public function testOtherCloseDateChangesNeedNoReason(): void {
		$old = ['forecast_category' => 'commit', 'value' => 100, 'expectedCloseDate' => '2026-01-10'];

		$this->assertNull($this->service->validateTransition($old, ['expectedCloseDate' => '2026-03-31T23:00:00+00:00'] + $old));
		$this->assertNull($this->service->validateTransition($old, ['expectedCloseDate' => '2025-12-01'] + $old));
		$this->assertNull($this->service->validateTransition($old, ['forecast_category' => 'pipeline', 'expectedCloseDate' => '2026-07-01'] + $old));
		$this->assertNull($this->service->validateTransition(['forecast_category' => 'commit', 'value' => 100], ['expectedCloseDate' => '2026-07-01'] + $old));
		$this->assertNull($this->service->validateTransition($old, ['expectedCloseDate' => 'volgend jaar'] + $old));
	}//end testOtherCloseDateChangesNeedNoReason()

	/**
	 * A valid slip records where it slipped from and when, once.
	 *
	 * @return void
	 */
	public function testAValidSlipIsRecordedOnce(): void {
		$old = ['forecast_category' => 'best_case', 'value' => 100, 'expectedCloseDate' => '2026-06-30T10:00:00+00:00'];
		$new = ['forecast_category' => 'best_case', 'value' => 100, 'expectedCloseDate' => '2026-07-15', 'slip_reason' => 'Customer postponed the decision.'];
		$now = new \DateTimeImmutable('2026-06-29T08:00:00+00:00');

		$slipped = $this->service->applySlip($old, $new, $now);

		$this->assertSame($new + ['slipped_from' => '2026-06-30', 'slipped_at' => '2026-06-29T08:00:00+00:00'], $slipped);
		$this->assertNull($this->service->applySlip($old, $slipped, $now), 'already recorded');
		$this->assertNull($this->service->applySlip($old, ['slip_reason' => ''] + $new, $now), 'refused');
		$this->assertNull($this->service->applySlip($old, ['expectedCloseDate' => '2026-06-01'] + $new, $now), 'no slip');
	}//end testAValidSlipIsRecordedOnce()
}//end class
