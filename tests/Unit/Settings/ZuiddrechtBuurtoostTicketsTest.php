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
	 * Three open tickets on the association's client, which shares dossiq's master entity.
	 *
	 * @return void
	 */
	public function testTheAssociationHasThreeOpenTickets(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$objects = array_column($set['objects'], 'data', 'key');

		$client = $objects['client-buurtoost'];
		$this->assertSame(expected: ['organization', '0f5e0000-0000-4000-8000-000000000007'], actual: [$client['type'], $client['masterEntityRef']]);

		$tickets = array_filter($objects, static fn (array $data): bool => ($data['client'] ?? null) === '{{object:client-buurtoost.id}}');
		$this->assertSame(
			expected: ['Aanvraag collectevergunning', 'Vraag over huur buurthuis', 'Melding verlichting Oosthavenweg'],
			actual: array_values(array_column($tickets, 'title'))
		);
		$this->assertNotContains(needle: 'resolved', haystack: array_column($tickets, 'status'));
	}//end testTheAssociationHasThreeOpenTickets()
}//end class
