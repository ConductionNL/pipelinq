<?php

/**
 * Pipelinq ReportMailRunTest.
 *
 * A mailed report run: groups resolved to their members, rows read once per
 * recipient while acting as that recipient, one mail each with their own
 * file, the run's outcome, and the owner told about a failed send. The mailer
 * is a fake; the extraction double answers per acting user, as OpenRegister's
 * access rules would.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Export
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/reports-compare-periods-and-scheduled-delivery/specs/report-periods-and-delivery/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Export;

use OCA\Pipelinq\Service\Export\ExportDataService;
use OCA\Pipelinq\Service\Export\ReportMailDelivery;
use OCA\Pipelinq\Service\Export\ReportMailRun;
use OCA\Pipelinq\Service\NotificationService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Mail\IAttachment;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every recipient gets the rows they may read, once; failures are recorded and told.
 *
 * @covers \OCA\Pipelinq\Service\Export\ReportMailRun
 * @covers \OCA\Pipelinq\Service\Export\ReportMailDelivery
 */
class ReportMailRunTest extends TestCase {

	private const JOB = [
		'id'            => 'job-1',
		'name'          => 'Open tickets',
		'format'        => 'csv',
		'sourceSchemas' => ['ticket'],
		'createdBy'     => 'owner',
	];

	/**
	 * Who is acting right now.
	 *
	 * @var IUser|null
	 */
	private ?IUser $acting = null;

	/**
	 * The mails sent: [address, subject, attachments [filename, contents, mime]].
	 *
	 * @var list<array{0: string, 1: string, 2: list<array{0: string, 1: string, 2: string}>}>
	 */
	private array $mails = [];

	/**
	 * Addresses the mail server refuses.
	 *
	 * @var list<string>
	 */
	private array $refused = [];

	/**
	 * Notifications sent: [userId, subject, parameters].
	 *
	 * @var list<array{0: string, 1: string, 2: array}>
	 */
	private array $notified = [];

	/**
	 * Who the extraction ran as, in order.
	 *
	 * @var list<string|null>
	 */
	private array $readAs = [];

	/**
	 * The users that exist, by uid.
	 *
	 * @var array<string, IUser>
	 */
	private array $users = [];

	/**
	 * Anna and Bram in the group Team leads, Cees on his own, the owner.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->acting = null;
		$this->mails = [];
		$this->refused = [];
		$this->notified = [];
		$this->readAs = [];
		$this->users = [];
		foreach (['anna', 'bram', 'cees', 'owner'] as $uid) {
			$this->users[$uid] = $this->user(uid: $uid);
		}
	}//end setUp()

	/**
	 * A user with an address.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn(ucfirst($uid));
		$user->method('getEMailAddress')->willReturn($uid.'@example.org');
		return $user;
	}//end user()

	/**
	 * The run over fakes.
	 *
	 * @param bool $readFails Whether reading the rows throws.
	 *
	 * @return ReportMailRun
	 */
	private function mailRun(bool $readFails = false): ReportMailRun {
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(fn (string $uid): ?IUser => $this->users[$uid] ?? null);

		$teamLeads = $this->createMock(IGroup::class);
		$teamLeads->method('getUsers')->willReturnCallback(fn (): array => ['anna' => $this->users['anna'], 'bram' => $this->users['bram']]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(fn (string $gid): ?IGroup => $gid === 'team-leads' ? $teamLeads : null);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->acting);
		$session->method('setVolatileActiveUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->acting = $user;
			}
		);

		// What each user may read, as OpenRegister's access rules would answer.
		$data = $this->createMock(ExportDataService::class);
		$data->method('extractSchema')->willReturnCallback(
			function (array $job, string $schemaSlug, ?string $watermarkFrom = null, ?int $rowCap = null) use ($readFails): array {
				$uid = $this->acting?->getUID();
				$this->readAs[] = $uid;
				if ($readFails === true) {
					throw new RuntimeException('register down');
				}

				$rows = ['anna' => 2, 'bram' => 1, 'cees' => 3][$uid] ?? 0;
				return ['schema' => $schemaSlug, 'rows' => $rows, 'contents' => "rows for {$uid}", 'compression_used' => 'none'];
			}
		);

		$notifications = $this->createMock(NotificationService::class);
		$notifications->method('sendNotification')->willReturnCallback(
			function (string $userId, string $subject, array $parameters): void {
				$this->notified[] = [$userId, $subject, $parameters];
			}
		);

		return new ReportMailRun(
			userManager: $userManager,
			groupManager: $groups,
			userSession: $session,
			data: $data,
			delivery: $this->delivery(userManager: $userManager),
			notifications: $notifications,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end run()

	/**
	 * The real delivery over a fake mailer that records what it sends.
	 *
	 * @param IUserManager $userManager The users.
	 *
	 * @return ReportMailDelivery
	 */
	private function delivery(IUserManager $userManager): ReportMailDelivery {
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturnCallback(fn (): IMessage => $this->message());
		$mailer->method('createAttachment')->willReturnCallback(
			function (string $data, string $filename, string $contentType): IAttachment {
				return $this->attachment(data: [$filename, $data, $contentType]);
			}
		);
		$mailer->method('send')->willReturnCallback(
			function (IMessage $message): array {
				$to = array_key_first($message->to);
				if (in_array($to, $this->refused, true) === true) {
					return [$to];
				}

				$this->mails[] = [$to, $message->subject, array_map(static fn (object $a): array => $a->name, $message->attachments)];
				return [];
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $p = []): string => vsprintf(str_replace('%1$s', '%s', $text), $p));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$factory->method('getUserLanguage')->willReturn('en');

		return new ReportMailDelivery(mailer: $mailer, userManager: $userManager, l10nFactory: $factory, logger: $this->createMock(LoggerInterface::class));
	}//end delivery()

	/**
	 * An attachment that keeps its file name, contents and type.
	 *
	 * @param array{0: string, 1: string, 2: string} $data The file name, contents and type.
	 *
	 * @return IAttachment
	 */
	private function attachment(array $data): IAttachment {
		return new class ($data) implements IAttachment {
			/**
			 * Constructor.
			 *
			 * @param array{0: string, 1: string, 2: string} $name The file name, contents and type.
			 */
			public function __construct(public array $name) {
			}

			public function setFilename(string $filename): IAttachment {
				return $this;
			}

			public function setContentType(string $contentType): IAttachment {
				return $this;
			}

			public function setBody(string $body): IAttachment {
				return $this;
			}
		};
	}//end attachment()

	/**
	 * A mail message that keeps what it is given.
	 *
	 * @return IMessage
	 */
	private function message(): IMessage {
		return new class () implements IMessage {
			/** @var array<string, string> */
			public array $to = [];
			public string $subject = '';
			/** @var list<object> */
			public array $attachments = [];

			public function setTo(array $recipients): IMessage {
				$this->to = $recipients;
				return $this;
			}

			public function setSubject(string $subject): IMessage {
				$this->subject = $subject;
				return $this;
			}

			public function setPlainBody(string $body): IMessage {
				return $this;
			}

			public function setHtmlBody(string $body): IMessage {
				return $this;
			}

			public function attach(IAttachment $attachment): IMessage {
				$this->attachments[] = $attachment;
				return $this;
			}

			public function attachInline(string $body, string $name, ?string $contentType = null): IMessage {
				return $this;
			}

			public function setFrom(array $addresses): IMessage {
				return $this;
			}

			public function setReplyTo(array $addresses): IMessage {
				return $this;
			}

			public function setCc(array $recipients): IMessage {
				return $this;
			}

			public function setBcc(array $recipients): IMessage {
				return $this;
			}

			public function useTemplate(\OCP\Mail\IEMailTemplate $emailTemplate): IMessage {
				return $this;
			}

			public function setAutoSubmitted(string $value): IMessage {
				return $this;
			}
		};
	}//end message()

	/**
	 * A group and a user that is also in it give each person once, in order;
	 * an unknown group or an empty id gives nobody.
	 *
	 * @return void
	 */
	public function testRecipientsResolveToPeopleOnce(): void {
		$ids = $this->mailRun()->recipientIds(
			recipients: [
				['type' => 'group', 'id' => 'team-leads'],
				['type' => 'user', 'id' => 'bram'],
				['type' => 'user', 'id' => 'cees'],
				['type' => 'group', 'id' => 'nobody'],
				['type' => 'user', 'id' => ' '],
			]
		);

		$this->assertSame(['anna', 'bram', 'cees'], $ids);
		$this->assertSame([], $this->mailRun()->recipientIds(recipients: 'not a list'));
	}//end testRecipientsResolveToPeopleOnce()

	/**
	 * Scenario "A weekly report on Monday morning" and "A recipient may read
	 * fewer rows": each member of Team leads gets one mail with their own CSV,
	 * read while acting as them, and the run succeeded.
	 *
	 * @return void
	 */
	public function testEachRecipientGetsTheRowsTheyMayRead(): void {
		$outcome = $this->mailRun()->deliver(job: self::JOB, destination: ['type' => 'mail', 'recipients' => [['type' => 'group', 'id' => 'team-leads']]]);

		$this->assertSame(['anna', 'bram'], $this->readAs);
		$this->assertNull($this->acting, 'whoever acted before is restored');
		$this->assertSame(
			[
				['anna@example.org', 'Report: Open tickets', [['open-tickets-ticket.csv', 'rows for anna', 'text/csv']]],
				['bram@example.org', 'Report: Open tickets', [['open-tickets-ticket.csv', 'rows for bram', 'text/csv']]],
			],
			$this->mails
		);
		$this->assertSame('succeeded', $outcome['status']);
		$this->assertSame(3, $outcome['rowCount']);
		$this->assertSame(2, $outcome['fileCount']);
		$this->assertSame(strlen('rows for anna') + strlen('rows for bram'), $outcome['byteCount']);
		$this->assertNull($outcome['errorMessage']);
		$this->assertSame(['anna', 'bram'], array_column($outcome['manifest']['mail'], 'userId'));
		$this->assertSame([], $this->notified);
	}//end testEachRecipientGetsTheRowsTheyMayRead()

	/**
	 * A refused mail makes the run partial, names the recipient and the reason,
	 * and tells the job's owner.
	 *
	 * @return void
	 */
	public function testARefusedMailIsRecordedAndToldToTheOwner(): void {
		$this->refused = ['bram@example.org'];

		$outcome = $this->mailRun()->deliver(job: self::JOB, destination: ['recipients' => [['type' => 'group', 'id' => 'team-leads']]]);

		$this->assertSame('partial', $outcome['status']);
		$this->assertSame('bram: The mail server refused the recipient.', $outcome['errorMessage']);
		$this->assertSame(['delivered', 'failed'], array_column($outcome['manifest']['mail'], 'status'));
		$this->assertSame(
			[['owner', 'export_mail_delivery_failed', ['title' => 'Open tickets', 'failed' => 1, 'error' => 'bram: The mail server refused the recipient.']]],
			$this->notified
		);
	}//end testARefusedMailIsRecordedAndToldToTheOwner()

	/**
	 * Scenario "Mail is not configured": every send failing makes the run
	 * failed; a recipient that does not exist and rows that cannot be read
	 * fail too, without a mail.
	 *
	 * @return void
	 */
	public function testARunWithoutASingleSentMailFails(): void {
		$this->refused = ['cees@example.org'];
		$outcome = $this->mailRun()->deliver(job: self::JOB, destination: ['recipients' => [['type' => 'user', 'id' => 'cees'], ['type' => 'user', 'id' => 'ghost']]]);

		$this->assertSame('failed', $outcome['status']);
		$this->assertSame('cees: The mail server refused the recipient.; ghost: The recipient does not exist.', $outcome['errorMessage']);

		$this->refused = [];
		$this->mails = [];
		$unreadable = $this->mailRun(readFails: true)->deliver(job: self::JOB, destination: ['recipients' => [['type' => 'user', 'id' => 'anna']]]);
		$this->assertSame('failed', $unreadable['status']);
		$this->assertSame('anna: register down', $unreadable['errorMessage']);
		$this->assertSame([], $this->mails);
		$this->assertNull($this->acting, 'the acting user is restored after a failed read');
	}//end testARunWithoutASingleSentMailFails()

	/**
	 * A destination without recipients sends nothing and fails the run; a job
	 * without an owner notifies nobody.
	 *
	 * @return void
	 */
	public function testNoRecipientsIsAFailedRun(): void {
		$job = self::JOB;
		unset($job['createdBy']);

		$outcome = $this->mailRun()->deliver(job: $job, destination: ['recipients' => []]);

		$this->assertSame('failed', $outcome['status']);
		$this->assertSame([], $this->mails);
		$this->assertSame([], $this->notified);
	}//end testNoRecipientsIsAFailedRun()

	/**
	 * A compressed export is attached as `.gz`, and an unknown format as CSV.
	 *
	 * @return void
	 */
	public function testAttachmentNamesFollowFormatAndCompression(): void {
		$run = $this->mailRun();
		$job = array_merge(self::JOB, ['format' => 'jsonl', 'sourceSchemas' => ['ticket', 'lead']]);
		$run->deliver(job: $job, destination: ['recipients' => [['type' => 'user', 'id' => 'cees']]]);
		$this->assertSame(
			[['open-tickets-ticket.jsonl', 'rows for cees', 'application/x-ndjson'], ['open-tickets-lead.jsonl', 'rows for cees', 'application/x-ndjson']],
			$this->mails[0][2]
		);

		$this->mails = [];
		$run->deliver(job: array_merge(self::JOB, ['format' => 'xml', 'name' => '!!!']), destination: ['recipients' => [['type' => 'user', 'id' => 'cees']]]);
		$this->assertSame([['report-ticket.csv', 'rows for cees', 'text/csv']], $this->mails[0][2]);
	}//end testAttachmentNamesFollowFormatAndCompression()
}//end class
