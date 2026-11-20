<?php

/**
 * MailIntakeService tests: one thread is one ticket, the mailbox's own mail opens none, the cursor advances, the mail is linked.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-names-the-ticket-mailboxes-req-itq-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\MailIntake;

use OCA\Pipelinq\Service\EmailMatchService;
use OCA\Pipelinq\Service\InboundTicketService;
use OCA\Pipelinq\Service\MailIntake\MailIntakeAccounts;
use OCA\Pipelinq\Service\MailIntake\MailIntakeReader;
use OCA\Pipelinq\Service\MailIntake\MailIntakeService;
use OCA\Pipelinq\Service\MailMessageKey;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see MailIntakeService}.
 *
 * @covers \OCA\Pipelinq\Service\MailIntake\MailIntakeService
 * @uses   \OCA\Pipelinq\Service\MailMessageKey
 */
final class MailIntakeServiceTest extends TestCase {

	/** @var MailIntakeAccounts&MockObject */
	private MailIntakeAccounts $accounts;

	/** @var MailIntakeReader&MockObject */
	private MailIntakeReader $reader;

	/** @var EmailMatchService&MockObject */
	private EmailMatchService $matcher;

	/** @var InboundTicketService&MockObject */
	private InboundTicketService $intake;

	/** @var IAppConfig&MockObject */
	private IAppConfig $config;

	/** @var list<array<string, mixed>> */
	private array $received = [];

	/**
	 * Build the subject and its collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->accounts = $this->createMock(originalClassName: MailIntakeAccounts::class);
		$this->reader = $this->createMock(originalClassName: MailIntakeReader::class);
		$this->matcher = $this->createMock(originalClassName: EmailMatchService::class);
		$this->intake = $this->createMock(originalClassName: InboundTicketService::class);
		$this->config = $this->createMock(originalClassName: IAppConfig::class);
		$this->accounts->method('mailboxes')->willReturn([
			['id' => 3, 'address' => 'Service@Gemeente.nl', 'name' => 'Service', 'owner' => 'functional'],
		]);
		$this->config->method('getValueString')->with('pipelinq', 'inboundMail.cursor.3', '0')->willReturn('10');
		$this->intake->method('tryReceive')->willReturnCallback(function (array $message): string {
			$this->received[] = $message;
			return 'ticket-' . $message['threadKey'];
		});
	}

	/**
	 * Two messages in one thread go to the intake on one thread key.
	 *
	 * @return void
	 */
	public function testTwoMessagesInOneThreadGoToTheIntakeOnOneThreadKey(): void {
		$this->reader->expects($this->once())->method('inboxSince')->with(3, 10)->willReturn([
			self::row(id: 11, thread: 'root-a', subject: 'Bestelling'),
			self::row(id: 12, thread: 'root-a', subject: 'Re: Bestelling'),
		]);
		$this->reader->method('senderOf')->willReturn('info@bakkerij.nl');
		$this->matcher->method('matchEmailToEntities')->with('info@bakkerij.nl')->willReturn([
			['entityType' => 'contact', 'entityId' => 'contact-1'],
			['entityType' => 'client', 'entityId' => 'client-1'],
			['entityType' => 'lead', 'entityId' => 'lead-1'],
		]);
		$this->matcher->expects($this->never())->method('matchDomainToOrganization');

		$totals = $this->service()->run();

		$this->assertSame(expected: ['mailboxes' => 1, 'scanned' => 2, 'tickets' => 2, 'skipped' => 0], actual: $totals);
		$this->assertSame(expected: ['mail:3:root-a', 'mail:3:root-a'], actual: array_column($this->received, 'threadKey'));
		$first = $this->received[0];
		$this->assertSame(expected: 'email', actual: $first['channel']);
		$this->assertSame(expected: 'Bestelling', actual: $first['title']);
		$this->assertSame(expected: 'preview 11', actual: $first['text']);
		$this->assertSame(expected: 'info@bakkerij.nl', actual: $first['fromAddress']);
		$this->assertSame(expected: 'bakkerij.nl', actual: $first['fromDomain']);
		$this->assertSame(expected: 'Service@Gemeente.nl', actual: $first['mailbox']);
		$this->assertSame(expected: 'client-1', actual: $first['client']);
		$this->assertSame(expected: 'contact-1', actual: $first['contact']);
		$this->assertArrayNotHasKey(key: 'lead', array: $first);
	}

	/**
	 * Mail from a mailboxs own address opens no ticket but the cursor still advances.
	 *
	 * @return void
	 */
	public function testMailFromAMailboxsOwnAddressOpensNoTicketButTheCursorStillAdvances(): void {
		$this->reader->method('inboxSince')->willReturn([self::row(id: 15, thread: 'root-b', subject: 'Antwoord')]);
		$this->reader->method('senderOf')->with(15)->willReturn('service@gemeente.nl');
		$this->intake->expects($this->never())->method('tryReceive');
		$this->matcher->expects($this->never())->method('linkMessageTo');
		$this->config->expects($this->once())->method('setValueString')->with('pipelinq', 'inboundMail.cursor.3', '15');

		$this->assertSame(
			expected: ['scanned' => 1, 'tickets' => 0, 'skipped' => 1],
			actual: $this->service()->runMailbox(mailbox: $this->accounts->mailboxes()[0], ownAddresses: ['service@gemeente.nl'])
		);
	}

	/**
	 * The cursor advances to the newest message and the mail is linked to its ticket.
	 *
	 * @return void
	 */
	public function testTheCursorAdvancesToTheNewestMessageAndTheMailIsLinkedToItsTicket(): void {
		$this->reader->method('inboxSince')->willReturn(
			[self::row(id: 21, thread: '', subject: 'Vraag'), self::row(id: 23, thread: 'root-c', subject: 'Vraag 2')]
		);
		$this->reader->method('senderOf')->willReturn('piet@example.org');
		$this->matcher->method('matchEmailToEntities')->willReturn([]);
		$this->matcher->method('matchDomainToOrganization')->with('example.org')->willReturn(['entityType' => 'client', 'entityId' => 'org-1']);
		$links = [];
		$this->matcher->method(
			'linkMessageTo'
		)->willReturnCallback(static function (MailMessageKey $key, string $uid, string $objectId, string $schemaKey) use (&$links): int {
			$links[] = [$key->mailAccountId, $key->mailMessageId, $uid, $objectId, $schemaKey];
			return 1;
		});
		$this->config->expects($this->once())->method('setValueString')->with('pipelinq', 'inboundMail.cursor.3', '23');

		$this->service()->run();

		$this->assertSame(expected: 'mail:3:21', actual: $this->received[0]['threadKey'], message: 'a message without a thread root is its own thread');
		$this->assertSame(
			expected: 'org-1',
			actual: $this->received[0]['client'],
			message: 'the domain names the organisation when the address names nobody'
		);
		$this->assertSame(
			expected: [[3, 21, 'uid-21', 'ticket-mail:3:21', 'ticket_schema'], [3, 23, 'uid-23', 'ticket-mail:3:root-c', 'ticket_schema']],
			actual: $links
		);
	}

	/**
	 * A failed intake links nothing and an empty inbox writes no cursor.
	 *
	 * @return void
	 */
	public function testAFailedIntakeLinksNothingAndAnEmptyInboxWritesNoCursor(): void {
		$intake = $this->createMock(originalClassName: InboundTicketService::class);
		$intake->method('tryReceive')->willReturn('');
		$this->reader->method('inboxSince')->willReturnOnConsecutiveCalls([self::row(id: 31, thread: 'x', subject: 'S')], []);
		$this->reader->method('senderOf')->willReturn('a@b.nl');
		$this->matcher->method('matchEmailToEntities')->willReturn([]);
		$this->matcher->expects($this->never())->method('linkMessageTo');
		$this->config->expects($this->once())->method('setValueString');
		$service = new MailIntakeService(
			accounts: $this->accounts,
			reader: $this->reader,
			matcher: $this->matcher,
			intake: $intake,
			appConfig: $this->config
		);

		$this->assertSame(
			expected: ['scanned' => 1, 'tickets' => 0, 'skipped' => 1],
			actual: $service->runMailbox(mailbox: $this->accounts->mailboxes()[0], ownAddresses: [])
		);
		$this->assertSame(
			expected: ['scanned' => 0, 'tickets' => 0, 'skipped' => 0],
			actual: $service->runMailbox(mailbox: $this->accounts->mailboxes()[0], ownAddresses: [])
		);
	}

	/**
	 * A message without a sender is skipped.
	 *
	 * @return void
	 */
	public function testAMessageWithoutASenderIsSkipped(): void {
		$this->reader->method('inboxSince')->willReturn([self::row(id: 41, thread: 'y', subject: 'S')]);
		$this->reader->method('senderOf')->willReturn('');
		$this->intake->expects($this->never())->method('tryReceive');

		$this->assertSame(expected: 1, actual: $this->service()->run()['skipped']);
	}

	/**
	 * One INBOX row.
	 *
	 * @param int    $id      The message id.
	 * @param string $thread  The thread root id.
	 * @param string $subject The subject.
	 *
	 * @return array{id: int, uid: string, subject: string, preview: string, threadRootId: string}
	 */
	private static function row(int $id, string $thread, string $subject): array {
		return ['id' => $id, 'uid' => 'uid-' . $id, 'subject' => $subject, 'preview' => 'preview ' . $id, 'threadRootId' => $thread];
	}

	/**
	 * Stand-in for service().
	 *
	 * @return MailIntakeService
	 */
	private function service(): MailIntakeService {
		return new MailIntakeService(
			accounts: $this->accounts,
			reader: $this->reader,
			matcher: $this->matcher,
			intake: $this->intake,
			appConfig: $this->config
		);
	}
}
