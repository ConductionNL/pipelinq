<?php

/**
 * Unit tests for EventCancelController.
 *
 * Opening the cancel link changes nothing; pressing the button with a valid
 * token cancels and mails whoever moves up from the waiting list; an expired
 * or forged token is refused and changes nothing.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
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

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\EventCancelController;
use OCA\Pipelinq\Service\Marketing\EventEmailService;
use OCA\Pipelinq\Service\Marketing\EventRegistrationService;
use OCA\Pipelinq\Service\TouchpointService;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../Support/InMemoryListObjectStore.php';

/**
 * Tests for the public cancel page of an event registration.
 */
class EventCancelControllerTest extends TestCase {

	/**
	 * Now, as Unix time: 2026-11-01T10:00:00Z.
	 */
	private const NOW = 1793527200;

	/**
	 * The store.
	 *
	 * @var InMemoryListObjectStore
	 */
	private InMemoryListObjectStore $store;

	/**
	 * Addresses mailed.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $mailedTo = [];

	/**
	 * The mail service, real, over a recording mailer.
	 *
	 * @var EventEmailService
	 */
	private EventEmailService $mails;

	/**
	 * Build the controller over a full event with one person waiting.
	 *
	 * @return EventCancelController
	 */
	private function controller(): EventCancelController {
		$this->store = new InMemoryListObjectStore(
			[
				'campaignEvent' => [['uuid' => 'ev-1', 'title' => 'Webinar', 'signupStatus' => 'full', 'capacity' => 1, 'startAt' => '2026-11-12T13:00:00+00:00']],
				'eventRegistration' => [
					['uuid' => 'r-1', 'event' => 'ev-1', 'email' => 'a@example.nl', 'status' => 'confirmed', 'registeredAt' => '2026-10-01T09:00:00+00:00'],
					['uuid' => 'w-1', 'event' => 'ev-1', 'email' => 'w@example.nl', 'status' => 'waitlist', 'waitlistPosition' => 1, 'registeredAt' => '2026-10-02T09:00:00+00:00'],
				],
			]
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturn(true);
		$mailer->method('createMessage')->willReturnCallback(
			function (): IMessage {
				$message = $this->createMock(IMessage::class);
				$message->method('setTo')->willReturnCallback(
					function (array $to) use ($message): IMessage {
						$this->mailedTo[] = $to;
						return $message;
					}
				);
				return $message;
			}
		);
		$mailer->method('send')->willReturn([]);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => ($key === 'event_link_secret' ? 's3cret' : $default));
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturn('/apps/pipelinq/events/cancel');
		$urls->method('linkToRouteAbsolute')->willReturn('https://cloud.example/apps/pipelinq/events/cancel');

		$this->mails = new EventEmailService($mailer, $l10n, $appConfig, $urls, $time, $this->createMock(LoggerInterface::class));

		return new EventCancelController(
			$this->createMock(IRequest::class),
			new EventRegistrationService($this->store, new TouchpointService($this->store), $time),
			$this->mails,
			$urls
		);
	}//end controller()

	/**
	 * Opening the link shows the event and the button, and changes nothing.
	 *
	 * @return void
	 */
	public function testOpeningTheLinkChangesNothing(): void {
		$controller = $this->controller();
		$token = $this->mails->cancelToken(registrationId: 'r-1', expiresAt: (self::NOW + 3600));
		$before = $this->store->writes;

		$response = $controller->show(token: $token);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('event-cancel', $response->getTemplateName());
		$this->assertSame('confirm', $response->getParams()['state']);
		$this->assertSame('Webinar', $response->getParams()['title']);
		$this->assertSame($token, $response->getParams()['token']);
		$this->assertSame('/apps/pipelinq/events/cancel', $response->getParams()['action']);
		$this->assertSame($before, $this->store->writes);
		$this->assertSame('confirmed', $this->store->rows['eventRegistration']['r-1']['status']);
		$this->assertSame([], $this->mailedTo);
	}//end testOpeningTheLinkChangesNothing()

	/**
	 * Pressing the button cancels, moves the waiting person up and mails them.
	 *
	 * @return void
	 */
	public function testPressingTheButtonCancels(): void {
		$controller = $this->controller();
		$token = $this->mails->cancelToken(registrationId: 'r-1', expiresAt: (self::NOW + 3600));

		$response = $controller->cancel(token: $token);

		$this->assertSame('done', $response->getParams()['state']);
		$this->assertSame('cancelled', $this->store->rows['eventRegistration']['r-1']['status']);
		$this->assertSame('confirmed', $this->store->rows['eventRegistration']['w-1']['status']);
		$this->assertSame([['w@example.nl']], $this->mailedTo);
		$this->assertSame('2026-11-01T10:00:00+00:00', $this->store->rows['eventRegistration']['w-1']['confirmationSentAt']);
		$this->assertSame('done', $controller->show(token: $token)->getParams()['state']);
	}//end testPressingTheButtonCancels()

	/**
	 * An expired or forged token is refused with a 404 and cancels nothing.
	 *
	 * @return void
	 */
	public function testAnExpiredOrForgedTokenIsRefused(): void {
		$controller = $this->controller();
		$expired = $this->mails->cancelToken(registrationId: 'r-1', expiresAt: self::NOW);
		$before = $this->store->writes;

		foreach ([$expired, 'r-1.event-cancel.9999999999.forged', ''] as $token) {
			$response = $controller->cancel(token: $token);
			$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
			$this->assertSame('invalid', $response->getParams()['state']);
			$this->assertSame('', $response->getParams()['token']);
		}

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->show(token: $expired)->getStatus());
		$this->assertSame($before, $this->store->writes);
		$this->assertSame('confirmed', $this->store->rows['eventRegistration']['r-1']['status']);
	}//end testAnExpiredOrForgedTokenIsRefused()
}//end class
