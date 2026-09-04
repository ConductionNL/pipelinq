<?php

/**
 * Pipelinq SearchQueryReportService.
 *
 * Aggregates the imported `searchQueryDaily` rows per query over a window:
 * clicks and impressions summed, CTR recomputed from the sums, position as
 * the impressions-weighted mean (a page-one position seen once must not
 * outweigh a page-three position seen a thousand times).
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
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-queries-page-lists-top-queries
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\SearchConsole;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * SearchQueryReportService: top queries by clicks over a window.
 *
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-queries-page-lists-top-queries
 */
class SearchQueryReportService {

	/**
	 * Default window in days when none is given.
	 *
	 * @var int
	 */
	public const DEFAULT_WINDOW_DAYS = 28;

	/**
	 * Rows read per page from OpenRegister.
	 *
	 * @var int
	 */
	private const PAGE = 500;

	/**
	 * Most rows ever read for one report.
	 *
	 * @var int
	 */
	private const MAX_ROWS = 20000;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container for the lazy object service.
	 * @param IAppConfig $appConfig Pipelinq app config.
	 * @param ITimeFactory $time Time factory for the default window.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-queries-page-lists-top-queries
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The top queries by clicks over a window.
	 *
	 * @param string|null $from Window start `YYYY-MM-DD`; defaults to 28 days ago.
	 * @param string|null $to Window end `YYYY-MM-DD`, inclusive; defaults to today.
	 * @param int $limit Rows to return (1..500).
	 * @param string|null $property Restrict to one property; null for all.
	 *
	 * @return array{from: string, to: string, rows: array<int, array<string, mixed>>, totalQueries: int}
	 *
	 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-queries-page-lists-top-queries
	 */
	public function topQueries(?string $from = null, ?string $to = null, int $limit = 50, ?string $property = null): array {
		[$from, $to] = $this->window(from: $from, to: $to);
		$rows = $this->loadRows(from: $from, to: $to, property: $property);
		$aggregated = $this->aggregate(rows: $rows);

		return [
			'from' => $from,
			'to' => $to,
			'rows' => array_slice($aggregated, 0, max(1, min(500, $limit))),
			'totalQueries' => count($aggregated),
		];
	}//end topQueries()

	/**
	 * Aggregate flat rows per query. Public so the arithmetic is testable
	 * without OpenRegister.
	 *
	 * @param array<int, array<string, mixed>> $rows `searchQueryDaily` rows.
	 *
	 * @return array<int, array<string, mixed>> One row per query, ordered by clicks then impressions, descending.
	 *
	 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-queries-page-lists-top-queries
	 */
	public function aggregate(array $rows): array {
		$byQuery = [];
		foreach ($rows as $row) {
			$query = trim((string)($row['query'] ?? ''));
			if ($query === '') {
				continue;
			}

			$impressions = max(0, (int)($row['impressions'] ?? 0));
			$entry = ($byQuery[$query] ?? ['query' => $query, 'clicks' => 0, 'impressions' => 0, 'weightedPosition' => 0.0, 'pages' => []]);
			$entry['clicks'] += max(0, (int)($row['clicks'] ?? 0));
			$entry['impressions'] += $impressions;
			$entry['weightedPosition'] += ((float)($row['position'] ?? 0.0) * $impressions);
			$page = (string)($row['page'] ?? '');
			if ($page !== '') {
				$entry['pages'][$page] = true;
			}

			$byQuery[$query] = $entry;
		}

		$out = [];
		foreach ($byQuery as $entry) {
			$impressions = (int)$entry['impressions'];
			$ctr = 0.0;
			$position = 0.0;
			if ($impressions > 0) {
				$ctr = round(($entry['clicks'] / $impressions), 4);
				$position = round(($entry['weightedPosition'] / $impressions), 1);
			}

			$out[] = [
				'query' => $entry['query'],
				'clicks' => (int)$entry['clicks'],
				'impressions' => $impressions,
				'ctr' => $ctr,
				'position' => $position,
				'pages' => count($entry['pages']),
			];
		}

		usort(
			$out,
			static function (array $a, array $b): int {
				return [$b['clicks'], $b['impressions'], $a['query']] <=> [$a['clicks'], $a['impressions'], $b['query']];
			}
		);

		return $out;
	}//end aggregate()

	/**
	 * Resolve the window: valid explicit dates win, else the last 28 days.
	 *
	 * @param string|null $from Requested start.
	 * @param string|null $to Requested end.
	 *
	 * @return array{0: string, 1: string} from, to.
	 */
	private function window(?string $from, ?string $to): array {
		$today = (new DateTimeImmutable('@' . $this->time->getTime()))->setTimezone(new DateTimeZone('UTC'));
		$end = ($this->parseDay(value: $to) ?? $today);
		$start = ($this->parseDay(value: $from) ?? $end->modify('-' . self::DEFAULT_WINDOW_DAYS . ' days'));
		if ($start > $end) {
			$start = $end;
		}

		return [$start->format('Y-m-d'), $end->format('Y-m-d')];
	}//end window()

	/**
	 * Parse a `YYYY-MM-DD` string, or null.
	 *
	 * @param string|null $value The string.
	 *
	 * @return DateTimeImmutable|null UTC midnight or null.
	 */
	private function parseDay(?string $value): ?DateTimeImmutable {
		if ($value === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $matches) !== 1) {
			return null;
		}

		if (checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1]) === false) {
			return null;
		}

		return new DateTimeImmutable((trim($value) . 'T00:00:00'), new DateTimeZone('UTC'));
	}//end parseDay()

	/**
	 * Read the rows in the window from OpenRegister, paged.
	 *
	 * @param string $from Window start.
	 * @param string $to Window end, inclusive.
	 * @param string|null $property Optional property filter.
	 *
	 * @return array<int, array<string, mixed>> Plain rows.
	 */
	private function loadRows(string $from, string $to, ?string $property): array {
		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning('SearchQueryReportService: OpenRegister unavailable', ['exception' => $e->getMessage()]);
			return [];
		}

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'pipelinq');
		$schemaKey = (SearchConsoleImportService::SCHEMA . '_schema');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, SearchConsoleImportService::SCHEMA);
		$dayAfter = (new DateTimeImmutable($to, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
		$filters = ['register' => $register, 'schema' => $schema, 'date' => ['gte' => $from, 'lt' => $dayAfter]];
		if ($property !== null && trim($property) !== '') {
			$filters['property'] = trim($property);
		}

		return $this->pages(objectService: $objectService, filters: $filters, pageSize: self::PAGE, maxRows: self::MAX_ROWS);
	}//end loadRows()

	/**
	 * Read matching rows page by page until a short page or the cap.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param array<string, mixed> $filters The filters, register and schema included.
	 * @param int $pageSize Rows per page.
	 * @param int $maxRows Never read more than this many rows.
	 *
	 * @return array<int, array<string, mixed>> Plain rows.
	 */
	private function pages(object $objectService, array $filters, int $pageSize, int $maxRows): array {
		$rows = [];
		$offset = 0;
		while ($offset < $maxRows) {
			try {
				$page = $objectService->findAll(
					config: ['filters' => $filters, 'limit' => $pageSize, 'offset' => $offset],
					_rbac: false,
					_multitenancy: false
				);
			} catch (Throwable $e) {
				$this->logger->warning('SearchQueryReportService: findAll failed', ['exception' => $e->getMessage()]);
				break;
			}

			if (is_iterable($page) === false) {
				break;
			}

			$count = 0;
			foreach ($page as $row) {
				$rows[] = $this->toArray(value: $row);
				$count++;
			}

			if ($count < $pageSize) {
				break;
			}

			$offset += $pageSize;
		}

		return $rows;
	}//end pages()

	/**
	 * Normalise an OpenRegister entity or array to a plain array.
	 *
	 * @param mixed $value Entity object or array.
	 *
	 * @return array<string, mixed> Plain payload.
	 */
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return [];
	}//end toArray()
}//end class
