<?php

/**
 * Unit tests for SubscriptionQueryService: the blast audience, per-state
 * counts, the paged subscriber list and a contact's memberships.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-pending-subscription-never-receives-a-blast
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\ComplianceService;
use OCA\Pipelinq\Service\ListTokenService;
use OCA\Pipelinq\Service\Marketing\RecipientNameReader;
use OCA\Pipelinq\Service\Marketing\SubscriptionQueryService;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the read side of mailing-list membership.
 *
 * @covers \OCA\Pipelinq\Service\Marketing\SubscriptionQueryService
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-pending-subscription-never-receives-a-blast
 */
class SubscriptionQueryServiceTest extends TestCase {

	/**
	 * The store, seeded with one list's memberships.
	 *
	 * @var InMemoryListObjectStore
	 */
	private InMemoryListObjectStore $store;

	/**
	 * The consent ledger.
	 *
	 * @var ComplianceService&MockObject
	 */
	private ComplianceService $compliance;

	/**
	 * The unsubscribe-link signer.
	 *
	 * @var ListTokenService&MockObject
	 */
	private ListTokenService $tokens;

	/**
	 * The contact-name reader.
	 *
	 * @var RecipientNameReader&MockObject
	 */
	private RecipientNameReader $names;

	/**
	 * Five memberships of list-1 in every state, and one of list-2.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryListObjectStore(seed: [
			'subscription' => [
				['uuid' => 's-1', 'listId' => 'list-1', 'contactId' => 'c-1', 'email' => 'one@example.org', 'state' => 'confirmed'],
				['uuid' => 's-2', 'listId' => 'list-1', 'contactId' => '', 'email' => 'walkin@example.org', 'state' => 'confirmed'],
				['uuid' => 's-3', 'listId' => 'list-1', 'contactId' => 'c-3', 'email' => 'three@example.org', 'state' => 'pending'],
				['uuid' => 's-4', 'listId' => 'list-1', 'contactId' => 'c-4', 'email' => 'four@example.org', 'state' => 'unsubscribed', 'name' => 'Kept Name'],
				['uuid' => 's-5', 'listId' => 'list-1', 'contactId' => 'c-5', 'email' => 'five@example.org', 'state' => 'bounced'],
				['uuid' => 's-6', 'listId' => 'list-1', 'contactId' => 'c-6', 'email' => 'six@example.org', 'state' => 'mystery'],
				['uuid' => 's-7', 'listId' => 'list-2', 'contactId' => 'c-1', 'email' => 'one@example.org', 'state' => 'confirmed'],
			],
		]);
		$this->compliance = $this->createMock(ComplianceService::class);
		$this->tokens = $this->createMock(ListTokenService::class);
		$this->tokens->method('signUnsubscribeToken')->willReturnCallback(
			static fn (string $subscriptionId, string $contactId): string => 'tok:' . $subscriptionId . ':' . $contactId
		);
		$this->tokens->method('unsubscribeUrl')->willReturnCallback(
			static fn (string $token): string => 'https://cloud.example/unsubscribe/' . $token
		);
		$this->names = $this->createMock(RecipientNameReader::class);
	}//end setUp()

	/**
	 * Only confirmed memberships with list consent are queued, each with its
	 * own unsubscribe link; every other one is named as skipped, and the
	 * ledger is only asked about confirmed memberships.
	 *
	 * @return void
	 */
	public function testTheBlastAudienceQueuesConfirmedConsentedMembersAndNamesTheRest(): void {
		$asked = [];
		$this->compliance->method('hasConsentForList')->willReturnCallback(
			function (string $contactId, string $listId, string $channel) use (&$asked): bool {
				$asked[] = [$contactId, $listId, $channel];
				return $contactId === 'c-1';
			}
		);

		$audience = $this->service()->getBlastAudienceForList(listId: 'list-1');

		$this->assertSame(
			[
				'members' => [
					[
						'contactId' => 'c-1',
						'email' => 'one@example.org',
						'subscriptionId' => 's-1',
						'unsubscribeUrl' => 'https://cloud.example/unsubscribe/tok:s-1:c-1',
					],
				],
				'skipped' => ['walkin@example.org', 'c-3', 'c-4', 'c-5', 'c-6'],
			],
			$audience
		);
		$this->assertSame([['c-1', 'list-1', 'email'], ['walkin@example.org', 'list-1', 'email']], $asked);
	}//end testTheBlastAudienceQueuesConfirmedConsentedMembersAndNamesTheRest()

	/**
	 * A blank list id has no audience and reads nothing.
	 *
	 * @return void
	 */
	public function testABlankListHasNoAudience(): void {
		$this->compliance->expects($this->never())->method('hasConsentForList');

		$this->assertSame(['members' => [], 'skipped' => []], $this->service()->getBlastAudienceForList(listId: ''));
		$this->assertSame(
			['pending' => 0, 'confirmed' => 0, 'unsubscribed' => 0, 'bounced' => 0, 'total' => 0],
			$this->service()->countsForList(listId: '')
		);
	}//end testABlankListHasNoAudience()

	/**
	 * Without both ids there is no unsubscribe link and nothing is signed.
	 *
	 * @return void
	 */
	public function testAnUnsubscribeLinkNeedsBothIds(): void {
		$this->assertSame('', $this->service()->unsubscribeUrlFor(subscriptionId: '', contactId: 'c-1'));
		$this->assertSame('', $this->service()->unsubscribeUrlFor(subscriptionId: 's-1', contactId: ''));
		$this->assertSame(
			'https://cloud.example/unsubscribe/tok:s-9:c-9',
			$this->service()->unsubscribeUrlFor(subscriptionId: 's-9', contactId: 'c-9')
		);
	}//end testAnUnsubscribeLinkNeedsBothIds()

	/**
	 * Every membership counts toward the total; an unknown state counts toward nothing else.
	 *
	 * @return void
	 */
	public function testCountsPerStateAndAnUnknownStateOnlyInTheTotal(): void {
		$this->assertSame(
			['pending' => 1, 'confirmed' => 2, 'unsubscribed' => 1, 'bounced' => 1, 'total' => 6],
			$this->service()->countsForList(listId: 'list-1')
		);
	}//end testCountsPerStateAndAnUnknownStateOnlyInTheTotal()

	/**
	 * The page is clamped to 1 and the size to 1..100; rows are named from
	 * their contact unless they carry a name or have no contact.
	 *
	 * @return void
	 */
	public function testThePagedListIsClampedAndNamesItsRows(): void {
		$this->names->method('fullName')->willReturnCallback(
			static fn (string $recipientId): string => (['c-1' => 'Bakkerij De Graaf'][$recipientId] ?? '')
		);

		$first = $this->service()->listSubscriptionsForList(listId: 'list-1', page: 0, limit: 2);
		$this->assertSame(['page' => 1, 'limit' => 2, 'total' => 6, 'pages' => 3], $first['pagination']);
		$this->assertSame('Bakkerij De Graaf', $first['data'][0]['name']);
		$this->assertArrayNotHasKey('name', $first['data'][1]);

		$second = $this->service()->listSubscriptionsForList(listId: 'list-1', page: 2, limit: 2);
		$this->assertSame(['s-3', 's-4'], array_column($second['data'], 'uuid'));
		$this->assertArrayNotHasKey('name', $second['data'][0]);
		$this->assertSame('Kept Name', $second['data'][1]['name']);

		$all = $this->service()->listSubscriptionsForList(listId: 'list-1', page: 1, limit: 500);
		$this->assertSame(['page' => 1, 'limit' => 100, 'total' => 6, 'pages' => 1], $all['pagination']);

		$none = $this->service()->listSubscriptionsForList(listId: 'list-9', page: 1, limit: 0);
		$this->assertSame(['data' => [], 'pagination' => ['page' => 1, 'limit' => 1, 'total' => 0, 'pages' => 0]], $none);
	}//end testThePagedListIsClampedAndNamesItsRows()

	/**
	 * A contact's memberships span lists; a blank contact holds none.
	 *
	 * @return void
	 */
	public function testAContactsMembershipsSpanListsAndABlankContactHasNone(): void {
		$this->assertSame(
			['s-1', 's-7'],
			array_column($this->service()->listSubscriptionsForContact(contactId: 'c-1'), 'uuid')
		);
		$this->assertSame([], $this->service()->listSubscriptionsForContact(contactId: ''));
	}//end testAContactsMembershipsSpanListsAndABlankContactHasNone()

	/**
	 * The service over the doubles.
	 *
	 * @return SubscriptionQueryService
	 */
	private function service(): SubscriptionQueryService {
		return new SubscriptionQueryService(
			store: $this->store,
			compliance: $this->compliance,
			tokens: $this->tokens,
			names: $this->names,
		);
	}//end service()
}//end class
