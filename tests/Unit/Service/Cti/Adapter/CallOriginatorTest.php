<?php

/**
 * Unit tests for CallOriginator.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Cti\Adapter
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

namespace OCA\Pipelinq\Tests\Unit\Service\Cti\Adapter;

use OCA\Pipelinq\Service\Cti\Adapter\CallOriginator;
use OCA\Pipelinq\Service\Cti\Adapter\CtiPlatform;
use OCA\Pipelinq\Service\Cti\Adapter\OriginateEndpoint;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * CallOriginator places a click-to-dial call on a telephony platform.
 */
class CallOriginatorTest extends TestCase {

	/**
	 * Posts made, as [url, options].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $posts = [];

	/**
	 * An originator whose platform answers with the body, or throws.
	 *
	 * @param string|null          $body   The response body, or null to throw.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return CallOriginator
	 */
	private function originator(?string $body, ?LoggerInterface $logger=null): CallOriginator {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn((string)$body);
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(function (string $uri, array $options=[]) use ($response, $body): IResponse {
			$this->posts[] = [$uri, $options];
			if ($body === null) {
				throw new RuntimeException('401 Unauthorized');
			}

			return $response;
		});
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		return new CallOriginator(clientService: $clients, logger: ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end originator()

	/**
	 * The platform used in every case.
	 *
	 * @return CtiPlatform
	 */
	private static function platform(): CtiPlatform {
		return new CtiPlatform(platform: 'voys', label: 'Voys');
	}//end platform()

	/**
	 * A placed call reads the first call-id key the body carries.
	 *
	 * @return void
	 */
	public function testAPlacedCallCarriesItsCallId(): void {
		$endpoint = new OriginateEndpoint(url: 'https://api.voys.example/clicktodial', callIdKeys: ['callid', 'id']);
		$result = $this->originator('{"id":"fallback","callid":"c-123"}')->place(
			request: $endpoint->request(options: ['json' => ['b_number' => '+31201234567']]),
			platform: self::platform()
		);

		self::assertTrue($result->success);
		self::assertSame('c-123', $result->externalCallId);
		self::assertSame('voys', $result->platform);
		self::assertSame([['https://api.voys.example/clicktodial', ['json' => ['b_number' => '+31201234567']]]], $this->posts);
	}//end testAPlacedCallCarriesItsCallId()

	/**
	 * A later key is used when the first is absent; numbers become text.
	 *
	 * @return void
	 */
	public function testALaterKeyIsUsed(): void {
		$endpoint = new OriginateEndpoint(url: 'u', callIdKeys: ['callid', 'id']);

		self::assertSame('77', $this->originator('{"id":77}')->place(request: $endpoint->request(options: []), platform: self::platform())->externalCallId);
	}//end testALaterKeyIsUsed()

	/**
	 * A body without a call id, or not JSON, still counts as placed without an id.
	 *
	 * @return void
	 */
	public function testAPlacedCallWithoutAnId(): void {
		$endpoint = new OriginateEndpoint(url: 'u', callIdKeys: ['callid']);

		$noKey = $this->originator('{"status":"ok"}')->place(request: $endpoint->request(options: []), platform: self::platform());
		self::assertTrue($noKey->success);
		self::assertNull($noKey->externalCallId);

		$notJson = $this->originator('OK')->place(request: $endpoint->request(options: []), platform: self::platform());
		self::assertTrue($notJson->success);
		self::assertNull($notJson->externalCallId);
	}//end testAPlacedCallWithoutAnId()

	/**
	 * A failed call is logged and refused with the platform label and the error.
	 *
	 * @return void
	 */
	public function testAFailedCallIsRefused(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with('Voys originate failed', ['exception' => '401 Unauthorized']);

		$result = $this->originator(null, $logger)->place(request: (new OriginateEndpoint(url: 'u', callIdKeys: []))->request(options: []), platform: self::platform());

		self::assertFalse($result->success);
		self::assertSame('Voys originate failed: 401 Unauthorized', $result->error);
		self::assertSame('voys', $result->platform);
		self::assertNull($result->externalCallId);
	}//end testAFailedCallIsRefused()
}//end class
