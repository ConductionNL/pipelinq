<?php

/**
 * Unit tests for ZrcClient writing a refreshed ETag back.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Zgw;

use OCA\Pipelinq\Service\Zgw\ZgwApiClient;
use OCA\Pipelinq\Service\Zgw\ZgwRegisterAccess;
use OCA\Pipelinq\Service\Zgw\ZrcClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * ZrcClient::getZaak with a mapping whose identity is present but blank:
 * the fresh ETag is not written to a mapping it cannot name.
 *
 * @covers \OCA\Pipelinq\Service\Zgw\ZrcClient
 *
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-002
 */
class ZrcClientEtagEdgeTest extends TestCase {
	private const ZAAK_URL = 'https://zrc.example/zaken/api/v1/zaken/z-1';

	/**
	 * A blank @self uuid with no id, or a blank id, saves nothing; the body is still returned.
	 *
	 * @return void
	 */
	public function testABlankMappingIdentitySavesNothing(): void {
		$endpoint  = ['id' => 'ep', 'clientId' => 'c', 'componenten' => ['zrc' => 'https://zrc.example']];
		$api       = $this->createMock(ZgwApiClient::class);
		$registers = $this->createMock(ZgwRegisterAccess::class);
		$registers->method('findClientForEndpoint')->willReturn(['clientIdentifier' => 'c']);
		$api->expects(self::exactly(2))
			->method('callComponent')
			->willReturn(['status' => 200, 'headers' => ['etag' => 'W/"3"'], 'body' => ['url' => self::ZAAK_URL]]);
		$registers->expects(self::never())->method('save');

		$zrc = new ZrcClient($api, $registers, $this->createMock(LoggerInterface::class));
		self::assertSame(['url' => self::ZAAK_URL], $zrc->getZaak($endpoint, ['@self' => ['uuid' => ''], 'zgwUrl' => self::ZAAK_URL]));
		self::assertSame(['url' => self::ZAAK_URL], $zrc->getZaak($endpoint, ['id' => '', 'zgwUrl' => self::ZAAK_URL]));
	}//end testABlankMappingIdentitySavesNothing()
}//end class
