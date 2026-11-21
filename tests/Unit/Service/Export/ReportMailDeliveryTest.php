<?php

/**
 * ReportMailDelivery: one export mailed to one user.
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
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/reports-compare-periods-and-scheduled-delivery/specs/report-periods-and-delivery/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Export;

use OCA\Pipelinq\Service\Export\ReportMailDelivery;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IAttachment;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The mail goes to the user's address in their language with the file
 * attached; a missing user, a missing address, a mailer error or a refused
 * recipient is a failure with its reason.
 *
 * @covers \OCA\Pipelinq\Service\Export\ReportMailDelivery
 */
class ReportMailDeliveryTest extends TestCase {

	/**
	 * Calls made on the message.
	 *
	 * @var array<string, mixed>
	 */
	private array $message = [];

	/**
	 * The mailer mock.
	 *
	 * @var IMailer&MockObject
	 */
	private IMailer&MockObject $mailer;

	/**
	 * The user manager mock.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $users;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The attachment the mailer made.
	 *
	 * @var IAttachment
	 */
	private IAttachment $attachment;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$message = $this->createMock(IMessage::class);
		foreach (['setTo', 'setSubject', 'setPlainBody', 'attach'] as $setter) {
			$message->method($setter)->willReturnCallback(
				function (mixed $value) use ($setter, $message): IMessage {
					$this->message[$setter] = $value;
					return $message;
				}
			);
		}

		$this->attachment = $this->createMock(IAttachment::class);
		$this->mailer = $this->createMock(IMailer::class);
		$this->mailer->method('createMessage')->willReturn($message);
		$this->users = $this->createMock(IUserManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The service under test, translating to a marked string.
	 *
	 * @return ReportMailDelivery
	 */
	private function service(): ReportMailDelivery {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array|string $parameters = []): string => '[nl]' . vsprintf($text, (array)$parameters)
		);
		$factory = $this->createMock(IFactory::class);
		$factory->method('getUserLanguage')->willReturn('nl');
		$factory->method('get')->with('pipelinq', 'nl')->willReturn($l10n);

		return new ReportMailDelivery(mailer: $this->mailer, userManager: $this->users, l10nFactory: $factory, logger: $this->logger);
	}//end service()

	/**
	 * A user with this address.
	 *
	 * @param string|null $address The address.
	 *
	 * @return void
	 */
	private function userWith(?string $address): void {
		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn($address);
		$user->method('getDisplayName')->willReturn('Ann de Vries');
		$this->users->method('get')->with('ann')->willReturn($user);
	}//end userWith()

	/**
	 * The file mailed in the tests.
	 *
	 * @return array{contents: string, filename: string, mimeType: string}
	 */
	private static function file(): array {
		return ['contents' => "a,b\n1,2\n", 'filename' => 'leads.csv', 'mimeType' => 'text/csv'];
	}//end file()

	/**
	 * The mail goes to the user's address, in their language, with the file.
	 *
	 * @return void
	 */
	public function testTheReportIsMailedWithTheFileAttached(): void {
		$this->userWith(address: 'ann@example.org');
		$this->mailer->expects($this->once())->method('createAttachment')->with("a,b\n1,2\n", 'leads.csv', 'text/csv')->willReturn($this->attachment);
		$this->mailer->expects($this->once())->method('send')->willReturn([]);

		$outcome = $this->service()->deliver(job: ['name' => 'Weekly leads'], userId: 'ann', file: self::file());

		$this->assertSame(['userId' => 'ann', 'status' => 'delivered', 'reason' => ''], $outcome);
		$this->assertSame(['ann@example.org' => 'Ann de Vries'], $this->message['setTo']);
		$this->assertSame('[nl]Report: Weekly leads', $this->message['setSubject']);
		$this->assertStringStartsWith('[nl]Attached is the report Weekly leads, as scheduled.', $this->message['setPlainBody']);
		$this->assertSame($this->attachment, $this->message['attach']);
	}//end testTheReportIsMailedWithTheFileAttached()

	/**
	 * A user that does not exist gets nothing.
	 *
	 * @return void
	 */
	public function testAMissingUserIsAFailure(): void {
		$this->users->method('get')->willReturn(null);
		$this->mailer->expects($this->never())->method('send');

		$outcome = $this->service()->deliver(job: ['name' => 'Weekly'], userId: 'zoe', file: self::file());

		$this->assertSame(['userId' => 'zoe', 'status' => 'failed', 'reason' => 'The recipient does not exist.'], $outcome);
	}//end testAMissingUserIsAFailure()

	/**
	 * A user without an address gets nothing.
	 *
	 * @return void
	 */
	public function testAUserWithoutAnAddressIsAFailure(): void {
		$this->userWith(address: null);
		$this->mailer->expects($this->never())->method('send');

		$outcome = $this->service()->deliver(job: ['name' => 'Weekly'], userId: 'ann', file: self::file());

		$this->assertSame(['userId' => 'ann', 'status' => 'failed', 'reason' => 'The recipient has no email address.'], $outcome);
	}//end testAUserWithoutAnAddressIsAFailure()

	/**
	 * A mailer error is a logged failure with its message.
	 *
	 * @return void
	 */
	public function testAMailerErrorIsALoggedFailure(): void {
		$this->userWith(address: 'ann@example.org');
		$this->mailer->method('createAttachment')->willReturn($this->attachment);
		$this->mailer->method('send')->willThrowException(new RuntimeException('smtp down'));
		$this->logger->expects($this->once())->method('warning')->with(
			'Pipelinq: a report mail could not be sent',
			['userId' => 'ann', 'job' => 'Weekly', 'exception' => 'smtp down']
		);

		$outcome = $this->service()->deliver(job: ['name' => 'Weekly'], userId: 'ann', file: self::file());

		$this->assertSame(['userId' => 'ann', 'status' => 'failed', 'reason' => 'smtp down'], $outcome);
	}//end testAMailerErrorIsALoggedFailure()

	/**
	 * A recipient the mail server refused is a failure.
	 *
	 * @return void
	 */
	public function testARefusedRecipientIsAFailure(): void {
		$this->userWith(address: 'ann@example.org');
		$this->mailer->method('createAttachment')->willReturn($this->attachment);
		$this->mailer->method('send')->willReturn(['ann@example.org']);

		$outcome = $this->service()->deliver(job: ['name' => 'Weekly'], userId: 'ann', file: self::file());

		$this->assertSame(['userId' => 'ann', 'status' => 'failed', 'reason' => 'The mail server refused the recipient.'], $outcome);
	}//end testARefusedRecipientIsAFailure()
}//end class
