<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The contact moment draft is the ticket itself (contact-moments-keep-draft,
 * decisions 180 and 216). Nothing here trusts a hand-written copy of the
 * schema: the register is loaded by the real ConfigFileLoaderService, which
 * is what the install imports, and the payloads are the exact ones the quick
 * log sends (tests/vitest/contactMomentDraft.spec.js asserts the same two).
 *
 * @category Tests
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @spec openspec/changes/contact-moments-keep-draft/specs/contact-moment-drafts/spec.md#requirement-a-manual-contact-moment-is-kept-as-a-private-draft-req-cmd2-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCP\App\IAppManager;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * The ticket schema carries the draft settings, and the quick log's payloads fit it.
 */
class ContactMomentDraftTicketTest extends TestCase {

	/**
	 * The ticket schema as the install imports it: the monolith with every fragment merged on top.
	 *
	 * @return array<string, mixed> The merged ticket schema.
	 */
	private function ticketSchema(): array {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 3));

		$register = (new ConfigFileLoaderService($appManager))->loadConfigurationFile();
		$ticket = ($register['components']['schemas']['ticket'] ?? null);
		$this->assertIsArray($ticket, 'the merged register must carry the ticket schema');

		return $ticket;
	}//end ticketSchema()

	/**
	 * Validate a payload against the merged ticket schema's own properties.
	 *
	 * @param array<string, mixed> $payload  The object as the quick log sends it, without `@self`.
	 * @param array<int, string>   $required The required list to apply (empty for a draft).
	 *
	 * @return ValidationResult The result.
	 */
	private function validate(array $payload, array $required): ValidationResult {
		$schema = $this->ticketSchema();
		$properties = [];
		foreach (array_keys($payload) as $key) {
			$this->assertArrayHasKey($key, $schema['properties'], "'{$key}' must be a ticket property or storage drops it");
			$properties[$key] = array_intersect_key(
				$schema['properties'][$key],
				array_flip(['type', 'enum', 'items', 'maxLength', 'minLength', 'format'])
			);
			// `format: user` and `format: uuid` references are OpenRegister's own; opis has no checker for `user`.
			if (($properties[$key]['format'] ?? '') === 'user') {
				unset($properties[$key]['format']);
			}
		}

		$jsonSchema = json_decode((string)json_encode(['type' => 'object', 'required' => $required, 'properties' => $properties]));

		return (new Validator())->validate(json_decode((string)json_encode((object)$payload)), $jsonSchema);
	}//end validate()

	/**
	 * The ticket schema sets the seven days of decision 145 and keeps the agent's own drafts out of its lists.
	 *
	 * @return void
	 */
	public function testTheTicketSchemaCarriesTheDraftSettings(): void {
		$configuration = $this->ticketSchema()['configuration'];

		$this->assertSame(7, $configuration['draftLifetimeDays'] ?? null);
		$this->assertFalse($configuration['draftsListed'] ?? null);

		// Control: the fragment added two keys and replaced nothing.
		$this->assertArrayHasKey('x-openregister-archival', $configuration);
		$this->assertArrayHasKey('x-openregister-lifecycle', $configuration);
		$this->assertArrayHasKey('x-openregister-mcp', $configuration);
	}//end testTheTicketSchemaCarriesTheDraftSettings()

	/**
	 * The fragment names its schema by slug; a fragment without one is rejected on import.
	 *
	 * @return void
	 */
	public function testTheFragmentCarriesItsSlug(): void {
		$file = dirname(__DIR__, 3) . '/lib/Settings/register.d/99-zzz-contact-moment-drafts.json';
		$fragment = json_decode((string)file_get_contents($file), true);

		$this->assertIsArray($fragment, 'the fragment must be valid JSON');
		$this->assertSame('ticket', $fragment['components']['schemas']['ticket']['slug']);
		$this->assertSame(['draftLifetimeDays', 'draftsListed'], array_keys($fragment['components']['schemas']['ticket']['configuration']));
	}//end testTheFragmentCarriesItsSlug()

	/**
	 * No draft schema exists: the draft is a ticket.
	 *
	 * @return void
	 */
	public function testThereIsNoSeparateDraftSchema(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 3));
		$register = (new ConfigFileLoaderService($appManager))->loadConfigurationFile();

		$this->assertArrayNotHasKey('contactMomentDraft', $register['components']['schemas']);
	}//end testThereIsNoSeparateDraftSchema()

	/**
	 * The draft the quick log autosaves is type-valid, though it misses the required title.
	 *
	 * @return void
	 */
	public function testTheAutosavedDraftFitsTheTicketSchema(): void {
		// The payload buildDraftTicket() produces, without `@self` (metadata, not a property).
		$draft = [
			'ticketType' => 'interaction',
			'client' => '0b9e2c9a-3c1f-4c55-9d5e-6f5a6f1d2b11',
			'notes' => 'Belt morgen terug',
		];

		// A draft is excused from `required` and from nothing else (decision 180).
		$this->assertTrue($this->validate(payload: $draft, required: [])->isValid());

		// Control: the same draft is not complete, which is why Save must run the full check.
		$this->assertFalse($this->validate(payload: $draft, required: $this->ticketSchema()['required'])->isValid());
	}//end testTheAutosavedDraftFitsTheTicketSchema()

	/**
	 * A draft is never type-invalid: a value outside the enum is refused even while it is a draft.
	 *
	 * @return void
	 */
	public function testADraftWithAWrongValueIsRefused(): void {
		$this->assertFalse($this->validate(payload: ['ticketType' => 'interaction', 'outcome' => 'shrugged'], required: [])->isValid());
		$this->assertFalse($this->validate(payload: ['ticketType' => 'contactmoment'], required: [])->isValid());
	}//end testADraftWithAWrongValueIsRefused()

	/**
	 * The contact moment the quick log sends to the owner action passes the whole ticket schema.
	 *
	 * @return void
	 */
	public function testTheSavedContactMomentPassesTheWholeTicketSchema(): void {
		// The payload buildContactMoment() produces with every field filled.
		$saved = [
			'ticketType' => 'interaction',
			'title' => 'Adreswijziging',
			'channel' => 'telefoon',
			'outcome' => 'handled',
			'client' => '0b9e2c9a-3c1f-4c55-9d5e-6f5a6f1d2b11',
			'contact' => '5c1f7a60-0b0e-4a43-8a5e-0d6c1b7f9a22',
			'parentTicket' => '9a7b6c5d-4e3f-4a2b-8c1d-0e9f8a7b6c5d',
			'description' => 'Verhuisd per 1 november',
			'duration' => 'PT5M',
			'notes' => 'Belt morgen terug',
			'occurredAt' => '2026-10-09T10:00:00.000Z',
			'assignee' => 'sanne',
		];
		$required = $this->ticketSchema()['required'];

		$this->assertSame(['ticketType', 'title'], $required);
		$this->assertTrue($this->validate(payload: $saved, required: $required)->isValid());

		// Control: without the subject the same payload is refused, as OpenRegister's owner action refuses it.
		unset($saved['title']);
		$this->assertFalse($this->validate(payload: $saved, required: $required)->isValid());
	}//end testTheSavedContactMomentPassesTheWholeTicketSchema()
}//end class
