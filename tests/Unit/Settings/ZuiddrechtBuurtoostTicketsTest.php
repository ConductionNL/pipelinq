<?php

/**
 * Example data for DqOrganisatie: Buurtvereniging Oost is a client with three open tickets,
 * linked to the dossiq company by its master entity.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Buurtvereniging Oost's tickets.
 */
class ZuiddrechtBuurtoostTicketsTest extends TestCase {

	/**
	 * Four tickets on the association's client, which shares dossiq's master entity: the collection permit in
	 * progress with Fatma Yildiz, the two older ones resolved by Pieter and Fatma, as DqOrganisatie draws them, and
	 * the new question about subsidy documents with Fatma that PqNieuwVerzoek draws.
	 *
	 * @return void
	 */
	public function testTheAssociationHasItsFourTickets(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$objects = array_column($set['objects'], 'data', 'key');

		$client = $objects['client-buurtoost'];
		$this->assertSame(expected: ['organization', '0f5e0000-0000-4000-8000-000000000007'], actual: [$client['type'], $client['masterEntityRef']]);

		$ticketRows = array_filter($set['objects'], static fn (array $object): bool => $object['schema'] === 'ticket');
		$tickets    = array_filter(
			array_column($ticketRows, 'data'),
			static fn (array $data): bool => ($data['client'] ?? null) === '{{object:client-buurtoost.id}}'
				&& ($data['ticketType'] ?? null) !== 'interaction'
		);
		$this->assertSame(
			expected: [
				'Aanvraag collectevergunning buurtfeest',
				'Vraag over huur buurthuis',
				'Melding verlichting Oosthavenweg',
				'Subsidie voor een straatfeest: welke documenten?',
			],
			actual: array_values(array_column($tickets, 'title'))
		);
		$this->assertSame(
			expected: [
				['in_progress', '{{cast:fatmayildiz.uid}}', false],
				['resolved', '{{cast:pieter.uid}}', true],
				['resolved', '{{cast:fatmayildiz.uid}}', true],
				['new', '{{cast:fatmayildiz.uid}}', false],
			],
			actual: array_values(array_map(static fn (array $t): array => [$t['status'], $t['assignee'] ?? null, isset($t['resolvedAt'])], $tickets))
		);
	}//end testTheAssociationHasItsFourTickets()

	/**
	 * DqOrganisatie and the ticket leaf draw the three tickets by their references; a declared reference is kept
	 * (TicketReferenceCreatingListener stamps only a ticket without one).
	 *
	 * @return void
	 */
	public function testTheBuurtoostTicketsCarryTheirReferences(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$objects = array_column($set['objects'], null, 'key');

		$this->assertSame(
			expected: ['PQ-2026-0402', 'PQ-2026-0377', 'PQ-2026-0310'],
			actual: [
				$objects['ticket-buurtoost-collectevergunning']['data']['reference'] ?? null,
				$objects['ticket-buurtoost-buurthuis']['data']['reference'] ?? null,
				$objects['ticket-buurtoost-verlichting']['data']['reference'] ?? null,
			]
		);
	}//end testTheBuurtoostTicketsCarryTheirReferences()

	/**
	 * The ticket counter starts above the highest reference the set names, so no new ticket repeats one; both keys are
	 * integers, as TicketReferenceSequence reads them. The counter is a floor (`atLeast`): the set never lowers it, and
	 * the app moves it on with every new ticket.
	 *
	 * @return void
	 */
	public function testTheTicketCounterStartsAboveTheSetsReferences(): void {
		$set    = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$config = array_column($set['appConfig'], null, 'key');

		$this->assertSame(expected: ['value' => '423', 'type' => 'int'], actual: array_intersect_key($config['ticket_reference_counter'] ?? [], ['value' => 1, 'type' => 1]));
		$this->assertTrue(condition: ($config['ticket_reference_counter']['atLeast'] ?? false) === true, message: 'the counter is a floor, so the app may move it on');
		$this->assertSame(expected: ['value' => '2026', 'type' => 'int'], actual: array_intersect_key($config['ticket_reference_counter_year'] ?? [], ['value' => 1, 'type' => 1]));
	}//end testTheTicketCounterStartsAboveTheSetsReferences()
}//end class
