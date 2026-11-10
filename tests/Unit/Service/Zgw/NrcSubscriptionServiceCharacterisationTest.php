<?php

/**
 * Characterisation test for NrcSubscriptionService.
 *
 * Pins every request, every stored record, every refusal and the callback
 * bearer hand-off of registering and unregistering an NRC abonnement.
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
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Zgw;

use OCA\Pipelinq\Service\Zgw\NrcSubscriptionFailedException;
use OCA\Pipelinq\Service\Zgw\NrcSubscriptionService;
use OCA\Pipelinq\Service\Zgw\ZgwApiClient;
use OCA\Pipelinq\Service\Zgw\ZgwRegisterAccess;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Registration and unregistration scenarios.
 */
class NrcSubscriptionServiceCharacterisationTest extends TestCase {
	private const NRC = 'https://nrc.example/api/v1';

	/**
	 * Calls seen, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $seen = [];

	/**
	 * The kanalen as a caller passes them.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const KANALEN = [
		['name' => 'zaken', 'filters' => ['zaaktype' => 'z', 'bronorganisatie' => 'b']],
		['name' => ''],
		['name' => 'documenten', 'filters' => 'bad'],
	];

	/**
	 * The kanalen as NRC and the record get them.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const NORMALISED = [
		['name' => 'documenten', 'filters' => []],
		['name' => 'zaken', 'filters' => ['bronorganisatie' => 'b', 'zaaktype' => 'z']],
	];

	/**
	 * Registration POSTs once, hands NRC the bearer it stores, and keeps the
	 * Location header (or the body url) as the abonnement URL.
	 *
	 * @return void
	 */
	public function testRegistrationPostsAndStores(): void {
		foreach ([
			[['headers' => ['location' => 'https://nrc.example/abonnement/1'], 'body' => ['url' => 'x']], 'https://nrc.example/abonnement/1', ['saved' => true]],
			[['headers' => [], 'body' => ['url' => 'https://nrc.example/abonnement/2']], 'https://nrc.example/abonnement/2', null],
		] as [$answer, $url, $saved]) {
			$service = $this->service(client: ['id' => 'c'], post: $answer, saved: $saved);

			$result = $service->registerAbonnement(['id' => 'ep-1', 'componenten' => ['nrc' => self::NRC]], self::KANALEN, 'https://pipelinq/inbox');

			[$post, $save] = $this->seen;
			self::assertSame('post', $post[0]);
			self::assertSame([self::NRC, 'POST', '/abonnement', ['id' => 'c']], array_slice($post, 1, 4));
			$body = $post[5];
			self::assertSame(['callbackUrl', 'auth', 'kanalen'], array_keys($body));
			self::assertSame('https://pipelinq/inbox', $body['callbackUrl']);
			self::assertMatchesRegularExpression('/^Bearer [0-9a-f]{64}$/', $body['auth']);
			self::assertSame(self::NORMALISED, $body['kanalen']);
			$bearer = substr($body['auth'], 7);
			$record = [
				'endpointId' => 'ep-1',
				'abonnementUrl' => $url,
				'callbackUrl' => 'https://pipelinq/inbox',
				'callbackAuth' => $bearer,
				'kanalen' => self::NORMALISED,
				'active' => true,
			];
			self::assertSame(['save', ZgwRegisterAccess::SCHEMA_ABONN, $record, null], $save);
			self::assertSame($saved ?? $record, $result);
		}//end foreach
	}//end testRegistrationPostsAndStores()

	/**
	 * Every registration refusal, with its message and whether NRC was called.
	 *
	 * @return void
	 */
	public function testRegistrationRefusals(): void {
		$cases = [
			'no client' => [null, ['headers' => [], 'body' => []], ['id' => 'ep-1', 'componenten' => ['nrc' => self::NRC]], 'ZGW NRC: ZgwEndpoint "ep-1" has no resolvable client', 0],
			'no client, no id' => [null, ['headers' => [], 'body' => []], [], 'ZGW NRC: ZgwEndpoint "?" has no resolvable client', 0],
			'no nrc url' => [['id' => 'c'], ['headers' => [], 'body' => []], ['id' => 'ep-1', 'componenten' => []], 'ZGW NRC: endpoint missing nrc URL', 0],
			'post fails' => [['id' => 'c'], new RuntimeException('refused'), ['id' => 'ep-1', 'componenten' => ['nrc' => self::NRC]], 'ZGW NRC: POST /abonnement failed: refused', 1],
			'no url back' => [['id' => 'c'], ['headers' => [], 'body' => []], ['id' => 'ep-1', 'componenten' => ['nrc' => self::NRC]], 'ZGW NRC: registration returned no abonnement URL', 1],
		];
		foreach ($cases as $name => [$client, $answer, $endpoint, $message, $posts]) {
			$service = $this->service(client: $client, post: $answer, saved: null);
			$caught = null;
			try {
				$service->registerAbonnement($endpoint, self::KANALEN, 'https://pipelinq/inbox');
			} catch (Throwable $e) {
				$caught = $e;
			}

			self::assertInstanceOf(NrcSubscriptionFailedException::class, $caught, $name);
			self::assertSame($message, $caught->getMessage(), $name);
			self::assertCount($posts, $this->seen, $name);
			if ($answer instanceof RuntimeException) {
				self::assertSame($answer, $caught->getPrevious(), $name);
				self::assertSame(0, $caught->getCode(), $name);
			}
		}//end foreach
	}//end testRegistrationRefusals()

	/**
	 * Unregistration DELETEs a known abonnement and always stores it inactive.
	 *
	 * @return void
	 */
	public function testUnregistration(): void {
		$service = $this->service(client: ['id' => 'c'], post: ['headers' => [], 'body' => []], saved: null);
		$service->unregisterAbonnement(['id' => 'ep'], ['abonnementUrl' => 'https://nrc/a/1', '@self' => ['uuid' => 'u-1'], 'active' => true]);
		self::assertSame(
			[
				['post', 'https://nrc/a/1', 'DELETE', '', ['id' => 'c'], null],
				['save', ZgwRegisterAccess::SCHEMA_ABONN, ['abonnementUrl' => 'https://nrc/a/1', '@self' => ['uuid' => 'u-1'], 'active' => false], 'u-1'],
			],
			$this->seen
		);

		$service = $this->service(client: ['id' => 'c'], post: ['headers' => [], 'body' => []], saved: null);
		$service->unregisterAbonnement(['id' => 'ep'], ['id' => 'i-2']);
		self::assertSame([['save', ZgwRegisterAccess::SCHEMA_ABONN, ['id' => 'i-2', 'active' => false], 'i-2']], $this->seen);

		$service = $this->service(client: ['id' => 'c'], post: ['headers' => [], 'body' => []], saved: null);
		$service->unregisterAbonnement(['id' => 'ep'], []);
		self::assertSame([['save', ZgwRegisterAccess::SCHEMA_ABONN, ['active' => false], null]], $this->seen);

		$service = $this->service(client: null, post: ['headers' => [], 'body' => []], saved: null);
		try {
			$service->unregisterAbonnement(['id' => 'ep'], []);
			self::fail('expected a refusal');
		} catch (NrcSubscriptionFailedException $e) {
			self::assertSame('ZGW NRC: endpoint has no resolvable client', $e->getMessage());
		}

		$service = $this->service(client: ['id' => 'c'], post: new RuntimeException('gone'), saved: null);
		try {
			$service->unregisterAbonnement(['id' => 'ep'], ['abonnementUrl' => 'https://nrc/a/1']);
			self::fail('expected a refusal');
		} catch (NrcSubscriptionFailedException $e) {
			self::assertSame('ZGW NRC: DELETE failed: gone', $e->getMessage());
			self::assertCount(1, $this->seen);
		}
	}//end testUnregistration()

	/**
	 * Build the service over recording fakes.
	 *
	 * @param array<string, mixed>|null                 $client The client the register resolves.
	 * @param array<string, mixed>|RuntimeException     $post   The NRC answer.
	 * @param array<string, mixed>|null                 $saved  What the register save answers.
	 *
	 * @return NrcSubscriptionService
	 */
	private function service(?array $client, array|RuntimeException $post, ?array $saved): NrcSubscriptionService {
		$this->seen = [];
		$api = $this->createMock(ZgwApiClient::class);
		$api->method('callComponent')->willReturnCallback(
			function (string $componentUrl, string $method, string $path, array $client, ?array $body = null) use ($post): array {
				$this->seen[] = ['post', $componentUrl, $method, $path, $client, $body];
				if ($post instanceof RuntimeException) {
					throw $post;
				}

				return $post;
			}
		);
		$registers = $this->createMock(ZgwRegisterAccess::class);
		$registers->method('findClientForEndpoint')->willReturn($client);
		$registers->method('save')->willReturnCallback(
			function (string $schema, array $record, ?string $uuid = null) use ($saved): ?array {
				$this->seen[] = ['save', $schema, $record, $uuid];
				return $saved;
			}
		);

		return new NrcSubscriptionService(api: $api, registers: $registers);
	}//end service()
}//end class
