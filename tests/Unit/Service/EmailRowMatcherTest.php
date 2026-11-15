<?php

/**
 * Unit tests for EmailRowMatcher.
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

use JsonSerializable;
use OCA\Pipelinq\Service\EmailRowMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EmailRowMatcher: the rows whose email matches exactly, and the first organisation whose email ends in a domain suffix.
 *
 * @covers \OCA\Pipelinq\Service\EmailRowMatcher
 * @uses \OCA\Pipelinq\Util\AnyPayloadToArray
 * @uses \OCA\Pipelinq\Util\ObjectToPlainArray
 * @uses \OCA\Pipelinq\Util\EntityPayload
 * @uses \OCA\Pipelinq\Util\SerialisedPayload
 * @uses \OCA\Pipelinq\Util\RowIdOf
 * @uses \OCA\Pipelinq\Util\RowIdentity
 */
class EmailRowMatcherTest extends TestCase {

	/**
	 * The matcher under test.
	 *
	 * @var EmailRowMatcher
	 */
	private EmailRowMatcher $matcher;

	/**
	 * Set up the matcher.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->matcher = new EmailRowMatcher();
	}//end setUp()

	/**
	 * Only rows whose email equals the (lowercase) needle, case-insensitively on the row side, are returned by id.
	 *
	 * @return void
	 */
	public function testIdsWithEmailMatchesCaseInsensitivelyOnTheRow(): void {
		$rows = [
			['@self' => ['id' => 'a'], 'email' => 'Jan@Example.NL'],
			['id' => 'b', 'email' => 'other@example.nl'],
			['uuid' => 'c', 'email' => 'jan@example.nl'],
			['email' => 'jan@example.nl'],
			'not-a-row',
		];

		self::assertSame(expected: ['a', 'c'], actual: $this->matcher->idsWithEmail(rows: $rows, email: 'jan@example.nl'));
	}//end testIdsWithEmailMatchesCaseInsensitivelyOnTheRow()

	/**
	 * A row given as an entity is read through jsonSerialize, or through toArray for a plain object.
	 *
	 * @return void
	 */
	public function testIdsWithEmailReadsEntitiesAndPlainObjects(): void {
		$entity = new class implements JsonSerializable {
			/**
			 * The serialised row.
			 *
			 * @return array<string,string>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'e1', 'email' => 'x@y.nl'];
			}//end jsonSerialize()
		};
		$plain  = new class {
			/**
			 * The row as an array.
			 *
			 * @return array<string,string>
			 */
			public function toArray(): array {
				return ['id' => 'p1', 'email' => 'x@y.nl'];
			}//end toArray()
		};

		self::assertSame(expected: ['e1', 'p1'], actual: $this->matcher->idsWithEmail(rows: [$entity, $plain], email: 'x@y.nl'));
	}//end testIdsWithEmailReadsEntitiesAndPlainObjects()

	/**
	 * A non-list input yields no ids and no organisation.
	 *
	 * @return void
	 */
	public function testNonArrayRowsYieldNothing(): void {
		self::assertSame(expected: [], actual: $this->matcher->idsWithEmail(rows: null, email: 'a@b.nl'));
		self::assertNull(actual: $this->matcher->firstOrganizationWithSuffix(rows: 'rows', suffix: '@b.nl'));
	}//end testNonArrayRowsYieldNothing()

	/**
	 * The first row with an id whose email ends in the suffix becomes a client entity reference.
	 *
	 * @return void
	 */
	public function testFirstOrganizationWithSuffixReturnsTheFirstMatchWithAnId(): void {
		$rows = [
			['id' => 'skip', 'email' => ''],
			['id' => 'skip2', 'email' => 'info@elsewhere.nl'],
			['email' => 'noid@acme.nl'],
			['id' => 'org-1', 'email' => 'INFO@ACME.NL'],
			['id' => 'org-2', 'email' => 'sales@acme.nl'],
			42,
		];

		self::assertSame(
			expected: ['entityType' => 'client', 'entityId' => 'org-1'],
			actual: $this->matcher->firstOrganizationWithSuffix(rows: $rows, suffix: '@acme.nl')
		);
	}//end testFirstOrganizationWithSuffixReturnsTheFirstMatchWithAnId()

	/**
	 * No row ending in the suffix gives null.
	 *
	 * @return void
	 */
	public function testFirstOrganizationWithSuffixIsNullWithoutAMatch(): void {
		self::assertNull(
			actual: $this->matcher->firstOrganizationWithSuffix(rows: [['id' => 'a', 'email' => 'a@b.nl'], null], suffix: '@c.nl')
		);
	}//end testFirstOrganizationWithSuffixIsNullWithoutAMatch()
}//end class
