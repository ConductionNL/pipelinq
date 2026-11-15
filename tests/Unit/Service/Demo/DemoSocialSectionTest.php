<?php

/**
 * Unit tests for DemoSocialSection.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Demo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Demo;

use OCA\Pipelinq\Service\Demo\DemoSocialSection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A social seed part and its schema.
 */
#[CoversClass(DemoSocialSection::class)]
class DemoSocialSectionTest extends TestCase {

	/**
	 * The definitions and schema are kept as given.
	 *
	 * @return void
	 */
	public function testKeepsWhatItIsGiven(): void {
		$section = new DemoSocialSection(definitions: [['key' => 'p1']], schemaSlug: 'socialPost');

		$this->assertSame(expected: [[['key' => 'p1']], 'socialPost'], actual: [$section->definitions, $section->schemaSlug]);
	}//end testKeepsWhatItIsGiven()
}//end class
