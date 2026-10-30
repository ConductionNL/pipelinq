<?php

/**
 * Pipelinq ClientRiskPresenter.
 *
 * Turns a stored `churnRisk` into what a reader sees: the level in words and
 * one line per reason, in the viewer's language. The stored reasons hold
 * signal codes, never text, so the same record reads in Dutch and English.
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
 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-every-client-carries-a-risk-level-with-its-reasons-req-cin-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use OCP\IL10N;
use Throwable;

/**
 * Churn risk in the viewer's words.
 *
 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-every-client-carries-a-risk-level-with-its-reasons-req-cin-001
 */
class ClientRiskPresenter {

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n The viewer's translations.
	 */
	public function __construct(
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The presented risk: `level`, `label`, `caption` (the heaviest reason,
	 * or a calm line when there is none) and the `reasons`, each with its
	 * `label`. A client not assessed yet has a null level.
	 *
	 * @param mixed $churnRisk The client's stored `churnRisk`.
	 *
	 * @return array{level: string|null, label: string, caption: string, reasons: list<array<string, mixed>>, computedAt: string|null}
	 *
	 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-every-client-carries-a-risk-level-with-its-reasons-req-cin-001
	 */
	public function present(mixed $churnRisk): array {
		if (is_array($churnRisk) === false || in_array((string)($churnRisk['level'] ?? ''), ['low', 'medium', 'high'], true) === false) {
			return [
				'level' => null,
				'label' => $this->l10n->t('Not worked out yet'),
				'caption' => '',
				'reasons' => [],
				'computedAt' => null,
			];
		}

		$reasons = [];
		foreach ((array)($churnRisk['reasons'] ?? []) as $reason) {
			if (is_array($reason) === true) {
				$reason['label'] = $this->reasonLabel(reason: $reason);
				$reasons[] = $reason;
			}
		}

		usort($reasons, static fn (array $left, array $right): int => (int)($right['points'] ?? 0) <=> (int)($left['points'] ?? 0));

		$caption = $this->l10n->t('No warning signs');
		if ($reasons !== []) {
			$caption = (string)$reasons[0]['label'];
		}

		$level = (string)$churnRisk['level'];
		return [
			'level' => $level,
			'label' => $this->levelLabel(level: $level),
			'caption' => $caption,
			'reasons' => $reasons,
			'computedAt' => self::stringOrNull(value: ($churnRisk['computedAt'] ?? null)),
		];
	}//end present()

	/**
	 * The level in words.
	 *
	 * @param string $level low, medium or high.
	 *
	 * @return string
	 */
	private function levelLabel(string $level): string {
		return match ($level) {
			'high' => $this->l10n->t('High'),
			'medium' => $this->l10n->t('Medium'),
			default => $this->l10n->t('Low'),
		};
	}//end levelLabel()

	/**
	 * One reason in words.
	 *
	 * @param array<string, mixed> $reason The stored reason.
	 *
	 * @return string
	 */
	private function reasonLabel(array $reason): string {
		$date = $this->date(raw: ($reason['since'] ?? null));
		$count = (int)($reason['count'] ?? 1);
		$signal = (string)($reason['signal'] ?? '');

		if ($date !== '') {
			$dated = match ($signal) {
				'contract-expiring' => $this->l10n->t('Contract ends on %s', [$date]),
				'contract-churned' => $this->l10n->t('Contract ended on %s', [$date]),
				'detractor' => $this->l10n->t('Unhappy survey answer on %s', [$date]),
				'silence' => $this->l10n->t('No contact since %s', [$date]),
				default => null,
			};
			if ($dated !== null) {
				return $dated;
			}
		}

		return match ($signal) {
			'contract-expiring' => $this->l10n->t('Contract ends soon'),
			'contract-churned' => $this->l10n->t('Contract ended'),
			'detractor' => $this->l10n->t('Unhappy survey answer'),
			'open-complaints' => $this->l10n->n('%n open complaint', '%n open complaints', $count),
			'sla-breached' => $this->l10n->n('%n deadline passed', '%n deadlines passed', $count),
			'silence' => $this->l10n->t('No contact for a long time'),
			default => $this->l10n->t('Other signal'),
		};
	}//end reasonLabel()

	/**
	 * A string value, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private static function stringOrNull(mixed $value): ?string {
		if (is_scalar($value) === false) {
			return null;
		}

		return (string)$value;
	}//end stringOrNull()

	/**
	 * A stored date in the viewer's short date format, or ''.
	 *
	 * @param mixed $raw The stored date.
	 *
	 * @return string
	 */
	private function date(mixed $raw): string {
		if (is_string($raw) === false || $raw === '') {
			return '';
		}

		try {
			return (string)$this->l10n->l('date', new DateTimeImmutable($raw), ['width' => 'short']);
		} catch (Throwable) {
			return '';
		}
	}//end date()
}//end class
