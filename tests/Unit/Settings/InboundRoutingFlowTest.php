<?php

/**
 * Tests for the shipped "Route incoming tickets" flow.
 *
 * The flow is configuration, so a wrong key fails nowhere loudly: a trigger
 * that names no schema never fires, a table OpenRegister cannot run is
 * refused only when someone saves it, and a flow shipped enabled routes
 * tickets on an instance whose administrator never asked for it. Each of
 * those is asserted here.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-routes-new-tickets-by-rules-req-itq-005
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class InboundRoutingFlowTest extends TestCase {

	/**
	 * The parsed register fragment.
	 *
	 * @var array<string, mixed>
	 */
	private array $fragment;

	/**
	 * Parse the fragment once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$path = (__DIR__ . '/../../../lib/Settings/register.d/99-inbound-ticket-intake.json');
		$this->assertFileExists(filename: $path);
		$this->fragment = (array) json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	}//end setUp()

	/**
	 * The one shipped flow on the ticket schema.
	 *
	 * @return array<string, mixed>
	 */
	private function flow(): array {
		$flows = $this->fragment['components']['schemas']['ticket']['configuration']['x-openregister-flows'];
		$this->assertCount(expectedCount: 1, haystack: $flows);

		return (array) $flows[0];
	}//end flow()

	/**
	 * The flow's nodes by id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function nodes(): array {
		$nodes = [];
		foreach ($this->flow()['nodes'] as $node) {
			$nodes[(string) $node['id']] = $node;
		}

		return $nodes;
	}//end nodes()

	/**
	 * The node of one type.
	 *
	 * @param string $type The node type.
	 *
	 * @return array<string, mixed>
	 */
	private function nodeOfType(string $type): array {
		$found = array_values(array_filter($this->flow()['nodes'], static fn (array $node): bool => $node['type'] === $type));
		$this->assertCount(expectedCount: 1, haystack: $found, message: 'exactly one ' . $type . ' node');

		return $found[0];
	}//end nodeOfType()

	/**
	 * The flow is named as the spec names it, ships for pipelinq and arrives
	 * disabled, so routing is off until an administrator adopts it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#scenario-routing-is-off-until-someone-switches-it-on
	 */
	public function testTheFlowShipsDisabledUnderItsName(): void {
		$flow = $this->flow();

		$this->assertSame(expected: 'Route incoming tickets', actual: $flow['name']);
		$this->assertSame(expected: 'pipelinq', actual: $flow['app']);
		$this->assertFalse(condition: $flow['enabled']);
		$this->assertArrayNotHasKey(key: 'owner', array: $flow);
	}//end testTheFlowShipsDisabledUnderItsName()

	/**
	 * The steps run trigger, filter, decision table, write, end, in that
	 * order along the edges.
	 *
	 * @return void
	 */
	public function testTheStepsRunInOrder(): void {
		$nodes = $this->nodes();
		$next = [];
		foreach ($this->flow()['edges'] as $edge) {
			$this->assertArrayHasKey(key: $edge['from'], array: $nodes, message: 'edge from an unknown node');
			$this->assertArrayHasKey(key: $edge['to'], array: $nodes, message: 'edge to an unknown node');
			$next[$edge['from']] = $edge['to'];
		}

		$types = [];
		$at = $this->nodeOfType(type: 'openregister.trigger-object')['id'];
		while ($at !== null) {
			$types[] = $nodes[$at]['type'];
			$at = ($next[$at] ?? null);
		}

		$this->assertSame(
			expected: [
				'openregister.trigger-object',
				'openregister.filter',
				'openregister.decision-table',
				'openregister.object-write',
				'openregister.end',
			],
			actual: $types
		);
	}//end testTheStepsRunInOrder()

	/**
	 * The trigger fires on a created ticket of this register, and the
	 * flow's trigger columns say the same, so OpenRegister wires it.
	 *
	 * @return void
	 */
	public function testTheTriggerIsATicketBeingCreated(): void {
		$trigger = $this->nodeOfType(type: 'openregister.trigger-object');
		$flow = $this->flow();

		$this->assertSame(
			expected: ['event' => 'object.created', 'register' => 'pipelinq', 'schema' => 'ticket'],
			actual: $trigger['config']
		);
		$this->assertSame(expected: 'object.created', actual: $flow['trigger']);
		$this->assertSame(expected: 'pipelinq', actual: $flow['triggerRegister']);
	}//end testTheTriggerIsATicketBeingCreated()

	/**
	 * The filter keeps an inbound ticket on one of the five channels with no
	 * category yet, so a ticket an agent already filed is left alone.
	 *
	 * @return void
	 */
	public function testTheFilterKeepsInboundTicketsWithoutACategory(): void {
		$condition = $this->nodeOfType(type: 'openregister.filter')['config']['condition'];

		$this->assertSame(
			expected: [
				'and' => [
					['in' => [['var' => 'json.channel'], ['email', 'whatsapp', 'sms', 'facebook', 'instagram']]],
					['!' => ['var' => 'json.category']],
				],
			],
			actual: $condition
		);
	}//end testTheFilterKeepsInboundTicketsWithoutACategory()

	/**
	 * The table reads the five routing facts, writes category and priority,
	 * takes the first matching rule and records the match on the ticket.
	 *
	 * @return void
	 */
	public function testTheTableReadsTheRoutingFactsAndRecordsTheMatch(): void {
		$config = $this->nodeOfType(type: 'openregister.decision-table')['config'];
		$table = $config['table'];

		$this->assertSame(expected: 'FIRST', actual: $table['hitPolicy']);
		$this->assertSame(
			expected: ['channel', 'fromAddress', 'fromDomain', 'mailbox', 'clientSegment'],
			actual: array_column($table['inputs'], 'name')
		);
		$this->assertSame(expected: ['category', 'priority'], actual: array_column($table['outputs'], 'name'));
		$this->assertSame(
			expected: [
				'channel'       => 'channel',
				'fromAddress'   => 'channelMetadata.fromAddress',
				'fromDomain'    => 'channelMetadata.fromDomain',
				'mailbox'       => 'channelMetadata.mailbox',
				'clientSegment' => 'channelMetadata.clientSegment',
			],
			actual: $config['inputMapping']
		);
		$this->assertSame(expected: ['category' => '', 'priority' => 'normal'], actual: $config['defaultOutputs']);
		$this->assertSame(expected: 'channelMetadata.routing', actual: $config['resultKey']);
	}//end testTheTableReadsTheRoutingFactsAndRecordsTheMatch()

	/**
	 * Every rule has one entry per column, which OpenRegister refuses
	 * otherwise, and the shipped table holds only the catch-all, so adopting
	 * it unchanged routes nothing anywhere.
	 *
	 * @return void
	 */
	public function testTheShippedTableHoldsOnlyTheCatchAll(): void {
		$table = $this->nodeOfType(type: 'openregister.decision-table')['config']['table'];

		$this->assertCount(expectedCount: 1, haystack: $table['rules']);
		$rule = $table['rules'][0];
		$this->assertSame(expected: 'everything-else', actual: $rule['id']);
		$this->assertSame(expected: ['-', '-', '-', '-', '-'], actual: $rule['inputEntries']);
		$this->assertSame(expected: ['', 'normal'], actual: $rule['outputEntries']);
	}//end testTheShippedTableHoldsOnlyTheCatchAll()

	/**
	 * Every routing fact the table reads from channelMetadata is one the
	 * intake writes there, so no input reads a path nothing fills.
	 *
	 * @return void
	 */
	public function testEveryMappedFactIsOneTheIntakeWrites(): void {
		$source = (string) file_get_contents(__DIR__ . '/../../../lib/Service/InboundTicketService.php');
		$mapping = $this->nodeOfType(type: 'openregister.decision-table')['config']['inputMapping'];

		foreach ($mapping as $path) {
			if (str_starts_with($path, 'channelMetadata.') === false) {
				continue;
			}

			$key = substr($path, strlen('channelMetadata.'));
			$this->assertStringContainsString(needle: "'" . $key . "'", haystack: $source, message: $key . ' is not written by the intake');
		}
	}//end testEveryMappedFactIsOneTheIntakeWrites()

	/**
	 * The write updates the ticket that fired the flow, and only the routed
	 * fields.
	 *
	 * @return void
	 */
	public function testTheWriteUpdatesTheSameTicket(): void {
		$config = $this->nodeOfType(type: 'openregister.object-write')['config'];

		$this->assertSame(expected: 'pipelinq', actual: $config['register']);
		$this->assertSame(expected: 'ticket', actual: $config['schema']);
		$this->assertSame(expected: 'update', actual: $config['operation']);
		$this->assertSame(expected: ['uuid' => '{{ id }}'], actual: $config['match']);
		$this->assertSame(expected: ['category', 'priority', 'channelMetadata'], actual: array_keys($config['fields']));
		$this->assertArrayNotHasKey(key: 'replace', array: $config);
	}//end testTheWriteUpdatesTheSameTicket()
}//end class
