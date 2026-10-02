<?php

/**
 * The ticket carries a question about a Woo dossier.
 *
 * A question asked from a resident's dossier (hydra woo-citizen-journey C4)
 * is a request ticket with two extra properties: `subjectReference`, the
 * snapshot of the dossier at the moment of asking, and `portalSubject`, the
 * portal subject reference of the resident. Magic-table storage drops an
 * undeclared property in silence, so a service that writes them against a
 * schema that does not declare them passes its own tests and loses the data
 * live. This suite validates the exact payload DossierQuestionService writes
 * against the shipped schema fragment.
 *
 * @category Tests
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.pipelinq.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * `ticket.subjectReference` and `ticket.portalSubject` in the shipped register.
 *
 * @coversNothing
 */
class DossierQuestionSchemaTest extends TestCase {

	/**
	 * The ticket payload DossierQuestionService writes for a question.
	 *
	 * @return array<string, mixed>
	 */
	public static function questionTicket(): array {
		return [
			'ticketType' => 'request',
			'title' => 'Windpark Noord',
			'description' => 'Wanneer valt het besluit over de vergunning?',
			'channel' => 'portal',
			'status' => 'new',
			'occurredAt' => '2026-09-30T10:00:00+00:00',
			'portalSubject' => 'subj-7f3a',
			'subjectReference' => [
				'type' => 'opencatalogi.collection',
				'id' => '5b0e8c55-1f7e-4a53-9a0e-2f1f0c1d9a11',
				'title' => 'Windpark Noord',
				'items' => [
					['title' => 'Besluit omgevingsvergunning', 'url' => 'https://gemeente.example/index.php/apps/opencatalogi/api/search/p-1'],
					['title' => 'Mijn eigen notitie', 'url' => ''],
				],
			],
		];
	}//end questionTicket()

	/**
	 * The ticket schema from the supertype fragment.
	 *
	 * @return array<string, mixed>
	 */
	private function ticket(): array {
		$path = dirname(__DIR__, 3) . '/lib/Settings/register.d/99-unify-ticket-supertype.json';
		$fragment = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($fragment, 'the fragment must be valid JSON');

		return ($fragment['components']['schemas']['ticket'] ?? []);
	}//end ticket()

	/**
	 * Both properties are declared, visible, and the snapshot holds the C4 shape.
	 *
	 * @return void
	 */
	public function testTheTicketDeclaresTheSnapshotAndTheResident(): void {
		$properties = $this->ticket()['properties'];

		$this->assertArrayHasKey('subjectReference', $properties);
		$this->assertArrayHasKey('portalSubject', $properties);
		$this->assertSame('object', $properties['subjectReference']['type']);
		$this->assertSame(['type', 'id', 'title', 'items'], array_keys($properties['subjectReference']['properties']));
		$this->assertSame(['title', 'url'], array_keys($properties['subjectReference']['properties']['items']['items']['properties']));
		$this->assertSame('string', $properties['portalSubject']['type']);
		$this->assertNotFalse($properties['subjectReference']['visible'] ?? true, 'the employee must see the snapshot');
	}//end testTheTicketDeclaresTheSnapshotAndTheResident()

	/**
	 * The exact payload the service writes validates against the fragment.
	 *
	 * @return void
	 */
	public function testTheQuestionPayloadValidatesAgainstTheSchema(): void {
		$schema = $this->ticket();
		$validator = new Validator();

		$properties = [];
		foreach (array_keys(self::questionTicket()) as $key) {
			$this->assertArrayHasKey($key, $schema['properties'], "'{$key}' must be declared or storage drops it");
			$properties[$key] = $this->plain(schema: $schema['properties'][$key]);
		}

		$jsonSchema = json_decode((string)json_encode(['type' => 'object', 'required' => $schema['required'], 'properties' => $properties]));
		$data = json_decode((string)json_encode(self::questionTicket()));
		$result = $validator->validate($data, $jsonSchema);

		$this->assertTrue($result->isValid(), (string)json_encode($result->error()?->args()));
	}//end testTheQuestionPayloadValidatesAgainstTheSchema()

	/**
	 * The snapshot's type is fixed to the dossier kind.
	 *
	 * @return void
	 */
	public function testTheSnapshotTypeIsTheDossierKind(): void {
		$type = $this->ticket()['properties']['subjectReference']['properties']['type'];

		$this->assertSame(['opencatalogi.collection'], $type['enum']);
	}//end testTheSnapshotTypeIsTheDossierKind()

	/**
	 * The schema and register versions moved with the change.
	 *
	 * @return void
	 */
	public function testTheVersionsMoved(): void {
		$this->assertSame('1.4.0', $this->ticket()['version']);

		$register = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/pipelinq_register.json'), true);
		$this->assertSame('1.9.0', $register['info']['version']);
		$this->assertStringContainsString('1.9.0:', $register['info']['x-changelog']);
	}//end testTheVersionsMoved()

	/**
	 * The status moves a dossier question makes are all allowed by the
	 * lifecycle. OpenRegister refuses any other write ("No transition allows
	 * moving status from new to converted"), and the Woo journey e2e found
	 * three: an employee converts a question that is still new or waiting for
	 * the resident, "Opslaan en wachten op een reactie" moves it to
	 * awaiting_customer, and the resident's reply moves it back to in_progress.
	 *
	 * @return void
	 */
	public function testTheLifecycleAllowsEveryMoveOfADossierQuestion(): void {
		$lifecycle = $this->ticket()['configuration']['x-openregister-lifecycle'];
		$allowed   = [];
		foreach ($lifecycle['transitions'] as $transition) {
			foreach ($transition['from'] as $from) {
				$allowed[] = $from . '>' . $transition['to'];
			}
		}

		foreach (['new>converted', 'in_progress>converted', 'awaiting_customer>converted', 'new>awaiting_customer', 'in_progress>awaiting_customer', 'awaiting_customer>in_progress'] as $move) {
			$this->assertContains($move, $allowed, $move . ' must be an allowed status move');
		}
	}//end testTheLifecycleAllowsEveryMoveOfADossierQuestion()

	/**
	 * A property schema with only the standard JSON Schema keywords.
	 *
	 * OpenRegister's own keywords (`facetable`, `x-*`, `visible`) mean nothing
	 * to a plain validator, so they are left out.
	 *
	 * @param array<string, mixed> $schema The property schema.
	 *
	 * @return array<string, mixed>
	 */
	private function plain(array $schema): array {
		$keep = ['type', 'enum', 'format', 'properties', 'items', 'required'];
		$plain = array_intersect_key($schema, array_flip($keep));
		if (isset($plain['format']) === true && in_array($plain['format'], ['date-time', 'uuid'], true) === false) {
			unset($plain['format']);
		}

		if (isset($plain['properties']) === true) {
			foreach ($plain['properties'] as $name => $property) {
				$plain['properties'][$name] = $this->plain(schema: $property);
			}
		}

		if (isset($plain['items']) === true && is_array($plain['items']) === true) {
			$plain['items'] = $this->plain(schema: $plain['items']);
		}

		return $plain;
	}//end plain()
}//end class
