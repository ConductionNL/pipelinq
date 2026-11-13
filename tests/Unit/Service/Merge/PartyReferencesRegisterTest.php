<?php

/**
 * Guards PartyReferences against the register: every property in pipelinq's
 * register files that references a client or a contact must be on the list a
 * merge moves, or named as no reference with a reason, and every listed one must
 * still exist.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Merge
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Merge;

use OCA\Pipelinq\Service\Merge\PartyReferences;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Service\Merge\PartyReferences
 */
class PartyReferencesRegisterTest extends TestCase {

	/**
	 * The listed references, by party type.
	 */
	private const LISTS = [
		'client' => PartyReferences::CLIENT,
		'contact' => PartyReferences::CONTACT,
	];

	/**
	 * Property names that reference a party, by party type.
	 */
	private const NAMES = [
		'client' => ['client', 'clientId', 'clientRef'],
		'contact' => ['contact', 'contactId', 'contactRef'],
	];

	/**
	 * The schemas of every register file, keyed `schema.property`, with the property definition.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function registerProperties(): array {
		$dir = dirname(__DIR__, 4) . '/lib/Settings';
		$files = array_merge([$dir . '/pipelinq_register.json'], glob($dir . '/register.d/*.json') ?: []);
		$properties = [];
		foreach ($files as $file) {
			$register = json_decode((string)file_get_contents($file), true);
			$properties += self::propertiesOf(register: is_array($register) === true ? $register : []);
		}

		return $properties;
	}//end registerProperties()

	/**
	 * The properties of one register document, keyed `schema.property`.
	 *
	 * @param array<string, mixed> $register The register document.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function propertiesOf(array $register): array {
		$properties = [];
		foreach (($register['components']['schemas'] ?? []) as $schema => $definition) {
			foreach (($definition['properties'] ?? []) as $name => $property) {
				$properties[$schema . '.' . $name] = is_array($property) === true ? $property : [];
			}
		}

		return $properties;
	}//end propertiesOf()

	/**
	 * The party references among the properties that the list neither moves nor excuses.
	 *
	 * @param array<string, array<string, mixed>> $properties The properties, keyed `schema.property`.
	 *
	 * @return list<string>
	 */
	private static function unlisted(array $properties): array {
		$missing = [];
		foreach ($properties as $key => $property) {
			[$schema, $name] = explode('.', $key, 2);
			foreach (self::NAMES as $type => $names) {
				$isReference = in_array($name, $names, true) || ($property['$ref'] ?? null) === $type;
				if ($isReference === false || $schema === $type) {
					continue;
				}

				$listed = in_array([$schema, $name], self::LISTS[$type], true);
				if ($listed === false && array_key_exists($key, PartyReferences::NOT_REFERENCES) === false) {
					$missing[] = $key;
				}
			}
		}

		sort($missing);
		return $missing;
	}//end unlisted()

	/**
	 * The real register holds no client or contact reference the merge would leave behind.
	 *
	 * @return void
	 */
	public function testEveryPartyReferenceInTheRegisterIsListed(): void {
		self::assertSame([], self::unlisted(properties: self::registerProperties()), 'Add the property to PartyReferences, or to NOT_REFERENCES with a reason.');
	}//end testEveryPartyReferenceInTheRegisterIsListed()

	/**
	 * A register file that adds `invoice.client` is caught, by name and by `$ref`.
	 *
	 * @return void
	 */
	public function testANewReferenceIsCaught(): void {
		$fixture = ['components' => ['schemas' => ['invoice' => ['properties' => [
			'client' => ['type' => 'string', 'format' => 'uuid'],
			'billTo' => ['type' => 'string', 'format' => 'uuid', '$ref' => 'contact'],
			'clientNote' => ['type' => 'string'],
		]]]]];

		self::assertSame(['invoice.billTo', 'invoice.client'], self::unlisted(properties: self::registerProperties() + self::propertiesOf(register: $fixture)));
	}//end testANewReferenceIsCaught()

	/**
	 * Every listed and every excused property still exists in the register.
	 *
	 * @return void
	 */
	public function testNoListedReferenceIsStale(): void {
		$properties = self::registerProperties();
		$listed = array_merge(PartyReferences::CLIENT, PartyReferences::CONTACT);
		foreach ($listed as [$schema, $name]) {
			self::assertArrayHasKey($schema . '.' . $name, $properties);
		}

		foreach (array_keys(PartyReferences::NOT_REFERENCES) as $key) {
			self::assertArrayHasKey($key, $properties);
			self::assertNotSame('', trim(PartyReferences::NOT_REFERENCES[$key]));
		}

	}//end testNoListedReferenceIsStale()
}//end class
