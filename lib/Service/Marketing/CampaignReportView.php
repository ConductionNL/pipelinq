<?php

/**
 * Pipelinq CampaignReportView.
 *
 * The campaign report as the report page draws it: the five figures, one row
 * per channel, the attributed value per channel under one attribution model,
 * and one row per lead.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

/**
 * Shapes a campaign report into the rows the report page's widgets read.
 *
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
 */
class CampaignReportView {
	/**
	 * The attribution models a report carries.
	 *
	 * @var array<int, string>
	 */
	public const MODELS = ['first', 'last', 'linear'];

	/**
	 * The report's rows.
	 *
	 * @param array<string, mixed> $report The report, as CampaignReportService answers it.
	 * @param string               $model  The attribution model; '' or an unknown one is the campaign's own.
	 *
	 * @return array<string, mixed> tiles, channelRows, model, modelRows and leadRows.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
	 */
	public function rows(array $report, string $model): array {
		if (in_array($model, self::MODELS, true) === false) {
			$model = (string)($report['campaign']['defaultModel'] ?? 'last');
		}

		$engagement = (array)($report['engagement'] ?? []);
		$cost = (array)($report['cost'] ?? []);

		return [
			'tiles' => [
				'clicks' => (int)($engagement['click'] ?? 0),
				'submissions' => (int)($engagement['submit'] ?? 0),
				'leads' => (int)($report['totals']['leads'] ?? 0),
				'attributedValue' => (float)($report['totals']['attributedValue'] ?? 0),
				'cost' => $cost['totalEur'] ?? null,
			],
			'channelRows' => $this->channelRows(channels: (array)($report['channels'] ?? [])),
			'model' => $model,
			'modelRows' => $this->modelRows(split: (array)($report['models'][$model] ?? [])),
			'leadRows' => $this->leadRows(leads: (array)($report['leads'] ?? [])),
		];
	}//end rows()

	/**
	 * One row per channel: reach (null when not recorded), opened, clicks, visits, submissions.
	 *
	 * @param array<int, array<string, mixed>> $channels The report's channels.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function channelRows(array $channels): array {
		$rows = [];
		foreach ($channels as $row) {
			$rows[] = [
				'channel' => (string)($row['channel'] ?? ''),
				'reach' => $row['reach'] ?? null,
				'opened' => (int)($row['opened'] ?? 0),
				'clicks' => (int)($row['click'] ?? 0),
				'visits' => (int)($row['visit'] ?? 0),
				'submissions' => (int)($row['submit'] ?? 0),
			];
		}

		return $rows;
	}//end channelRows()

	/**
	 * The attributed value per channel under one model, largest first, with its share in percent.
	 *
	 * @param array<string, mixed> $split The model's `byChannel` and `total`.
	 *
	 * @return array<int, array{channel: string, value: float, sharePercent: float}> The rows.
	 */
	private function modelRows(array $split): array {
		$total = (float)($split['total'] ?? 0);
		$rows = [];
		foreach ((array)($split['byChannel'] ?? []) as $channel => $value) {
			$share = 0.0;
			if ($total > 0) {
				$share = round(((float)$value / $total) * 100, 1);
			}

			$rows[] = ['channel' => (string)$channel, 'value' => (float)$value, 'sharePercent' => $share];
		}

		usort($rows, static fn (array $a, array $b): int => [$b['value'], $a['channel']] <=> [$a['value'], $b['channel']]);
		return $rows;
	}//end modelRows()

	/**
	 * One row per lead: its title, what it closed on and its value.
	 *
	 * @param array<int, array<string, mixed>> $leads The report's leads.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function leadRows(array $leads): array {
		$rows = [];
		foreach ($leads as $lead) {
			$id = (string)($lead['leadId'] ?? '');
			$title = (string)($lead['title'] ?? '');
			if ($title === '') {
				$title = $id;
			}

			$rows[] = [
				'leadId' => $id,
				'title' => $title,
				'basis' => (string)($lead['basis'] ?? ''),
				'value' => (float)($lead['value'] ?? 0),
			];
		}

		return $rows;
	}//end leadRows()
}//end class
