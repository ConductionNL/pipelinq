<?php

/**
 * Unit tests for ZrcClient.
 *
 * Covers REQ-ZGW-010 (idempotent linkInitiator) and contact identification.
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
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-002
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-009
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-010
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Zgw;

use OCA\Pipelinq\Service\Zgw\ZgwApiClient;
use OCA\Pipelinq\Service\Zgw\ZgwRegisterAccess;
use OCA\Pipelinq\Service\Zgw\ZrcClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ZrcClient.
 *
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-002
 */
class ZrcClientTest extends TestCase {
	/**
	 * The ZGW endpoint payload used across tests.
	 *
	 * @var array<string, mixed>
	 */
	private array $endpoint;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->endpoint = [
			'id' => 'zgw-ep-zoetermeer-openzaak',
			'municipalityCode' => '0637',
			'clientId' => 'zgw-client-zoetermeer',
			'componenten' => [
				'zrc' => 'https://open-zaak.zoetermeer.nl/zaken/api/v1',
				'drc' => 'https://open-zaak.zoetermeer.nl/documenten/api/v1',
				'brc' => 'https://open-zaak.zoetermeer.nl/besluiten/api/v1',
				'ztc' => 'https://open-zaak.zoetermeer.nl/catalogi/api/v1',
				'ac' => 'https://open-zaak.zoetermeer.nl/autorisaties/api/v1',
				'nrc' => 'https://open-notificaties.zoetermeer.nl/api/v1',
			],
		];
	}//end setUp()

	// The three createZaak / updateZaak cases that stood here were removed with
	// the methods they called. `lib/Settings/register.d/80-zgw-api-bridge.json`
	// records that the ZGW write bridge is not wired in this app and that ZGW
	// writes are routed via the openconnector ZGW connector (ADR-085), so both
	// methods had zero callers. The optimistic-lock and scope-guard behaviours
	// they asserted belong with the client that actually issues the writes.

	/**
	 * linkInitiator: existing rol → skip POST (REQ-ZGW-010 idempotency).
	 *
	 * @return void
	 */
	public function testLinkInitiatorIdempotentSkipsPost(): void {
		$existingRoleUrl = 'https://open-zaak.zoetermeer.nl/zaken/api/v1/rollen/77ee44aa-1234-4d10-9b22-aabbccddeeff';
		$api = $this->createMock(ZgwApiClient::class);
		$api->expects(self::once())
			->method('callComponent')
			->willReturnCallback(
				static function (string $componentUrl, string $method, string $path, array $client): array {
					if ($method !== 'GET' || $path !== '/rollen') {
						throw new \RuntimeException('unexpected ' . $method . ' ' . $path);
					}
					return [
						'status' => 200,
						'headers' => [],
						'body' => [
							'results' => [[
								'url' => 'https://open-zaak.zoetermeer.nl/zaken/api/v1/rollen/77ee44aa-1234-4d10-9b22-aabbccddeeff',
								'betrokkeneIdentificatie' => ['inpBsn' => '123456789'],
							]],
						],
					];
				}
			);

		$registers = $this->createMock(ZgwRegisterAccess::class);
		$registers->method('findClientForEndpoint')->willReturn([
			'clientIdentifier' => 'pipelinq-zoetermeer',
			'secretVaultRef' => 'vault://x',
			'userId' => 'pipelinq',
			'userRepresentation' => 'Pipelinq',
		]);
		$client = new ZrcClient(
			api: $api,
			registers: $registers,
			logger: $this->createMock(LoggerInterface::class),
		);

		$url = $client->linkInitiator($this->endpoint,
			['zgwUrl' => 'https://open-zaak.zoetermeer.nl/zaken/api/v1/zaken/abc'],
			['bsn' => '123456789', 'name' => 'Jeroen van der Velde'],
			'https://ztc/roltype/initiator'
		);

		self::assertSame($existingRoleUrl, $url);
	}//end testLinkInitiatorIdempotentSkipsPost()

	/**
	 * contactIdentification picks the right betrokkeneType per identification.
	 *
	 * @return void
	 */
	public function testContactIdentificationPicksRightType(): void {
		[$type, $ident] = ZrcClient::contactIdentification(['bsn' => '123456789']);
		self::assertSame('natuurlijk_persoon', $type);
		self::assertSame(['inpBsn' => '123456789'], $ident);

		[$type, $ident] = ZrcClient::contactIdentification(['rsin' => '002564440']);
		self::assertSame('niet_natuurlijk_persoon', $type);
		self::assertSame(['innNnpId' => '002564440'], $ident);

		[$type, $ident] = ZrcClient::contactIdentification(['name' => 'Acme Stichting']);
		self::assertSame('niet_natuurlijk_persoon', $type);
		self::assertSame(['statutaireNaam' => 'Acme Stichting'], $ident);
	}//end testContactIdentificationPicksRightType()

}//end class
