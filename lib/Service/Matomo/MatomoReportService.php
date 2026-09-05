<?php

/**
 * Pipelinq MatomoReportService.
 *
 * Reads three Matomo reports for a window: campaigns, referrer types and
 * goals. It is the second analytics source of phase 5, and the first-party one:
 * a public-sector tenant that cannot put a third-party tracker on its site can
 * run Matomo itself and still see what happened after the click.
 *
 * NO TOKEN REACHES THIS CLASS. Rule 2 of the marketing architecture and
 * ADR-064 put every secret in the OpenRegister credential broker. What
 * Pipelinq holds is `matomo.credential_ref`, a credential UUID, and all it
 * ever reads from it is the STATUS, through {@see BrokerCredentialReader},
 * whose readable allow-list has no token field in it. The call itself leaves
 * through the OpenConnector source named by `matomo.source_id`, which is where
 * the credential is resolved and injected.
 *
 * The service refuses to call when that credential is not active. A dead grant
 * that is allowed to reach Matomo comes back as a 401 buried in a call log,
 * which reads as "Matomo is broken" rather than as "reconnect the account".
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Matomo
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
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-matomo-is-read-through-a-source-with-the-token-as-a-credential-reference
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Matomo;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\CampaignService;
use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use OCA\Pipelinq\Service\Egress\EgressResult;
use OCA\Pipelinq\Service\Social\BrokerCredentialReader;
use OCP\IAppConfig;

/**
 * Campaign, referrer and goal reports out of Matomo.
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-matomo-is-read-through-a-source-with-the-token-as-a-credential-reference
 */
class MatomoReportService {

	/**
	 * App-config key naming the OpenConnector source that reaches Matomo.
	 *
	 * @var string
	 */
	public const SOURCE_KEY = 'matomo.source_id';

	/**
	 * App-config key holding the brokered credential's UUID. Never a token.
	 *
	 * @var string
	 */
	public const CREDENTIAL_KEY = 'matomo.credential_ref';

	/**
	 * App-config key holding the Matomo site id.
	 *
	 * @var string
	 */
	public const SITE_KEY = 'matomo.site_id';

	/**
	 * The Matomo entry point, relative to the source's own location.
	 *
	 * @var string
	 */
	public const ENDPOINT = '/index.php';

	/**
	 * The three reports, by the name the response uses.
	 *
	 * Each is a READ. `Referrers.getCampaigns` is core Matomo rather than the
	 * MarketingCampaignsReporting plugin's own method, so a plain Matomo
	 * answers it. Matomo recognises `mtm_*` and `utm_*` alike, so the labels
	 * that come back are the campaign values Pipelinq already mints.
	 *
	 * @var array<string, string>
	 */
	public const REPORTS = [
		'campaigns' => 'Referrers.getCampaigns',
		'referrerTypes' => 'Referrers.getReferrerType',
		'goals' => 'Goals.get',
	];

	/**
	 * Constructor.
	 *
	 * @param ConnectorEgress $egress The single outbound seam.
	 * @param BrokerCredentialReader $credentials Reads a credential's status, never its secret.
	 * @param CampaignService $campaigns Our own campaigns, to match rows onto.
	 * @param IAppConfig $appConfig Pipelinq app config.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-matomo-is-read-through-a-source-with-the-token-as-a-credential-reference
	 */
	public function __construct(
		private ConnectorEgress $egress,
		private BrokerCredentialReader $credentials,
		private CampaignService $campaigns,
		private IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The three reports for a window, or the reason there are none.
	 *
	 * @param string $from Window start `YYYY-MM-DD`.
	 * @param string $to Window end `YYYY-MM-DD`, inclusive.
	 *
	 * @return array<string, mixed> `{connected, reason, from, to, campaigns[], referrerTypes[], goals}`.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-three-matomo-reports-matched-to-the-campaigns-we-already-mint
	 */
	public function report(string $from, string $to): array {
		$refusal = $this->refusal();
		if ($refusal !== null) {
			return ($refusal + ['from' => $from, 'to' => $to, 'campaigns' => [], 'referrerTypes' => [], 'goals' => []]);
		}

		$out = ['connected' => true, 'reason' => '', 'from' => $from, 'to' => $to];
		foreach (self::REPORTS as $name => $method) {
			$out[$name] = $this->fetch(method: $method, from: $from, to: $to);
		}

		$out['campaigns'] = $this->matchCampaigns(rows: (array)$out['campaigns']);

		return $out;
	}//end report()

	/**
	 * Why this read cannot happen, or null when it can.
	 *
	 * @return array{connected: bool, reason: string, failure: string}|null
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-matomo-is-read-through-a-source-with-the-token-as-a-credential-reference
	 */
	public function refusal(): ?array {
		if ($this->egress->isConfigured(configKey: self::SOURCE_KEY) === false) {
			return [
				'connected' => false,
				'failure' => EgressResult::NOT_CONFIGURED,
				'reason' => 'No Matomo source is configured under ' . self::SOURCE_KEY . '.',
			];
		}

		$credentialRef = $this->credentialRef();
		if ($credentialRef === '') {
			return [
				'connected' => false,
				'failure' => EgressResult::NOT_CONFIGURED,
				'reason' => 'No credential is referenced under ' . self::CREDENTIAL_KEY . '.',
			];
		}

		$status = $this->credentials->status(credentialRef: $credentialRef);
		if ($status === '') {
			return [
				'connected' => false,
				'failure' => EgressResult::NOT_CONFIGURED,
				'reason' => 'The referenced credential could not be read from the broker.',
			];
		}

		if ($status !== 'active') {
			return [
				'connected' => false,
				'failure' => 'relink_needed',
				'reason' => 'The Matomo credential is ' . $status . '. Reconnect it in the credential broker.',
			];
		}

		return null;
	}//end refusal()

	/**
	 * The configured credential reference.
	 *
	 * @return string The UUID, or an empty string.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-matomo-is-read-through-a-source-with-the-token-as-a-credential-reference
	 */
	public function credentialRef(): string {
		return trim($this->appConfig->getValueString(Application::APP_ID, self::CREDENTIAL_KEY, ''));
	}//end credentialRef()

	/**
	 * Whether a value written into the credential reference field is in fact
	 * a Matomo token. Matomo's `token_auth` is 32 hexadecimal characters, and
	 * pasting one here is the single most likely way rule 2 gets broken.
	 *
	 * @param string $value The value being written.
	 *
	 * @return bool True when it looks like a token rather than a reference.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-matomo-is-read-through-a-source-with-the-token-as-a-credential-reference
	 */
	public static function looksLikeAToken(string $value): bool {
		return (preg_match('/^[0-9a-fA-F]{32}$/', trim($value)) === 1);
	}//end looksLikeAToken()

	/**
	 * One report, as rows.
	 *
	 * @param string $method The Matomo API method.
	 * @param string $from Window start.
	 * @param string $to Window end.
	 *
	 * @return array<int|string, mixed> The rows, or an empty list.
	 */
	private function fetch(string $method, string $from, string $to): array {
		$result = $this->egress->read(
			configKey: self::SOURCE_KEY,
			endpoint: self::ENDPOINT,
			config: [
				'query' => [
					'module' => 'API',
					'method' => $method,
					'idSite' => $this->siteId(),
					'period' => 'range',
					'date' => ($from . ',' . $to),
					'format' => 'JSON',
					'filter_limit' => '100',
				],
			],
		);

		$decoded = $result->json();
		if ($decoded === null) {
			return [];
		}

		return $decoded;
	}//end fetch()

	/**
	 * Match Matomo's campaign rows onto the campaigns Pipelinq mints.
	 *
	 * @param array<int|string, mixed> $rows The rows Matomo returned.
	 *
	 * @return array<int, array<string, mixed>> The rows, each marked matched or not.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-three-matomo-reports-matched-to-the-campaigns-we-already-mint
	 */
	private function matchCampaigns(array $rows): array {
		$ours = [];
		foreach ($this->campaigns->all() as $campaign) {
			$value = mb_strtolower(trim((string)($campaign['utmCampaign'] ?? '')), 'UTF-8');
			if ($value !== '') {
				$ours[$value] = $campaign;
			}
		}

		$out = [];
		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$label = trim((string)($row['label'] ?? ''));
			$key = mb_strtolower($label, 'UTF-8');
			$match = ($ours[$key] ?? null);
			$out[] = [
				'campaign' => $label,
				'visits' => (int)($row['nb_visits'] ?? 0),
				'actions' => (int)($row['nb_actions'] ?? 0),
				'matched' => ($match !== null),
				'campaignId' => (string)($match['id'] ?? ($match['@self']['id'] ?? '')),
				'campaignName' => (string)($match['name'] ?? ''),
			];
		}

		return $out;
	}//end matchCampaigns()

	/**
	 * The configured Matomo site id, defaulting to the first site.
	 *
	 * @return string
	 */
	private function siteId(): string {
		$value = trim($this->appConfig->getValueString(Application::APP_ID, self::SITE_KEY, ''));
		if ($value === '') {
			return '1';
		}

		return $value;
	}//end siteId()
}//end class
