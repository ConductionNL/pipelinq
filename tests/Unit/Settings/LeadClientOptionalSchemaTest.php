<?php

/**
 * A lead without a client against the lead schema as it ships.
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
 * @spec openspec/specs/lead-management/spec.md#requirement-lead-crud-mvp
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCP\App\IAppManager;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Decision 207 (12 October 2026): `lead.client` is optional.
 *
 * OpenRegister validates `required` on every save, so the schema decides
 * whether a lead without a client can exist at all. These tests read the lead
 * schema through the loader OpenRegister's import uses (the register file with
 * every `register.d` fragment merged onto it) and validate the payloads the app
 * really sends.
 *
 * @covers \OCA\Pipelinq\Service\ConfigFileLoaderService
 */
class LeadClientOptionalSchemaTest extends TestCase {

	/**
	 * The keywords of a property a standard JSON Schema validator understands.
	 *
	 * `$ref` is left out on purpose: in this register it names a schema slug,
	 * not a JSON pointer.
	 *
	 * @var array<int, string>
	 */
	private const KEYWORDS = ['type', 'enum', 'format', 'pattern', 'minimum', 'maximum', 'maxLength', 'items'];

	/**
	 * The lead schema as the import composes it.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function leadSchema(): array {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 3));

		$register = (new ConfigFileLoaderService($appManager))->loadConfigurationFile();
		$schema = ($register['components']['schemas']['lead'] ?? null);
		$this->assertIsArray($schema, 'The lead schema was not read.');
		$this->assertArrayHasKey('qualificationScore', $schema['properties'], 'The fragments were not merged onto the lead schema.');

		return $schema;
	}//end leadSchema()

	/**
	 * What the New lead form saves when no client is picked.
	 *
	 * These are the keys `LeadForm.onSave()` emits after it drops the empty
	 * ones: no `client`, no `contact`.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function formPayloadWithoutClient(): array {
		return [
			'title' => 'Website enquiry: 40 licences',
			'description' => 'Asked through the contact form, named no organisation.',
			'currency' => 'EUR',
			'priority' => 'normal',
			'pipeline' => '6f1d2c3b-4a5e-4f60-8a7b-9c0d1e2f3a4b',
			'stage' => 'New',
			'stageOrder' => 1,
			'stageEnteredAt' => '2026-10-12T09:30:00.000Z',
		];
	}//end formPayloadWithoutClient()

	/**
	 * Validate a payload against the standard keywords of the schema.
	 *
	 * @param array<string, mixed> $schema   The lead schema.
	 * @param array<string, mixed> $payload  The payload.
	 * @param array<int, string>   $required The required list to judge with.
	 *
	 * @return array{valid: bool, error: string} The verdict.
	 */
	private function judge(array $schema, array $payload, array $required): array {
		$properties = [];
		foreach (array_keys($payload) as $key) {
			$this->assertArrayHasKey($key, $schema['properties'], "'{$key}' must be declared or storage drops it");
			$properties[$key] = array_intersect_key($schema['properties'][$key], array_flip(self::KEYWORDS));
		}

		$jsonSchema = json_decode((string)json_encode(['type' => 'object', 'required' => $required, 'properties' => $properties]));
		$result = (new Validator())->validate(json_decode((string)json_encode($payload)), $jsonSchema);

		return ['valid' => $result->isValid(), 'error' => (string)json_encode($result->error()?->args())];
	}//end judge()

	/**
	 * The schema asks for a title and a pipeline, and no longer for a client.
	 *
	 * @return void
	 */
	public function testTheLeadSchemaRequiresATitleAndAPipelineOnly(): void {
		$schema = $this->leadSchema();

		$this->assertSame(['title', 'pipeline'], $schema['required']);
		$this->assertArrayHasKey('client', $schema['properties'], 'client stays a declared property: optional, not gone');
		$this->assertSame('client', $schema['properties']['client']['$ref']);
	}//end testTheLeadSchemaRequiresATitleAndAPipelineOnly()

	/**
	 * The lead the form saves without a client is valid.
	 *
	 * The control judges the same payload with the list as it was before
	 * decision 207. It must be refused, or this test could not fail.
	 *
	 * @return void
	 */
	public function testALeadWithoutAClientValidates(): void {
		$schema = $this->leadSchema();
		$payload = $this->formPayloadWithoutClient();

		$verdict = $this->judge(schema: $schema, payload: $payload, required: $schema['required']);
		$this->assertTrue($verdict['valid'], $verdict['error']);

		$before = $this->judge(schema: $schema, payload: $payload, required: ['title', 'pipeline', 'client']);
		$this->assertFalse($before['valid'], 'control: the list as it was must refuse a lead without a client');
		$this->assertStringContainsString('client', $before['error']);
	}//end testALeadWithoutAClientValidates()

	/**
	 * A lead with a client is as valid as it was.
	 *
	 * @return void
	 */
	public function testALeadWithAClientStillValidates(): void {
		$schema = $this->leadSchema();
		$payload = ($this->formPayloadWithoutClient() + ['client' => '0b7d5a3e-1c2f-4a6b-9d8e-7f6a5b4c3d2e']);

		$verdict = $this->judge(schema: $schema, payload: $payload, required: $schema['required']);
		$this->assertTrue($verdict['valid'], $verdict['error']);
	}//end testALeadWithAClientStillValidates()

	/**
	 * A pipeline is still required: only the client was loosened.
	 *
	 * @return void
	 */
	public function testALeadWithoutAPipelineIsStillRefused(): void {
		$schema = $this->leadSchema();
		$payload = $this->formPayloadWithoutClient();
		unset($payload['pipeline']);

		$verdict = $this->judge(schema: $schema, payload: $payload, required: $schema['required']);
		$this->assertFalse($verdict['valid']);
		$this->assertStringContainsString('pipeline', $verdict['error']);
	}//end testALeadWithoutAPipelineIsStillRefused()

	/**
	 * The loosened contract is announced: the schema and register versions moved.
	 *
	 * @return void
	 */
	public function testTheChangeIsVersioned(): void {
		$root = dirname(__DIR__, 3);
		$register = json_decode((string)file_get_contents($root . '/lib/Settings/pipelinq_register.json'), true);

		$this->assertTrue(version_compare($register['components']['schemas']['lead']['version'], '1.5.0', '>='), 'lead schema version');
		$this->assertTrue(version_compare($register['info']['version'], '1.10.0', '>='), 'register version');
		$this->assertStringContainsString('lead.client is optional', $register['info']['x-changelog']);
	}//end testTheChangeIsVersioned()
}//end class
