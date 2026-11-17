<?php

/**
 * Unit tests for PosContactSearch.
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\PosContactSearch;
use PHPUnit\Framework\TestCase;

/**
 * A contact search carries its query, fields and cap unchanged.
 */
class PosContactSearchTest extends TestCase {

	/**
	 * Every value given is the value read back.
	 *
	 * @return void
	 */
	public function testCarriesItsValues(): void {
		$search = new PosContactSearch(query: 'jan', fields: ['name', 'email'], cap: 5);

		self::assertSame('jan', $search->query);
		self::assertSame(['name', 'email'], $search->fields);
		self::assertSame(5, $search->cap);
	}//end testCarriesItsValues()
}//end class
