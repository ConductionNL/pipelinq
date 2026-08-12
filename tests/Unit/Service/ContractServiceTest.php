<?php

/**
 * Unit tests for ContractService.
 *
 * Covers the lifecycle transition guards (terminal-state immutability,
 * renewed-requires-won-lead, expiring-engine-only, cancelled-requires-reason),
 * unique contract-number generation, and successor-draft construction.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Pipelinq\Service\ContractService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Test suite for ContractService.
 */
class ContractServiceTest extends TestCase {
	/**
	 * The service under test.
	 *
	 * @var ContractService
	 */
	private ContractService $service;

	/**
	 * Build the service with stubbed dependencies.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = new ContractService(
			$this->createMock(IAppConfig::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * A terminal-state contract rejects any transition.
	 *
	 * @return void
	 */
	public function testTerminalStateIsImmutable(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->assertTransitionAllowed(['status' => 'churned'], 'active');
	}//end testTerminalStateIsImmutable()

	/**
	 * `renewed` requires a won renewal lead.
	 *
	 * @return void
	 */
	public function testRenewedRequiresWonLead(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->assertTransitionAllowed(['status' => 'expiring', 'renewalLeadOutcome' => ''], 'renewed');
	}//end testRenewedRequiresWonLead()

	/**
	 * `renewed` is allowed once the renewal lead is won.
	 *
	 * @return void
	 */
	public function testRenewedAllowedWithWonLead(): void {
		$this->service->assertTransitionAllowed(
			['status' => 'expiring', 'renewalLeadOutcome' => 'won'],
			'renewed'
		);
		$this->addToAssertionCount(1);
	}//end testRenewedAllowedWithWonLead()

	/**
	 * `expiring` may only be set by the renewal engine.
	 *
	 * @return void
	 */
	public function testExpiringEngineOnly(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->assertTransitionAllowed(['status' => 'active'], 'expiring', false);
	}//end testExpiringEngineOnly()

	/**
	 * `expiring` is allowed via the engine path.
	 *
	 * @return void
	 */
	public function testExpiringAllowedByEngine(): void {
		$this->service->assertTransitionAllowed(['status' => 'active'], 'expiring', true);
		$this->addToAssertionCount(1);
	}//end testExpiringAllowedByEngine()

	/**
	 * `cancelled` requires a non-empty cancellationReason.
	 *
	 * @return void
	 */
	public function testCancelledRequiresReason(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->assertTransitionAllowed(['status' => 'active'], 'cancelled');
	}//end testCancelledRequiresReason()

	/**
	 * An unknown target status is rejected.
	 *
	 * @return void
	 */
	public function testUnknownStatusRejected(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->assertTransitionAllowed(['status' => 'active'], 'bogus');
	}//end testUnknownStatusRejected()

	/**
	 * Legal graph edges declared in the schema are accepted (draft->active,
	 * active->draft, expiring->active), preserving prior reachability.
	 *
	 * @return void
	 */
	public function testLegalGraphEdgesAccepted(): void {
		$this->service->assertTransitionAllowed(['status' => 'draft'], 'active');
		$this->service->assertTransitionAllowed(['status' => 'active'], 'draft');
		$this->service->assertTransitionAllowed(['status' => 'expiring'], 'active');
		$this->addToAssertionCount(3);
	}//end testLegalGraphEdgesAccepted()

	/**
	 * The contract schema's `x-openregister-lifecycle` is the source of truth:
	 * the derived graph rejects an edge that the schema does not declare from a
	 * given source. (draft -> draft is a no-op and skipped; we assert a real
	 * illegal edge cannot be reached.) Because the graph mirrors prior
	 * reachability, every previously-legal edge stays legal and no new edge opens.
	 *
	 * @return void
	 */
	public function testSchemaGraphIsSourceOfTruthForTerminal(): void {
		// `renewed` is declared terminal in the schema; deriving the terminal set
		// from the schema must still reject any transition out of it.
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('terminal state "renewed"');
		$this->service->assertTransitionAllowed(['status' => 'renewed'], 'active');
	}//end testSchemaGraphIsSourceOfTruthForTerminal()

	/**
	 * When the schema declaration is unreadable, terminal immutability still
	 * holds via the mirrored fallback constant (never regresses).
	 *
	 * @return void
	 */
	public function testTerminalImmutabilityFallsBackWhenSchemaUnreadable(): void {
		$service = new ContractService(
			$this->createMock(IAppConfig::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class),
			new \OCA\Pipelinq\Service\Lifecycle\SchemaLifecycleGraph(settingsDir: '/nonexistent/Settings'),
		);

		$this->expectException(InvalidArgumentException::class);
		$service->assertTransitionAllowed(['status' => 'churned'], 'active');
	}//end testTerminalImmutabilityFallsBackWhenSchemaUnreadable()

	/**
	 * Contract numbers are unique and increment within the year.
	 *
	 * @return void
	 */
	public function testContractNumberGeneration(): void {
		$existing = [
			['contractNumber' => 'C-2026-001'],
			['contractNumber' => 'C-2026-002'],
			['contractNumber' => 'C-2025-099'],
		];

		$next = $this->service->generateContractNumber($existing, 2026);
		$this->assertSame('C-2026-003', $next);

		// First of a fresh year.
		$this->assertSame('C-2027-001', $this->service->generateContractNumber($existing, 2027));
	}//end testContractNumberGeneration()

	/**
	 * A successor draft starts the day after the predecessor's endDate and
	 * links back via predecessorContractRef.
	 *
	 * @return void
	 */
	public function testSuccessorDraft(): void {
		$predecessor = [
			'id' => 'pred-1',
			'title' => 'Support',
			'clientRef' => 'client-1',
			'billingInterval' => 'monthly',
			'valuePerInterval' => 750,
			'currency' => 'EUR',
			'endDate' => '2027-06-30',
			'autoRenew' => true,
			'noticePeriodDays' => 60,
			'ownerId' => 'maria',
		];

		$successor = $this->service->buildSuccessorDraft($predecessor);

		$this->assertSame('draft', $successor['status']);
		$this->assertSame('2027-07-01', $successor['startDate']);
		$this->assertSame('pred-1', $successor['predecessorContractRef']);
		$this->assertSame(750.0, $successor['valuePerInterval']);
		$this->assertSame('maria', $successor['ownerId']);
		$this->assertSame('', $successor['renewalLeadOutcome']);
	}//end testSuccessorDraft()
}//end class
