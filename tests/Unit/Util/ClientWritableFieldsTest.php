<?php

/**
 * Behavioural tests for the ClientWritableFields trait.
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

namespace OCA\Pipelinq\Tests\Unit\Util;

use OCA\Pipelinq\Util\ClientWritableFields;
use PHPUnit\Framework\TestCase;

/**
 * Only the host's client-writable keys survive, null values included.
 *
 * @covers \OCA\Pipelinq\Util\ClientWritableFields
 */
class ClientWritableFieldsTest extends TestCase {

	/**
	 * @return void
	 */
	public function testClientFields(): void {
		$host = new class {
			use ClientWritableFields;

			private const CLIENT_WRITABLE = ['title', 'body'];

			/**
			 * @param array<string, mixed> $payload The payload.
			 * @return array<string, mixed>
			 */
			public function read(array $payload): array {
				return $this->clientFields(payload: $payload);
			}
		};

		$this->assertSame(['title' => 't', 'body' => null], $host->read(payload: ['id' => 'x', 'title' => 't', 'body' => null]));
	}//end testClientFields()
}//end class
