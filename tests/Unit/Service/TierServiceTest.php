<?php

/**
 * Unit tests for TierService.
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
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\TierService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TierService.
 *
 * Only the stateless helper applyTierBenefits is tested here without OR mocks;
 * walk + downgrade + emit-event paths are covered by integration tests
 * (no in-memory ObjectService stub yet in pipelinq).
 */
class TierServiceTest extends TestCase {
	public function testApplyTierBenefitsDefaultsToOne(): void {
		$service = $this->makeServiceStub();
		$this->assertEqualsWithDelta(
			1.0,
			$service->applyTierBenefits(tier: []),
			0.001,
			'Tier without benefits returns multiplier 1.0'
		);
	}//end testApplyTierBenefitsDefaultsToOne()

	public function testApplyTierBenefitsReadsMultiplier(): void {
		$service = $this->makeServiceStub();
		$this->assertEqualsWithDelta(
			1.25,
			$service->applyTierBenefits(tier: ['benefits' => ['pointsMultiplier' => 1.25]]),
			0.001
		);
	}//end testApplyTierBenefitsReadsMultiplier()

	public function testApplyTierBenefitsRejectsMalformedBenefits(): void {
		$service = $this->makeServiceStub();
		$this->assertEqualsWithDelta(
			1.0,
			$service->applyTierBenefits(tier: ['benefits' => 'not-an-object']),
			0.001
		);
	}//end testApplyTierBenefitsRejectsMalformedBenefits()

	/**
	 * Construct a TierService with mocked collaborators.
	 *
	 * @return TierService
	 */
	private function makeServiceStub(): TierService {
		$container = $this->createMock(\Psr\Container\ContainerInterface::class);
		$appConfig = $this->createMock(\OCP\IAppConfig::class);
		$accountService = $this->createMock(\OCA\Pipelinq\Service\LoyaltyAccountService::class);
		$eventDispatcher = $this->createMock(\OCP\EventDispatcher\IEventDispatcher::class);
		$logger = $this->createMock(\Psr\Log\LoggerInterface::class);

		return new TierService(
			appConfig: $appConfig,
			accountService: $accountService,
			eventDispatcher: $eventDispatcher,
			logger: $logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);
	}//end makeServiceStub()
}//end class
