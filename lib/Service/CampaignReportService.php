<?php

/**
 * Pipelinq CampaignReportService.
 *
 * One campaign, one response: reach and clicks per channel, submissions,
 * leads with what each one closed on, the three attribution models, and
 * what the campaign cost.
 *
 * 🔴 IT IS ONE READ ON PURPOSE. pipelinq#1781 fixed a performance page
 * that asked the server once per blast before it rendered anything; the
 * page waited on a fan-out the reader had not asked for. This report is
 * the same shape and is built as one aggregate from the start, so the
 * page paints from a single response.
 *
 * A cost nobody recorded is reported as absent, never as zero. Zero
 * reads as free, and a campaign that looks free is a campaign nobody
 * questions.
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
 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Kernel\Contracts\CampaignReportServiceInterface;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * CampaignReportService: the whole campaign report in one call.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The report joins the
 *  campaign, its blasts, its touchpoints and its attribution; one
 *  cohesive read model, assembled once so the page fetches once.
 *
 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
 */
class CampaignReportService implements CampaignReportServiceInterface {

	/**
	 * The blast schema slug.
	 *
	 * @var string
	 */
	public const BLAST_SCHEMA_SLUG = 'blast';

	/**
	 * Constructor.
	 *
	 * @param CampaignService $campaigns Reads the campaign.
	 * @param CampaignAttributionService $attribution The models, the close and the touchpoint log.
	 * @param ListObjectStore $store Reads the campaign's blasts.
	 * @param ITimeFactory $time Closes an open window at today.
	 *
	 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
	 */
	public function __construct(
		private CampaignService $campaigns,
		private CampaignAttributionService $attribution,
		private ListObjectStore $store,
		private ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * The report for one campaign.
	 *
	 * @param string $campaignId The campaign.
	 * @param string|null $from Window start `YYYY-MM-DD`; defaults to the campaign's own start.
	 * @param string|null $to Window end `YYYY-MM-DD`; defaults to the campaign's end, or today.
	 *
	 * @return array<string, mixed>|null The report, or null when the campaign does not exist.
	 *
	 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
	 */
	public function forCampaign(string $campaignId, ?string $from = null, ?string $to = null): ?array {
		$campaign = $this->campaigns->find(id: $campaignId);
		if ($campaign === null) {
			return null;
		}

		$window = $this->window(
			start: $this->startDay(campaign: $campaign, from: $from),
			end: $this->endDay(campaign: $campaign, to: $to)
		);
		$head = ['campaign' => $this->summary(campaignId: $campaignId, campaign: $campaign), 'window' => $window];

		return ($head + $this->figures(campaignId: $campaignId, window: $window) + ['cost' => $this->cost(campaign: $campaign)]);
	}//end forCampaign()

	/**
	 * The channel, engagement, lead and attribution figures of a campaign in a window.
	 *
	 * @param string                          $campaignId The campaign.
	 * @param array{from: string, to: string} $window     The report window.
	 *
	 * @return array<string, mixed> Keys: channels, engagement, leads, totals, models.
	 */
	private function figures(string $campaignId, array $window): array {
		$blasts = $this->store->findAll(schemaSlug: self::BLAST_SCHEMA_SLUG, filters: ['campaignId' => $campaignId]);
		$attribution = $this->attribution->forCampaign(campaignId: $campaignId, from: $window['from'], to: $window['to']);

		return [
			'channels' => $this->channels(blasts: $blasts, touchpoints: $attribution['touchpoints']),
			'engagement' => $this->engagement(touchpoints: $attribution['touchpoints']),
			'leads' => $attribution['leads'],
			'totals' => $attribution['totals'],
			'models' => $attribution['models'],
		];
	}//end figures()

	/**
	 * The campaign's own fields the report shows.
	 *
	 * @param string               $campaignId The campaign id.
	 * @param array<string, mixed> $campaign   The campaign.
	 *
	 * @return array<string, mixed> The summary.
	 */
	private function summary(string $campaignId, array $campaign): array {
		return [
			'id' => $campaignId,
			'name' => (string)($campaign['name'] ?? ''),
			'goal' => (string)($campaign['goal'] ?? ''),
			'status' => (string)($campaign['status'] ?? ''),
			'utmCampaign' => (string)($campaign['utmCampaign'] ?? ''),
			'utmSource' => (string)($campaign['utmSource'] ?? ''),
			'utmMedium' => (string)($campaign['utmMedium'] ?? ''),
			'landingPage' => (array)($campaign['landingPage'] ?? []),
			'defaultModel' => (string)($campaign['attribution']['model'] ?? 'last'),
		];
	}//end summary()

	/**
	 * Reach, clicks and leads per channel.
	 *
	 * Email reach comes from the blasts' own totals, because a delivery
	 * that was sent is reach whether or not anybody clicked. Every other
	 * channel has no reach figure of its own yet, so it reports its
	 * touchpoints and leaves reach null rather than guessing. A channel's
	 * leads are the distinct leads its touchpoints carry.
	 *
	 * @param array<int, array<string, mixed>> $blasts The campaign's blasts.
	 * @param array<int, array<string, mixed>> $touchpoints The campaign's touchpoints.
	 *
	 * @return array<int, array<string, mixed>> One row per channel.
	 *
	 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
	 */
	public function channels(array $blasts, array $touchpoints): array {
		$rows = $this->withTouchpoints(rows: $this->blastChannels(blasts: $blasts), touchpoints: $touchpoints);
		$leadsPerChannel = $this->leadsPerChannel(touchpoints: $touchpoints);

		foreach ($rows as $channel => $row) {
			if ($row['reported'] === false) {
				$rows[$channel]['reach'] = null;
			}

			$rows[$channel]['leads'] = count($leadsPerChannel[$channel] ?? []);
			unset($rows[$channel]['reported']);
		}

		ksort($rows);

		return array_values($rows);
	}//end channels()

	/**
	 * One row per blast channel, with the blasts' delivered, sent and opened totals summed.
	 *
	 * @param array<int, array<string, mixed>> $blasts The campaign's blasts.
	 *
	 * @return array<string, array<string, mixed>> The rows by channel.
	 */
	private function blastChannels(array $blasts): array {
		$rows = [];
		foreach ($blasts as $blast) {
			$channel = (string)($blast['channel'] ?? 'email');
			$totals = (array)($blast['totals'] ?? []);
			$row = ($rows[$channel] ?? $this->emptyChannel(channel: $channel));
			$row['reach'] = ((int)($row['reach'] ?? 0) + (int)($totals['delivered'] ?? 0));
			$row['sent'] = ($row['sent'] + (int)($totals['sent'] ?? 0));
			$row['opened'] = ($row['opened'] + (int)($totals['opened'] ?? 0));
			$row['reported'] = true;
			$rows[$channel] = $row;
		}

		return $rows;
	}//end blastChannels()

	/**
	 * The rows with every touchpoint counted under its kind, adding a row for a new channel.
	 *
	 * @param array<string, array<string, mixed>> $rows        The rows by channel.
	 * @param array<int, array<string, mixed>>    $touchpoints The campaign's touchpoints.
	 *
	 * @return array<string, array<string, mixed>> The rows by channel.
	 */
	private function withTouchpoints(array $rows, array $touchpoints): array {
		foreach ($touchpoints as $touchpoint) {
			$channel = (string)($touchpoint['channel'] ?? 'direct');
			$row = ($rows[$channel] ?? $this->emptyChannel(channel: $channel));
			$kind = (string)($touchpoint['kind'] ?? '');
			if (isset($row[$kind]) === true) {
				$row[$kind]++;
			}

			$rows[$channel] = $row;
		}

		return $rows;
	}//end withTouchpoints()

	/**
	 * The distinct lead ids the touchpoints carry, per channel.
	 *
	 * @param array<int, array<string, mixed>> $touchpoints The campaign's touchpoints.
	 *
	 * @return array<string, array<string, true>> Lead ids per channel.
	 */
	private function leadsPerChannel(array $touchpoints): array {
		$leadsPerChannel = [];
		foreach ($touchpoints as $touchpoint) {
			$channel = (string)($touchpoint['channel'] ?? 'direct');
			$leadId = trim((string)($touchpoint['leadId'] ?? ''));
			if ($leadId !== '') {
				$leadsPerChannel[$channel][$leadId] = true;
			}
		}

		return $leadsPerChannel;
	}//end leadsPerChannel()

	/**
	 * The campaign's clicks, visits, submissions and replies.
	 *
	 * @param array<int, array<string, mixed>> $touchpoints The campaign's touchpoints.
	 *
	 * @return array<string, int> One count per kind.
	 *
	 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
	 */
	public function engagement(array $touchpoints): array {
		$counts = [];
		foreach (TouchpointService::KINDS as $kind) {
			$counts[$kind] = 0;
		}

		foreach ($touchpoints as $touchpoint) {
			$kind = (string)($touchpoint['kind'] ?? '');
			if (isset($counts[$kind]) === true) {
				$counts[$kind]++;
			}
		}

		return $counts;
	}//end engagement()

	/**
	 * What the campaign cost, and what is simply not recorded.
	 *
	 * @param array<string, mixed> $campaign The campaign.
	 *
	 * @return array{budgetEur: float|null, recorded: array<int, array<string, mixed>>, totalEur: float|null, currency: string}
	 *         `totalEur` is null when nothing was recorded.
	 *
	 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
	 */
	public function cost(array $campaign): array {
		$recorded = [];
		$total = null;
		foreach ((array)($campaign['costs'] ?? []) as $entry) {
			if (is_array($entry) === false || isset($entry['amountEur']) === false) {
				continue;
			}

			$recorded[] = [
				'channel' => (string)($entry['channel'] ?? ''),
				'amountEur' => (float)$entry['amountEur'],
				'note' => (string)($entry['note'] ?? ''),
			];
			$total = ((float)($total ?? 0.0) + (float)$entry['amountEur']);
		}

		$budget = null;
		if (isset($campaign['budgetEur']) === true && is_numeric($campaign['budgetEur']) === true) {
			$budget = (float)$campaign['budgetEur'];
		}

		return ['budgetEur' => $budget, 'recorded' => $recorded, 'totalEur' => $total, 'currency' => 'EUR'];
	}//end cost()

	/**
	 * A channel row with nothing counted yet.
	 *
	 * @param string $channel The channel name.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function emptyChannel(string $channel): array {
		return [
			'channel' => $channel,
			'reach' => 0,
			'sent' => 0,
			'opened' => 0,
			'click' => 0,
			'visit' => 0,
			'submit' => 0,
			'reply' => 0,
			'attend' => 0,
			'reported' => false,
		];
	}//end emptyChannel()

	/**
	 * The report start: what was asked for, else the campaign's own start,
	 * else ninety days ago.
	 *
	 * @param array<string, mixed> $campaign The campaign.
	 * @param string|null          $from     Requested start.
	 *
	 * @return string The start, `YYYY-MM-DD`.
	 */
	private function startDay(array $campaign, ?string $from): string {
		$start = $this->day(value: $from);
		if ($start === '') {
			$start = $this->day(value: (string)($campaign['startsAt'] ?? ''));
		}

		if ($start === '') {
			$start = gmdate('Y-m-d', ($this->time->getTime() - (90 * 86400)));
		}

		return $start;
	}//end startDay()

	/**
	 * The requested report end, else the campaign's own end; '' when neither names one.
	 *
	 * @param array<string, mixed> $campaign The campaign.
	 * @param string|null          $to       Requested end.
	 *
	 * @return string The end, `YYYY-MM-DD`, or ''.
	 */
	private function endDay(array $campaign, ?string $to): string {
		$end = $this->day(value: $to);
		if ($end === '') {
			$end = $this->day(value: (string)($campaign['endsAt'] ?? ''));
		}

		return $end;
	}//end endDay()

	/**
	 * The report window, its end closed at today at the latest and its start not after its end.
	 *
	 * @param string $start The start, `YYYY-MM-DD`.
	 * @param string $end   The end, `YYYY-MM-DD`, or '' for today.
	 *
	 * @return array{from: string, to: string} Both `YYYY-MM-DD`.
	 */
	private function window(string $start, string $end): array {
		$today = gmdate('Y-m-d', $this->time->getTime());
		if ($end === '' || $end > $today) {
			$end = $today;
		}

		if ($start > $end) {
			$start = $end;
		}

		return ['from' => $start, 'to' => $end];
	}//end window()

	/**
	 * The date part of a value, when it is a usable calendar date.
	 *
	 * @param string|null $value The value.
	 *
	 * @return string `YYYY-MM-DD`, or an empty string.
	 */
	private function day(?string $value): string {
		if ($value === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim($value), $matches) !== 1) {
			return '';
		}

		if (checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1]) === false) {
			return '';
		}

		return ($matches[1] . '-' . $matches[2] . '-' . $matches[3]);
	}//end day()
}//end class
