<?php

/**
 * Pins every answer of the NRC inbox across bearer, subscription and body shapes.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\ZgwNotificationController;
use OCA\Pipelinq\Listener\NrcNotificationListener;
use OCA\Pipelinq\Service\Zgw\ZgwApiClient;
use OCA\Pipelinq\Service\Zgw\ZgwRegisterAccess;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Header × body, with status, body, lookups, dispatches and log lines.
 */
class ZgwInboxGateGoldenTest extends TestCase {

	/**
	 * Runs one request.
	 *
	 * @param string $header The Authorization header.
	 * @param string $body   The raw body.
	 *
	 * @return array<string, mixed>
	 */
	private function outcome(string $header, string $body): array {
		$calls   = [];
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => $name === 'Authorization' ? $header : ''
		);
		$request->method('getRemoteAddress')->willReturn('198.51.100.7');
		$registers = $this->createMock(ZgwRegisterAccess::class);
		$registers->method('findAll')->willReturnCallback(
			function (...$args) use (&$calls): array {
				$calls[] = ['findAll', $args];
				return [
					['id' => 'a0', 'active' => false, 'callbackAuth' => 'good-1'],
					['id' => 'a1', 'active' => true, 'callbackAuth' => ''],
					['id' => 'a2', 'active' => true, 'callbackAuth' => 'vault:x'],
					['id' => 'a3', 'active' => true, 'callbackAuth' => 'good-1'],
				];
			}
		);
		$api = $this->createMock(ZgwApiClient::class);
		$api->method('resolveClientSecret')->willReturnCallback(
			function (string $stored) use (&$calls): string {
				$calls[] = ['resolve', $stored];
				return $stored === 'vault:x' ? 'vault-ok' : '';
			}
		);
		$listener = $this->createMock(NrcNotificationListener::class);
		$listener->method('dispatch')->willReturnCallback(
			function (array $abonnement, array $payload) use (&$calls): void {
				$calls[] = ['dispatch', $abonnement['id'], $payload];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use (&$calls, $level): void {
					$calls[] = [$level, $message, $context];
				}
			);
		}

		$controller = new class($request, $registers, $api, $listener, $logger) extends ZgwNotificationController {
			/**
			 * The body the request carries.
			 *
			 * @var string
			 */
			public string $body = '';

			/**
			 * The fixture body.
			 *
			 * @return string
			 */
			protected function readRawBody(): string {
				return $this->body;
			}
		};
		$controller->body = $body;
		$response = $controller->inbox();
		return ['status' => $response->getStatus(), 'body' => $response->getData(), 'calls' => $calls];
	}//end outcome()

	/**
	 * The whole matrix answers exactly as pinned.
	 *
	 * @return void
	 */
	public function testMatrix(): void {
		$headers = ['none' => '', 'empty bearer' => 'Bearer   ', 'basic' => 'Basic good-1', 'unknown' => 'Bearer nope', 'literal' => 'bearer good-1 ', 'vault' => 'Bearer vault-ok'];
		$bodies  = ['json' => '{"kanaal":"zaken","resource":"zaak"}', 'garbage' => 'not json', 'scalar' => '"zaken"'];
		$actual  = [];
		foreach ($headers as $h => $header) {
			foreach ($bodies as $b => $body) {
				$actual[$h.' '.$b] = $this->outcome(header: $header, body: $body);
			}
		}

		$missing = ['status' => 422, 'body' => ['error' => 'Missing bearer token'], 'calls' => []];
		$lookup  = [
			['findAll', [ZgwRegisterAccess::SCHEMA_ABONN, ['active' => true]]],
			['resolve', 'vault:x'],
			['resolve', 'good-1'],
		];
		$unknown = [
			'status' => 422,
			'body'   => ['error' => 'Unknown bearer token'],
			'calls'  => array_merge($lookup, [['warning', 'ZGW NRC: inbox received with unknown bearer', ['ip' => '198.51.100.7']]]),
		];
		$invalid = static fn (array $lookups): array => ['status' => 400, 'body' => ['error' => 'Invalid JSON payload'], 'calls' => $lookups];
		$accepted = static fn (array $lookups, string $id): array => [
			'status' => 202,
			'body'   => ['status' => 'accepted'],
			'calls'  => array_merge($lookups, [['dispatch', $id, ['kanaal' => 'zaken', 'resource' => 'zaak']]]),
		];
		$vaultLookup = array_slice($lookup, 0, 2);
		$expected = [];
		foreach (['none', 'empty bearer', 'basic'] as $h) {
			foreach (array_keys($bodies) as $b) {
				$expected[$h.' '.$b] = $missing;
			}
		}

		foreach (array_keys($bodies) as $b) {
			$expected['unknown '.$b] = $unknown;
		}

		$expected += [
			'literal json'    => $accepted($lookup, 'a3'),
			'literal garbage' => $invalid($lookup),
			'literal scalar'  => $invalid($lookup),
			'vault json'      => $accepted($vaultLookup, 'a2'),
			'vault garbage'   => $invalid($vaultLookup),
			'vault scalar'    => $invalid($vaultLookup),
		];
		$this->assertSame($expected, $actual);
	}//end testMatrix()
}//end class
