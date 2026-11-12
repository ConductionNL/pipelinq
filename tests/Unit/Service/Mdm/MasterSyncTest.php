<?php

/**
 * Unit tests for MasterSync.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Mdm
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Mdm;

use OCA\Pipelinq\Service\Mdm\MasterSync;
use PHPUnit\Framework\TestCase;

/**
 * Pins the entity type, the golden record and the master markers.
 *
 * @covers \OCA\Pipelinq\Service\Mdm\MasterSync
 */
class MasterSyncTest extends TestCase {

	/**
	 * A full master entity reads back its type, golden record and markers.
	 *
	 * @return void
	 */
	public function testReadsTheEntity(): void {
		$sync = new MasterSync('m-1', ['entityType' => 'organisation', 'goldenRecord' => ['name' => 'Acme']]);

		$this->assertSame('organisation', $sync->entityType());
		$this->assertSame(['name' => 'Acme'], $sync->golden());
		$this->assertSame(['masterEntityRef' => 'm-1', 'isMasterRecord' => true], $sync->markers());
	}//end testReadsTheEntity()

	/**
	 * A missing type is empty, and a missing or non-array golden record is empty.
	 *
	 * @return void
	 */
	public function testMissingOrMalformedFieldsAreEmpty(): void {
		$this->assertSame('', (new MasterSync('m', []))->entityType());
		$this->assertSame([], (new MasterSync('m', []))->golden());
		$this->assertSame([], (new MasterSync('m', ['goldenRecord' => 'Acme']))->golden());
		$this->assertSame('7', (new MasterSync('m', ['entityType' => 7]))->entityType());
	}//end testMissingOrMalformedFieldsAreEmpty()
}//end class
