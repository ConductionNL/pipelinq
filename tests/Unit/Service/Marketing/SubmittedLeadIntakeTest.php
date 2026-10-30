<?php

/**
 * Unit tests for SubmittedLeadIntake.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
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
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\SubmittedLeadIntake;
use OCA\Pipelinq\Service\TouchpointService;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Support/InMemoryListObjectStore.php';

/**
 * A lead a form submitted gets its contact, its client and one touchpoint.
 */
class SubmittedLeadIntakeTest extends TestCase {

	/**
	 * The lead a campaign form creates, as the hosting page writes it.
	 *
	 * @var array<string, mixed>
	 */
	private const LEAD = [
		'title' => 'Webinar AI voor gemeenten',
		'source' => 'landing-page',
		'campaignId' => 'camp-1',
		'submitterName' => 'Jane Doe',
		'submitterEmail' => 'JANE.DOE@EXAMPLE.COM',
		'submitterOrganisation' => 'Gemeente Tilburg',
		'firstTouch' => ['campaign' => 'webinar', 'source' => 'nieuwsbrief', 'medium' => 'email'],
		'lastTouch' => ['campaign' => 'webinar', 'source' => 'linkedin', 'medium' => 'social'],
		'pageId' => 'page-1',
		'submittedAt' => '2026-10-12T09:15:00+02:00',
		'nonce' => 'nonce-1',
	];

	private InMemoryListObjectStore $store;

	/**
	 * The intake over a store seeded with rows.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $seed Schema slug to rows.
	 */
	private function intake(array $seed = []): SubmittedLeadIntake {
		$this->store = new InMemoryListObjectStore($seed);

		return new SubmittedLeadIntake($this->store, new TouchpointService($this->store));
	}//end intake()

	/**
	 * Spec scenario: a first submission creates a contact and a client, and names the lead after the visitor.
	 */
	public function testAFirstSubmissionCreatesItsContactAndClient(): void {
		$patch = $this->intake()->partiesFor(lead: self::LEAD);

		$contact = $this->store->find(schemaSlug: 'contact', id: $patch['contact']);
		$this->assertSame('jane.doe@example.com', $contact['email']);
		$this->assertSame('Jane Doe', $contact['name']);
		$this->assertSame('landing-page', $contact['source']);
		$this->assertFalse($contact['marketingConsent'], 'Signing up is not consent to marketing.');
		$this->assertSame('Gemeente Tilburg', $this->store->find(schemaSlug: 'client', id: $patch['client'])['name']);
		$this->assertSame('Webinar AI voor gemeenten: Jane Doe', $patch['title']);
	}//end testAFirstSubmissionCreatesItsContactAndClient()

	/**
	 * Spec scenario: a known contact gets a lead, not a duplicate contact; the same for a known client.
	 */
	public function testAKnownContactAndClientAreReused(): void {
		$intake = $this->intake(
			seed: [
				'contact' => [['uuid' => 'contact-1', 'name' => 'Jane', 'email' => 'jane.doe@example.com']],
				'client' => [['uuid' => 'client-1', 'name' => 'Gemeente Tilburg']],
			]
		);

		$patch = $intake->partiesFor(lead: self::LEAD);

		$this->assertSame('contact-1', $patch['contact']);
		$this->assertSame('client-1', $patch['client']);
		$this->assertSame(1, $this->store->countOf(schemaSlug: 'contact'));
		$this->assertSame(1, $this->store->countOf(schemaSlug: 'client'));
	}//end testAKnownContactAndClientAreReused()

	/**
	 * No organisation, no client: nobody invents an account from a personal address.
	 */
	public function testWithoutAnOrganisationNoClientIsMade(): void {
		$lead = self::LEAD;
		unset($lead['submitterOrganisation']);

		$patch = $this->intake()->partiesFor(lead: $lead);

		$this->assertArrayHasKey('contact', $patch);
		$this->assertArrayNotHasKey('client', $patch);
		$this->assertSame(0, $this->store->countOf(schemaSlug: 'client'));
	}//end testWithoutAnOrganisationNoClientIsMade()

	/**
	 * A lead nobody submitted through a form, and values the lead already has, are left alone.
	 */
	public function testOtherLeadsAndGivenValuesAreLeftAlone(): void {
		$intake = $this->intake();
		$this->assertSame([], $intake->partiesFor(lead: ['title' => 'Typed by a colleague', 'client' => 'client-9']));
		$this->assertSame(0, $this->store->countOf(schemaSlug: 'contact'));

		$given = array_merge(self::LEAD, ['contact' => 'contact-9', 'client' => 'client-9', 'title' => 'Jane Doe wil een demo']);
		$this->assertSame([], $intake->partiesFor(lead: $given));
		$this->assertSame(0, $this->store->countOf(schemaSlug: 'contact'));
	}//end testOtherLeadsAndGivenValuesAreLeftAlone()

	/**
	 * Spec scenario: the stored lead gets one `submit` touchpoint with its campaign, channel, UTM, moment and nonce.
	 */
	public function testTheStoredLeadGetsItsSubmitTouchpoint(): void {
		$intake = $this->intake();

		$this->assertTrue($intake->recordTouchpoint(leadId: 'lead-1', lead: array_merge(self::LEAD, ['contact' => 'contact-1'])));

		$rows = $this->store->findAll(schemaSlug: 'touchpoint');
		$this->assertCount(1, $rows);
		$this->assertSame('submit', $rows[0]['kind']);
		$this->assertSame('camp-1', $rows[0]['campaignId']);
		$this->assertSame('lead-1', $rows[0]['leadId']);
		$this->assertSame('contact-1', $rows[0]['contactId']);
		$this->assertSame('page-1', $rows[0]['sourceRef']);
		$this->assertSame('2026-10-12T09:15:00+02:00', $rows[0]['occurredAt']);
		$this->assertSame('nonce-1', $rows[0]['nonce']);
		$this->assertSame(['campaign' => 'webinar', 'source' => 'linkedin', 'medium' => 'social', 'term' => '', 'content' => ''], $rows[0]['utm']);
		$this->assertSame((new TouchpointService($this->store))->channelForMedium(medium: 'social'), $rows[0]['channel']);
	}//end testTheStoredLeadGetsItsSubmitTouchpoint()

	/**
	 * Spec scenario: a redelivered submission creates nothing twice.
	 */
	public function testARedeliveredSubmissionCreatesNothingTwice(): void {
		$intake = $this->intake();
		$intake->recordTouchpoint(leadId: 'lead-1', lead: self::LEAD);

		$this->assertFalse($intake->recordTouchpoint(leadId: 'lead-2', lead: self::LEAD));
		$this->assertSame([], $intake->partiesFor(lead: self::LEAD));
		$this->assertSame(1, $this->store->countOf(schemaSlug: 'touchpoint'));
		$this->assertSame(0, $this->store->countOf(schemaSlug: 'contact'));
	}//end testARedeliveredSubmissionCreatesNothingTwice()

	/**
	 * A lead without a nonce was not submitted through a page, so it gets no touchpoint.
	 */
	public function testALeadWithoutANonceGetsNoTouchpoint(): void {
		$intake = $this->intake();

		$this->assertFalse($intake->recordTouchpoint(leadId: 'lead-1', lead: ['title' => 'Typed by a colleague', 'lastTouch' => 'not a block']));
		$this->assertFalse($intake->recordTouchpoint(leadId: '', lead: self::LEAD));
		$this->assertSame(0, $this->store->countOf(schemaSlug: 'touchpoint'));
	}//end testALeadWithoutANonceGetsNoTouchpoint()
	/**
	 * The stored lead's contact and client get the touch: the lead's own
	 * client, the form page, and the form's name without the visitor's.
	 *
	 * @spec openspec/changes/marketing-campaign-on-client/specs/marketing-origin-on-client/spec.md#requirement-contacts-and-clients-show-how-they-found-you-req-moc-001
	 *
	 * @return void
	 */
	public function testTheLeadsContactAndClientGetTheirTouches(): void {
		$intake = $this->intake(
			seed: [
				'contact' => [['uuid' => 'contact-1', 'name' => 'Jane', 'email' => 'jane.doe@example.com']],
				'client' => [['uuid' => 'client-1', 'name' => 'Gemeente Tilburg']],
			]
		);

		$lead = array_merge(self::LEAD, ['contact' => 'contact-1', 'client' => 'client-1', 'title' => 'Webinar AI voor gemeenten: Jane Doe']);
		$this->assertTrue($intake->recordTouchpoint(leadId: 'lead-1', lead: $lead));

		$touch = $this->store->find(schemaSlug: 'client', id: 'client-1')['lastTouch'];
		$this->assertSame('webinar', $touch['campaign']);
		$this->assertSame('linkedin', $touch['source']);
		$this->assertSame('page-1', $touch['landingPage']);
		$this->assertSame('Webinar AI voor gemeenten', $touch['form']);
		$this->assertSame('2026-10-12T09:15:00+02:00', $touch['at']);
		$this->assertSame($touch, $this->store->find(schemaSlug: 'contact', id: 'contact-1')['firstTouch']);
		$this->assertSame('client-1', $this->store->findAll(schemaSlug: 'touchpoint')[0]['clientId']);
	}//end testTheLeadsContactAndClientGetTheirTouches()
}//end class
