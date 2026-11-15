<?php

/**
 * Unit tests for LookupQuery: when a lookup carries a purpose.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\PersonLookup
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\PersonLookup;

use OCA\Pipelinq\Service\BsnAudit\LookupReason;
use OCA\Pipelinq\Service\PersonLookup\LookupLinks;
use OCA\Pipelinq\Service\PersonLookup\LookupQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * One person lookup as it was asked.
 */
#[CoversClass(LookupQuery::class)]
#[UsesClass(LookupReason::class)]
#[UsesClass(LookupLinks::class)]
class LookupQueryTest extends TestCase {

	/**
	 * A query with the given reason fields.
	 *
	 * @param string $verzoekreden   The stated reason.
	 * @param string $purposeBinding The purpose the lookup is bound to.
	 *
	 * @return LookupQuery
	 */
	private function query(string $verzoekreden, string $purposeBinding): LookupQuery {
		return new LookupQuery(
			rawBsn: '999993653',
			reason: new LookupReason(verzoekreden: $verzoekreden, purposeBinding: $purposeBinding, linkedRequest: null, vogScreening: false),
			basis: 'wettelijke-taak',
			links: new LookupLinks(contactId: 'contact-1', clientId: 'client-1', identityCheck: 'checked')
		);
	}//end query()

	/**
	 * A query with both a reason and a purpose binding has a purpose.
	 *
	 * @return void
	 */
	public function testAReasonAndABindingTogetherAreAPurpose(): void {
		self::assertTrue($this->query(verzoekreden: 'klantvraag', purposeBinding: 'dienstverlening')->hasPurpose());
	}//end testAReasonAndABindingTogetherAreAPurpose()

	/**
	 * Either field empty means the lookup has no purpose.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function incompleteReasons(): array {
		return [
			'no reason'  => ['', 'dienstverlening'],
			'no binding' => ['klantvraag', ''],
			'neither'    => ['', ''],
		];
	}//end incompleteReasons()

	/**
	 * A missing reason or a missing binding is not a purpose.
	 *
	 * @param string $verzoekreden   The stated reason.
	 * @param string $purposeBinding The purpose the lookup is bound to.
	 *
	 * @return void
	 */
	#[DataProvider('incompleteReasons')]
	public function testAnEmptyFieldIsNoPurpose(string $verzoekreden, string $purposeBinding): void {
		self::assertFalse($this->query(verzoekreden: $verzoekreden, purposeBinding: $purposeBinding)->hasPurpose());
	}//end testAnEmptyFieldIsNoPurpose()

	/**
	 * A blank of spaces is still text, so it counts: only the empty string is missing.
	 *
	 * @return void
	 */
	public function testOnlyTheEmptyStringCountsAsMissing(): void {
		self::assertTrue($this->query(verzoekreden: ' ', purposeBinding: ' ')->hasPurpose());
	}//end testOnlyTheEmptyStringCountsAsMissing()

	/**
	 * The query keeps what it was given, so the audit sees the same reason and links.
	 *
	 * @return void
	 */
	public function testTheQueryKeepsItsReasonAndLinks(): void {
		$query = $this->query(verzoekreden: 'klantvraag', purposeBinding: 'dienstverlening');

		self::assertSame('999993653', $query->rawBsn);
		self::assertSame('wettelijke-taak', $query->basis);
		self::assertSame('dienstverlening', $query->reason->purposeBinding);
		self::assertSame('client-1', $query->links->clientId);
	}//end testTheQueryKeepsItsReasonAndLinks()
}//end class
