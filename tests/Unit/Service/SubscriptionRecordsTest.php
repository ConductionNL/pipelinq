<?php

/**
 * Unit tests for SubscriptionRecords.
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

use OCA\Pipelinq\Service\ListSignupRun;
use OCA\Pipelinq\Service\SubscriptionRecords;
use OCA\Pipelinq\Service\SubscriptionService;
use PHPUnit\Framework\TestCase;

/**
 * SubscriptionRecords builds the subscription rows of a list signup.
 */
class SubscriptionRecordsTest extends TestCase {

	/**
	 * The records under test.
	 *
	 * @var SubscriptionRecords
	 */
	private SubscriptionRecords $records;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->records = new SubscriptionRecords();
	}//end setUp()

	/**
	 * A signup run for a list and an address.
	 *
	 * @param string|null              $contactId The contact, if known.
	 * @param array<string, mixed>|null $existing  The existing subscription, if any.
	 *
	 * @return ListSignupRun
	 */
	private static function signup(?string $contactId=null, ?array $existing=null): ListSignupRun {
		$run = new ListSignupRun(listId: 'list-1', email: 'anna@example.nl');
		$run->contactId = $contactId;
		$run->existing = $existing;
		$run->source = 'web-form';

		return $run;
	}//end run()

	/**
	 * now() is an ISO-8601 UTC moment.
	 *
	 * @return void
	 */
	public function testNowIsAnUtcMoment(): void {
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $this->records->now());
	}//end testNowIsAnUtcMoment()

	/**
	 * A new pending subscription carries consent, the token digest and a creation moment.
	 *
	 * @return void
	 */
	public function testANewPendingSubscription(): void {
		$record = $this->records->pending(run: self::signup(), digest: 'sha-digest');

		self::assertSame('list-1', $record['listId']);
		self::assertSame('anna@example.nl', $record['email']);
		self::assertSame(SubscriptionService::STATE_PENDING, $record['state']);
		self::assertSame('web-form', $record['source']);
		self::assertSame('consent', $record['lawfulBasis']);
		self::assertSame('sha-digest', $record['confirmTokenHash']);
		self::assertArrayNotHasKey('contactId', $record);
		self::assertArrayHasKey('createdAt', $record);
	}//end testANewPendingSubscription()

	/**
	 * A pending subscription names the contact when there is one, and keeps an existing creation moment.
	 *
	 * @return void
	 */
	public function testAPendingSubscriptionKeepsTheExistingRow(): void {
		$existing = ['id' => 'sub-9', 'createdAt' => '2025-01-01T00:00:00Z', 'state' => 'unsubscribed'];
		$record = $this->records->pending(run: self::signup(contactId: 'contact-7', existing: $existing), digest: 'd');

		self::assertSame('sub-9', $record['id']);
		self::assertSame('2025-01-01T00:00:00Z', $record['createdAt']);
		self::assertSame('contact-7', $record['contactId']);
		self::assertSame(SubscriptionService::STATE_PENDING, $record['state']);
	}//end testAPendingSubscriptionKeepsTheExistingRow()

	/**
	 * An empty contact id is not written.
	 *
	 * @return void
	 */
	public function testAnEmptyContactIdIsNotWritten(): void {
		self::assertArrayNotHasKey('contactId', $this->records->pending(run: self::signup(contactId: ''), digest: 'd'));
	}//end testAnEmptyContactIdIsNotWritten()

	/**
	 * Confirming spends the token and records the hashed address and the moment.
	 *
	 * @return void
	 */
	public function testConfirmingSpendsTheToken(): void {
		$confirmed = $this->records->confirmed(subscription: ['id' => 's', 'confirmTokenHash' => 'x', 'state' => 'pending'], ipHash: 'ip-hash');

		self::assertSame('s', $confirmed['id']);
		self::assertSame(SubscriptionService::STATE_CONFIRMED, $confirmed['state']);
		self::assertSame('', $confirmed['confirmTokenHash']);
		self::assertSame('ip-hash', $confirmed['confirmIpHash']);
		self::assertSame('consent', $confirmed['lawfulBasis']);
		self::assertArrayHasKey('confirmedAt', $confirmed);
	}//end testConfirmingSpendsTheToken()

	/**
	 * Double opt-in evidence offers objection from the moment the subscription was created.
	 *
	 * @return void
	 */
	public function testDoubleOptInEvidence(): void {
		$confirmed = ['createdAt' => '2026-11-01T10:00:00Z', 'confirmedAt' => '2026-11-01T10:05:00Z', 'confirmIpHash' => 'h'];

		self::assertSame(
			[
				'mechanism' => 'double-opt-in',
				'reference' => 'ref-1',
				'objectionOffered' => true,
				'objectionOfferedAt' => '2026-11-01T10:00:00Z',
				'ipHash' => 'h',
				'recordedAt' => '2026-11-01T10:05:00Z',
			],
			$this->records->doubleOptInEvidence(confirmed: $confirmed, reference: 'ref-1')
		);
	}//end testDoubleOptInEvidence()

	/**
	 * Without a creation moment, objection counts from the confirmation.
	 *
	 * @return void
	 */
	public function testDoubleOptInEvidenceFallsBackToTheConfirmation(): void {
		$evidence = $this->records->doubleOptInEvidence(
			confirmed: ['confirmedAt' => '2026-11-02T09:00:00Z', 'confirmIpHash' => 'h'],
			reference: 'r'
		);

		self::assertSame('2026-11-02T09:00:00Z', $evidence['objectionOfferedAt']);
	}//end testDoubleOptInEvidenceFallsBackToTheConfirmation()

	/**
	 * A soft opt-in import is confirmed at once on its own lawful basis.
	 *
	 * @return void
	 */
	public function testASoftOptInIsConfirmedAtOnce(): void {
		$record = $this->records->softOptIn(run: self::signup(contactId: 'contact-1'));

		self::assertSame(SubscriptionService::STATE_CONFIRMED, $record['state']);
		self::assertSame('soft-opt-in-import', $record['source']);
		self::assertSame('soft-opt-in', $record['lawfulBasis']);
		self::assertSame('contact-1', $record['contactId']);
		self::assertSame('', $record['confirmTokenHash']);
		self::assertSame($record['confirmedAt'], $record['createdAt']);
	}//end testASoftOptInIsConfirmedAtOnce()

	/**
	 * Without an existing row or an address there is nothing to subscribe.
	 *
	 * @return void
	 */
	public function testForContactNeedsAnAddressOrARow(): void {
		$run = new ListSignupRun(listId: 'list-1', email: '');

		self::assertNull($this->records->forContact(run: $run));
	}//end testForContactNeedsAnAddressOrARow()

	/**
	 * An already confirmed subscription is left alone.
	 *
	 * @return void
	 */
	public function testForContactLeavesAConfirmedSubscription(): void {
		$run = self::signup(contactId: 'c', existing: ['state' => SubscriptionService::STATE_CONFIRMED]);

		self::assertNull($this->records->forContact(run: $run));
	}//end testForContactLeavesAConfirmedSubscription()

	/**
	 * A contact without a subscription is confirmed with consent from the run's source.
	 *
	 * @return void
	 */
	public function testForContactConfirmsANewSubscription(): void {
		$record = $this->records->forContact(run: self::signup(contactId: 'c-2'));

		self::assertNotNull($record);
		self::assertSame('anna@example.nl', $record['email']);
		self::assertSame('list-1', $record['listId']);
		self::assertSame('c-2', $record['contactId']);
		self::assertSame(SubscriptionService::STATE_CONFIRMED, $record['state']);
		self::assertSame('web-form', $record['source']);
		self::assertSame('consent', $record['lawfulBasis']);
		self::assertSame('', $record['confirmTokenHash']);
		self::assertArrayHasKey('createdAt', $record);
	}//end testForContactConfirmsANewSubscription()

	/**
	 * A pending subscription of the contact is confirmed in place.
	 *
	 * @return void
	 */
	public function testForContactConfirmsAPendingRowInPlace(): void {
		$record = $this->records->forContact(
			run: self::signup(contactId: 'c-3', existing: ['id' => 'sub-1', 'state' => 'pending', 'createdAt' => '2026-01-01T00:00:00Z', 'confirmTokenHash' => 'old'])
		);

		self::assertSame('sub-1', $record['id']);
		self::assertSame('2026-01-01T00:00:00Z', $record['createdAt']);
		self::assertSame(SubscriptionService::STATE_CONFIRMED, $record['state']);
		self::assertSame('', $record['confirmTokenHash']);
	}//end testForContactConfirmsAPendingRowInPlace()

	/**
	 * Contact evidence names the source as the mechanism.
	 *
	 * @return void
	 */
	public function testContactEvidence(): void {
		self::assertSame(
			[
				'mechanism' => 'crm-entry',
				'objectionOffered' => true,
				'objectionOfferedAt' => '2026-11-03T12:00:00Z',
				'recordedAt' => '2026-11-03T12:00:00Z',
			],
			$this->records->contactEvidence(confirmed: ['source' => 'crm-entry', 'confirmedAt' => '2026-11-03T12:00:00Z'])
		);
	}//end testContactEvidence()
}//end class
