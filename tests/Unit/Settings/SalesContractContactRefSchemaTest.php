<?php

/**
 * The sales contract names the contact person it was made with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/contract-contact-ref/specs/pipelinq-contracts/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCP\App\IAppManager;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/contract-contact-ref/specs/pipelinq-contracts/spec.md
 */
class SalesContractContactRefSchemaTest extends TestCase {

	/**
	 * The salesContract schema as the import composes it.
	 *
	 * @return array<string, mixed>
	 */
	private function schema(): array {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 3));

		$register = (new ConfigFileLoaderService($appManager))->loadConfigurationFile();
		$schema   = ($register['components']['schemas']['salesContract'] ?? null);
		$this->assertIsArray($schema, 'The salesContract schema was not read.');

		return $schema;

	}//end schema()

	/**
	 * contactRef is an optional uuid reference to a contact.
	 *
	 * @return void
	 */
	public function testContactRefIsAnOptionalReferenceToAContact(): void {
		$schema = $this->schema();
		$ref    = ($schema['properties']['contactRef'] ?? null);

		$this->assertIsArray($ref);
		$this->assertSame('string', $ref['type']);
		$this->assertSame('uuid', $ref['format']);
		$this->assertSame('contact', $ref['$ref']);
		$this->assertNotContains('contactRef', ($schema['required'] ?? []));

	}//end testContactRefIsAnOptionalReferenceToAContact()

	/**
	 * The renewal lead outcome accepts null as well as its words.
	 *
	 * OpenRegister stores the declared default "" as null, so a contract
	 * saved without an outcome reads back with null. Any later validation
	 * of that contract (an edit in the app, an example-set change) refused
	 * it as "should be type 'string' but is 'null'".
	 *
	 * @return void
	 */
	public function testTheRenewalLeadOutcomeAcceptsNull(): void {
		$property = ($this->schema()['properties']['renewalLeadOutcome'] ?? null);
		$this->assertIsArray($property);

		$keywords = array_intersect_key($property, array_flip(['type', 'enum']));
		$schema   = json_decode((string) json_encode(['type' => 'object', 'properties' => ['renewalLeadOutcome' => $keywords]]));
		foreach ([null, '', 'won', 'lost'] as $value) {
			$valid = (new Validator())->validate(json_decode((string) json_encode(['renewalLeadOutcome' => $value])), $schema)->isValid();
			$this->assertTrue($valid, 'renewalLeadOutcome should accept '.var_export($value, true));
		}

		$invalid = (new Validator())->validate(json_decode((string) json_encode(['renewalLeadOutcome' => 'maybe'])), $schema)->isValid();
		$this->assertFalse($invalid);

	}//end testTheRenewalLeadOutcomeAcceptsNull()

	/**
	 * Adding the field bumps the schema to 1.1.0, so OpenRegister re-imports it.
	 *
	 * @return void
	 */
	public function testTheSchemaVersionIsBumped(): void {
		$this->assertSame('1.1.0', ($this->schema()['version'] ?? null));

	}//end testTheSchemaVersionIsBumped()
}//end class
