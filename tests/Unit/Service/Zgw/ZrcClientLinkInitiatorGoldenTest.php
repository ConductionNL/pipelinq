<?php

/**
 * Golden test for ZrcClient::linkInitiator().
 *
 * Pins every request linkInitiator() sends, every log line it writes and the
 * rol URL it answers, for each branch of the idempotent GET-then-POST flow.
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
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-010
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Zgw;

use OCA\Pipelinq\Service\Zgw\InitiatorLink;
use OCA\Pipelinq\Service\Zgw\RolAssignment;
use OCA\Pipelinq\Service\Zgw\RolType;
use OCA\Pipelinq\Service\Zgw\ZgwApiClient;
use OCA\Pipelinq\Service\Zgw\ZgwException;
use OCA\Pipelinq\Service\Zgw\ZgwRegisterAccess;
use OCA\Pipelinq\Service\Zgw\ZrcClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden scenarios for the rol link flow.
 */
class ZrcClientLinkInitiatorGoldenTest extends TestCase {
	private const ZRC = 'https://zrc.example/api/v1';
	private const ZAAK = 'https://zrc.example/api/v1/zaken/abc';
	private const ROLTYPE = 'https://ztc.example/roltypen/initiator';

	/**
	 * The client record the register facade answers.
	 *
	 * @var array<string, mixed>
	 */
	private array $client = ['clientIdentifier' => 'pipelinq', 'secret' => 'x'];

	/**
	 * Scenarios: GET answer, POST answer, contact, roltoelichting, expected outcome.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function scenarios(): array {
		$created = ['status' => 201, 'headers' => ['location' => 'https://zrc.example/rollen/new'], 'body' => ['url' => 'https://zrc.example/rollen/body']];
		return [
			'existing rol in results is reused' => [
				['status' => 200, 'headers' => [], 'body' => ['results' => [
					'not-a-row',
					['url' => 'https://zrc.example/rollen/other', 'betrokkeneIdentificatie' => ['inpBsn' => '999']],
					['url' => 'https://zrc.example/rollen/hit', 'betrokkeneIdentificatie' => ['inpBsn' => '123456789']],
				]]],
				$created,
				['bsn' => '123456789'],
				null,
				'https://zrc.example/rollen/hit',
			],
			'unpaginated body list is read too' => [
				['status' => 200, 'headers' => [], 'body' => [
					['url' => 'https://zrc.example/rollen/flat', 'betrokkeneIdentificatie' => ['innNnpId' => '002564440']],
				]],
				$created,
				['rsin' => '002564440'],
				null,
				'https://zrc.example/rollen/flat',
			],
			'match without url falls through to POST' => [
				['status' => 200, 'headers' => [], 'body' => ['results' => [
					['betrokkeneIdentificatie' => ['inpBsn' => '123456789']],
					['url' => 'https://zrc.example/rollen/noident', 'betrokkeneIdentificatie' => 'flat'],
				]]],
				$created,
				['bsn' => '123456789'],
				'Aanvrager',
				'https://zrc.example/rollen/new',
			],
			'non-list results fall through to POST, body url used' => [
				['status' => 200, 'headers' => [], 'body' => ['results' => 'none']],
				['status' => 201, 'headers' => [], 'body' => ['url' => 'https://zrc.example/rollen/body']],
				['kvk' => '12345678'],
				null,
				'https://zrc.example/rollen/body',
			],
			'failed GET is logged and POST answers empty' => [
				new RuntimeException('boom'),
				['status' => 201, 'headers' => [], 'body' => []],
				['name' => 'Acme'],
				null,
				'',
			],
		];
	}//end scenarios()

	/**
	 * Every scenario sends the same requests and answers the same URL.
	 *
	 * @param array<string, mixed>|RuntimeException $getAnswer  Answer to GET /rollen.
	 * @param array<string, mixed>                  $postAnswer Answer to POST /rollen.
	 * @param array<string, mixed>                  $contact    Contact payload.
	 * @param string|null                           $toelichting Explicit roltoelichting or default.
	 * @param string                                $expected   Expected rol URL.
	 *
	 * @return void
	 */
	#[DataProvider('scenarios')]
	public function testScenario(
		array|RuntimeException $getAnswer,
		array $postAnswer,
		array $contact,
		?string $toelichting,
		string $expected,
	): void {
		$calls = [];
		$api = $this->createMock(ZgwApiClient::class);
		$api->method('callComponent')->willReturnCallback(
			static function (string $componentUrl, string $method, string $path, array $client, ?array $body = null, array $extraHeaders = [], array $query = []) use (&$calls, $getAnswer, $postAnswer): array {
				$calls[] = compact('componentUrl', 'method', 'path', 'client', 'body', 'extraHeaders', 'query');
				if ($method === 'GET') {
					if ($getAnswer instanceof RuntimeException) {
						throw $getAnswer;
					}

					return $getAnswer;
				}

				return $postAnswer;
			}
		);
		$logs = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			static function (string $message, array $context) use (&$logs): void {
				$logs[] = [$message, $context];
			}
		);

		$url = $this->linkFor(api: $api, logger: $logger, contact: $contact, toelichting: $toelichting);

		self::assertSame($expected, $url);
		[$betrType, $ident] = ZrcClient::contactIdentification(contact: $contact);
		$expectedGet = [
			'componentUrl' => self::ZRC,
			'method' => 'GET',
			'path' => '/rollen',
			'client' => $this->client,
			'body' => null,
			'extraHeaders' => [],
			'query' => ['zaak' => self::ZAAK, 'betrokkeneType' => $betrType],
		];
		self::assertSame($expectedGet, $calls[0]);
		$reused = count($calls) === 1;
		if ($reused === true) {
			self::assertSame([], $logs);
			return;
		}

		self::assertSame(
			[
				'componentUrl' => self::ZRC,
				'method' => 'POST',
				'path' => '/rollen',
				'client' => $this->client,
				'body' => [
					'zaak' => self::ZAAK,
					'betrokkeneType' => $betrType,
					'betrokkeneIdentificatie' => $ident,
					'roltype' => self::ROLTYPE,
					'roltoelichting' => $toelichting ?? 'Initiator',
				],
				'extraHeaders' => [],
				'query' => [],
			],
			$calls[1]
		);
		self::assertCount(2, $calls);
		$expectedLogs = [];
		if ($getAnswer instanceof RuntimeException) {
			$expectedLogs[] = ['ZGW ZRC: linkInitiator GET /rollen failed (will fall through to POST)', ['err' => 'boom']];
		}

		self::assertSame($expectedLogs, $logs);
	}//end testScenario()

	/**
	 * An endpoint without a known client is refused before any request.
	 *
	 * @return void
	 */
	public function testUnknownClientIsRefused(): void {
		$api = $this->createMock(ZgwApiClient::class);
		$api->expects(self::never())->method('callComponent');
		$registers = $this->createMock(ZgwRegisterAccess::class);
		$registers->method('findClientForEndpoint')->willReturn(null);
		$zrc = new ZrcClient(api: $api, registers: $registers, logger: $this->createMock(LoggerInterface::class));

		$this->expectException(ZgwException::class);
		$this->expectExceptionMessage('ZGW: ZgwEndpoint "ep" references unknown clientId "cid"');
		$zrc->linkInitiator(['id' => 'ep', 'clientId' => 'cid', 'componenten' => ['zrc' => self::ZRC]], new InitiatorLink(['zgwUrl' => self::ZAAK], new RolAssignment(['bsn' => '1'], new RolType(self::ROLTYPE))));
	}//end testUnknownClientIsRefused()

	/**
	 * An endpoint without a ZRC URL is refused before any request.
	 *
	 * @return void
	 */
	public function testMissingZrcIsRefused(): void {
		$api = $this->createMock(ZgwApiClient::class);
		$api->expects(self::never())->method('callComponent');
		$registers = $this->createMock(ZgwRegisterAccess::class);
		$registers->method('findClientForEndpoint')->willReturn($this->client);
		$zrc = new ZrcClient(api: $api, registers: $registers, logger: $this->createMock(LoggerInterface::class));

		$this->expectException(ZgwException::class);
		$this->expectExceptionMessage('ZGW: endpoint missing "zrc" component URL');
		$zrc->linkInitiator(['id' => 'ep', 'componenten' => []], new InitiatorLink(['zgwUrl' => self::ZAAK], new RolAssignment(['bsn' => '1'], new RolType(self::ROLTYPE))));
	}//end testMissingZrcIsRefused()

	/**
	 * Run linkInitiator against the fakes.
	 *
	 * @param ZgwApiClient    $api         Fake transport.
	 * @param LoggerInterface $logger      Recording logger.
	 * @param array           $contact     Contact payload.
	 * @param string|null     $toelichting Explicit roltoelichting or default.
	 *
	 * @return string
	 */
	private function linkFor(ZgwApiClient $api, LoggerInterface $logger, array $contact, ?string $toelichting): string {
		$registers = $this->createMock(ZgwRegisterAccess::class);
		$registers->method('findClientForEndpoint')->willReturn($this->client);
		$zrc = new ZrcClient(api: $api, registers: $registers, logger: $logger);
		$endpoint = ['id' => 'ep', 'componenten' => ['zrc' => self::ZRC]];
		if ($toelichting === null) {
			return $zrc->linkInitiator($endpoint, new InitiatorLink(['zgwUrl' => self::ZAAK], new RolAssignment($contact, new RolType(self::ROLTYPE))));
		}

		return $zrc->linkInitiator($endpoint, new InitiatorLink(['zgwUrl' => self::ZAAK], new RolAssignment($contact, new RolType(self::ROLTYPE, $toelichting))));
	}//end linkFor()
}//end class
