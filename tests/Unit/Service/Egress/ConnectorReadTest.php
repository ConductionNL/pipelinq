<?php

/**
 * Unit tests for ConnectorRead.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Egress
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Egress;

use OCA\Pipelinq\Service\Egress\ConnectorRead;
use PHPUnit\Framework\TestCase;

/**
 * A read carries its source, endpoint, configuration and method unchanged.
 */
class ConnectorReadTest extends TestCase {

	/**
	 * Every value given is the value read back.
	 *
	 * @return void
	 */
	public function testCarriesItsValues(): void {
		$source = ['id' => 'src-1', 'location' => 'https://example.org'];
		$read = new ConnectorRead(source: $source, sourceId: 'src-1', endpoint: '/feed', config: ['query' => ['a' => 1]], method: 'GET');

		self::assertSame($source, $read->source);
		self::assertSame('src-1', $read->sourceId);
		self::assertSame('/feed', $read->endpoint);
		self::assertSame(['query' => ['a' => 1]], $read->config);
		self::assertSame('GET', $read->method);
	}//end testCarriesItsValues()
}//end class
