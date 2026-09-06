<?php

/**
 * Pipelinq CampaignPerformanceService.
 *
 * Joins what a blast did in the mailbox (opens, clicks, attributed deals,
 * all Pipelinq's own) to what it did on the site: the sessions Portaliq
 * attributed to the blast's campaign in its daily `portalTrafficDaily`
 * rollups (fleet traffic contract, section 3). The rollups are read through
 * OpenRegister's object service, duck-typed, so Pipelinq boots and answers
 * without Portaliq present; without a configured portal the site half is
 * simply reported as not connected.
 *
 * Attribution is by campaign, not by person (Ruben decision 5): a rollup
 * row matches when its `campaign` equals the blast's UTM slug (what a
 * decorated link carries) or the blast name (what phase 0 stamps on the
 * `email_open`/`email_click` events).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-campaign-performance-joins-site-sessions-to-a-blast
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * CampaignPerformanceService: one blast, mailbox and site numbers together.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The join needs the blast
 *  (BlastService), the deals (AttributionService), the campaign slug
 *  (CampaignLinkDecorator), the portal setting, the clock and the lazy,
 *  duck-typed object service; one cohesive read model.
 *
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-campaign-performance-joins-site-sessions-to-a-blast
 */
class CampaignPerformanceService {

	/**
	 * Portaliq's register and rollup schema slugs (contract section 3).
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
	 * Longest window served, in days.
	 *
	 * @var int
	 */
	private const MAX_WINDOW_DAYS = 366;

	/**
	 * Default window when the blast carries no usable date, in days.
	 *
	 * @var int
	 */
	private const DEFAULT_WINDOW_DAYS = 30;

	/**
	 * Rollup rows read per page, and the hard cap.
	 *
	 * @var int
	 */
	private const PAGE = 200;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container for the lazy, duck-typed object service.
	 * @param IAppConfig $appConfig Pipelinq app config.
	 * @param ITimeFactory $time Time factory for the window's end.
	 * @param BlastService $blastService Loads the blast.
	 * @param AttributionService $attributionService Blast to deal roll-up.
	 * @param CampaignLinkDecorator $decorator Owns the campaign slug.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-campaign-performance-joins-site-sessions-to-a-blast
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private ITimeFactory $time,
		private BlastService $blastService,
		private AttributionService $attributionService,
		private CampaignLinkDecorator $decorator,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The performance of one blast over a window.
	 *
	 * @param string $blastId Blast UUID or slug.
	 * @param string|null $from Window start `YYYY-MM-DD`; defaults to the blast's send date.
	 * @param string|null $to Window end `YYYY-MM-DD`, inclusive; defaults to today.
	 *
	 * @return array<string, mixed>|null The performance record, or null when the blast does not exist.
	 *
	 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-campaign-performance-joins-site-sessions-to-a-blast
	 */
	public function forBlast(string $blastId, ?string $from = null, ?string $to = null): ?array {
		$blast = $this->blastService->getBlastById(blastId: $blastId);
		if ($blast === null) {
			return null;
		}

		$window = $this->window(blast: $blast, from: $from, to: $to);
		$campaign = $this->decorator->campaignFor(blast: $blast);
		$totals = (array)($blast['totals'] ?? []);
		$deals = $this->attributionService->getBlastAttributionSummary(blastId: $blastId);

		$record = [
			'blastId' => $blastId,
			'campaign' => $campaign,
			'portal' => '',
			'connected' => false,
			'reason' => '',
			'window' => $window,
			'email' => [
				'sent' => (int)($totals['sent'] ?? 0),
				'delivered' => (int)($totals['delivered'] ?? 0),
				'opened' => (int)($totals['opened'] ?? 0),
				'clicked' => (int)($totals['clicked'] ?? 0),
			],
			'deals' => [
				'dealCount' => (int)($deals['dealCount'] ?? 0),
				'attributedValue' => (float)($deals['attributedValue'] ?? 0.0),
				'currency' => (string)($deals['currency'] ?? 'EUR'),
			],
			'site' => null,
		];

		$portal = trim($this->appConfig->getValueString(Application::APP_ID, TrafficEventEmitter::PORTAL_CONFIG_KEY, ''));
		if ($portal === '') {
			$record['reason'] = 'no_portal';
			return $record;
		}

		$record['portal'] = $portal;
		if ($this->isPortaliqInstalled() === false) {
			$record['reason'] = 'portaliq_missing';
			return $record;
		}

		$rows = $this->loadRollups(portal: $portal, from: $window['from'], to: $window['to']);
		$record['connected'] = true;
		$record['site'] = $this->sumCampaignRows(rows: $rows, campaign: $campaign, blastName: (string)($blast['name'] ?? ''));

		return $record;
	}//end forBlast()

	/**
	 * Whether Portaliq is installed. Protected so a test can answer for it.
	 *
	 * @return bool
	 */
	protected function isPortaliqInstalled(): bool {
		return class_exists(self::PORTALIQ_PROBE_CLASS);
	}//end isPortaliqInstalled()

	/**
	 * Resolve the window: explicit and valid dates win, else the blast's
	 * send date to today, else the last thirty days; never longer than a year.
	 *
	 * @param array<string, mixed> $blast The blast row.
	 * @param string|null $from Requested start.
	 * @param string|null $to Requested end.
	 *
	 * @return array{from: string, to: string} Both `YYYY-MM-DD`.
	 */
	private function window(array $blast, ?string $from, ?string $to): array {
		$today = (new DateTimeImmutable('@' . $this->time->getTime()))->setTimezone(new DateTimeZone('UTC'));
		$end = ($this->parseDay(value: $to) ?? $today);

		$start = $this->parseDay(value: $from);
		if ($start === null) {
			foreach (['sentAt', 'scheduledFor', 'createdAt'] as $key) {
				$start = $this->parseDay(value: (string)($blast[$key] ?? ''));
				if ($start !== null) {
					break;
				}
			}
		}

		if ($start === null) {
			$start = $end->modify('-' . self::DEFAULT_WINDOW_DAYS . ' days');
		}

		if ($start > $end) {
			$start = $end;
		}

		$span = (int)$start->diff($end)->format('%a');
		if ($span > self::MAX_WINDOW_DAYS) {
			$start = $end->modify('-' . self::MAX_WINDOW_DAYS . ' days');
		}

		return ['from' => $start->format('Y-m-d'), 'to' => $end->format('Y-m-d')];
	}//end window()

	/**
	 * Parse the date part of an ISO string, or null when unusable.
	 *
	 * @param string|null $value The string.
	 *
	 * @return DateTimeImmutable|null The UTC midnight, or null.
	 */
	private function parseDay(?string $value): ?DateTimeImmutable {
		if ($value === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim($value), $matches) !== 1) {
			return null;
		}

		if (checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1]) === false) {
			return null;
		}

		return new DateTimeImmutable(($matches[1] . '-' . $matches[2] . '-' . $matches[3] . 'T00:00:00'), new DateTimeZone('UTC'));
	}//end parseDay()

	/**
	 * Read the portal's rollups in the window through OpenRegister.
	 *
	 * @param string $portal The portal slug.
	 * @param string $from Window start, inclusive.
	 * @param string $to Window end, inclusive.
	 *
	 * @return array<int, array<string, mixed>> The rollup rows.
	 */
	private function loadRollups(string $portal, string $from, string $to): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$dayAfter = (new DateTimeImmutable($to, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
		$filters = [
			'register' => self::PORTALIQ_REGISTER,
			'schema' => self::DAILY_SCHEMA,
			'portal' => $portal,
			'date' => ['gte' => $from, 'lt' => $dayAfter],
		];

		return $this->pages(objectService: $objectService, filters: $filters, pageSize: self::PAGE, maxRows: self::MAX_WINDOW_DAYS);
	}//end loadRollups()

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
				$this->logger->warning(
					'CampaignPerformanceService: reading portalTrafficDaily failed',
					['portal' => (string)($filters['portal'] ?? ''), 'exception' => $e->getMessage()]
				);
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
	 * Sum the matching `campaigns[]` rows across the rollups.
	 *
	 * `pageViews` and `formSubmits` stay null unless a row carries them:
	 * the contract's campaign rows hold sessions only today, and a zero
	 * would read as "nobody came" rather than "not counted".
	 *
	 * @param array<int, array<string, mixed>> $rows The rollups.
	 * @param string $campaign The blast's UTM slug.
	 * @param string $blastName The blast name email events carry.
	 *
	 * @return array<string, mixed> sessions, pageViews, formSubmits, days, sources.
	 */
	private function sumCampaignRows(array $rows, string $campaign, string $blastName): array {
		$wanted = array_filter(array_unique([strtolower($campaign), strtolower(trim($blastName))]), static fn (string $v): bool => $v !== '');
		$sessions = 0;
		$pageViews = null;
		$formSubmits = null;
		$days = [];
		$sources = [];
		foreach ($rows as $row) {
			$campaigns = ($row['campaigns'] ?? []);
			if (is_array($campaigns) === false) {
				continue;
			}

			foreach ($campaigns as $entry) {
				if (is_array($entry) === false || in_array(strtolower((string)($entry['campaign'] ?? '')), $wanted, true) === false) {
					continue;
				}

				$sessions += (int)($entry['sessions'] ?? 0);
				$days[(string)($row['date'] ?? '')] = true;
				$key = ((string)($entry['source'] ?? '') . '/' . (string)($entry['medium'] ?? ''));
				$sources[$key] = ($sources[$key] ?? 0) + (int)($entry['sessions'] ?? 0);
				if (isset($entry['pageViews']) === true) {
					$pageViews = (($pageViews ?? 0) + (int)$entry['pageViews']);
				}

				if (isset($entry['formSubmits']) === true) {
					$formSubmits = (($formSubmits ?? 0) + (int)$entry['formSubmits']);
				}
			}
		}

		arsort($sources);
		$sourceRows = [];
		foreach ($sources as $key => $count) {
			[$source, $medium] = explode('/', (string)$key, 2);
			$sourceRows[] = ['source' => $source, 'medium' => $medium, 'sessions' => $count];
		}

		return [
			'sessions' => $sessions,
			'pageViews' => $pageViews,
			'formSubmits' => $formSubmits,
			'days' => count($days),
			'sources' => $sourceRows,
		];
	}//end sumCampaignRows()

	/**
	 * Resolve the OpenRegister ObjectService lazily.
	 *
	 * @return object|null ObjectService or null when OR is unavailable.
	 */
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'CampaignPerformanceService: OpenRegister unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end getObjectService()

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

		if (is_object($value) === true && method_exists($value, 'getObject') === true) {
			$payload = $value->getObject();
			if (is_array($payload) === true) {
				return $payload;
			}
		}

		return [];
	}//end toArray()
}//end class
