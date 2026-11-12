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
 * @spec openspec/specs/marketing-campaign-attribution/spec.md#requirement-campaign-performance-joins-site-sessions-to-a-blast
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * CampaignPerformanceService: one blast, mailbox and site numbers together.
 *
 * @spec openspec/specs/marketing-campaign-attribution/spec.md#requirement-campaign-performance-joins-site-sessions-to-a-blast
 */
class CampaignPerformanceService {
	/**
	 * Portaliq's register slug (contract section 3).
	 *
	 * @var string
	 */
	public const PORTALIQ_REGISTER = CampaignSiteTraffic::PORTALIQ_REGISTER;

	/**
	 * The rollup schema slug.
	 *
	 * @var string
	 */
	public const DAILY_SCHEMA = CampaignSiteTraffic::DAILY_SCHEMA;

	/**
	 * A class that exists exactly when Portaliq is installed.
	 *
	 * @var string
	 */
	public const PORTALIQ_PROBE_CLASS = CampaignSiteTraffic::PORTALIQ_PROBE_CLASS;

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
	 * Constructor.
	 *
	 * @param ITimeFactory        $time               Time factory for the window's end.
	 * @param BlastService        $blastService       Loads the blast.
	 * @param AttributionService  $attributionService Blast to deal roll-up.
	 * @param CampaignSiteTraffic $traffic            Portal, campaign slug and site sessions.
	 *
	 * @spec openspec/specs/marketing-campaign-attribution/spec.md#requirement-campaign-performance-joins-site-sessions-to-a-blast
	 */
	public function __construct(
		private ITimeFactory $time,
		private BlastService $blastService,
		private AttributionService $attributionService,
		private CampaignSiteTraffic $traffic,
	) {
	}//end __construct()

	/**
	 * The performance of one blast over a window.
	 *
	 * @param string      $blastId Blast UUID or slug.
	 * @param string|null $from    Window start `YYYY-MM-DD`; defaults to the blast's send date.
	 * @param string|null $to      Window end `YYYY-MM-DD`, inclusive; defaults to today.
	 *
	 * @return array<string, mixed>|null The performance record, or null when the blast does not exist.
	 *
	 * @spec openspec/specs/marketing-campaign-attribution/spec.md#requirement-campaign-performance-joins-site-sessions-to-a-blast
	 */
	public function forBlast(string $blastId, ?string $from=null, ?string $to=null): ?array {
		$blast = $this->blastService->getBlastById(blastId: $blastId);
		if ($blast === null) {
			return null;
		}

		$record           = $this->mailboxRecord(blastId: $blastId, blast: $blast);
		$record['window'] = $this->window(blast: $blast, requested: ['from' => $from, 'to' => $to]);
		return $this->withSite(record: $record, blast: $blast);
	}//end forBlast()

	/**
	 * The record with the mailbox and deal numbers, the site half not yet read.
	 *
	 * @param string               $blastId The requested blast id.
	 * @param array<string, mixed> $blast   The blast row.
	 *
	 * @return array<string, mixed>
	 */
	private function mailboxRecord(string $blastId, array $blast): array {
		return [
			'blastId'   => $blastId,
			'campaign'  => $this->traffic->campaignFor(blast: $blast),
			'portal'    => '',
			'connected' => false,
			'reason'    => '',
			'window'    => [],
			'email'     => $this->emailCounts(totals: (array) ($blast['totals'] ?? [])),
			'deals'     => $this->dealCounts(blastId: $blastId),
			'site'      => null,
		];
	}//end mailboxRecord()

	/**
	 * The blast's mailbox counters.
	 *
	 * @param array<string, mixed> $totals The blast's totals.
	 *
	 * @return array<string, int>
	 */
	private function emailCounts(array $totals): array {
		return [
			'sent'      => (int) ($totals['sent'] ?? 0),
			'delivered' => (int) ($totals['delivered'] ?? 0),
			'opened'    => (int) ($totals['opened'] ?? 0),
			'clicked'   => (int) ($totals['clicked'] ?? 0),
		];
	}//end emailCounts()

	/**
	 * The deals attributed to the blast.
	 *
	 * @param string $blastId The requested blast id.
	 *
	 * @return array<string, mixed>
	 */
	private function dealCounts(string $blastId): array {
		$deals = $this->attributionService->getBlastAttributionSummary(blastId: $blastId);
		return [
			'dealCount'       => (int) ($deals['dealCount'] ?? 0),
			'attributedValue' => (float) ($deals['attributedValue'] ?? 0.0),
			'currency'        => (string) ($deals['currency'] ?? 'EUR'),
		];
	}//end dealCounts()

	/**
	 * The record with its site half: the numbers, or the reason there are none.
	 *
	 * @param array<string, mixed> $record The record so far.
	 * @param array<string, mixed> $blast  The blast row.
	 *
	 * @return array<string, mixed>
	 */
	private function withSite(array $record, array $blast): array {
		$portal = $this->traffic->portal();
		if ($portal === '') {
			$record['reason'] = 'no_portal';
			return $record;
		}

		$record['portal'] = $portal;
		if ($this->traffic->isPortaliqInstalled() === false) {
			$record['reason'] = 'portaliq_missing';
			return $record;
		}

		$record['site']      = $this->traffic->site(window: $record['window'], blast: $blast);
		$record['connected'] = true;
		return $record;
	}//end withSite()

	/**
	 * Resolve the window: explicit and valid dates win, else the blast's
	 * send date to today, else the last thirty days; never longer than a year.
	 *
	 * @param array<string, mixed>                          $blast     The blast row.
	 * @param array{from: string|null, to: string|null}     $requested The requested start and end.
	 *
	 * @return array{from: string, to: string} Both `YYYY-MM-DD`.
	 */
	private function window(array $blast, array $requested): array {
		$today = (new DateTimeImmutable('@' . $this->time->getTime()))->setTimezone(new DateTimeZone('UTC'));
		$end   = ($this->parseDay(value: $requested['to']) ?? $today);
		$start = ($this->parseDay(value: $requested['from']) ?? $this->blastDay(blast: $blast) ?? $end->modify('-' . self::DEFAULT_WINDOW_DAYS . ' days'));
		if ($start > $end) {
			$start = $end;
		}

		if ((int) $start->diff($end)->format('%a') > self::MAX_WINDOW_DAYS) {
			$start = $end->modify('-' . self::MAX_WINDOW_DAYS . ' days');
		}

		return ['from' => $start->format('Y-m-d'), 'to' => $end->format('Y-m-d')];
	}//end window()

	/**
	 * The first usable date the blast carries: sent, scheduled, then created.
	 *
	 * @param array<string, mixed> $blast The blast row.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function blastDay(array $blast): ?DateTimeImmutable {
		foreach (['sentAt', 'scheduledFor', 'createdAt'] as $key) {
			$day = $this->parseDay(value: (string) ($blast[$key] ?? ''));
			if ($day !== null) {
				return $day;
			}
		}

		return null;
	}//end blastDay()

	/**
	 * Parse the date part of an ISO string, or null when unusable.
	 *
	 * @param string|null $value The string.
	 *
	 * @return DateTimeImmutable|null The UTC midnight, or null.
	 */
	private function parseDay(?string $value): ?DateTimeImmutable {
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim((string) $value), $matches) !== 1) {
			return null;
		}

		if (checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]) === false) {
			return null;
		}

		return new DateTimeImmutable(($matches[1] . '-' . $matches[2] . '-' . $matches[3] . 'T00:00:00'), new DateTimeZone('UTC'));
	}//end parseDay()
}//end class
