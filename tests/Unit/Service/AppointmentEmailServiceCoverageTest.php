<?php

/**
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\AppointmentEmailService;
use OCA\Pipelinq\Service\IntegriqConsentClient;
use OCA\Pipelinq\Service\UnsubscribeMail;
use OCA\Pipelinq\Tests\Unit\Support\RecordingMessage;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Mail\IAttachment;
use OCP\Mail\IMailer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Coverage tests for the AppointmentEmailService send pipeline.
 *
 * @covers \OCA\Pipelinq\Service\AppointmentEmailService
 * @uses   \OCA\Pipelinq\Service\UnsubscribeMail
 * @uses   \OCA\Pipelinq\Service\Consent\ConsentAsk
 * @uses   \OCA\Pipelinq\Util\NullablePayloadToArray
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 * @uses   \OCA\Pipelinq\Util\ObjectToPlainArray
 */
class AppointmentEmailServiceCoverageTest extends TestCase {

	/**
	 * App config values by key.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Objects the fake register returns, keyed by id.
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	private array $rows = [];

	/**
	 * Objects handed to saveObject.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Messages created by the mailer.
	 *
	 * @var array<int, RecordingMessage>
	 */
	private array $messages = [];

	/**
	 * Integriq's decision returned for every ask.
	 *
	 * @var array<string, mixed>
	 */
	private array $decision = ['send' => true, 'overridden' => false, 'code' => 'allowed', 'reason' => '', 'unsubscribe' => null];

	/**
	 * Mailer mock.
	 *
	 * @var IMailer&MockObject
	 */
	private IMailer $mailer;

	/**
	 * Object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Integriq client mock.
	 *
	 * @var IntegriqConsentClient&MockObject
	 */
	private IntegriqConsentClient $integriq;

	/**
	 * Set up shared fakes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->config = [
			'register' => 'reg-1',
			'booking_schema' => 'sch-booking',
			'service_schema' => 'sch-service',
			'contact_schema' => 'sch-contact',
			'resource_schema' => 'sch-resource',
			AppointmentEmailService::LINK_SECRET_KEY => 'secret',
		];
		$this->rows = [
			'b-1' => [
				'@self' => ['id' => 'b-1'],
				'customerId' => 'c-1',
				'serviceId' => 's-1',
				'startAt' => '2026-10-06T09:00:00Z',
				'endAt' => '2026-10-06T09:30:00Z',
			],
			'c-1' => ['email' => 'jan@example.nl', 'fullName' => 'Jan Jansen'],
			's-1' => ['name' => 'Paspoort', 'price' => 0],
		];

		$this->mailer = $this->createMock(IMailer::class);
		$this->mailer->method('createMessage')->willReturnCallback(
			function (): RecordingMessage {
				$message = new RecordingMessage();
				$this->messages[] = $message;
				return $message;
			}
		);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->objectService->method('find')->willReturnCallback(
			function ($id) {
				$row = ($this->rows[(string)$id] ?? null);
				if ($row === null) {
					return null;
				}

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($row);
				return $entity;
			}
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) {
				$this->saved[] = $object;
				return $this->createMock(ObjectEntityInterface::class);
			}
		);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->integriq = $this->createMock(IntegriqConsentClient::class);
		$this->integriq->method('decideOne')->willReturnCallback(fn () => $this->decision);
	}//end setUp()

	/**
	 * Build the service under test.
	 *
	 * @param ContainerInterface|null $container Container for the header helper.
	 *
	 * @return AppointmentEmailService
	 */
	private function service(?ContainerInterface $container = null): AppointmentEmailService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => ($this->config[$key] ?? $default)
		);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $u) => 'https://nc.example'.$u);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $p = []) => vsprintf($text, $p));

		return new AppointmentEmailService(
			appConfig: $appConfig,
			mailer: $this->mailer,
			urlGenerator: $urls,
			l10n: $l10n,
			logger: $this->logger,
			objectService: $this->objectService,
			integriq: $this->integriq,
			unsubscribeMail: new UnsubscribeMail(($container ?? $this->createMock(ContainerInterface::class)), $l10n, $this->logger),
		);
	}//end service()

	/**
	 * Empty booking ids are refused before anything is loaded.
	 *
	 * @return void
	 */
	public function testEmptyBookingIdIsRefusedWithoutLookups(): void {
		$this->objectService->expects($this->never())->method('find');
		$service = $this->service();

		self::assertFalse($service->sendConfirmation(''));
		self::assertFalse($service->sendReminder(''));
	}//end testEmptyBookingIdIsRefusedWithoutLookups()

	/**
	 * A booking the register does not know produces no mail.
	 *
	 * @return void
	 */
	public function testUnknownBookingSendsNothing(): void {
		$this->mailer->expects($this->never())->method('send');

		self::assertFalse($this->service()->sendConfirmation('b-missing'));
		self::assertFalse($this->service()->sendReminder('b-missing'));
	}//end testUnknownBookingSendsNothing()

	/**
	 * An unconfigured register refuses the lookup and logs it.
	 *
	 * @return void
	 */
	public function testUnconfiguredRegisterRefusesLookupAndWarns(): void {
		$this->config['register'] = '';
		$this->objectService->expects($this->never())->method('find');
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('app-config "register" is not configured'));

		self::assertFalse($this->service()->sendConfirmation('b-1'));
	}//end testUnconfiguredRegisterRefusesLookupAndWarns()

	/**
	 * A register lookup that throws is treated as a missing booking.
	 *
	 * @return void
	 */
	public function testThrowingLookupIsTreatedAsMissing(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->once())->method('find')->willThrowException(new RuntimeException('db down'));
		$this->objectService = $objects;
		$this->mailer->expects($this->never())->method('send');

		self::assertFalse($this->service()->sendReminder('b-1'));
	}//end testThrowingLookupIsTreatedAsMissing()

	/**
	 * A customer without any email address skips the mail with an info log.
	 *
	 * @return void
	 */
	public function testCustomerWithoutEmailIsSkipped(): void {
		$this->rows['c-1'] = ['fullName' => 'Jan', 'emails' => ['', ['value' => ''], 42]];
		$this->logger->expects($this->once())->method('info')
			->with('Pipelinq appointment email: skipped — no recipient email on customer', ['booking' => 'b-1']);
		$this->integriq->expects($this->never())->method('decideOne');

		self::assertFalse($this->service()->sendConfirmation('b-1'));
	}//end testCustomerWithoutEmailIsSkipped()

	/**
	 * A booking without a customer reference has no recipient.
	 *
	 * @return void
	 */
	public function testBookingWithoutCustomerHasNoRecipient(): void {
		unset($this->rows['b-1']['customerId']);
		$this->mailer->expects($this->never())->method('createMessage');

		self::assertFalse($this->service()->sendReminder('b-1'));
	}//end testBookingWithoutCustomerHasNoRecipient()

	/**
	 * The full confirmation: subject, body lines, ICS attachment, sender and stamp.
	 *
	 * @return void
	 */
	public function testConfirmationComposesBodyIcsAndStampsBooking(): void {
		$this->config['appointment_email_sender'] = 'noreply@gemeente.nl';
		$this->rows['b-1']['notes'] = "Bring ID, please; thanks\nline2";
		$this->rows['b-1']['resourceAssignments'] = [['resourceId' => 'r-1']];
		$this->rows['r-1'] = ['name' => 'Balie 3'];
		$this->rows['s-1'] = ['name' => 'Paspoort', 'price' => 1234.5, 'currency' => 'EUR'];
		$this->rows['c-1'] = ['firstName' => 'Jan', 'lastName' => 'Jansen', 'emails' => [['address' => 'jan@example.nl']]];

		$this->mailer->method('validateMailAddress')->willReturn(true);
		$ics = null;
		$attachment = $this->createMock(IAttachment::class);
		$this->mailer->expects($this->once())->method('createAttachment')
			->with($this->anything(), 'appointment.ics', 'text/calendar; method=REQUEST; charset=UTF-8')
			->willReturnCallback(
				function (string $content) use (&$ics, $attachment) {
					$ics = $content;
					return $attachment;
				}
			);
		$this->mailer->expects($this->once())->method('send')->willReturn([]);

		$service = $this->service();
		self::assertTrue($service->sendConfirmation('b-1'));
		self::assertNull($service->lastRefusal());

		$message = $this->messages[0];
		self::assertSame(['jan@example.nl'], $message->to);
		self::assertSame('Uw afspraak is bevestigd: Paspoort, 06-10-2026 11:00', $message->subject);
		self::assertStringContainsString("Beste Jan Jansen,\n\nUw afspraak is bevestigd.", $message->plain);
		self::assertStringContainsString('Medewerker/locatie: Balie 3', $message->plain);
		self::assertStringContainsString('Datum en tijd: 06-10-2026 11:00 tot 11:30', $message->plain);
		self::assertStringContainsString('Prijs: EUR 1.234,50', $message->plain);
		self::assertStringContainsString("Opmerkingen:\nBring ID, please; thanks\nline2", $message->plain);
		self::assertMatchesRegularExpression(
			'~Afspraak verplaatsen: https://nc\.example/index\.php/apps/pipelinq/portal/reschedule\?link=b-1\.reschedule\.\d+\.[0-9a-f]{64}&bookingId=b-1~',
			$message->plain
		);
		self::assertStringEndsWith('Tot ziens!', $message->plain);

		self::assertIsString($ics);
		self::assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", $ics);
		self::assertStringContainsString("UID:b-1@pipelinq\r\n", $ics);
		self::assertStringContainsString("DTSTART:20261006T090000Z\r\nDTEND:20261006T093000Z\r\nSUMMARY:Paspoort\r\n", $ics);
		self::assertStringContainsString("DESCRIPTION:Bring ID\\, please\\; thanks\\nline2\r\n", $ics);
		self::assertStringContainsString("ATTENDEE;CN=Jan Jansen;RSVP=TRUE:mailto:jan@example.nl\r\n", $ics);
		self::assertStringContainsString("ORGANIZER:mailto:noreply@gemeente.nl\r\n", $ics);
		self::assertStringEndsWith("END:VEVENT\r\nEND:VCALENDAR\r\n", $ics);

		self::assertCount(1, $this->saved);
		self::assertArrayNotHasKey('@self', $this->saved[0]);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $this->saved[0]['confirmationSentAt']);
	}//end testConfirmationComposesBodyIcsAndStampsBooking()

	/**
	 * A confirmation for a customer without name greets "klant" and the ICS omits an empty description.
	 *
	 * @return void
	 */
	public function testConfirmationWithoutNameOrNotesUsesDefaults(): void {
		$this->rows['c-1'] = ['mail' => 'anon@example.nl'];
		$this->rows['b-1']['startAt'] = 'not-a-date';
		$this->mailer->method('validateMailAddress')->willReturn(true);
		$ics = null;
		$this->mailer->method('createAttachment')->willReturnCallback(
			function (string $content) use (&$ics) {
				$ics = $content;
				return $this->createMock(IAttachment::class);
			}
		);
		$this->mailer->method('send')->willReturn([]);

		self::assertTrue($this->service()->sendConfirmation('b-1'));
		$plain = $this->messages[0]->plain;
		self::assertStringStartsWith('Beste klant,', $plain);
		self::assertStringNotContainsString('Prijs:', $plain);
		self::assertStringNotContainsString('Medewerker/locatie:', $plain);
		// An unparseable date is shown verbatim and left out of the ICS.
		self::assertStringContainsString('Datum en tijd: not-a-date tot 11:30', $plain);
		self::assertStringContainsString("DTSTART:\r\n", $ics);
		self::assertStringNotContainsString('DESCRIPTION:', $ics);
		self::assertStringNotContainsString('ORGANIZER:', $ics);
	}//end testConfirmationWithoutNameOrNotesUsesDefaults()

	/**
	 * A refused confirmation is never sent and the booking records why.
	 *
	 * @return void
	 */
	public function testRefusedConfirmationStampsMailNotSent(): void {
		$this->decision = ['send' => false, 'overridden' => false, 'code' => 'opted-out', 'reason' => 'Opted out.', 'unsubscribe' => null];
		$this->mailer->expects($this->never())->method('createMessage');
		$this->logger->expects($this->once())->method('warning')
			->with('Pipelinq appointment email: not sent, integriq refused it', ['booking' => 'b-1', 'code' => 'opted-out']);

		$service = $this->service();
		self::assertFalse($service->sendConfirmation('b-1'));
		self::assertSame(['code' => 'opted-out', 'reason' => 'Opted out.'], $service->lastRefusal());
		self::assertSame('opted-out', $this->saved[0]['mailNotSentReason']);
		self::assertArrayHasKey('mailNotSentAt', $this->saved[0]);
	}//end testRefusedConfirmationStampsMailNotSent()

	/**
	 * An invalid recipient address is not dispatched.
	 *
	 * @return void
	 */
	public function testInvalidRecipientIsNotDispatched(): void {
		$this->mailer->method('validateMailAddress')->willReturn(false);
		$this->mailer->expects($this->never())->method('createMessage');
		$this->logger->expects($this->once())->method('warning')
			->with('Pipelinq appointment email: no valid recipient', ['recipient' => 'jan@example.nl']);

		self::assertFalse($this->service()->sendConfirmation('b-1'));
		self::assertSame([], $this->saved);
	}//end testInvalidRecipientIsNotDispatched()

	/**
	 * A throwing transport is logged and reported as not sent.
	 *
	 * @return void
	 */
	public function testThrowingTransportIsReportedAsFailure(): void {
		$this->mailer->method('validateMailAddress')->willReturn(true);
		$this->mailer->method('send')->willThrowException(new RuntimeException('smtp down'));
		$this->logger->expects($this->once())->method('warning')
			->with('Pipelinq appointment email: dispatch failed', ['exception' => 'smtp down']);

		self::assertFalse($this->service()->sendReminder('b-1'));
		self::assertSame([], $this->saved);
	}//end testThrowingTransportIsReportedAsFailure()

	/**
	 * Rejected recipients mean the mail was not accepted and nothing is stamped.
	 *
	 * @return void
	 */
	public function testRejectedRecipientsAreNotStamped(): void {
		$this->mailer->method('validateMailAddress')->willReturnCallback(static fn (string $a) => $a !== 'bad-sender');
		$this->config['appointment_email_sender'] = 'bad-sender';
		$this->mailer->expects($this->once())->method('send')->willReturn(['jan@example.nl']);

		self::assertFalse($this->service()->sendReminder('b-1'));
		self::assertSame([], $this->saved);
	}//end testRejectedRecipientsAreNotStamped()

	/**
	 * The reminder lists resource and price, carries no attachment and stamps reminderSentAt.
	 *
	 * @return void
	 */
	public function testReminderListsResourceAndPrice(): void {
		$this->rows['b-1']['resourceAssignments'] = [['resourceId' => 'r-1']];
		$this->rows['r-1'] = ['name' => 'Balie 1'];
		$this->rows['s-1'] = ['name' => 'Rijbewijs', 'price' => 41, 'currency' => 'USD'];
		$this->rows['c-1'] = ['emailAddress' => 'jan@example.nl', 'displayName' => 'J. Jansen'];
		$this->mailer->method('validateMailAddress')->willReturn(true);
		$this->mailer->expects($this->never())->method('createAttachment');
		$this->mailer->method('send')->willReturn([]);

		self::assertTrue($this->service()->sendReminder('b-1'));
		$message = $this->messages[0];
		self::assertSame('Herinnering: Uw afspraak morgen om 11:00', $message->subject);
		self::assertStringContainsString('Beste J. Jansen,', $message->plain);
		self::assertStringContainsString('Medewerker/locatie: Balie 1', $message->plain);
		self::assertStringContainsString('Tijd: 06-10-2026 11:00', $message->plain);
		self::assertStringContainsString('Prijs: USD 41,00', $message->plain);
		self::assertArrayHasKey('reminderSentAt', $this->saved[0]);
	}//end testReminderListsResourceAndPrice()

	/**
	 * A reminder to a customer with no name greets "klant"; a string email entry is used.
	 *
	 * @return void
	 */
	public function testReminderFallsBackToKlantAndStringEmailEntry(): void {
		$this->rows['c-1'] = ['emails' => ['', 'piet@example.nl']];
		$this->mailer->method('validateMailAddress')->willReturn(true);
		$this->mailer->method('send')->willReturn([]);

		self::assertTrue($this->service()->sendReminder('b-1'));
		self::assertSame(['piet@example.nl'], $this->messages[0]->to);
		self::assertStringStartsWith('Beste klant,', $this->messages[0]->plain);
	}//end testReminderFallsBackToKlantAndStringEmailEntry()

	/**
	 * Resource name resolution falls back to empty for every malformed assignment.
	 *
	 * @return void
	 */
	public function testMalformedResourceAssignmentsYieldNoResourceLine(): void {
		$this->mailer->method('validateMailAddress')->willReturn(true);
		$this->mailer->method('send')->willReturn([]);

		foreach ([['not-an-array'], [['resourceId' => '']], [['resourceId' => 'r-unknown']], 'scalar'] as $assignments) {
			$this->rows['b-1']['resourceAssignments'] = $assignments;
			self::assertTrue($this->service()->sendReminder('b-1'));
			self::assertStringNotContainsString('Medewerker/locatie', end($this->messages)->plain);
		}
	}//end testMalformedResourceAssignmentsYieldNoResourceLine()

	/**
	 * Unsubscribe material adds the body line and passes it to the header helper.
	 *
	 * @return void
	 */
	public function testUnsubscribeLinkIsAppendedAndHeadersApplied(): void {
		$this->decision['unsubscribe'] = ['url' => 'https://integriq.example/u/1', 'oneClickUrl' => 'https://integriq.example/u/1/oc'];
		$helper = new class {
			public array $calls = [];

			public function apply(object $message, array $unsubscribe): bool {
				$this->calls[] = $unsubscribe;
				return true;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->once())->method('get')->with(UnsubscribeMail::HEADER_HELPER)->willReturn($helper);
		$this->mailer->method('validateMailAddress')->willReturn(true);
		$this->mailer->method('send')->willReturn([]);

		self::assertTrue($this->service($container)->sendReminder('b-1'));
		self::assertStringEndsWith("Meld u af: https://integriq.example/u/1\n", $this->messages[0]->plain);
		self::assertSame([$this->decision['unsubscribe']], $helper->calls);
	}//end testUnsubscribeLinkIsAppendedAndHeadersApplied()

	/**
	 * A stamp lookup that throws is logged and leaves the booking untouched.
	 *
	 * @return void
	 */
	public function testStampLookupFailureIsLogged(): void {
		$calls = 0;
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function ($id) use (&$calls) {
				if ((string)$id === 'b-1' && ++$calls > 1) {
					throw new RuntimeException('gone');
				}

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($this->rows[(string)$id]);
				return $entity;
			}
		);
		$objects->expects($this->never())->method('saveObject');
		$this->objectService = $objects;
		$this->mailer->method('validateMailAddress')->willReturn(true);
		$this->mailer->method('send')->willReturn([]);
		$this->logger->expects($this->once())->method('warning')
			->with('Pipelinq appointment email: stamp lookup failed', ['booking' => 'b-1']);

		self::assertTrue($this->service()->sendReminder('b-1'));
	}//end testStampLookupFailureIsLogged()

	/**
	 * A booking that disappears before the stamp is not saved.
	 *
	 * @return void
	 */
	public function testStampSkipsVanishedBooking(): void {
		$calls = 0;
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function ($id) use (&$calls) {
				if ((string)$id === 'b-1' && ++$calls > 1) {
					return null;
				}

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($this->rows[(string)$id]);
				return $entity;
			}
		);
		$objects->expects($this->never())->method('saveObject');
		$this->objectService = $objects;
		$this->mailer->method('validateMailAddress')->willReturn(true);
		$this->mailer->method('send')->willReturn([]);

		self::assertTrue($this->service()->sendReminder('b-1'));
	}//end testStampSkipsVanishedBooking()

	/**
	 * A failing stamp save is logged with the field name.
	 *
	 * @return void
	 */
	public function testStampSaveFailureIsLogged(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function ($id) {
				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($this->rows[(string)$id]);
				return $entity;
			}
		);
		$objects->expects($this->once())->method('saveObject')
			->with($this->anything(), [], 'reg-1', 'sch-booking', 'b-1')
			->willThrowException(new RuntimeException('locked'));
		$this->objectService = $objects;
		$this->mailer->method('validateMailAddress')->willReturn(true);
		$this->mailer->method('send')->willReturn([]);
		$this->logger->expects($this->once())->method('warning')
			->with('Pipelinq appointment email: stamp save failed', ['booking' => 'b-1', 'field' => 'reminderSentAt']);

		self::assertTrue($this->service()->sendReminder('b-1'));
	}//end testStampSaveFailureIsLogged()
}//end class
