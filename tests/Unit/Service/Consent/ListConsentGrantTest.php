<?php

/**
 * Unit tests for ListConsentGrant.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Consent
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Consent;

use OCA\Pipelinq\Service\Consent\ListConsentGrant;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ListConsentGrant.
 */
class ListConsentGrantTest extends TestCase {

	/**
	 * The grant keeps every value it is given.
	 *
	 * @return void
	 */
	public function testKeepsItsValues(): void {
		$grant = new ListConsentGrant('c-1', 'list-1', 'email', 'consent', 'webform', ['text' => 'Yes please']);

		$this->assertSame(expected: 'c-1', actual: $grant->contactId);
		$this->assertSame(expected: 'list-1', actual: $grant->listId);
		$this->assertSame(expected: 'email', actual: $grant->channel);
		$this->assertSame(expected: 'consent', actual: $grant->lawfulBasis);
		$this->assertSame(expected: 'webform', actual: $grant->source);
		$this->assertSame(expected: ['text' => 'Yes please'], actual: $grant->evidence);
	}//end testKeepsItsValues()
}//end class
