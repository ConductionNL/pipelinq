<?php

/**
 * Unit tests for ObjectEventTarget.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ObjectEventTarget;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ObjectEventTarget.
 */
class ObjectEventTargetTest extends TestCase {

	/**
	 * The fields are kept as given and the entity type defaults to empty.
	 *
	 * @return void
	 */
	public function testKeepsItsFields(): void {
		$target = new ObjectEventTarget(title: 'Deal', objectId: 'o-1', assignee: 'alice');

		$this->assertSame(expected: 'Deal', actual: $target->title);
		$this->assertSame(expected: 'o-1', actual: $target->objectId);
		$this->assertSame(expected: 'alice', actual: $target->assignee);
		$this->assertSame(expected: '', actual: $target->entityType);
	}//end testKeepsItsFields()

	/**
	 * The affected user is the assignee, or null without one.
	 *
	 * @return void
	 */
	public function testAffectedUser(): void {
		$this->assertSame(expected: 'alice', actual: (new ObjectEventTarget(title: 't', objectId: 'o', assignee: 'alice'))->affectedUser());
		$this->assertNull((new ObjectEventTarget(title: 't', objectId: 'o', assignee: '', entityType: 'lead'))->affectedUser());
	}//end testAffectedUser()
}//end class
