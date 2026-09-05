<?php

/**
 * Unit tests for LandingPageFormSubmittedListener.
 *
 * Covers:
 * - a first submission writes a contact, a lead and a submit touchpoint
 * - the lead carries firstTouch and lastTouch
 * - a redelivery with the same nonce writes nothing at all
 * - an existing contact is reused, whatever case the address arrives in
 * - an organisation creates or reuses a client; no organisation creates none
 * - a submission with no email still counts as a submission
 * - the event carries the lead id and is marked handled
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
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\Pipelinq\Event\LandingPageFormSubmittedEvent;
use OCA\Pipelinq\Listener\LandingPageFormSubmittedListener;
use OCA\Pipelinq\Service\CampaignLinkDecorator;
use OCA\Pipelinq\Service\CampaignService;
use OCA\Pipelinq\Service\TouchpointService;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../Support/InMemoryListObjectStore.php';

/**
 * Tests for LandingPageFormSubmittedListener.
 *
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
 */
class LandingPageFormSubmittedListenerTest extends TestCase {

	/**
	 * The seeded campaign.
	 *
	 * @var array<string, mixed>
	 */
	private const CAMPAIGN = [
		'uuid' => 'camp-1',
		'name' => 'Webinar AI voor gemeenten',
		'utmCampaign' => 'webinar-ai-voor-gemeenten',
		'formRef' => 'form-1',
	];

	/**
	 * The store every write lands in.
	 *
	 * @var InMemoryListObjectStore
	 */
	private InMemoryListObjectStore $store;

	/**
	 * Build a listener over an in-memory store.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $seed Rows to start from.
	 *
	 * @return LandingPageFormSubmittedListener
	 */
	private function build(array $seed = []): LandingPageFormSubmittedListener {
		$this->store = new InMemoryListObjectStore(array_merge(['campaign' => [self::CAMPAIGN]], $seed));

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnArgument(2);

		return new LandingPageFormSubmittedListener(
			$this->store,
			new CampaignService($this->store, $appConfig, new CampaignLinkDecorator($appConfig)),
			new TouchpointService($this->store),
			$this->createMock(LoggerInterface::class)
		);
	}//end build()

	/**
	 * One submission, in the exact positional shape Portaliq constructs.
	 *
	 * @param array<string, mixed> $values The submitted values.
	 * @param string $nonce The submission nonce.
	 * @param string $externalReference The reference Portaliq echoes back.
	 *
	 * @return LandingPageFormSubmittedEvent
	 */
	private function submission(
		array $values = ['name' => 'J. de Vries', 'email' => 'JANE.DOE@EXAMPLE.COM'],
		string $nonce = 'nonce-1',
		string $externalReference = 'pipelinq:campaign:camp-1',
	): LandingPageFormSubmittedEvent {
		return new LandingPageFormSubmittedEvent(
			'pipelinq',
			'form-1',
			'page-1',
			'/campagne/webinar-ai-voor-gemeenten',
			'open-tilburg',
			$externalReference,
			$values,
			['campaign' => 'webinar-ai-voor-gemeenten', 'source' => 'nieuwsbrief', 'medium' => 'email'],
			['campaign' => 'webinar-ai-voor-gemeenten', 'source' => 'linkedin', 'medium' => 'social'],
			'https://www.linkedin.com/',
			'2026-10-21T09:47:12+00:00',
			$nonce,
			$externalReference
		);
	}//end submission()

	/**
	 * @return void
	 */
	public function testCreatesTheContactLeadAndTouchpoint(): void {
		$listener = $this->build();
		$event = $this->submission();

		$listener->handle($event);

		$this->assertSame(1, $this->store->countOf(schemaSlug: 'contact'));
		$this->assertSame(1, $this->store->countOf(schemaSlug: 'lead'));
		$this->assertSame(1, $this->store->countOf(schemaSlug: 'touchpoint'));
		$this->assertTrue($event->isHandled());
		$this->assertNotNull($event->getLeadId());

		$touchpoint = array_values($this->store->rows['touchpoint'])[0];
		$this->assertSame('submit', $touchpoint['kind']);
		$this->assertSame('camp-1', $touchpoint['campaignId']);
		$this->assertSame('social', $touchpoint['channel']);
		$this->assertSame('nonce-1', $touchpoint['nonce']);
		$this->assertSame('2026-10-21T09:47:12+00:00', $touchpoint['occurredAt']);
		$this->assertSame($event->getLeadId(), $touchpoint['leadId']);
	}//end testCreatesTheContactLeadAndTouchpoint()

	/**
	 * @return void
	 */
	public function testWritesFirstAndLastTouchOnTheLead(): void {
		$listener = $this->build();
		$event = $this->submission();

		$listener->handle($event);

		$lead = $this->store->find(schemaSlug: 'lead', id: (string)$event->getLeadId());
		$this->assertSame('nieuwsbrief', $lead['firstTouch']['source']);
		$this->assertSame('email', $lead['firstTouch']['medium']);
		$this->assertSame('linkedin', $lead['lastTouch']['source']);
		$this->assertSame('social', $lead['lastTouch']['medium']);
		$this->assertSame('camp-1', $lead['campaignId']);
		$this->assertSame('landing-page', $lead['source']);
	}//end testWritesFirstAndLastTouchOnTheLead()

	/**
	 * @return void
	 */
	public function testARedeliveredSubmissionWritesNothing(): void {
		$listener = $this->build();
		$listener->handle($this->submission());

		$before = $this->store->writes;

		$second = $this->submission();
		$listener->handle($second);

		$this->assertSame($before, $this->store->writes);
		$this->assertSame(1, $this->store->countOf(schemaSlug: 'contact'));
		$this->assertSame(1, $this->store->countOf(schemaSlug: 'lead'));
		$this->assertSame(1, $this->store->countOf(schemaSlug: 'touchpoint'));
		$this->assertTrue($second->isHandled());
	}//end testARedeliveredSubmissionWritesNothing()

	/**
	 * @return void
	 */
	public function testMatchesAnExistingContactCaseInsensitively(): void {
		$listener = $this->build(
			seed: ['contact' => [['uuid' => 'contact-9', 'name' => 'Jane Doe', 'email' => 'jane.doe@example.com']]]
		);

		$event = $this->submission();
		$listener->handle($event);

		$this->assertSame(1, $this->store->countOf(schemaSlug: 'contact'));
		$lead = $this->store->find(schemaSlug: 'lead', id: (string)$event->getLeadId());
		$this->assertSame('contact-9', $lead['contact']);
	}//end testMatchesAnExistingContactCaseInsensitively()

	/**
	 * @return void
	 */
	public function testAnOrganisationCreatesTheClientAndASecondSubmissionReusesIt(): void {
		$listener = $this->build();

		$listener->handle($this->submission(
			values: ['name' => 'J. de Vries', 'email' => 'j@gemeente-voorbeeld.nl', 'organisation' => 'Gemeente Voorbeeld'],
			nonce: 'nonce-a'
		));
		$listener->handle($this->submission(
			values: ['name' => 'P. Jansen', 'email' => 'p@gemeente-voorbeeld.nl', 'organisation' => 'Gemeente Voorbeeld'],
			nonce: 'nonce-b'
		));

		$this->assertSame(1, $this->store->countOf(schemaSlug: 'client'));
		$this->assertSame(2, $this->store->countOf(schemaSlug: 'lead'));
	}//end testAnOrganisationCreatesTheClientAndASecondSubmissionReusesIt()

	/**
	 * @return void
	 */
	public function testNoOrganisationCreatesNoClient(): void {
		$listener = $this->build();

		$listener->handle($this->submission());

		$this->assertSame(0, $this->store->countOf(schemaSlug: 'client'));
	}//end testNoOrganisationCreatesNoClient()

	/**
	 * A submission with no usable address is still a submission: dropping
	 * it would make the campaign's submission count disagree with
	 * Portaliq's, and the count is what the report is read for.
	 *
	 * @return void
	 */
	public function testASubmissionWithoutAnEmailStillCounts(): void {
		$listener = $this->build();
		$event = $this->submission(values: ['name' => 'J. de Vries'], nonce: 'nonce-c');

		$listener->handle($event);

		$this->assertSame(1, $this->store->countOf(schemaSlug: 'touchpoint'));
		$this->assertSame(0, $this->store->countOf(schemaSlug: 'contact'));
		$this->assertSame(0, $this->store->countOf(schemaSlug: 'lead'));
		$this->assertNull($event->getLeadId());
		$this->assertTrue($event->isHandled());
	}//end testASubmissionWithoutAnEmailStillCounts()

	/**
	 * @return void
	 */
	public function testTheCampaignIsFoundByFormIdWhenTheReferenceIsGone(): void {
		$listener = $this->build();
		$event = $this->submission(nonce: 'nonce-d', externalReference: '');

		$listener->handle($event);

		$touchpoint = array_values($this->store->rows['touchpoint'])[0];
		$this->assertSame('camp-1', $touchpoint['campaignId']);
	}//end testTheCampaignIsFoundByFormIdWhenTheReferenceIsGone()

	/**
	 * @return void
	 */
	public function testAnUnrelatedEventIsIgnored(): void {
		$listener = $this->build();

		$listener->handle(new \OCP\EventDispatcher\Event());

		$this->assertSame([], $this->store->writes);
	}//end testAnUnrelatedEventIsIgnored()
}//end class
