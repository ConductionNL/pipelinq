<?php

/**
 * Unit tests for ObjectSelection.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\ObjectSelection;
use PHPUnit\Framework\TestCase;

/**
 * A selection scopes its filters to a register and its schema.
 *
 * @covers \OCA\Pipelinq\Service\Marketing\ObjectSelection
 */
class ObjectSelectionTest extends TestCase {

	/**
	 * The register and schema lead, followed by the filters as given.
	 *
	 * @return void
	 */
	public function testScopedPutsRegisterAndSchemaBeforeTheFilters(): void {
		$selection = new ObjectSelection(schemaSlug: 'blast', filters: ['status' => 'sent', 'channel' => 'email']);

		$this->assertSame(
			['register' => 'pipelinq', 'schema' => 'blast', 'status' => 'sent', 'channel' => 'email'],
			$selection->scoped(register: 'pipelinq')
		);
	}//end testScopedPutsRegisterAndSchemaBeforeTheFilters()

	/**
	 * No filters scope to the register and schema alone.
	 *
	 * @return void
	 */
	public function testScopedWithoutFilters(): void {
		$selection = new ObjectSelection(schemaSlug: 'contact', filters: []);

		$this->assertSame(['register' => 'crm', 'schema' => 'contact'], $selection->scoped(register: 'crm'));
	}//end testScopedWithoutFilters()

	/**
	 * A filter with the same key as the scope wins, as array_merge keeps the later value.
	 *
	 * @return void
	 */
	public function testAFilterNamedLikeTheScopeOverridesIt(): void {
		$selection = new ObjectSelection(schemaSlug: 'blast', filters: ['schema' => 'other']);

		$this->assertSame(['register' => 'r', 'schema' => 'other'], $selection->scoped(register: 'r'));
	}//end testAFilterNamedLikeTheScopeOverridesIt()
}//end class
