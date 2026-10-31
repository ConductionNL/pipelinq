<?php
/**
 * Example data for DqZaak Gekoppeld: the request that became case 2026-0110
 * finds that case and not the closed Woo request with the same title.
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
 * The Lindelaan request's case lookup.
 */
class ZuiddrechtLindelaanCaseLookupTest extends TestCase {

	/**
	 * The lookup names the open case's Mijn Zuiddrecht reference, which the
	 * closed request with the same title does not carry.
	 *
	 * @return void
	 */
	public function testTheLookupNamesTheOpenCasesReference(): void {
		$data = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.d/69-request-into-lindelaan-case.json'), true);
		$this->assertIsArray(actual: $data);

		$ticket = array_column($data['objects'], null, 'key')['ticket-sanne-fietspad-lindelaan'];
		$this->assertSame(
			expected: ['title' => 'Verlichting fietspad Lindelaan', 'externalReference' => 'MZ-ZDR-2026-0412'],
			actual: $ticket['lookups']['case']['where']
		);
	}//end testTheLookupNamesTheOpenCasesReference()
}//end class
