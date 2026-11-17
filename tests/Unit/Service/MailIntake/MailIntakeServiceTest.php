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

	protected function setUp(): void {
		parent::setUp();
		$this->accounts = $this->createMock(MailIntakeAccounts::class);
		$this->reader = $this->createMock(MailIntakeReader::class);
		$this->matcher = $this->createMock(EmailMatchService::class);
		$this->intake = $this->createMock(InboundTicketService::class);
		$this->config = $this->createMock(IAppConfig::class);
		$this->accounts->method('mailboxes')->willReturn([
			['id' => 3, 'address' => 'Service@Gemeente.nl', 'name' => 'Service', 'owner' => 'functional'],
		]);
		$this->config->method('getValueString')->with('pipelinq', 'inboundMail.cursor.3', '0')->willReturn('10');
		$this->intake->method('tryReceive')->willReturnCallback(function (array $message): string {
			$this->received[] = $message;
			return 'ticket-' . $message['threadKey'];
		});
	}

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

		$this->assertSame(['mailboxes' => 1, 'scanned' => 2, 'tickets' => 2, 'skipped' => 0], $totals);
		$this->assertSame(['mail:3:root-a', 'mail:3:root-a'], array_column($this->received, 'threadKey'));
		$first = $this->received[0];
		$this->assertSame('email', $first['channel']);
		$this->assertSame('Bestelling', $first['title']);
		$this->assertSame('preview 11', $first['text']);
		$this->assertSame('info@bakkerij.nl', $first['fromAddress']);
		$this->assertSame('bakkerij.nl', $first['fromDomain']);
		$this->assertSame('Service@Gemeente.nl', $first['mailbox']);
		$this->assertSame('client-1', $first['client']);
		$this->assertSame('contact-1', $first['contact']);
		$this->assertArrayNotHasKey('lead', $first);
	}

	public function testMailFromAMailboxsOwnAddressOpensNoTicketButTheCursorStillAdvances(): void {
		$this->reader->method('inboxSince')->willReturn([self::row(id: 15, thread: 'root-b', subject: 'Antwoord')]);
		$this->reader->method('senderOf')->with(15)->willReturn('service@gemeente.nl');
		$this->intake->expects($this->never())->method('tryReceive');
		$this->matcher->expects($this->never())->method('linkMessageTo');
		$this->config->expects($this->once())->method('setValueString')->with('pipelinq', 'inboundMail.cursor.3', '15');

		$this->assertSame(['scanned' => 1, 'tickets' => 0, 'skipped' => 1], $this->service()->runMailbox(mailbox: $this->accounts->mailboxes()[0], ownAddresses: ['service@gemeente.nl']));
	}

	public function testTheCursorAdvancesToTheNewestMessageAndTheMailIsLinkedToItsTicket(): void {
		$this->reader->method('inboxSince')->willReturn([self::row(id: 21, thread: '', subject: 'Vraag'), self::row(id: 23, thread: 'root-c', subject: 'Vraag 2')]);
		$this->reader->method('senderOf')->willReturn('piet@example.org');
		$this->matcher->method('matchEmailToEntities')->willReturn([]);
		$this->matcher->method('matchDomainToOrganization')->with('example.org')->willReturn(['entityType' => 'client', 'entityId' => 'org-1']);
		$links = [];
		$this->matcher->method('linkMessageTo')->willReturnCallback(static function (MailMessageKey $key, string $uid, string $objectId, string $schemaKey) use (&$links): int {
			$links[] = [$key->mailAccountId, $key->mailMessageId, $uid, $objectId, $schemaKey];
			return 1;
		});
		$this->config->expects($this->once())->method('setValueString')->with('pipelinq', 'inboundMail.cursor.3', '23');

		$this->service()->run();

		$this->assertSame('mail:3:21', $this->received[0]['threadKey'], 'a message without a thread root is its own thread');
		$this->assertSame('org-1', $this->received[0]['client'], 'the domain names the organisation when the address names nobody');
		$this->assertSame(
			[[3, 21, 'uid-21', 'ticket-mail:3:21', 'ticket_schema'], [3, 23, 'uid-23', 'ticket-mail:3:root-c', 'ticket_schema']],
			$links
		);
	}

	public function testAFailedIntakeLinksNothingAndAnEmptyInboxWritesNoCursor(): void {
		$intake = $this->createMock(InboundTicketService::class);
		$intake->method('tryReceive')->willReturn('');
		$this->reader->method('inboxSince')->willReturnOnConsecutiveCalls([self::row(id: 31, thread: 'x', subject: 'S')], []);
		$this->reader->method('senderOf')->willReturn('a@b.nl');
		$this->matcher->method('matchEmailToEntities')->willReturn([]);
		$this->matcher->expects($this->never())->method('linkMessageTo');
		$this->config->expects($this->once())->method('setValueString');
		$service = new MailIntakeService($this->accounts, $this->reader, $this->matcher, $intake, $this->config);

		$this->assertSame(['scanned' => 1, 'tickets' => 0, 'skipped' => 1], $service->runMailbox(mailbox: $this->accounts->mailboxes()[0], ownAddresses: []));
		$this->assertSame(['scanned' => 0, 'tickets' => 0, 'skipped' => 0], $service->runMailbox(mailbox: $this->accounts->mailboxes()[0], ownAddresses: []));
	}

	public function testAMessageWithoutASenderIsSkipped(): void {
		$this->reader->method('inboxSince')->willReturn([self::row(id: 41, thread: 'y', subject: 'S')]);
		$this->reader->method('senderOf')->willReturn('');
		$this->intake->expects($this->never())->method('tryReceive');

		$this->assertSame(1, $this->service()->run()['skipped']);
	}

	/**
	 * One INBOX row.
	 *
	 * @return array{id: int, uid: string, subject: string, preview: string, threadRootId: string}
	 */
	private static function row(int $id, string $thread, string $subject): array {
		return ['id' => $id, 'uid' => 'uid-' . $id, 'subject' => $subject, 'preview' => 'preview ' . $id, 'threadRootId' => $thread];
	}

	private function service(): MailIntakeService {
		return new MailIntakeService($this->accounts, $this->reader, $this->matcher, $this->intake, $this->config);
	}
}
