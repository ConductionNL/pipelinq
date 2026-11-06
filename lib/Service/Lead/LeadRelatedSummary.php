<?php

/**
 * Pipelinq LeadRelatedSummary.
 *
 * What the lead page's Related card says about a lead beyond its related
 * objects: the client's earlier deals and how they ended, and the hours
 * written on the lead.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Lead
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/lead-management/spec.md#requirement-the-related-card-names-earlier-deals-and-hours
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Lead;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IL10N;

/**
 * The lead's earlier deals with the same client and the hours on it.
 *
 * @spec openspec/specs/lead-management/spec.md#requirement-the-related-card-names-earlier-deals-and-hours
 */
class LeadRelatedSummary {

	/**
	 * How many rows a summary reads at most.
	 */
	private const LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig             $appConfig     App config (register, schemas).
	 * @param IL10N                  $l10n          Writes the sentences.
	 * @param ObjectServiceInterface $objectService OpenRegister objects.
	 *
	 * @return void
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private IL10N $l10n,
		private ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * The summary of a lead: `earlier` ("2 earlier deals, both won"; '' when
	 * the client has none) and `hours` ("14.5"; '' when none are written).
	 *
	 * @param array<string, mixed> $lead The lead.
	 *
	 * @return array{earlier: string, hours: string} The summary.
	 *
	 * @spec openspec/specs/lead-management/spec.md#requirement-the-related-card-names-earlier-deals-and-hours
	 */
	public function forLead(array $lead): array {
		return [
			'earlier' => $this->earlierDeals(lead: $lead),
			'hours'   => $this->hours(leadId: (string)($lead['id'] ?? '')),
		];
	}//end forLead()

	/**
	 * The client's other closed deals and how they ended.
	 *
	 * @param array<string, mixed> $lead The lead.
	 *
	 * @return string The sentence, or ''.
	 */
	private function earlierDeals(array $lead): string {
		$clientId = (string)($lead['client'] ?? '');
		if ($clientId === '') {
			return '';
		}

		$won  = 0;
		$lost = 0;
		foreach ($this->find(schemaKey: 'lead_schema', filters: ['client' => $clientId]) as $other) {
			if ((string)($other['id'] ?? '') === (string)($lead['id'] ?? '')) {
				continue;
			}

			$status = (string)($other['status'] ?? '');
			if ($status === 'won') {
				$won++;
			} else if ($status === 'lost') {
				$lost++;
			}
		}

		$total = ($won + $lost);
		if ($total === 0) {
			return '';
		}

		$count = $this->l10n->n('%n earlier deal', '%n earlier deals', $total);
		if ($won === 0 || $lost === 0) {
			return $count . ', ' . $this->allEnded(total: $total, won: $lost === 0);
		}

		return $this->l10n->t('%1$s: %2$s won, %3$s lost', [$count, $won, $lost]);
	}//end earlierDeals()

	/**
	 * How deals that all ended alike ended: "won", "both won", "all won".
	 *
	 * @param int  $total How many deals.
	 * @param bool $won   Whether they were won (else lost).
	 *
	 * @return string The words.
	 */
	private function allEnded(int $total, bool $won): string {
		$outcome = 'lost';
		if ($won === true) {
			$outcome = 'won';
		}

		$words = [
			1 => ['won' => $this->l10n->t('won'), 'lost' => $this->l10n->t('lost')],
			2 => ['won' => $this->l10n->t('both won'), 'lost' => $this->l10n->t('both lost')],
			3 => ['won' => $this->l10n->t('all won'), 'lost' => $this->l10n->t('all lost')],
		];

		return ($words[$total] ?? $words[3])[$outcome];
	}//end allEnded()

	/**
	 * The hours written on the lead, or '' when none are.
	 *
	 * @param string $leadId The lead's id.
	 *
	 * @return string The hours.
	 */
	private function hours(string $leadId): string {
		if ($leadId === '') {
			return '';
		}

		$total = 0.0;
		foreach ($this->find(schemaKey: 'timeEntry_schema', filters: ['lead' => $leadId]) as $entry) {
			$total += (float)($entry['hours'] ?? 0);
		}

		if ($total <= 0.0) {
			return '';
		}

		return rtrim(rtrim(number_format($total, 2, '.', ''), '0'), '.');
	}//end hours()

	/**
	 * Objects of a configured schema matching the filters, as arrays.
	 *
	 * @param string               $schemaKey The app-config key of the schema.
	 * @param array<string, mixed> $filters   Equality filters.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 */
	private function find(string $schemaKey, array $filters): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema   = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
		if ($register === '' || $schema === '') {
			return [];
		}

		$rows = [];
		$config = [
			'filters' => array_merge(['register' => $register, 'schema' => $schema], $filters),
			'limit'   => self::LIMIT,
		];
		foreach ($this->objectService->findAll(config: $config) as $result) {
			if (is_array($result) === true) {
				$rows[] = $result;
			} else if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
				$rows[] = (array)$result->jsonSerialize();
			}
		}

		return $rows;
	}//end find()
}//end class
