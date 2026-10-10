<?php

/**
 * The contactMomentDraft schema and the draft the quick log writes.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.pipelinq.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/contact-moments-keep-draft/specs/contact-moment-drafts/spec.md#requirement-a-manual-contact-moment-is-kept-as-a-private-draft-req-cmd2-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * `contactMomentDraft` in register.d/98-contact-moment-draft.json.
 *
 * @coversNothing
 */
class ContactMomentDraftSchemaTest extends TestCase {

	/**
	 * The draft schema, decoded from the shipped fragment.
	 *
	 * @return array<string, mixed>
	 */
	private function fragment(): array {
		$file = dirname(__DIR__, 3) . '/lib/Settings/register.d/98-contact-moment-draft.json';
		$fragment = json_decode((string)file_get_contents($file), true);
		$this->assertIsArray($fragment, 'the fragment must be valid JSON');
		return $fragment;
	}//end fragment()

	/**
	 * The register carries the schema, private by default.
	 *
	 * @return void
	 */
	public function testTheDraftIsPrivateToItsAuthor(): void {
		$fragment = $this->fragment();
		$this->assertContains('contactMomentDraft', $fragment['components']['registers']['pipelinq']['schemas']);

		$schema = $fragment['components']['schemas']['contactMomentDraft'];
		$this->assertSame('contactMomentDraft', $schema['slug']);
		$this->assertSame('private', $schema['authorization']['scope']);
		$this->assertSame(['author', 'client', 'request', 'form', 'updatedAt'], array_keys($schema['properties']));
		$this->assertSame(['author', 'form', 'updatedAt'], $schema['required']);
	}//end testTheDraftIsPrivateToItsAuthor()

	/**
	 * No archival block: OpenRegister refuses every user delete on a schema that
	 * declares one, and the quick log deletes the draft on save and on Discard.
	 *
	 * @return void
	 */
	public function testTheDraftCanBeDeletedByItsAuthor(): void {
		$schema = $this->fragment()['components']['schemas']['contactMomentDraft'];
		$this->assertArrayNotHasKey('x-openregister-archival', ($schema['configuration'] ?? []));
		$this->assertArrayNotHasKey('x-openregister-archival', $schema);
	}//end testTheDraftCanBeDeletedByItsAuthor()

	/**
	 * The exact draft buildDraftPayload() writes for a client page validates.
	 *
	 * @return void
	 */
	public function testTheQuickLogDraftValidates(): void {
		$payload = [
			'author' => 'sanne',
			'form' => [
				'title' => 'Adreswijziging',
				'channel' => 'telefoon',
				'client' => 'c-1',
				'notes' => 'Belt morgen terug',
			],
			'updatedAt' => '2026-10-09T10:00:00.000Z',
			'client' => 'c-1',
		];

		$this->assertTrue($this->validate(payload: $payload)->isValid());
	}//end testTheQuickLogDraftValidates()

	/**
	 * Control: a null reference is refused, which is why the quick log leaves
	 * an empty client or request out instead of writing null.
	 *
	 * @return void
	 */
	public function testANullReferenceIsRefused(): void {
		$payload = [
			'author' => 'sanne',
			'form' => ['title' => 'Adreswijziging'],
			'updatedAt' => '2026-10-09T10:00:00.000Z',
			'request' => null,
		];

		$this->assertFalse($this->validate(payload: $payload)->isValid());
	}//end testANullReferenceIsRefused()

	/**
	 * Validate a payload against the standard keywords of the schema.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return ValidationResult
	 */
	private function validate(array $payload): ValidationResult {
		$schema = $this->fragment()['components']['schemas']['contactMomentDraft'];
		$properties = [];
		foreach (array_keys($payload) as $key) {
			$this->assertArrayHasKey($key, $schema['properties'], "'{$key}' must be declared or storage drops it");
			$properties[$key] = array_intersect_key($schema['properties'][$key], array_flip(['type', 'enum', 'items', 'maxLength', 'format']));
		}

		$jsonSchema = json_decode((string)json_encode(['type' => 'object', 'required' => $schema['required'], 'properties' => $properties]));
		return (new Validator())->validate(json_decode((string)json_encode($payload)), $jsonSchema);
	}//end validate()
}//end class
