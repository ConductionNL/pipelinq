<?php

/**
 * Edge cases of EventEmailService: undated events, registrations without an
 * id, the configured sender, and a transport that refuses or fails.
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
 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\EventEmailService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the EventEmailService paths the main test does not reach.
 *
 * @covers \OCA\Pipelinq\Service\Marketing\EventEmailService
 *
 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
 */
class EventEmailServiceEdgesTest extends TestCase {

	/**
	 * Now, as Unix time: 2026-11-01T10:00:00Z.
	 */
	private const NOW = 1793527200;

	/**
	 * The mailer.
	 *
	 * @var IMailer&MockObject
	 */
	private IMailer $mailer;

	/**
	 * The message the mailer hands out.
	 *
	 * @var IMessage&MockObject
	 */
	private IMessage $message;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The plain body the last message was given.
	 *
	 * @var string
	 */
	private string $body = '';

	/**
	 * App config values by key.
	 *
	 * @var array<string, string>
	 */
	private array $config = ['event_link_secret' => 'test-secret'];

	/**
	 * Recording doubles for the mailer and the logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->message = $this->createMock(IMessage::class);
		$this->message->method('setPlainBody')->willReturnCallback(
			function (string $body): IMessage {
				$this->body = $body;
				return $this->message;
			}
		);

		$this->mailer = $this->createMock(IMailer::class);
		$this->mailer->method('validateMailAddress')->willReturnCallback(static fn (string $a): bool => str_contains($a, '@'));
		$this->mailer->method('createMessage')->willReturn($this->message);

		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * An unreadable start is shown as written, and the calendar times stay empty.
	 *
	 * @return void
	 */
	public function testAnUnreadableStartIsShownRawAndLeavesTheCalendarTimesEmpty(): void {
		$this->mailer->method('send')->willReturn([]);
		$event = ['uuid' => 'ev-1', 'title' => 'Open dag', 'startAt' => 'next tuesday-ish'];

		$this->assertTrue($this->service()->sendReminder(event: $event, registration: ['email' => 'a@example.org']));
		$this->assertSame('A reminder: Open dag is on next tuesday-ish.', $this->body);

		$ics = $this->service()->ics(event: $event, registration: ['uuid' => 'reg-1']);
		$this->assertStringContainsString("DTSTART:\r\n", $ics);
		$this->assertStringContainsString("DTEND:\r\n", $ics);
		$this->assertStringNotContainsString('ATTENDEE', $ics);
		$this->assertStringNotContainsString('LOCATION', $ics);
	}//end testAnUnreadableStartIsShownRawAndLeavesTheCalendarTimesEmpty()

	/**
	 * Without a start the date reads empty, and a registration without an id gets no cancel link.
	 *
	 * @return void
	 */
	public function testAnUndatedEventAndAnAnonymousRegistrationHaveNoDateAndNoCancelLink(): void {
		$this->mailer->method('send')->willReturn([]);

		$this->assertSame('', $this->service()->cancelUrl(event: ['startAt' => '2026-12-01T10:00:00Z'], registration: ['name' => 'Anna']));
		$this->assertTrue($this->service()->sendReminder(event: ['title' => 'Open dag'], registration: ['email' => 'a@example.org']));
		$this->assertSame('A reminder: Open dag is on .', $this->body);
	}//end testAnUndatedEventAndAnAnonymousRegistrationHaveNoDateAndNoCancelLink()

	/**
	 * A cancel link for an event with no readable start lasts thirty days from now.
	 *
	 * @return void
	 */
	public function testACancelLinkWithoutAReadableStartExpiresInThirtyDays(): void {
		$url = $this->service()->cancelUrl(event: ['startAt' => 'soon'], registration: ['id' => 'reg-9']);

		$token = substr($url, (strpos($url, 'token=') + 6));
		$this->assertSame('reg-9', explode('.', $token)[0]);
		$this->assertSame((string)(self::NOW + (30 * 86400)), explode('.', $token)[2]);
		$this->assertSame('reg-9', $this->service()->verifyCancelToken(token: $token));
	}//end testACancelLinkWithoutAReadableStartExpiresInThirtyDays()

	/**
	 * A valid configured sender is set as the From address; an invalid one is not.
	 *
	 * @return void
	 */
	public function testOnlyAValidConfiguredSenderBecomesTheFromAddress(): void {
		$this->mailer->method('send')->willReturn([]);
		$this->config['event_email_sender'] = 'events@example.org';
		$this->message->expects($this->once())->method('setFrom')->with(['events@example.org']);

		$this->assertTrue($this->service()->sendClosed(event: ['title' => 'Open dag'], person: ['email' => 'a@example.org']));

		$this->config['event_email_sender'] = 'not-an-address';
		$this->assertTrue($this->service()->sendClosed(event: ['title' => 'Open dag'], person: ['email' => 'a@example.org']));
	}//end testOnlyAValidConfiguredSenderBecomesTheFromAddress()

	/**
	 * A blank or invalid recipient is refused before a message is built.
	 *
	 * @return void
	 */
	public function testAnInvalidRecipientIsRefusedBeforeAMessageIsBuilt(): void {
		$this->mailer->expects($this->never())->method('createMessage');

		$this->assertFalse($this->service()->sendClosed(event: ['title' => 'Open dag'], person: ['email' => '  ']));
		$this->assertFalse($this->service()->sendClosed(event: ['title' => 'Open dag'], person: ['email' => 'nobody']));
	}//end testAnInvalidRecipientIsRefusedBeforeAMessageIsBuilt()

	/**
	 * A transport that names a failed recipient answers false.
	 *
	 * @return void
	 */
	public function testAFailedRecipientIsNotASend(): void {
		$this->mailer->method('send')->willReturn(['a@example.org']);

		$this->assertFalse($this->service()->sendClosed(event: ['title' => 'Open dag'], person: ['email' => 'a@example.org']));
	}//end testAFailedRecipientIsNotASend()

	/**
	 * A transport that throws answers false and logs a warning with the reason.
	 *
	 * @return void
	 */
	public function testATransportFailureIsLoggedAndAnswersFalse(): void {
		$this->mailer->method('send')->willThrowException(new RuntimeException('smtp down'));
		$this->logger->expects($this->once())->method('warning')->with(
			'Pipelinq: an event mail could not be sent',
			['app' => 'pipelinq', 'exception' => 'smtp down']
		);

		$this->assertFalse($this->service()->sendClosed(event: ['title' => 'Open dag'], person: ['email' => 'a@example.org']));
	}//end testATransportFailureIsLoggedAndAnswersFalse()

	/**
	 * The service over the doubles.
	 *
	 * @return EventEmailService
	 */
	private function service(): EventEmailService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default));

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $params): string => 'https://cloud.example/' . $route . '?token=' . $params['token']
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new EventEmailService($this->mailer, $l10n, $appConfig, $urls, $time, $this->logger);
	}//end service()
}//end class
