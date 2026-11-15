<?php
/**
 * Example data for DqOrganisatie: Buurtvereniging Oost's open ticket, the
 * collection permit request PQ-2026-0402, is due three days after the install
 * day, as the board's "termijn 10 okt" reads against its 7 okt.
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
 * The collection permit request's term.
 */
class ZuiddrechtOostCollectionPermitTermTest extends TestCase {

	/**
	 * PQ-2026-0402 is due three days out.
	 *
	 * @return void
	 */
	public function testTheCollectionPermitRequestIsDueInThreeDays(): void {
		$set = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$this->assertIsArray(actual: $set);
		$tickets = array_column($set['objects'], 'data', 'key');
		$ticket  = $tickets['ticket-buurtoost-collectevergunning'];
		$this->assertSame(expected: 'PQ-2026-0402', actual: $ticket['reference']);
		$this->assertSame(expected: '{{datetime:3 17:00}}', actual: $ticket['slaDeadline']);
	}//end testTheCollectionPermitRequestIsDueInThreeDays()
}//end class
