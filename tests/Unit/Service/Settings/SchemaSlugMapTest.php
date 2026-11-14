<?php

/**
 * Unit tests for SchemaSlugMap: which schema slugs map to a config key.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Settings
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

namespace OCA\Pipelinq\Tests\Unit\Service\Settings;

use OCA\Pipelinq\Service\Settings\SchemaSlugMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The store schemas whose slug is set through a config key.
 */
#[CoversClass(SchemaSlugMap::class)]
class SchemaSlugMapTest extends TestCase {

	/**
	 * Every mapped slug answers its config key, which is the slug plus "_schema".
	 *
	 * @return void
	 */
	public function testEveryMappedSlugAnswersItsKey(): void {
		$this->assertCount(12, SchemaSlugMap::SLUG_TO_CONFIG_KEY);
		foreach (SchemaSlugMap::SLUG_TO_CONFIG_KEY as $slug => $key) {
			$this->assertSame($slug . '_schema', $key);
			$this->assertSame($key, SchemaSlugMap::configKeyFor($slug));
		}

		$this->assertSame('posTenderType_schema', SchemaSlugMap::configKeyFor('posTenderType'));
	}//end testEveryMappedSlugAnswersItsKey()

	/**
	 * An unmapped slug, a differently cased one or an empty one answers null.
	 *
	 * @return void
	 */
	public function testAnUnmappedSlugAnswersNull(): void {
		$this->assertNull(SchemaSlugMap::configKeyFor('lead'));
		$this->assertNull(SchemaSlugMap::configKeyFor('Product'));
		$this->assertNull(SchemaSlugMap::configKeyFor(''));
	}//end testAnUnmappedSlugAnswersNull()
}//end class
