<?php

/**
 * Example data for PqKassaAudit: today's four till audit entries are all still
 * to be checked and none is exported.
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
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The till audit log the board draws.
 */
class ZuiddrechtTillAuditPendingTest extends TestCase {

	/**
	 * Four entries at 09:47, 11:20, 11:43 and 12:05 today, each with verified null
	 * (still to be checked; false would read as tampering) and no export time.
	 *
	 * @return void
	 */
	public function testTheFourEntriesArePending(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$entries = [];
		foreach ($set['objects'] as $object) {
			if ($object['schema'] === 'kassakoppelingAuditLog') {
				$entries[$object['data']['timestamp']] = $object['data'];
			}
		}

		ksort($entries);
		$this->assertSame(
			expected: ['{{datetime:0 09:47}}', '{{datetime:0 11:20}}', '{{datetime:0 11:43}}', '{{datetime:0 12:05}}'],
			actual: array_keys($entries)
		);
		foreach ($entries as $moment => $entry) {
			$this->assertArrayHasKey(key: 'verified', array: $entry, message: $moment);
			$this->assertNull(actual: $entry['verified'], message: $moment);
			$this->assertArrayNotHasKey(key: 'exportedAt', array: $entry, message: $moment);
		}
	}//end testTheFourEntriesArePending()
}//end class
