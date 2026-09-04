<?php

/**
 * Unit tests for SearchConsoleImportService.
 *
 * Covers:
 * - nothing happens without a key or without properties
 * - rows become searchQueryDaily objects with the identity and the numbers
 * - a re-run updates the existing object instead of creating a second one
 * - pagination continues until a short page
 * - an API error on one property is reported and does not stop the others
 * - the property list parses lines and commas
 *
 * Google is never reached: the HTTP client is a mock and the auth helper's
 * token exchange is stubbed; the object service is a hand-written fake.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\SearchConsole
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\SearchConsole;

use OCA\Pipelinq\Service\SearchConsole\GoogleServiceAccountAuth;
use OCA\Pipelinq\Service\SearchConsole\SearchConsoleImportService;
use OCA\Pipelinq\Service\SearchConsole\SearchQueryDailyStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for SearchConsoleImportService.
 *
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
 */
class SearchConsoleImportServiceTest extends TestCase {

	/**
	 * Fixed clock: 2026-09-04T10:00:00Z.
	 *
	 * @var int
	 */
	private const NOW = 1788516000;

	/**
	 * The app-config store the mock reads and writes.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Fake object service: an in-memory store keyed by uuid.
	 *
	 * @var object
	 */
	private object $objectService;

	/**
	 * Every POST the fake HTTP client received.
	 *
	 * @var array<int, array{url: string, options: array<string, mixed>}>
	 */
	private array $posts = [];

	/**
	 * Build the importer.
	 *
	 * @param array<int, string> $responses JSON bodies the HTTP client answers in order; a body starting with `!` throws.
	 * @param bool $withKey Whether a key is configured.
	 * @param string $properties The stored property list.
	 *
	 * @return SearchConsoleImportService
	 */
	private function build(array $responses = [], bool $withKey = true, string $properties = "https://example.org/\nsc-domain:example.net"): SearchConsoleImportService {
		$this->config = ['register' => 'pipelinq', SearchConsoleImportService::PROPERTIES_KEY => $properties];
		if ($withKey === true) {
			$this->config[SearchConsoleImportService::KEY_KEY] = '{"type":"service_account","client_email":"svc@example.iam.gserviceaccount.com","private_key":"unused","token_uri":"https://oauth2.googleapis.com/token"}';
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default));
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$this->posts = [];
		$queue = $responses;
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $url, array $options) use (&$queue): IResponse {
				$this->posts[] = ['url' => $url, 'options' => $options];
				$body = (string)array_shift($queue);
				if (str_starts_with($body, '!') === true) {
					throw new \RuntimeException(substr($body, 1));
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getBody')->willReturn($body);
				return $response;
			}
		);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$auth = $this->createMock(GoogleServiceAccountAuth::class);
		$auth->method('accessToken')->willReturn('tok-1');
		$auth->method('parseKey')->willReturnCallback(
			static function (string $json): ?array {
				$decoded = json_decode($json, true);
				if (is_array($decoded) === false || ($decoded['client_email'] ?? '') === '') {
					return null;
				}

				return ['client_email' => (string)$decoded['client_email'], 'private_key' => (string)($decoded['private_key'] ?? ''), 'token_uri' => (string)($decoded['token_uri'] ?? '')];
			}
		);

		$this->objectService = new class {
			/** @var array<string, array<string, mixed>> */
			public array $store = [];

			/** @var int */
			public int $created = 0;

			/** @return array<int, array<string, mixed>> */
			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$filters = $config['filters'];
				$out = [];
				foreach ($this->store as $row) {
					$match = true;
					foreach (['property', 'date', 'query', 'page'] as $key) {
						$match = $match && (($row[$key] ?? null) === ($filters[$key] ?? null));
					}

					if ($match === true) {
						$out[] = $row;
					}
				}

				return $out;
			}//end findAll()

			/** @return array<string, mixed> */
			public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				if ($uuid === null) {
					$this->created++;
					$uuid = ('obj-' . $this->created);
				}

				$object['uuid'] = $uuid;
				$this->store[$uuid] = $object;
				return $object;
			}//end saveObject()
		};

		$container = $this->createMock(ContainerInterface::class);
		$objectService = $this->objectService;
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService): object {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		$store = new SearchQueryDailyStore($container, $appConfig, $this->createMock(LoggerInterface::class));

		return new SearchConsoleImportService($clientService, $appConfig, $time, $store, $auth, $this->createMock(LoggerInterface::class));
	}//end build()

	/**
	 * A Search Analytics API page.
	 *
	 * @param array<int, array{0: string, 1: string, 2: string, 3: int, 4: int}> $rows date, query, page, clicks, impressions.
	 *
	 * @return string JSON.
	 */
	private function page(array $rows): string {
		$out = [];
		foreach ($rows as [$date, $query, $page, $clicks, $impressions]) {
			$out[] = ['keys' => [$date, $query, $page], 'clicks' => $clicks, 'impressions' => $impressions, 'ctr' => ($impressions > 0) ? ($clicks / $impressions) : 0, 'position' => 3.456];
		}

		return (string)json_encode(['rows' => $out, 'responseAggregationType' => 'byPage']);
	}//end page()

	/**
	 * @return void
	 */
	public function testSkipsWithoutAKey(): void {
		$importer = $this->build(withKey: false);

		$this->assertFalse($importer->hasKey());
		$this->assertSame('', $importer->serviceAccountEmail());
		$this->assertSame(['properties' => 0, 'rows' => 0, 'errors' => []], $importer->importRecent());
		$this->assertSame([], $this->posts);
	}//end testSkipsWithoutAKey()

	/**
	 * @return void
	 */
	public function testSkipsWithoutProperties(): void {
		$importer = $this->build(properties: " \n, ");

		$this->assertSame([], $importer->properties());
		$this->assertSame(['properties' => 0, 'rows' => 0, 'errors' => []], $importer->importRecent());
		$this->assertSame([], $this->posts);
	}//end testSkipsWithoutProperties()

	/**
	 * @return void
	 */
	public function testPropertyListParsesLinesAndCommas(): void {
		$importer = $this->build(properties: "https://a.example/, sc-domain:b.example\r\n https://a.example/ \n");

		$this->assertSame(['https://a.example/', 'sc-domain:b.example'], $importer->properties());
		$this->assertSame('svc@example.iam.gserviceaccount.com', $importer->serviceAccountEmail());
	}//end testPropertyListParsesLinesAndCommas()

	/**
	 * @return void
	 */
	public function testImportsRowsAsSearchQueryDailyObjects(): void {
		$importer = $this->build(
			[
				$this->page([['2026-09-02', 'woo verzoek', 'https://example.org/woo', 12, 340]]),
				$this->page([]),
			]
		);

		$result = $importer->importRecent(days: 3);

		$this->assertSame(['properties' => 2, 'rows' => 1, 'errors' => []], $result);
		$this->assertCount(2, $this->posts);
		$this->assertSame('https://www.googleapis.com/webmasters/v3/sites/https%3A%2F%2Fexample.org%2F/searchAnalytics/query', $this->posts[0]['url']);
		$this->assertSame('Bearer tok-1', $this->posts[0]['options']['headers']['Authorization']);
		$this->assertSame('2026-09-01', $this->posts[0]['options']['json']['startDate']);
		$this->assertSame('2026-09-04', $this->posts[0]['options']['json']['endDate']);
		$this->assertSame(['date', 'query', 'page'], $this->posts[0]['options']['json']['dimensions']);

		$saved = array_values($this->objectService->store)[0];
		$this->assertSame('https://example.org/', $saved['property']);
		$this->assertSame('2026-09-02', $saved['date']);
		$this->assertSame('woo verzoek', $saved['query']);
		$this->assertSame('https://example.org/woo', $saved['page']);
		$this->assertSame(12, $saved['clicks']);
		$this->assertSame(340, $saved['impressions']);
		$this->assertSame(0.0353, $saved['ctr']);
		$this->assertSame(3.46, $saved['position']);
		$this->assertSame('gsc', $saved['source']);
		$this->assertSame('2026-09-04T10:00:00Z', $saved['importedAt']);
		$this->assertSame('2026-09-04T10:00:00Z', $this->config[SearchConsoleImportService::LAST_IMPORT_KEY]);
		$this->assertSame('2026-09-04T10:00:00Z', $importer->lastImportAt());
	}//end testImportsRowsAsSearchQueryDailyObjects()

	/**
	 * @return void
	 */
	public function testARerunUpdatesInsteadOfDuplicating(): void {
		$first = $this->page([['2026-09-02', 'q', 'https://example.org/', 1, 10]]);
		$second = $this->page([['2026-09-02', 'q', 'https://example.org/', 5, 50]]);
		$importer = $this->build([$first, $this->page([]), $second, $this->page([])]);

		$importer->importRecent();
		$importer->importRecent();

		$this->assertSame(1, $this->objectService->created);
		$this->assertCount(1, $this->objectService->store);
		$this->assertSame(5, array_values($this->objectService->store)[0]['clicks']);
	}//end testARerunUpdatesInsteadOfDuplicating()

	/**
	 * @return void
	 */
	public function testPaginatesUntilAShortPage(): void {
		$full = [];
		for ($i = 0; $i < 5000; $i++) {
			$full[] = ['2026-09-02', ('q' . $i), 'https://example.org/', 1, 1];
		}

		$importer = $this->build([$this->page($full), $this->page([['2026-09-03', 'last', 'https://example.org/', 1, 1]]), $this->page([])], properties: 'https://example.org/');
		$result = $importer->importRecent();

		$this->assertSame(5001, $result['rows']);
		$this->assertCount(2, $this->posts);
		$this->assertSame(0, $this->posts[0]['options']['json']['startRow']);
		$this->assertSame(5000, $this->posts[1]['options']['json']['startRow']);
	}//end testPaginatesUntilAShortPage()

	/**
	 * @return void
	 */
	public function testAnApiErrorOnOnePropertyIsReportedAndTheOthersContinue(): void {
		$importer = $this->build(
			[
				'{"error":{"code":403,"message":"User does not have sufficient permission for site"}}',
				$this->page([['2026-09-02', 'q', 'https://example.net/', 2, 20]]),
			]
		);
		$result = $importer->importRecent();

		$this->assertSame(1, $result['properties']);
		$this->assertSame(1, $result['rows']);
		$this->assertSame(['https://example.org/' => 'Search Console: User does not have sufficient permission for site'], $result['errors']);
	}//end testAnApiErrorOnOnePropertyIsReportedAndTheOthersContinue()

	/**
	 * @return void
	 */
	public function testATransportFailureIsReportedPerProperty(): void {
		$importer = $this->build(['!connection refused', $this->page([])]);
		$result = $importer->importRecent();

		$this->assertSame(1, $result['properties']);
		$this->assertSame('connection refused', $result['errors']['https://example.org/']);
	}//end testATransportFailureIsReportedPerProperty()
}//end class
