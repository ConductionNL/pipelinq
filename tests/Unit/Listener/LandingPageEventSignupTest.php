<?php

/**
 * Unit tests for the event leg of LandingPageFormSubmittedListener.
 *
 * A sign-up on the landing page of a campaign with an event still writes the
 * contact, lead and touchpoint and acknowledges the submission, and now also
 * registers the person for the event and mails them; a redelivered submission
 * writes nothing; a closed event gets the closed notice and no registration;
 * and a failing registration never costs the lead.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-a-landing-page-sign-up-registers-for-the-event-req-mev-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\Pipelinq\Event\LandingPageFormSubmittedEvent;
use OCA\Pipelinq\Listener\LandingPageFormSubmittedListener;
use OCA\Pipelinq\Service\CampaignLinkDecorator;
use OCA\Pipelinq\Service\CampaignService;
use OCA\Pipelinq\Service\Marketing\EventEmailService;
use OCA\Pipelinq\Service\Marketing\EventRegistrationService;
use OCA\Pipelinq\Service\TouchpointService;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/../Support/InMemoryListObjectStore.php';

/**
 * Tests for a landing page sign-up on a campaign with an event.
 */
class LandingPageEventSignupTest extends TestCase {

	/**
	 * The store every write lands in.
	 *
	 * @var InMemoryListObjectStore
	 */
	private InMemoryListObjectStore $store;

	/**
	 * The mail double.
	 *
	 * @var EventEmailService&MockObject
	 */
	private EventEmailService $mails;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the listener over a campaign with one event.
	 *
	 * @param array<string, mixed>          $event         Event fields over the defaults.
	 * @param EventRegistrationService|null $registrations A registration service to use instead of the real one.
	 *
	 * @return LandingPageFormSubmittedListener
	 */
	private function build(array $event = [], ?EventRegistrationService $registrations = null): LandingPageFormSubmittedListener {
		$this->store = new InMemoryListObjectStore(
			[
				'campaign' => [['uuid' => 'camp-1', 'name' => 'Webinar AI voor gemeenten', 'formRef' => 'form-1']],
				'campaignEvent' => [array_merge(['uuid' => 'ev-1', 'campaign' => 'camp-1', 'signupStatus' => 'open', 'capacity' => 100], $event)],
			]
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnArgument(2);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1793527200);
		$touchpoints = new TouchpointService($this->store);
		$this->mails = $this->createMock(EventEmailService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		return new LandingPageFormSubmittedListener(
			$this->store,
			new CampaignService($this->store, $appConfig, new CampaignLinkDecorator($appConfig)),
			$touchpoints,
			$this->logger,
			($registrations ?? new EventRegistrationService($this->store, $touchpoints, $time)),
			$this->mails
		);
	}//end build()

	/**
	 * One submission on the campaign's landing page.
	 *
	 * @param array<string, string> $values The form values.
	 *
	 * @return LandingPageFormSubmittedEvent
	 */
	private function submission(array $values = ['name' => 'J. de Vries', 'email' => 'JANE.DOE@EXAMPLE.COM']): LandingPageFormSubmittedEvent {
		return new LandingPageFormSubmittedEvent(
			'pipelinq',
			'form-1',
			'page-1',
			'/campagne/webinar-ai-voor-gemeenten',
			'open-tilburg',
			'pipelinq:campaign:camp-1',
			$values,
			[],
			[],
			'',
			'2026-10-21T09:47:12+00:00',
			'nonce-1',
			'pipelinq:campaign:camp-1'
		);
	}//end submission()

	/**
	 * The sign-up becomes a registration linked to its contact and lead, and is confirmed by mail.
	 *
	 * @return void
	 */
	public function testASignUpRegistersForTheEvent(): void {
		$listener = $this->build();
		$this->mails->expects($this->once())->method('sendConfirmation')
			->with($this->callback(static fn (array $e): bool => $e['uuid'] === 'ev-1'), $this->callback(static fn (array $r): bool => $r['status'] === 'confirmed'))
			->willReturn(true);
		$event = $this->submission();

		$listener->handle($event);

		$this->assertTrue($event->isHandled());
		$this->assertNotNull($event->getLeadId());
		$this->assertSame(1, $this->store->countOf(schemaSlug: 'lead'));
		$registration = array_values($this->store->rows['eventRegistration'])[0];
		$this->assertSame('ev-1', $registration['event']);
		$this->assertSame('jane.doe@example.com', $registration['email']);
		$this->assertSame('J. de Vries', $registration['name']);
		$this->assertSame('confirmed', $registration['status']);
		$this->assertSame('web-form', $registration['source']);
		$this->assertSame($event->getLeadId(), $registration['lead']);
		$this->assertSame(array_keys($this->store->rows['contact'])[0], $registration['contact']);
		$this->assertSame('2026-11-01T10:00:00+00:00', $registration['confirmationSentAt']);
	}//end testASignUpRegistersForTheEvent()

	/**
	 * A redelivered submission registers nobody a second time and mails nobody.
	 *
	 * @return void
	 */
	public function testARedeliveredSubmissionWritesNothing(): void {
		$listener = $this->build();
		$this->mails->expects($this->once())->method('sendConfirmation')->willReturn(true);
		$listener->handle($this->submission());
		$before = $this->store->writes;

		$second = $this->submission();
		$listener->handle($second);

		$this->assertSame($before, $this->store->writes);
		$this->assertCount(1, $this->store->rows['eventRegistration']);
		$this->assertTrue($second->isHandled());
	}//end testARedeliveredSubmissionWritesNothing()

	/**
	 * A closed event registers nobody and sends the closed notice; the lead is still written.
	 *
	 * @return void
	 */
	public function testAClosedEventSendsTheClosedNotice(): void {
		$listener = $this->build(event: ['signupStatus' => 'closed']);
		$this->mails->expects($this->never())->method('sendConfirmation');
		$this->mails->expects($this->once())->method('sendClosed')
			->with($this->anything(), $this->callback(static fn (array $p): bool => $p['email'] === 'jane.doe@example.com'));
		$event = $this->submission();

		$listener->handle($event);

		$this->assertArrayNotHasKey('eventRegistration', $this->store->rows);
		$this->assertNotNull($event->getLeadId());
	}//end testAClosedEventSendsTheClosedNotice()

	/**
	 * Without an address there is nothing to register.
	 *
	 * @return void
	 */
	public function testASignUpWithoutAnAddressRegistersNobody(): void {
		$listener = $this->build();
		$this->mails->expects($this->never())->method($this->anything());

		$listener->handle($this->submission(values: ['name' => 'Anoniem']));

		$this->assertArrayNotHasKey('eventRegistration', $this->store->rows);
	}//end testASignUpWithoutAnAddressRegistersNobody()

	/**
	 * A failing registration is logged on its own and the submission stays acknowledged with its lead.
	 *
	 * @return void
	 */
	public function testAFailingRegistrationKeepsTheLead(): void {
		$registrations = $this->createMock(EventRegistrationService::class);
		$registrations->method('eventForCampaign')->willThrowException(new RuntimeException('store down'));
		$listener = $this->build(registrations: $registrations);
		$this->logger->expects($this->once())->method('warning')->with(
			'Pipelinq: landing page sign-up could not be registered for the event',
			['form' => 'form-1', 'reason' => 'store down']
		);
		$event = $this->submission();

		$listener->handle($event);

		$this->assertTrue($event->isHandled());
		$this->assertNotNull($event->getLeadId());
	}//end testAFailingRegistrationKeepsTheLead()
}//end class
