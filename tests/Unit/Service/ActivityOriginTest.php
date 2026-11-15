<?php

/**
 * Unit tests for ActivityOrigin.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ActivityOrigin;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ActivityOrigin.
 */
class ActivityOriginTest extends TestCase {

	/**
	 * The origin keeps the entity type and id it was given.
	 *
	 * @return void
	 */
	public function testKeepsTypeAndId(): void {
		$origin = new ActivityOrigin(entityType: 'client', entityId: 'c-1');

		$this->assertSame(expected: 'client', actual: $origin->entityType);
		$this->assertSame(expected: 'c-1', actual: $origin->entityId);
	}//end testKeepsTypeAndId()
}//end class
