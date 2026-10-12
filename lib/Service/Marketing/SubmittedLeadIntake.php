<?php

/**
 * What a lead that arrived through a form still needs: its people and its touchpoint.
 *
 * Decision 179: a form submit creates the lead itself. The lead then carries
 * what the visitor typed (`submitterName`, `submitterEmail`,
 * `submitterOrganisation`) and what the page knew (`nonce`, `submittedAt`,
 * `pageId`, the touch blocks). This class turns that into the records sales
 * works with: the contact with that email address, matched or made; the
 * client with that organisation name, matched or made; and one `submit`
 * touchpoint.
 *
 * It works for any lead that carries those properties, whichever form made
 * it. It knows no campaign type and no page.
 *
 * The nonce is the page's own id for one submission. A lead whose nonce
 * already has a touchpoint is a redelivery, and gets neither a second
 * touchpoint nor new people.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

use OCA\Pipelinq\Service\TouchpointService;

/**
 * Contact, client and touchpoint for a lead a form submitted.
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
 */
class SubmittedLeadIntake {

	/**
	 * The contact schema slug.
	 *
	 * @var string
	 */
	public const CONTACT_SCHEMA_SLUG = 'contact';

	/**
	 * The client schema slug.
	 *
	 * @var string
	 */
	public const CLIENT_SCHEMA_SLUG = 'client';

	/**
	 * Constructor.
	 *
	 * @param ListObjectStore   $store       Register-scoped object access.
	 * @param TouchpointService $touchpoints The touchpoint log and its nonce guard.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
	 */
	public function __construct(
		private readonly ListObjectStore $store,
		private readonly TouchpointService $touchpoints,
	) {
	}//end __construct()

	/**
	 * The contact, client and title a new lead gets before it is stored.
	 *
	 * Nothing for a lead no form submitted (no submitter email), and nothing
	 * for a redelivered submission. A value the lead already has is kept.
	 *
	 * @param array<string, mixed> $lead The lead as it is about to be stored.
	 *
	 * @return array<string, string> The properties to set; empty when there is nothing to add.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
	 */
	public function partiesFor(array $lead): array {
		$email = strtolower(trim((string)($lead['submitterEmail'] ?? '')));
		if ($email === '' || $this->alreadyRecorded(lead: $lead) === true) {
			return [];
		}

		$patch = [];
		$name = trim((string)($lead['submitterName'] ?? ''));
		if (trim((string)($lead['contact'] ?? '')) === '') {
			$contactId = $this->matchOrCreateContact(
				email: $email,
				submitted: trim((string)$lead['submitterEmail']),
				name: $name,
				source: (string)($lead['source'] ?? '')
			);
			if ($contactId !== '') {
				$patch['contact'] = $contactId;
			}
		}

		$organisation = trim((string)($lead['submitterOrganisation'] ?? ''));
		if ($organisation !== '' && trim((string)($lead['client'] ?? '')) === '') {
			$clientId = $this->matchOrCreateClient(organisation: $organisation);
			if ($clientId !== '') {
				$patch['client'] = $clientId;
			}
		}

		return array_merge($patch, $this->titleFor(lead: $lead, name: $name));
	}//end partiesFor()

	/**
	 * The lead's title with the visitor's name, so two leads of one campaign can be told apart.
	 *
	 * @param array<string, mixed> $lead The lead.
	 * @param string               $name The submitted name, may be empty.
	 *
	 * @return array<string, string> `title`, or nothing when there is no name or the title already holds it.
	 */
	private function titleFor(array $lead, string $name): array {
		$title = trim((string)($lead['title'] ?? ''));
		if ($name === '' || str_contains($title, $name) === true) {
			return [];
		}

		return ['title' => ltrim($title . ': ' . $name, ': ')];
	}//end titleFor()

	/**
	 * Append the `submit` touchpoint of a stored lead, once per nonce.
	 *
	 * @param string               $leadId The stored lead's id.
	 * @param array<string, mixed> $lead   The stored lead.
	 *
	 * @return bool True when a touchpoint was written.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
	 */
	public function recordTouchpoint(string $leadId, array $lead): bool {
		$nonce = trim((string)($lead['nonce'] ?? ''));
		if ($leadId === '' || $nonce === '' || $this->touchpoints->existsForNonce(nonce: $nonce) === true) {
			return false;
		}

		$utm = $this->utmBlock(utm: ($lead['lastTouch'] ?? []));
		$saved = $this->touchpoints->append(
			touchpoint: [
				'campaignId' => trim((string)($lead['campaignId'] ?? '')),
				'contactId' => trim((string)($lead['contact'] ?? '')),
				'leadId' => $leadId,
				'kind' => 'submit',
				'channel' => $this->touchpoints->channelForMedium(medium: $utm['medium']),
				'utm' => $utm,
				'sourceRef' => trim((string)($lead['pageId'] ?? '')),
				'occurredAt' => trim((string)($lead['submittedAt'] ?? '')),
				'nonce' => $nonce,
			]
		);

		return ($saved !== null);
	}//end recordTouchpoint()

	/**
	 * Whether this submission was recorded before.
	 *
	 * @param array<string, mixed> $lead The lead.
	 *
	 * @return bool True when its nonce already has a touchpoint.
	 */
	private function alreadyRecorded(array $lead): bool {
		$nonce = trim((string)($lead['nonce'] ?? ''));

		return ($nonce !== '' && $this->touchpoints->existsForNonce(nonce: $nonce) === true);
	}//end alreadyRecorded()

	/**
	 * Find the contact with this email address, or create one.
	 *
	 * The address is matched lowercased and then as submitted, because
	 * OpenRegister's filter has no case-insensitive equality and a contact
	 * stored with capitals would otherwise be duplicated. A contact stored
	 * in a third casing is still missed; that is the price of a bounded
	 * query, and a duplicate contact is a smaller harm than a scan of every
	 * contact on the instance.
	 *
	 * @param string $email     The submitted address, lowercased.
	 * @param string $submitted The address as submitted.
	 * @param string $name      The submitted name, may be empty.
	 * @param string $source    The lead's origin, recorded on a new contact.
	 *
	 * @return string The contact id, or an empty string when the write failed.
	 */
	private function matchOrCreateContact(string $email, string $submitted, string $name, string $source): string {
		foreach (array_unique([$email, $submitted]) as $candidate) {
			$rows = $this->store->findAll(schemaSlug: self::CONTACT_SCHEMA_SLUG, filters: ['email' => $candidate]);
			if ($rows !== []) {
				return $this->store->idOf(payload: $rows[0]);
			}
		}

		if ($name === '') {
			$name = $email;
		}

		$saved = $this->store->save(
			schemaSlug: self::CONTACT_SCHEMA_SLUG,
			payload: [
				'name' => $name,
				'email' => $email,
				'source' => $source,
				'marketingConsent' => false,
			],
		);

		return $this->store->idOf(payload: $saved);
	}//end matchOrCreateContact()

	/**
	 * Find the client with this organisation name, or create one.
	 *
	 * Only called when the visitor named an organisation. Inventing a client
	 * from a personal address would put a record in the account list that
	 * nobody asked for.
	 *
	 * @param string $organisation The organisation the visitor named.
	 *
	 * @return string The client id, or an empty string when the write failed.
	 */
	private function matchOrCreateClient(string $organisation): string {
		$rows = $this->store->findAll(schemaSlug: self::CLIENT_SCHEMA_SLUG, filters: ['name' => $organisation]);
		if ($rows !== []) {
			return $this->store->idOf(payload: $rows[0]);
		}

		$saved = $this->store->save(
			schemaSlug: self::CLIENT_SCHEMA_SLUG,
			payload: ['name' => $organisation, 'type' => 'organization', 'notes' => 'Created from a form submission.'],
		);

		return $this->store->idOf(payload: $saved);
	}//end matchOrCreateClient()

	/**
	 * A touch block as the five keys the schemas declare.
	 *
	 * @param mixed $utm Whatever the lead holds.
	 *
	 * @return array{campaign: string, source: string, medium: string, term: string, content: string} The block, absent keys empty.
	 */
	private function utmBlock(mixed $utm): array {
		if (is_array($utm) === false) {
			$utm = [];
		}

		$block = [];
		foreach (['campaign', 'source', 'medium', 'term', 'content'] as $key) {
			$block[$key] = trim((string)($utm[$key] ?? ''));
		}

		return $block;
	}//end utmBlock()
}//end class
