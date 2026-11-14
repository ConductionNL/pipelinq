<?php

/**
 * Unit tests for CampaignFormDestination, against the lead schema as it ships.
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

use OCA\Pipelinq\Service\Marketing\CampaignFormDestination;
use PHPUnit\Framework\TestCase;

/**
 * The declaration a campaign form carries, judged against the real lead schema.
 */
class CampaignFormDestinationTest extends TestCase {

	/**
	 * What the hosting page writes on every submit, when the destination declares a property for it.
	 *
	 * @var array<int, string>
	 */
	private const SERVER_VALUES = ['formId', 'pageId', 'pageRoute', 'portal', 'sourceApp', 'externalReference', 'campaign', 'submittedAt', 'nonce'];

	/**
	 * The lead schema as OpenRegister composes it: the base register, then each fragment in file order.
	 *
	 * @return array{properties: array<string, array<string, mixed>>, required: array<int, string>} The schema.
	 */
	private function leadSchema(): array {
		$root = dirname(__DIR__, 4) . '/lib/Settings';
		$files = array_merge([$root . '/pipelinq_register.json'], glob($root . '/register.d/*.json'));
		$properties = [];
		$required = [];
		foreach ($files as $file) {
			$data = json_decode((string)file_get_contents($file), true);
			$this->assertIsArray($data, $file . ' is not valid JSON');
			$lead = ($data['components']['schemas']['lead'] ?? null);
			if (is_array($lead) === false) {
				continue;
			}

			foreach (($lead['properties'] ?? []) as $name => $property) {
				$properties[$name] = array_replace_recursive(($properties[$name] ?? []), $property);
			}

			if (isset($lead['required']) === true) {
				$required = $lead['required'];
			}
		}

		$this->assertArrayHasKey('title', $properties, 'The base lead schema was not read.');

		return ['properties' => $properties, 'required' => $required];
	}//end leadSchema()

	/**
	 * A campaign's form submits into a lead, with the campaign fixed on it.
	 */
	public function testACampaignFormSubmitsIntoALead(): void {
		$destination = (new CampaignFormDestination())->forCampaign(campaignId: 'camp-1', campaign: ['name' => 'Webinar AI voor gemeenten']);

		$this->assertSame('pipelinq', $destination['register']);
		$this->assertSame('lead', $destination['schema']);
		$this->assertSame(['title' => 'Webinar AI voor gemeenten', 'source' => 'landing-page', 'campaignId' => 'camp-1'], $destination['fixed']);
		$this->assertSame('submitterEmail', $destination['fields']['email']);
	}//end testACampaignFormSubmitsIntoALead()

	/**
	 * A form whose campaign is unknown still gets a lead with a title; an empty campaign id is not written.
	 */
	public function testAnUnknownCampaignStillGivesAValidLead(): void {
		$destination = (new CampaignFormDestination())->forCampaign(campaignId: ' ', campaign: []);

		$this->assertSame(['title' => 'Landing page', 'source' => 'landing-page'], $destination['fixed']);
	}//end testAnUnknownCampaignStillGivesAValidLead()

	/**
	 * Every answer and every fixed value lands in a property the lead schema declares.
	 *
	 * OpenRegister's validator refuses a mapping onto an unknown property, and
	 * a refused mapping is a form nobody can submit.
	 */
	public function testEveryTargetIsAPropertyOfTheRealLeadSchema(): void {
		$schema = $this->leadSchema();
		$destination = (new CampaignFormDestination())->forCampaign(campaignId: '0b7d5a3e-1c2f-4a6b-9d8e-7f6a5b4c3d2e', campaign: ['name' => 'Webinar']);

		foreach ($destination['fields'] as $field => $property) {
			$this->assertArrayHasKey($property, $schema['properties'], 'Answer "' . $field . '" writes an unknown property.');
		}

		foreach (array_keys($destination['fixed']) as $property) {
			$this->assertArrayHasKey($property, $schema['properties'], 'Fixed value "' . $property . '" is not a lead property.');
		}

		// The page adds `referrer` to every form's answers under its own name.
		$this->assertArrayHasKey('referrer', $schema['properties']);
		$this->assertSame('email', $schema['properties']['submitterEmail']['format']);
		$this->assertSame('object', $schema['properties'][$destination['fields']['utmLastTouch']]['type']);
	}//end testEveryTargetIsAPropertyOfTheRealLeadSchema()

	/**
	 * Every required lead property is fixed by the form, has a default, or is filled by the server.
	 *
	 * A required property nobody fills is a 422 on every submit.
	 */
	public function testNoRequiredLeadPropertyIsLeftToTheVisitor(): void {
		$schema = $this->leadSchema();
		$destination = (new CampaignFormDestination())->forCampaign(campaignId: 'camp-1', campaign: ['name' => 'Webinar']);
		$this->assertNotSame([], $schema['required']);

		foreach ($schema['required'] as $name) {
			$property = $schema['properties'][$name];
			$filled = array_key_exists($name, $destination['fixed']) === true
				|| array_key_exists('default', $property) === true
				|| (($property['x-openregister']['serverSet'] ?? null) === true);
			$this->assertTrue($filled, 'Required lead property "' . $name . '" is filled by nobody.');
		}
	}//end testNoRequiredLeadPropertyIsLeftToTheVisitor()

	/**
	 * The lead declares the server values the intake needs, so the page writes them.
	 */
	public function testTheLeadDeclaresTheServerValuesTheIntakeReads(): void {
		$properties = $this->leadSchema()['properties'];

		foreach (['nonce', 'submittedAt', 'pageId'] as $name) {
			$this->assertContains($name, self::SERVER_VALUES, $name . ' is not a value the page writes.');
			$this->assertArrayHasKey($name, $properties);
		}

		// `campaign` is the page's own UTM block; the lead keeps its campaign in `campaignId`.
		$this->assertArrayNotHasKey('campaign', $properties);
	}//end testTheLeadDeclaresTheServerValuesTheIntakeReads()

	/**
	 * The reference round-trips, and anything else names no campaign.
	 */
	public function testTheReferenceNamesTheCampaign(): void {
		$destination = new CampaignFormDestination();

		$this->assertSame('pipelinq:campaign:camp-1', $destination->referenceFor(campaignId: 'camp-1'));
		$this->assertSame('camp-1', $destination->campaignIdOf(reference: ' pipelinq:campaign:camp-1 '));
		$this->assertSame('', $destination->campaignIdOf(reference: 'pipelinq:campaign:'));
		$this->assertSame('', $destination->campaignIdOf(reference: 'dossiq:case:1'));
		$this->assertSame('', $destination->campaignIdOf(reference: ''));
	}//end testTheReferenceNamesTheCampaign()
}//end class
