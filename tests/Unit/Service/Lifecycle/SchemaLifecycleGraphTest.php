<?php

/**
 * Unit tests for SchemaLifecycleGraph — schema-declared transition resolution.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pipelinq-lifecycle-batch-a/specs/openregister-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Lifecycle;

use OCA\Pipelinq\Service\Lifecycle\SchemaLifecycleGraph;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the helper resolves the `x-openregister-lifecycle` transition graph
 * from the bundled register JSON for all three Batch A schemas, and degrades to
 * an empty map for an unknown schema (so callers fall back safely).
 */
class SchemaLifecycleGraphTest extends TestCase {
	/**
	 * Build a helper pointed at the real bundled Settings directory.
	 *
	 * @return SchemaLifecycleGraph
	 */
	private function graph(): SchemaLifecycleGraph {
		return new SchemaLifecycleGraph(settingsDir: __DIR__ . '/../../../../lib/Settings');
	}//end graph()

	/**
	 * The Task schema graph matches the prior hardcoded ALLOWED_TRANSITIONS.
	 *
	 * @return void
	 */
	public function testTaskAdjacencyMatchesDeclaredGraph(): void {
		$this->assertSame(
			expected: [
				'open' => ['in_behandeling'],
				'in_behandeling' => ['afgerond', 'verlopen'],
				'afgerond' => ['open'],
				'verlopen' => ['open'],
			],
			actual: $this->graph()->adjacencyFor(schemaSlug: 'task')
		);
	}//end testTaskAdjacencyMatchesDeclaredGraph()

	/**
	 * The walkInTicket FULL graph includes terminal states as empty-array keys.
	 *
	 * @return void
	 */
	public function testWalkInTicketFullAdjacencyIncludesTerminalStates(): void {
		$this->assertSame(
			expected: [
				'waiting' => ['called', 'abandoned'],
				'called' => ['served', 'abandoned'],
				'served' => [],
				'abandoned' => [],
			],
			actual: $this->graph()->fullAdjacencyFor(schemaSlug: 'walkInTicket')
		);
	}//end testWalkInTicketFullAdjacencyIncludesTerminalStates()

	/**
	 * The loyaltyProgramme graph declares the activate edge plus operational edges.
	 *
	 * @return void
	 */
	public function testLoyaltyProgrammeAdjacencyDeclaresActivateEdge(): void {
		$graph = $this->graph()->adjacencyFor(schemaSlug: 'loyaltyProgramme');
		$this->assertArrayHasKey(key: 'concept', array: $graph);
		$this->assertSame(expected: ['actief'], actual: $graph['concept']);
		$this->assertContains(needle: 'gepauzeerd', haystack: $graph['actief']);
		$this->assertContains(needle: 'beeindigd', haystack: $graph['actief']);
	}//end testLoyaltyProgrammeAdjacencyDeclaresActivateEdge()

	/**
	 * The contract FULL graph mirrors the ContractService reachability and seeds
	 * terminal states (renewed/churned/cancelled) as empty-array keys.
	 *
	 * @return void
	 */
	public function testContractFullAdjacencyMatchesReachability(): void {
		$this->assertSame(
			expected: [
				'draft' => ['active', 'expiring', 'renewed', 'churned', 'cancelled'],
				'expiring' => ['active', 'draft', 'renewed', 'churned', 'cancelled'],
				'active' => ['draft', 'expiring', 'renewed', 'churned', 'cancelled'],
				'renewed' => [],
				'churned' => [],
				'cancelled' => [],
			],
			actual: $this->graph()->fullAdjacencyFor(schemaSlug: 'contract')
		);
	}//end testContractFullAdjacencyMatchesReachability()

	/**
	 * The contract schema declares the canonical terminal states.
	 *
	 * @return void
	 */
	public function testContractLifecycleDeclaresTerminalStates(): void {
		$lifecycle = $this->graph()->lifecycleFor(schemaSlug: 'contract');
		$this->assertIsArray($lifecycle);
		$this->assertSame(
			expected: ['renewed', 'churned', 'cancelled'],
			actual: $lifecycle['terminal']
		);
	}//end testContractLifecycleDeclaresTerminalStates()

	/**
	 * The booking FULL graph matches the prior hardcoded allowedTransitions().
	 *
	 * @return void
	 */
	public function testBookingFullAdjacencyMatchesPriorMap(): void {
		$this->assertSame(
			expected: [
				'pending-deposit' => [
					'confirmed',
					'cancelled-by-customer',
					'cancelled-by-business',
					'rescheduled',
				],
				'confirmed' => [
					'completed',
					'no-show',
					'cancelled-by-customer',
					'cancelled-by-business',
					'rescheduled',
				],
				'completed' => [],
				'no-show' => [],
				'cancelled-by-customer' => [],
				'cancelled-by-business' => [],
				'rescheduled' => [],
			],
			actual: $this->graph()->fullAdjacencyFor(schemaSlug: 'booking')
		);
	}//end testBookingFullAdjacencyMatchesPriorMap()

	/**
	 * The lead schema declares the forecast-category partition under the
	 * pipelinq-namespaced (non-OR-enforced) configuration key.
	 *
	 * @return void
	 */
	public function testForecastLifecycleConfigurationIsResolved(): void {
		$annotation = $this->graph()->configurationFor(
			schemaSlug: 'lead',
			key: 'x-pipelinq-forecast-lifecycle'
		);

		$this->assertIsArray($annotation);
		$this->assertSame(expected: 'pipeline', actual: $annotation['default']);
		$this->assertSame(
			expected: ['commit', 'best_case', 'pipeline', 'omitted'],
			actual: $annotation['open']
		);
		$this->assertSame(
			expected: ['closed_won', 'closed_lost'],
			actual: $annotation['closed']
		);
	}//end testForecastLifecycleConfigurationIsResolved()

	/**
	 * configurationFor returns null for an absent key (callers fall back).
	 *
	 * @return void
	 */
	public function testConfigurationForUnknownKeyYieldsNull(): void {
		$this->assertNull($this->graph()->configurationFor(schemaSlug: 'lead', key: 'x-does-not-exist'));
		$this->assertNull($this->graph()->configurationFor(schemaSlug: 'doesNotExist', key: 'x-pipelinq-forecast-lifecycle'));
	}//end testConfigurationForUnknownKeyYieldsNull()

	/**
	 * An unknown schema yields an empty map (callers fall back).
	 *
	 * @return void
	 */
	public function testUnknownSchemaYieldsEmptyMap(): void {
		$this->assertSame(expected: [], actual: $this->graph()->adjacencyFor(schemaSlug: 'doesNotExist'));
		$this->assertSame(expected: [], actual: $this->graph()->fullAdjacencyFor(schemaSlug: 'doesNotExist'));
	}//end testUnknownSchemaYieldsEmptyMap()

	/**
	 * An unreadable settings directory yields an empty map (safe fallback path).
	 *
	 * @return void
	 */
	public function testUnreadableSettingsDirYieldsEmptyMap(): void {
		$graph = new SchemaLifecycleGraph(settingsDir: '/nonexistent/path/Settings');
		$this->assertSame(expected: [], actual: $graph->adjacencyFor(schemaSlug: 'task'));
	}//end testUnreadableSettingsDirYieldsEmptyMap()
}//end class
