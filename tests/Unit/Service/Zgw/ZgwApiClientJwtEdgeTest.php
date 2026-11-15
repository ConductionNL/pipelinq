<?php

/**
 * Unit tests for ZgwApiClient's JWT inspection and method guards.
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
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Zgw;

use OCA\Pipelinq\Service\Zgw\ZgwApiClient;
use OCA\Pipelinq\Service\Zgw\ZgwException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * ZgwApiClient: inspectJwt and verifyJwt on malformed tokens, and a request
 * with an HTTP method the client does not speak.
 *
 * @covers \OCA\Pipelinq\Service\Zgw\ZgwApiClient
 * @uses   \OCA\Pipelinq\Service\Zgw\ZgwException
 *
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-001
 */
class ZgwApiClientJwtEdgeTest extends TestCase {

	/**
	 * A ZGW client whose secret resolves.
	 *
	 * @var array<string, mixed>
	 */
	private array $zgwClient = ['clientIdentifier' => 'pipelinq', 'secretVaultRef' => 'literal-secret'];

	/**
	 * Build the API client with the given HTTP client.
	 *
	 * @param IClient $http The HTTP client.
	 *
	 * @return ZgwApiClient
	 */
	private function api(IClient $http): ZgwApiClient {
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($http);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnArgument(2);
		$appConfig->method('getValueInt')->willReturnArgument(2);

		return new ZgwApiClient($service, $appConfig, $this->createMock(LoggerInterface::class));
	}//end api()

	/**
	 * Base64-url encode without padding.
	 *
	 * @param string $raw The bytes.
	 *
	 * @return string
	 */
	private static function b64(string $raw): string {
		return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
	}//end b64()

	/**
	 * A token without three segments is neither readable nor valid.
	 *
	 * @return void
	 */
	public function testATokenWithoutThreeSegmentsIsRefused(): void {
		self::assertNull(ZgwApiClient::inspectJwt('only.two'));
		self::assertNull(ZgwApiClient::inspectJwt('a.b.c.d'));
		self::assertFalse(ZgwApiClient::verifyJwt('only.two', 'secret'));
	}//end testATokenWithoutThreeSegmentsIsRefused()

	/**
	 * A header or payload that is not a JSON object reads as nothing.
	 *
	 * @return void
	 */
	public function testANonObjectHeaderOrPayloadReadsAsNothing(): void {
		$object = self::b64('{"alg":"HS256"}');
		self::assertNull(ZgwApiClient::inspectJwt(self::b64('"text"') . '.' . $object . '.sig'));
		self::assertNull(ZgwApiClient::inspectJwt($object . '.' . self::b64('not json') . '.sig'));
		self::assertNull(ZgwApiClient::inspectJwt('***.' . $object . '.sig'));
		self::assertSame(
			['header' => ['alg' => 'HS256'], 'payload' => ['sub' => 'x']],
			ZgwApiClient::inspectJwt($object . '.' . self::b64('{"sub":"x"}') . '.sig')
		);
	}//end testANonObjectHeaderOrPayloadReadsAsNothing()

	/**
	 * A signature that is not base64-url fails, a matching one passes.
	 *
	 * @return void
	 */
	public function testVerifyJwtChecksTheSignatureBytes(): void {
		$signing = self::b64('{"alg":"HS256"}') . '.' . self::b64('{"sub":"x"}');
		$good    = self::b64(hash_hmac('sha256', $signing, 'secret', true));

		self::assertTrue(ZgwApiClient::verifyJwt($signing . '.' . $good, 'secret'));
		self::assertFalse(ZgwApiClient::verifyJwt($signing . '.' . $good, 'other'));
		self::assertFalse(ZgwApiClient::verifyJwt($signing . '.!!!', 'secret'));
	}//end testVerifyJwtChecksTheSignatureBytes()

	/**
	 * A minted token verifies with its secret and carries the client.
	 *
	 * @return void
	 */
	public function testAMintedTokenRoundTrips(): void {
		$jwt  = $this->api($this->createMock(IClient::class))->mintJwt($this->zgwClient);
		$read = ZgwApiClient::inspectJwt($jwt);

		self::assertTrue(ZgwApiClient::verifyJwt($jwt, 'literal-secret'));
		self::assertSame('pipelinq', $read['payload']['client_id']);
		self::assertSame('HS256', $read['header']['alg']);
	}//end testAMintedTokenRoundTrips()

	/**
	 * A method the client does not speak is refused without sending anything.
	 *
	 * @return void
	 */
	public function testAnUnsupportedMethodIsRefusedWithoutARequest(): void {
		$http = $this->createMock(IClient::class);
		$http->expects(self::never())->method('get');
		$http->expects(self::never())->method('post');
		$http->expects(self::never())->method('put');
		$http->expects(self::never())->method('delete');
		$http->expects(self::never())->method('request');

		$this->expectException(ZgwException::class);
		$this->expectExceptionMessage('unsupported HTTP method "TRACE"');

		$this->api($http)->callComponent('https://zrc.example', 'TRACE', '/zaken', $this->zgwClient);
	}//end testAnUnsupportedMethodIsRefusedWithoutARequest()
}//end class
