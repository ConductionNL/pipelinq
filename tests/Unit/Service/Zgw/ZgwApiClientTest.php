<?php

/**
 * Unit tests for ZgwApiClient.
 *
 * Covers JWT minting (REQ-ZGW-001) and the clock-skew classification
 * heuristic used by `translateTransportError()` — the two non-trivial
 * pieces of behaviour that don't require live HTTP. Full HTTP-flow tests
 * for ZRC / DRC / BRC live alongside their respective client test files
 * and stub `IClientService::newClient()` via PHPUnit mocks.
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
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Zgw;

use OCA\Pipelinq\Service\Zgw\ZgwApiClient;
use OCA\Pipelinq\Service\Zgw\ZgwException;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ZgwApiClient JWT + skew handling.
 *
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-001
 */
class ZgwApiClientTest extends TestCase {
	private ZgwApiClient $client;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$clientService = $this->createMock(IClientService::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$logger = $this->createMock(LoggerInterface::class);

		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				// Resolve "vault://zgw/zoetermeer/client-secret" → app-config lookup
				if ($key === 'zgw.vault.zgw/zoetermeer/client-secret') {
					return 's3cret-zoetermeer';
				}
				return $default;
			}
		);
		$appConfig->method('getValueInt')->willReturnArgument(2);

		$this->client = new ZgwApiClient($clientService, $appConfig, $logger);
	}//end setUp()

	/**
	 * JWT minted with required claims and verifiable signature.
	 *
	 * @return void
	 */
	public function testMintJwtCarriesAllRequiredClaims(): void {
		$zgwClient = [
			'clientIdentifier' => 'pipelinq-zoetermeer',
			'secretVaultRef' => 'vault://zgw/zoetermeer/client-secret',
			'userId' => 'pipelinq',
			'userRepresentation' => 'Pipelinq backend (Conduction)',
		];

		$jwt = $this->client->mintJwt($zgwClient, 3600);
		$decoded = ZgwApiClient::inspectJwt($jwt);

		self::assertNotNull($decoded);
		self::assertSame('HS256', $decoded['header']['alg']);
		self::assertSame('pipelinq-zoetermeer', $decoded['payload']['iss']);
		self::assertSame('pipelinq-zoetermeer', $decoded['payload']['client_id']);
		self::assertSame('pipelinq', $decoded['payload']['user_id']);
		self::assertSame('Pipelinq backend (Conduction)', $decoded['payload']['user_representation']);
		self::assertIsInt($decoded['payload']['iat']);
		self::assertIsInt($decoded['payload']['exp']);
		self::assertGreaterThan($decoded['payload']['iat'], $decoded['payload']['exp']);
		self::assertLessThanOrEqual(60, abs($decoded['payload']['iat'] - time()));

		// Signature verifies with the resolved secret.
		self::assertTrue(ZgwApiClient::verifyJwt($jwt, 's3cret-zoetermeer'));
		self::assertFalse(ZgwApiClient::verifyJwt($jwt, 'wrong-secret'));
	}//end testMintJwtCarriesAllRequiredClaims()

	/**
	 * mintJwt raises ZgwException when the vault reference doesn't resolve.
	 *
	 * @return void
	 */
	public function testMintJwtRaisesWhenSecretMissing(): void {
		$this->expectException(ZgwException::class);
		$this->client->mintJwt([
			'clientIdentifier' => 'pipelinq-missing',
			'secretVaultRef' => 'vault://zgw/nowhere/client-secret',
			'userId' => 'pipelinq',
			'userRepresentation' => 'Pipelinq backend',
		]);
	}//end testMintJwtRaisesWhenSecretMissing()

	/**
	 * Clock-skew heuristic catches the VNG fault strings.
	 *
	 * @return void
	 */
	public function testLooksLikeClockSkewMatchesVngFaultStrings(): void {
		self::assertTrue(ZgwApiClient::looksLikeClockSkew('JWT verlopen'));
		self::assertTrue(ZgwApiClient::looksLikeClockSkew('jwt nog niet geldig'));
		self::assertTrue(ZgwApiClient::looksLikeClockSkew('{"code":"jwt-invalid","title":"JWT verlopen"}'));
		self::assertFalse(ZgwApiClient::looksLikeClockSkew('jwt fine'));
		self::assertFalse(ZgwApiClient::looksLikeClockSkew(''));
	}//end testLooksLikeClockSkewMatchesVngFaultStrings()

	/**
	 * Vault-URI resolver falls back to the literal value when not a vault://.
	 *
	 * @return void
	 */
	public function testResolveClientSecretFallsBackToLiteral(): void {
		self::assertSame('literal-secret', $this->client->resolveClientSecret('literal-secret'));
		self::assertSame('', $this->client->resolveClientSecret(''));
		self::assertSame('s3cret-zoetermeer', $this->client->resolveClientSecret('vault://zgw/zoetermeer/client-secret'));
	}//end testResolveClientSecretFallsBackToLiteral()

	/**
	 * Exp claim respects the requested lifetime (clamped to ≥ 60s).
	 *
	 * @return void
	 */
	public function testMintJwtClampsExpiryToSafeFloor(): void {
		$zgwClient = [
			'clientIdentifier' => 'pipelinq-zoetermeer',
			'secretVaultRef' => 'vault://zgw/zoetermeer/client-secret',
			'userId' => 'pipelinq',
			'userRepresentation' => 'Pipelinq backend',
		];
		$jwt = $this->client->mintJwt($zgwClient, 5);
		$decoded = ZgwApiClient::inspectJwt($jwt);
		self::assertNotNull($decoded);
		// 5s is below the 60s floor → mintJwt clamps to 60.
		self::assertGreaterThanOrEqual(60, ($decoded['payload']['exp'] - $decoded['payload']['iat']));
	}//end testMintJwtClampsExpiryToSafeFloor()

}//end class
