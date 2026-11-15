<?php

/**
 * Unit tests for CompanyRegistryHttp.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\CompanyRegistryHttp;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * CompanyRegistryHttp fetches registry JSON and integration-leaf result rows.
 */
class CompanyRegistryHttpTest extends TestCase {

	/**
	 * The GET calls made, as [url, options].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $calls = [];

	/**
	 * A client answering every GET with the given status and body, or throwing.
	 *
	 * @param int         $status The status.
	 * @param string|null $body   The body, or null to throw.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return CompanyRegistryHttp
	 */
	private function http(int $status, ?string $body, ?LoggerInterface $logger=null): CompanyRegistryHttp {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn((string)$body);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $uri, array $options=[]) use ($response, $body): IResponse {
				$this->calls[] = [$uri, $options];
				if ($body === null) {
					throw new RuntimeException('connection refused');
				}

				return $response;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		return new CompanyRegistryHttp(clientService: $clients, logger: ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end http()

	/**
	 * JSON is decoded, asked for as JSON, with a 15-second timeout.
	 *
	 * @return void
	 */
	public function testGetJsonDecodesTheBody(): void {
		$json = $this->http(200, '{"kvkNummer":"12345678","naam":"Bakkerij"}')->getJson(url: 'https://kvk.example/api/12345678');

		self::assertSame(['kvkNummer' => '12345678', 'naam' => 'Bakkerij'], $json);
		self::assertSame('https://kvk.example/api/12345678', $this->calls[0][0]);
		self::assertSame(['headers' => ['Accept' => 'application/json'], 'timeout' => 15], $this->calls[0][1]);
	}//end testGetJsonDecodesTheBody()

	/**
	 * A body that is not a JSON object or list gives an empty array.
	 *
	 * @return void
	 */
	public function testGetJsonWithoutAnObjectIsEmpty(): void {
		self::assertSame([], $this->http(200, 'not json')->getJson(url: 'u'));
		self::assertSame([], $this->http(200, '"a string"')->getJson(url: 'u'));
	}//end testGetJsonWithoutAnObjectIsEmpty()

	/**
	 * Leaf rows are the array rows of `results`, sent as an OCS request that may reach a local address.
	 *
	 * @return void
	 */
	public function testLeafRows(): void {
		$rows = $this->http(200, '{"results":[{"id":"a"},"skip me",{"id":"b"}],"total":3}')->leafRows(url: 'http://localhost/leaf', unavailable: 'leaf down');

		self::assertSame([['id' => 'a'], ['id' => 'b']], $rows);
		self::assertSame('true', $this->calls[0][1]['headers']['OCS-APIREQUEST']);
		self::assertTrue($this->calls[0][1]['nextcloud']['allow_local_address']);
		self::assertSame(3, $this->calls[0][1]['connect_timeout']);
	}//end testLeafRows()

	/**
	 * A non-200 answer, a body without a results list, or a failed call give null.
	 *
	 * @return void
	 */
	public function testLeafRowsRefusals(): void {
		self::assertNull($this->http(404, '{"results":[]}')->leafRows(url: 'u', unavailable: 'x'));
		self::assertNull($this->http(200, '{"rows":[]}')->leafRows(url: 'u', unavailable: 'x'));
		self::assertNull($this->http(200, '{"results":"none"}')->leafRows(url: 'u', unavailable: 'x'));
		self::assertNull($this->http(200, '[1,2]')->leafRows(url: 'u', unavailable: 'x'));
		self::assertSame([], $this->http(200, '{"results":[]}')->leafRows(url: 'u', unavailable: 'x'));
	}//end testLeafRowsRefusals()

	/**
	 * A failed call is logged at debug level with the caller's message.
	 *
	 * @return void
	 */
	public function testAFailedLeafCallIsLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('debug')->with('KvK leaf unavailable', self::callback(static fn (array $context): bool => $context['exception'] instanceof RuntimeException));

		self::assertNull($this->http(200, null, $logger)->leafRows(url: 'u', unavailable: 'KvK leaf unavailable'));
	}//end testAFailedLeafCallIsLogged()
}//end class
