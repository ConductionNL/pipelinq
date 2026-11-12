<?php

/**
 * Pipelinq CampaignSiteTraffic.
 *
 * What a blast's campaign did on the site: the sessions Portaliq attributed
 * to it in its daily `portalTrafficDaily` rollups (fleet traffic contract,
 * section 3), read through OpenRegister's object service, duck-typed, so
 * Pipelinq boots and answers without Portaliq present.
 *
 * A rollup row matches when its `campaign` equals the blast's UTM slug or
 * the blast name.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/marketing-campaign-attribution/spec.md#requirement-campaign-performance-joins-site-sessions-to-a-blast
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Util\PayloadToArray;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The configured portal, the blast's campaign slug, and the campaign's
 * sessions, page views, form submits, days and sources over a window.
 *
 * @spec openspec/specs/marketing-campaign-attribution/spec.md#requirement-campaign-performance-joins-site-sessions-to-a-blast
 */
class CampaignSiteTraffic {
	use PayloadToArray;

	/**
	 * Portaliq's register slug (contract section 3).
	 *
	 * @var string
	 */
	public const PORTALIQ_REGISTER = 'portaliq';

	/**
	 * The rollup schema slug.
	 *
	 * @var string
	 */
	public const DAILY_SCHEMA = 'portalTrafficDaily';

	/**
	 * A class that exists exactly when Portaliq is installed.
	 *
	 * @var string
	 */
	public const PORTALIQ_PROBE_CLASS = 'OCA\\Portaliq\\AppInfo\\Application';

	/**
	 * Rollup rows read per page.
	 *
	 * @var int
	 */
	private const PAGE = 200;

	/**
	 * Never read more rollup rows than a year's worth of days.
	 *
	 * @var int
	 */
	private const MAX_ROWS = 366;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface    $container DI container for the lazy, duck-typed object service.
	 * @param IAppConfig            $appConfig Pipelinq app config.
	 * @param CampaignLinkDecorator $decorator Owns the campaign slug.
	 * @param LoggerInterface       $logger    Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private CampaignLinkDecorator $decorator,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The configured portal slug, empty when none is configured.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/marketing-campaign-attribution/spec.md#requirement-campaign-performance-joins-site-sessions-to-a-blast
	 */
	public function portal(): string {
		return trim($this->appConfig->getValueString(Application::APP_ID, TrafficEventEmitter::PORTAL_CONFIG_KEY, ''));
	}//end portal()

	/**
	 * The blast's UTM campaign slug.
	 *
	 * @param array<string, mixed> $blast The blast row.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/marketing-campaign-attribution/spec.md#requirement-campaign-performance-joins-site-sessions-to-a-blast
	 */
	public function campaignFor(array $blast): string {
		return $this->decorator->campaignFor(blast: $blast);
	}//end campaignFor()

	/**
	 * Whether Portaliq is installed. Overridable so a test can answer for it.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/marketing-campaign-attribution/spec.md#requirement-campaign-performance-joins-site-sessions-to-a-blast
	 */
	public function isPortaliqInstalled(): bool {
		return class_exists(self::PORTALIQ_PROBE_CLASS);
	}//end isPortaliqInstalled()

	/**
	 * The blast's campaign numbers on the configured portal over a window.
	 *
	 * @param array{from: string, to: string} $window The window, both `YYYY-MM-DD`.
	 * @param array<string, mixed>            $blast  The blast row.
	 *
	 * @return array<string, mixed> sessions, pageViews, formSubmits, days, sources.
	 *
	 * @spec openspec/specs/marketing-campaign-attribution/spec.md#requirement-campaign-performance-joins-site-sessions-to-a-blast
	 */
	public function site(array $window, array $blast): array {
		return $this->sum(rows: $this->rollups(window: $window), wanted: $this->wanted(blast: $blast));
	}//end site()

	/**
	 * The lower-cased campaign names that count for a blast: its slug and its name.
	 *
	 * @param array<string, mixed> $blast The blast row.
	 *
	 * @return array<int, string>
	 */
	private function wanted(array $blast): array {
		$names = [strtolower($this->campaignFor(blast: $blast)), strtolower(trim((string) ($blast['name'] ?? '')))];
		return array_filter(array_unique($names), static fn (string $v): bool => $v !== '');
	}//end wanted()

	/**
	 * Read the portal's rollups in the window through OpenRegister.
	 *
	 * @param array{from: string, to: string} $window The window, both inclusive.
	 *
	 * @return array<int, array<string, mixed>> The rollup rows.
	 */
	private function rollups(array $window): array {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return [];
		}

		$dayAfter = (new DateTimeImmutable($window['to'] . ' UTC'))->modify('+1 day')->format('Y-m-d');
		$filters  = [
			'register' => self::PORTALIQ_REGISTER,
			'schema'   => self::DAILY_SCHEMA,
			'portal'   => $this->portal(),
			'date'     => ['gte' => $window['from'], 'lt' => $dayAfter],
		];

		return $this->pages(objectService: $objectService, filters: $filters);
	}//end rollups()

	/**
	 * Read matching rows page by page until a short page, a failed page or the cap.
	 *
	 * @param object               $objectService The OpenRegister object service.
	 * @param array<string, mixed> $filters       The filters, register and schema included.
	 *
	 * @return array<int, array<string, mixed>> Plain rows.
	 */
	private function pages(object $objectService, array $filters): array {
		$rows   = [];
		$offset = 0;
		while ($offset < self::MAX_ROWS) {
			$page = $this->pageRows(objectService: $objectService, config: ['filters' => $filters, 'limit' => self::PAGE, 'offset' => $offset]);
			$rows = array_merge($rows, $page);
			if (count($page) < self::PAGE) {
				break;
			}

			$offset += self::PAGE;
		}

		return $rows;
	}//end pages()

	/**
	 * One page as plain rows; empty when the read failed or answered no list,
	 * which also ends the paging.
	 *
	 * @param object               $objectService The OpenRegister object service.
	 * @param array<string, mixed> $config        The findAll() query.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function pageRows(object $objectService, array $config): array {
		try {
			$page = $objectService->findAll(config: $config, _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			$portal = (string) ($config['filters']['portal'] ?? '');
			$this->logger->warning('CampaignPerformanceService: reading portalTrafficDaily failed', ['portal' => $portal, 'exception' => $e->getMessage()]);
			return [];
		}

		if (is_iterable($page) === false) {
			return [];
		}

		return $this->plainRows(page: $page);
	}//end pageRows()

	/**
	 * A page's rows as plain arrays.
	 *
	 * @param iterable<mixed> $page The page.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function plainRows(iterable $page): array {
		$rows = [];
		foreach ($page as $row) {
			$rows[] = $this->toArray(value: $row);
		}

		return $rows;
	}//end plainRows()

	/**
	 * Sum the matching `campaigns[]` rows across the rollups.
	 *
	 * `pageViews` and `formSubmits` stay null unless a row carries them:
	 * the contract's campaign rows hold sessions only today, and a zero
	 * would read as "nobody came" rather than "not counted".
	 *
	 * @param array<int, array<string, mixed>> $rows   The rollups.
	 * @param array<int, string>               $wanted The campaign names that count.
	 *
	 * @return array<string, mixed> sessions, pageViews, formSubmits, days, sources.
	 */
	private function sum(array $rows, array $wanted): array {
		$tally = ['sessions' => 0, 'pageViews' => null, 'formSubmits' => null, 'days' => [], 'sources' => []];
		foreach ($rows as $row) {
			$entries = $this->matchingEntries(row: $row, wanted: $wanted);
			foreach ($entries as $entry) {
				$tally = $this->addEntry(tally: $tally, entry: $entry);
			}

			if ($entries !== []) {
				$tally['days'][(string) ($row['date'] ?? '')] = true;
			}
		}

		return $this->summary(tally: $tally);
	}//end sum()

	/**
	 * A rollup's `campaigns[]` entries for the wanted campaigns.
	 *
	 * @param array<string, mixed> $row    The rollup.
	 * @param array<int, string>   $wanted The campaign names that count.
	 *
	 * @return array<int|string, array<string, mixed>>
	 */
	private function matchingEntries(array $row, array $wanted): array {
		$campaigns = ($row['campaigns'] ?? []);
		if (is_array($campaigns) === false) {
			return [];
		}

		return array_filter(
			$campaigns,
			static fn (mixed $entry): bool => is_array($entry) === true && in_array(strtolower((string) ($entry['campaign'] ?? '')), $wanted, true) === true
		);
	}//end matchingEntries()

	/**
	 * Add one campaign entry to the tally.
	 *
	 * @param array<string, mixed> $tally The running tally.
	 * @param array<string, mixed> $entry The campaign entry.
	 *
	 * @return array<string, mixed>
	 */
	private function addEntry(array $tally, array $entry): array {
		$sessions           = (int) ($entry['sessions'] ?? 0);
		$tally['sessions'] += $sessions;
		$key = ((string) ($entry['source'] ?? '') . '/' . (string) ($entry['medium'] ?? ''));
		$tally['sources'][$key] = ($tally['sources'][$key] ?? 0) + $sessions;
		if (isset($entry['pageViews']) === true) {
			$tally['pageViews'] = (($tally['pageViews'] ?? 0) + (int) $entry['pageViews']);
		}

		if (isset($entry['formSubmits']) === true) {
			$tally['formSubmits'] = (($tally['formSubmits'] ?? 0) + (int) $entry['formSubmits']);
		}

		return $tally;
	}//end addEntry()

	/**
	 * The tally as the record's `site` block, sources busiest first.
	 *
	 * @param array<string, mixed> $tally The tally.
	 *
	 * @return array<string, mixed>
	 */
	private function summary(array $tally): array {
		$sources = $tally['sources'];
		arsort($sources);
		$tally['sources'] = [];
		foreach ($sources as $key => $count) {
			[$source, $medium]  = explode('/', (string) $key, 2);
			$tally['sources'][] = ['source' => $source, 'medium' => $medium, 'sessions' => $count];
		}

		$tally['days'] = count($tally['days']);
		return $tally;
	}//end summary()

	/**
	 * Resolve the OpenRegister ObjectService lazily.
	 *
	 * @return object|null ObjectService or null when OR is unavailable.
	 */
	private function objectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CampaignPerformanceService: OpenRegister unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end objectService()
}//end class
