<?php

/**
 * Test fake for the website form suite: OpenRegister FormDestinationValidator::validate and SchemaMapper::find.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes;

use RuntimeException;

/**
 * Records validate() calls and returns the findings set per schema slug; also resolves schemas by slug.
 */
class FakeValidator {

	/**
	 * Every validate() call.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $calls = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $findings Schema slug to findings.
	 * @param array<int, string>                              $schemas  Slugs find() resolves.
	 */
	public function __construct(
		public array $findings = [],
		public array $schemas = ['ticket', 'lead', 'contact'],
	) {
	}//end __construct()

	/**
	 * The findings for a mapping into a schema.
	 *
	 * @param array<string, mixed> $mapping The mapping.
	 * @param object               $schema  The schema.
	 * @param array<string, mixed> $options The options.
	 *
	 * @return array<int, array<string, mixed>> The findings.
	 */
	public function validate(array $mapping, object $schema, array $options = []): array {
		$this->calls[] = ['mapping' => $mapping, 'schema' => $schema->slug, 'options' => $options];

		return ($this->findings[$schema->slug] ?? []);
	}//end validate()

	/**
	 * A schema by slug, or a throw as SchemaMapper does for an unknown one.
	 *
	 * @param string    $reference     The slug.
	 * @param bool|null $_multitenancy Multitenancy flag.
	 *
	 * @return object The schema.
	 */
	public function find(string $reference, ?bool $_multitenancy = null): object {
		if (in_array($reference, $this->schemas, true) === false) {
			throw new RuntimeException('Schema not found');
		}

		return (object)['slug' => $reference];
	}//end find()
}//end class
