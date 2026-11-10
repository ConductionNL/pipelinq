<?php

/**
 * Pipelinq SearchConsoleImportService.
 *
 * Pulls Search Console `searchanalytics/query` rows (dimensions date,
 * query, page) for every configured property and upserts them as
 * `searchQueryDaily` objects, one per (property, date, query, page), so a
 * re-run of the same window changes nothing. Google publishes a day's
 * data with a lag of about two days and may revise it, which is why the
 * daily job re-reads the last three days rather than only yesterday.
 *
 * Credentials follow the app's pattern for provider secrets: the service
 * account key lives in a sensitive app-config value that no read path
 * returns (compare `blast.tracking_secret`).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\SearchConsole
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\SearchConsole;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * SearchConsoleImportService: fetch, upsert, report.
 *
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
 */
class SearchConsoleImportService {

	/**
	 * App-config key holding the property list (site URLs), one per line
	 * or comma-separated.
	 *
	 * @var string
	 */
	public const PROPERTIES_KEY = 'search.gsc.properties';

	/**
	 * App-config key holding the service account key JSON (sensitive).
	 *
	 * @var string
	 */
	public const KEY_KEY = 'search.gsc.service_account_key';

	/**
	 * App-config key recording the last successful import, ISO 8601.
	 *
	 * @var string
	 */
	public const LAST_IMPORT_KEY = 'search.gsc.last_import_at';

	/**
	 * The `searchQueryDaily` schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA = SearchQueryDailyStore::SCHEMA;

	/**
	 * Read-only Search Console scope.
	 *
	 * @var string
	 */
	public const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

	/**
	 * Search Analytics endpoint; `{site}` is the URL-encoded property.
	 *
	 * @var string
	 */
	public const API_URL = 'https://www.googleapis.com/webmasters/v3/sites/{site}/searchAnalytics/query';

	/**
	 * Rows requested per API page, and the cap per property and window.
	 *
	 * @var int
	 */
	private const ROW_LIMIT = 5000;

	/**
	 * Hard cap on rows per property and window (Google's own maximum).
	 *
	 * @var int
	 */
	private const MAX_ROWS = 25000;

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService Nextcloud HTTP client factory.
	 * @param IAppConfig $appConfig Pipelinq app config.
	 * @param ITimeFactory $time Time factory for the window.
	 * @param SearchQueryDailyStore $store Writes the rows.
	 * @param GoogleServiceAccountAuth $auth Service account token minting.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
	 */
	public function __construct(
		private IClientService $clientService,
		private IAppConfig $appConfig,
		private ITimeFactory $time,
		private SearchQueryDailyStore $store,
		private GoogleServiceAccountAuth $auth,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The configured properties, trimmed and deduplicated.
	 *
	 * @return array<int, string> Site URLs such as `https://example.org/` or `sc-domain:example.org`.
	 *
	 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
	 */
	public function properties(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::PROPERTIES_KEY, '');
		$parts = preg_split('/[\r\n,]+/', $raw);
		if ($parts === false) {
			return [];
		}

		$out = [];
		foreach ($parts as $part) {
			$trimmed = trim($part);
			if ($trimmed !== '') {
				$out[$trimmed] = true;
			}
		}

		return array_keys($out);
	}//end properties()

	/**
	 * Whether a usable service account key is on file.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
	 */
	public function hasKey(): bool {
		return $this->key() !== null;
	}//end hasKey()

	/**
	 * The service account email the admin must add on the property.
	 *
	 * @return string The email, empty without a key.
	 *
	 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
	 */
	public function serviceAccountEmail(): string {
		$key = $this->key();
		if ($key === null) {
			return '';
		}

		return $key['client_email'];
	}//end serviceAccountEmail()

	/**
	 * When the last import finished, ISO 8601, or empty.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
	 */
	public function lastImportAt(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::LAST_IMPORT_KEY, '');
	}//end lastImportAt()

	/**
	 * Import the last `$days` days for every configured property.
	 *
	 * @param int $days How many days back from today, inclusive of today.
	 *
	 * @return array{properties: int, rows: int, errors: array<string, string>} Counts and per-property failures.
	 *
	 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
	 */
	public function importRecent(int $days = 3): array {
		$key = $this->key();
		$properties = $this->properties();
		if ($key === null || $properties === []) {
			$this->logger->debug('SearchConsoleImportService: no key or no properties configured, skipping');
			return ['properties' => 0, 'rows' => 0, 'errors' => []];
		}

		$window = $this->windowEndingToday(days: $days);

		try {
			$token = $this->auth->accessToken(key: $key, scope: self::SCOPE);
		} catch (Throwable $e) {
			return ['properties' => 0, 'rows' => 0, 'errors' => ['*' => $e->getMessage()]];
		}

		$result = $this->importAll(read: new SearchConsoleRead(token: $token, window: $window), properties: $properties);
		$this->stampWhenImported(result: $result, window: $window);

		return $result;
	}//end importRecent()

	/**
	 * The window of `$days` days back ending today (UTC).
	 *
	 * @param int $days How many days back from today, inclusive of today.
	 *
	 * @return ImportWindow
	 */
	private function windowEndingToday(int $days): ImportWindow {
		return new ImportWindow(timestamp: $this->time->getTime(), days: $days);
	}//end windowEndingToday()

	/**
	 * Keep the import moment when at least one property was imported.
	 *
	 * @param array{properties: int, rows: int, errors: array<string, string>} $result The run's counts.
	 * @param ImportWindow                                                      $window The window it read.
	 *
	 * @return void
	 */
	private function stampWhenImported(array $result, ImportWindow $window): void {
		if ($result['properties'] > 0) {
			$this->appConfig->setValueString(Application::APP_ID, self::LAST_IMPORT_KEY, $window->today()->format('Y-m-d\TH:i:s\Z'));
		}
	}//end stampWhenImported()

	/**
	 * Import every property, a failing one reported without stopping the others.
	 *
	 * @param SearchConsoleRead  $read       The token and the window.
	 * @param array<int, string> $properties The configured properties.
	 *
	 * @return array{properties: int, rows: int, errors: array<string, string>}
	 */
	private function importAll(SearchConsoleRead $read, array $properties): array {
		$result = ['properties' => 0, 'rows' => 0, 'errors' => []];
		foreach ($properties as $property) {
			try {
				$result['rows'] += $this->importProperty(read: $read, property: $property);
				$result['properties']++;
			} catch (Throwable $e) {
				$this->logger->warning(
					'SearchConsoleImportService: property import failed',
					['property' => $property, 'exception' => $e->getMessage()]
				);
				$result['errors'][$property] = $e->getMessage();
			}
		}

		return $result;
	}//end importAll()

	/**
	 * Import one property over a window.
	 *
	 * @param SearchConsoleRead $read     The bearer token for the read-only scope and the window.
	 * @param string            $property The site URL or `sc-domain:` property.
	 *
	 * @return int Rows upserted.
	 *
	 * @throws Throwable When the API refuses.
	 *
	 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
	 */
	public function importProperty(SearchConsoleRead $read, string $property): int {
		$rows = $this->fetchRows(read: $read, property: $property);
		$importedAt = gmdate('Y-m-d\TH:i:s\Z', $this->time->getTime());
		$saved = 0;
		foreach ($rows as $row) {
			if ($this->store->upsert(property: $property, row: $row, importedAt: $importedAt) === true) {
				$saved++;
			}
		}

		$this->logger->info(
			'SearchConsoleImportService: property imported',
			['property' => $property, 'from' => $read->window->firstDay(), 'to' => $read->window->lastDay(), 'rows' => $saved]
		);

		return $saved;
	}//end importProperty()

	/**
	 * Fetch every row of the window, paging with `startRow` until a short page.
	 *
	 * @param SearchConsoleRead $read     The bearer token and the window.
	 * @param string            $property The property.
	 *
	 * @return array<int, array<string, mixed>> Normalised rows: date, query, page, clicks, impressions, ctr, position.
	 *
	 * @throws Throwable When the API answers with an error (from the property endpoint).
	 */
	private function fetchRows(SearchConsoleRead $read, string $property): array {
		$endpoint = new PropertyEndpoint(
			client: $this->clientService->newClient(),
			url: str_replace('{site}', rawurlencode($property), self::API_URL)
		);
		$rows = [];
		$startRow = 0;
		while ($startRow < self::MAX_ROWS) {
			$page = $endpoint->page(options: self::requestOptions(read: $read, startRow: $startRow));
			$rows = array_merge($rows, $this->normalisedRows(page: $page));
			if (count($page) < self::ROW_LIMIT) {
				break;
			}

			$startRow += self::ROW_LIMIT;
		}

		return $rows;
	}//end fetchRows()

	/**
	 * The query request for one page of the window.
	 *
	 * @param SearchConsoleRead $read     The bearer token and the window.
	 * @param int               $startRow The first row of the page.
	 *
	 * @return array<string, mixed>
	 */
	private static function requestOptions(SearchConsoleRead $read, int $startRow): array {
		return [
			'headers' => ['Authorization' => ('Bearer ' . $read->token), 'Accept' => 'application/json'],
			'json' => [
				'startDate' => $read->window->firstDay(),
				'endDate' => $read->window->lastDay(),
				'dimensions' => ['date', 'query', 'page'],
				'rowLimit' => self::ROW_LIMIT,
				'startRow' => $startRow,
				'dataState' => 'all',
			],
			'timeout' => 60,
		];
	}//end requestOptions()

	/**
	 * The rows of a page that normalise, in order.
	 *
	 * @param array<int|string, mixed> $page The raw rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function normalisedRows(array $page): array {
		$rows = [];
		foreach ($page as $raw) {
			$row = $this->normaliseRow(raw: $raw);
			if ($row !== null) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end normalisedRows()

	/**
	 * Turn one API row (`keys: [date, query, page]`) into a flat record.
	 *
	 * @param mixed $raw The API row.
	 *
	 * @return array<string, mixed>|null The record, or null when the keys are missing.
	 */
	private function normaliseRow(mixed $raw): ?array {
		if (is_array($raw) === false || is_array($raw['keys'] ?? null) === false || count($raw['keys']) < 3) {
			return null;
		}

		[$date, $query, $page] = $raw['keys'];
		$date = (string)$date;
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
			return null;
		}

		return [
			'date' => $date,
			'query' => (string)$query,
			'page' => (string)$page,
			'clicks' => (int)($raw['clicks'] ?? 0),
			'impressions' => (int)($raw['impressions'] ?? 0),
			'ctr' => round((float)($raw['ctr'] ?? 0.0), 4),
			'position' => round((float)($raw['position'] ?? 0.0), 2),
		];
	}//end normaliseRow()

	/**
	 * The parsed key on file, or null.
	 *
	 * @return array<string, string>|null
	 */
	private function key(): ?array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::KEY_KEY, '');
		if ($raw === '') {
			return null;
		}

		return $this->auth->parseKey(json: $raw);
	}//end key()

}//end class
