<?php

/**
 * Unit tests for ActivityObject and ActivitySubject.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ActivityObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Who an activity is addressed to.
 */
#[CoversClass(ActivityObject::class)]
class ActivityObjectTest extends TestCase {

	/**
	 * The affected user wins over the author.
	 *
	 * @return void
	 */
	public function testTheAffectedUserIsTheRecipient(): void {
		$object = new ActivityObject(objectType: 'lead', objectId: '7', affectedUser: 'jan');

		$this->assertSame(expected: 'jan', actual: $object->recipient(author: 'anouk'));
	}//end testTheAffectedUserIsTheRecipient()

	/**
	 * Without an affected user (null or empty) the author is the recipient, possibly nobody.
	 *
	 * @return void
	 */
	public function testWithoutAnAffectedUserTheAuthorIs(): void {
		$this->assertSame(expected: 'anouk', actual: (new ActivityObject(objectType: 'lead', objectId: '7'))->recipient(author: 'anouk'));
		$this->assertSame(expected: 'anouk', actual: (new ActivityObject(objectType: 'lead', objectId: '7', affectedUser: ''))->recipient(author: 'anouk'));
		$this->assertSame(expected: '', actual: (new ActivityObject(objectType: 'lead', objectId: '7'))->recipient(author: ''));
	}//end testWithoutAnAffectedUserTheAuthorIs()
}//end class
