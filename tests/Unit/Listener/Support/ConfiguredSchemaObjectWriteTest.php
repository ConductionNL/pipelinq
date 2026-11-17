<?php

/**
 * Behavioural tests for the ConfiguredSchemaObjectWrite trait.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Util
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener\Support;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Saves by uuid against the configured register and the host's schema key.
 *
 * @covers \OCA\Pipelinq\Listener\Support\ConfiguredSchemaObjectWrite
 * @uses   \OCA\Pipelinq\Util\ContainerObjectSave
 */
class ConfiguredSchemaObjectWriteTest extends TestCase {

	/**
	 * @return void
	 */
	public function testPersist(): void {
		$config = ['register' => 'r', 'thing_schema' => 's'];
		$quiet = $this->createMock(LoggerInterface::class);
		$quiet->expects($this->never())->method('warning');

		$store = ConfiguredSchemaObjectReadTest::store();
		ConfiguredSchemaObjectReadTest::host($config, $store, $quiet, $this)->write('u1', ['a' => 1]);
		ConfiguredSchemaObjectReadTest::host($config, $store, $quiet, $this)->write('', ['a' => 2]);
		ConfiguredSchemaObjectReadTest::host(['thing_schema' => 's'], $store, $quiet, $this)->write('u3', ['a' => 3]);
		$this->assertSame([[['a' => 1], [], 'r', 's', 'u1']], $store->calls);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('save failed', ['exception' => 'down', 'uuid' => 'u1']);
		ConfiguredSchemaObjectReadTest::host($config, ConfiguredSchemaObjectReadTest::store(fail: true), $logger, $this)->write('u1', []);
	}//end testPersist()
}//end class
