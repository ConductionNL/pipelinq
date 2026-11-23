<?php

/**
 * The edges of LandingPageFormSubmittedListener: a failing ingest, a
 * campaign without an event, no mailer and outcomes that are not mailed.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\Pipelinq\Event\LandingPageFormSubmittedEvent;
use OCA\Pipelinq\Kernel\Contracts\CampaignServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ListObjectStoreInterface;
use OCA\Pipelinq\Kernel\Contracts\TouchpointServiceInterface;
use OCA\Pipelinq\Listener\LandingPageFormSubmittedListener;
use OCA\Pipelinq\Service\CampaignLinkDecorator;
use OCA\Pipelinq\Service\CampaignService;
use OCA\Pipelinq\Service\Marketing\EventEmailService;
use OCA\Pipelinq\Service\Marketing\EventRegistrationOutcome;
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
 * A failure while ingesting is logged and leaves the submission unhandled; a
 * campaign without an event registers nobody; without a mailer a sign-up is
 * registered but not mailed; a duplicate, or an outcome without a
 * registration, is never confirmed by mail.
 *
 * @covers \OCA\Pipelinq\Listener\LandingPageFormSubmittedListener
 * @uses   \OCA\Pipelinq\Event\LandingPageFormSubmittedEvent
 * @uses   \OCA\Pipelinq\Listener\LandingSubmission
 * @uses   \OCA\Pipelinq\Listener\SubmissionCampaign
 * @uses   \OCA\Pipelinq\Listener\SubmissionRecords
 * @uses   \OCA\Pipelinq\Service\CampaignLinkDecorator
 * @uses   \OCA\Pipelinq\Service\CampaignService
 * @uses   \OCA\Pipelinq\Service\Marketing\EventRegistrationOutcome
 * @uses   \OCA\Pipelinq\Service\Marketing\EventRegistrationService
 * @uses   \OCA\Pipelinq\Service\Marketing\SubmitterParties
 * @uses   \OCA\Pipelinq\Service\TouchpointService
 */
class LandingPageEventSignupEdgesTest extends TestCase {

	/**
	 * The store every write lands in.
	 *
	 * @var InMemoryListObjectStore
	 */
	private InMemoryListObjectStore $store;

	/**
	 * The logger double; no edge here may log a warning.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the listener over a campaign with one event.
	 *
	 * @param EventRegistrationService|null $registrations A registration service to use instead of the real one.
	 * @param EventEmailService|null        $mails         The mailer, or null for none.
	 *
	 * @return LandingPageFormSubmittedListener
	 */
	private function build(?EventRegistrationService $registrations, ?EventEmailService $mails): LandingPageFormSubmittedListener {
		$this->store = new InMemoryListObjectStore(
			[
				'campaign' => [['uuid' => 'camp-1', 'name' => 'Webinar AI voor gemeenten', 'formRef' => 'form-1']],
				'campaignEvent' => [['uuid' => 'ev-1', 'campaign' => 'camp-1', 'signupStatus' => 'open', 'capacity' => 100]],
			]
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnArgument(2);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1793527200);
		$touchpoints  = new TouchpointService($this->store);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logger->expects($this->never())->method('warning');

		return new LandingPageFormSubmittedListener(
			$this->store,
			new CampaignService($this->store, $appConfig, new CampaignLinkDecorator($appConfig)),
			$touchpoints,
			$this->logger,
			($registrations ?? new EventRegistrationService($this->store, $touchpoints, $time)),
			$mails
		);
	}//end build()

	/**
	 * One submission on the campaign's landing page.
	 *
	 * @return LandingPageFormSubmittedEvent
	 */
	private function submission(): LandingPageFormSubmittedEvent {
		return new LandingPageFormSubmittedEvent(
			'pipelinq',
			'form-1',
			'page-1',
			'/campagne/webinar-ai-voor-gemeenten',
			'open-tilburg',
			'pipelinq:campaign:camp-1',
			['name' => 'J. de Vries', 'email' => 'jane.doe@example.com'],
			[],
			[],
			'',
			'2026-10-21T09:47:12+00:00',
			'nonce-1',
			'pipelinq:campaign:camp-1'
		);
	}//end submission()

	/**
	 * A failure while checking for a redelivery is logged with the form id
	 * and reason, and the submission is not marked handled.
	 *
	 * @return void
	 */
	public function testAFailingIngestIsLoggedAndLeavesTheSubmissionUnhandled(): void {
		$touchpoints = $this->createMock(TouchpointServiceInterface::class);
		$touchpoints->method('existsForNonce')->willThrowException(new RuntimeException('index down'));
		$touchpoints->expects($this->never())->method('recordSubmission');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Pipelinq: landing page submission could not be ingested',
			['form' => 'form-1', 'reason' => 'index down']
		);
		$listener = new LandingPageFormSubmittedListener(
			$this->createMock(ListObjectStoreInterface::class),
			$this->createMock(CampaignServiceInterface::class),
			$touchpoints,
			$logger
		);
		$event = $this->submission();

		$listener->handle($event);

		$this->assertFalse($event->isHandled());
		$this->assertNull($event->getLeadId());
	}//end testAFailingIngestIsLoggedAndLeavesTheSubmissionUnhandled()

	/**
	 * A campaign without an event registers nobody and mails nobody, while
	 * the lead is still written.
	 *
	 * @return void
	 */
	public function testACampaignWithoutAnEventRegistersNobody(): void {
		$registrations = $this->createMock(EventRegistrationService::class);
		$registrations->expects($this->once())->method('eventForCampaign')->with('camp-1')->willReturn(null);
		$registrations->expects($this->never())->method('register');
		$mails = $this->createMock(EventEmailService::class);
		$mails->expects($this->never())->method($this->anything());
		$event = $this->submission();

		$this->build(registrations: $registrations, mails: $mails)->handle($event);

		$this->assertTrue($event->isHandled());
		$this->assertSame(1, $this->store->countOf(schemaSlug: 'lead'));
	}//end testACampaignWithoutAnEventRegistersNobody()

	/**
	 * Without a mailer the sign-up is registered but never stamped as confirmed.
	 *
	 * @return void
	 */
	public function testWithoutAMailerTheSignUpIsRegisteredButNotConfirmed(): void {
		$event = $this->submission();

		$this->build(registrations: null, mails: null)->handle($event);

		$registration = array_values($this->store->rows['eventRegistration'])[0];
		$this->assertSame('ev-1', $registration['event']);
		$this->assertSame('confirmed', $registration['status']);
		$this->assertArrayNotHasKey('confirmationSentAt', $registration);
	}//end testWithoutAMailerTheSignUpIsRegisteredButNotConfirmed()

	/**
	 * A duplicate sign-up, and a new one that came back without its
	 * registration, are not mailed and not stamped.
	 *
	 * @return void
	 */
	public function testADuplicateOrARegistrationlessOutcomeIsNotMailed(): void {
		$registrations = $this->createMock(EventRegistrationService::class);
		$registrations->method('eventForCampaign')->willReturn(['uuid' => 'ev-1']);
		$registrations->expects($this->exactly(2))->method('register')->willReturnOnConsecutiveCalls(
			new EventRegistrationOutcome(status: EventRegistrationOutcome::DUPLICATE, registration: ['uuid' => 'r-1']),
			new EventRegistrationOutcome(status: EventRegistrationOutcome::REGISTERED, registration: null)
		);
		$registrations->expects($this->never())->method('stamp');
		$mails = $this->createMock(EventEmailService::class);
		$mails->expects($this->never())->method('sendConfirmation');
		$mails->expects($this->never())->method('sendClosed');
		$listener = $this->build(registrations: $registrations, mails: $mails);

		$first = $this->submission();
		$listener->handle($first);
		$second = new LandingPageFormSubmittedEvent(
			'pipelinq',
			'form-1',
			'page-1',
			'/campagne/webinar-ai-voor-gemeenten',
			'open-tilburg',
			'pipelinq:campaign:camp-1',
			['name' => 'A. Jansen', 'email' => 'a.jansen@example.com'],
			[],
			[],
			'',
			'2026-10-21T09:48:12+00:00',
			'nonce-2',
			'pipelinq:campaign:camp-1'
		);
		$listener->handle($second);

		$this->assertTrue($first->isHandled());
		$this->assertTrue($second->isHandled());
	}//end testADuplicateOrARegistrationlessOutcomeIsNotMailed()
}//end class
