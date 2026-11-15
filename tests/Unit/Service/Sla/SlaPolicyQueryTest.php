<?php

/**
 * SlaPolicyQueryTest
 *
 * Pins SLA policy selection rule by rule: which policies match an object
 * (applies-to, customer tier with its bronze default, organisation and
 * contract scope) and how matches rank (scope specificity, then priority,
 * then the newest validFrom); and the delivery markers SlaRecipient writes.
 *
 * @category  Test
 * @package   OCA\Pipelinq\Tests\Unit\Service\Sla
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Sla;

use DateTimeImmutable;
use OCA\Pipelinq\Service\Sla\SlaPolicyQuery;
use OCA\Pipelinq\Service\Sla\SlaRecipient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SLA policy matching, ranking and delivery markers.
 *
 * @covers \OCA\Pipelinq\Service\Sla\SlaPolicyQuery
 * @covers \OCA\Pipelinq\Service\Sla\SlaRecipient
 */
final class SlaPolicyQueryTest extends TestCase {

	/**
	 * A query for a ticket of a gold customer in org-1 under contract c-1.
	 *
	 * @param array<string, string> $metadata Metadata overrides.
	 *
	 * @return SlaPolicyQuery
	 */
	private static function query(array $metadata = ['tier' => 'gold', 'organisationId' => 'org-1', 'contractId' => 'c-1']): SlaPolicyQuery {
		return new SlaPolicyQuery(objectType: 'ticket', metadata: $metadata, now: new DateTimeImmutable('2026-11-12'));
	}//end query()

	/**
	 * Policy to whether it matches the gold org-1/c-1 ticket.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: bool}>
	 */
	public static function policies(): array {
		return [
			'empty policy is a wildcard'      => [[], true],
			'applies to this type'            => [['appliesTo' => 'ticket'], true],
			'applies to another type'         => [['appliesTo' => 'lead'], false],
			'same tier'                       => [['customerTier' => 'gold'], true],
			'other tier'                      => [['customerTier' => 'bronze'], false],
			'scope that is not a list'        => [['customerScope' => 'org-1'], true],
			'organisation in scope'           => [['customerScope' => ['organisationIds' => ['org-1', 'org-2']]], true],
			'organisation not in scope'       => [['customerScope' => ['organisationIds' => ['org-2']]], false],
			'contract in scope'               => [['customerScope' => ['contractIds' => ['c-1']]], true],
			'contract not in scope'           => [['customerScope' => ['contractIds' => ['c-2']]], false],
			'org matches, contract does not'  => [['customerScope' => ['organisationIds' => ['org-1'], 'contractIds' => ['c-2']]], false],
			'empty scope lists'               => [['customerScope' => ['organisationIds' => [], 'contractIds' => []]], true],
		];
	}//end policies()

	/**
	 * Each policy matches as recorded.
	 *
	 * @param array<string, mixed> $policy  The policy.
	 * @param bool                 $matches Whether it matches.
	 *
	 * @return void
	 */
	#[DataProvider('policies')]
	public function testMatching(array $policy, bool $matches): void {
		self::assertSame($matches, self::query()->matches(policy: $policy));
	}//end testMatching()

	/**
	 * A customer without a tier is treated as bronze.
	 *
	 * @return void
	 */
	public function testAnUntieredCustomerIsBronze(): void {
		$query = self::query(metadata: []);

		self::assertSame(['', '', ''], [$query->tier, $query->organisationId, $query->contractId]);
		self::assertTrue($query->matches(policy: ['customerTier' => 'bronze']));
		self::assertFalse($query->matches(policy: ['customerTier' => 'gold']));
	}//end testAnUntieredCustomerIsBronze()

	/**
	 * Matching policies rank contract scope, organisation scope, other scope,
	 * tier-only, then wildcard; then by priority; then newest validFrom.
	 *
	 * @return void
	 */
	public function testRanking(): void {
		$policies = [
			'wildcard'      => [],
			'tier'          => ['customerTier' => 'gold'],
			'scope-other'   => ['customerScope' => ['organisationIds' => []]],
			'org'           => ['customerScope' => ['organisationIds' => ['org-1']]],
			'contract'      => ['customerScope' => ['contractIds' => ['c-1']]],
			'tier-urgent'   => ['customerTier' => 'gold', 'priority' => 10],
			'tier-newer'    => ['customerTier' => 'gold', 'validFrom' => '2026-06-01'],
			'tier-older'    => ['customerTier' => 'gold', 'validFrom' => '2026-01-01'],
		];
		$query = self::query();

		uasort($policies, static fn (array $a, array $b): int => $query->compare(first: $a, second: $b));

		self::assertSame(
			['contract', 'org', 'scope-other', 'tier-urgent', 'tier-newer', 'tier-older', 'tier', 'wildcard'],
			array_keys($policies)
		);
	}//end testRanking()

	/**
	 * Without an organisation or contract on the object, a scoped policy ranks as plain scope.
	 *
	 * @return void
	 */
	public function testAnUnscopedObjectDoesNotGainScopeSpecificity(): void {
		$query = self::query(metadata: ['tier' => 'gold']);

		self::assertSame(
			0,
			$query->compare(
				first: ['customerScope' => ['contractIds' => ['c-1']]],
				second: ['customerScope' => ['organisationIds' => ['org-1']]]
			)
		);
	}//end testAnUnscopedObjectDoesNotGainScopeSpecificity()

	/**
	 * Delivery outcome to the marker recorded on the object.
	 *
	 * @return void
	 */
	public function testRecipientMarkers(): void {
		$known = new SlaRecipient(contact: [], contactId: 'contact-7', context: []);
		$anonymous = new SlaRecipient(contact: [], contactId: '', context: []);

		self::assertSame(
			[['contact-7'], ['customer'], ['consent-missing:email'], ['template-missing:whatsapp'], ['failed:sms:bounced'], ['failed:email:']],
			[
				$known->marker(channel: 'email', outcome: ['status' => 'sent']),
				$anonymous->marker(channel: 'email', outcome: ['status' => 'sent']),
				$known->marker(channel: 'email', outcome: ['status' => 'consentMissing']),
				$known->marker(channel: 'whatsapp', outcome: ['status' => 'sessionWindowExpired']),
				$known->marker(channel: 'sms', outcome: ['status' => 'bounced']),
				$known->marker(channel: 'email', outcome: []),
			]
		);
	}//end testRecipientMarkers()
}//end class
