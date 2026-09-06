<?php

/**
 * Pipelinq SocialMetricsService.
 *
 * What a published post did, pulled once a day and normalised to five numbers.
 *
 * THE NORMALISATION IS NOT COSMETIC. Meta and LinkedIn withdrew reach and
 * impressions in June 2026. A report built on those names would simply have
 * gone blank one morning with nothing to say why. So every network's payload
 * is reduced to views, likes, comments, shares and clicks, and the untouched
 * payload is kept beside it: when a network changes what it reports, the
 * normalisation can be recomputed from stored data instead of from a pull that
 * can no longer be made.
 *
 * A NUMBER A NETWORK DOES NOT REPORT STAYS ZERO. Mastodon publishes no view
 * count and LinkedIn no longer publishes impressions, and neither is inferred
 * from something adjacent. A zero that means "not reported" is honest; a
 * borrowed number is a measurement that looks real and is not.
 *
 * THE RANKING DIVIDES BY FOLLOWERS. Comparing a company page with 900
 * followers against a spokesperson with 4,000 on raw counts answers the wrong
 * question, so the ranking is engagement over the follower count the same pull
 * recorded. An account with no followers recorded shows no rate rather than an
 * error or an infinity, and a publication with no numbers yet sorts last
 * rather than disappearing.
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
 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Social\SocialAdapterCall;
use OCA\Pipelinq\Service\Social\SocialAdapterRegistry;
use OCA\Pipelinq\Service\Social\SocialBrokerGateway;
use OCA\Pipelinq\Service\Social\SocialGatewayResult;
use OCA\Pipelinq\Service\Social\SocialNetworkAdapter;
use OCA\Pipelinq\Service\Social\SocialPublicationStore;
use OCP\IAppConfig;

/**
 * The daily metrics pull, the normalisation and the engagement ranking.
 *
 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The pull is a join across
 *  publications, accounts, adapters, the broker and the spend budget; each is
 *  consulted once per row and none of them is incidental.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) `SocialGatewayResult` and
 *  `SocialPublishOutcome` are value objects with NAMED CONSTRUCTORS
 *  (`succeeded`, `failed`, `published`, `refused`). Those are static by
 *  definition, and the alternative PHPMD is asking for, a factory injected
 *  into every adapter, would add a collaborator that constructs a struct.
 */
class SocialMetricsService {
	/**
	 * Constructor.
	 *
	 * @param SocialPublicationStore $publications The per-account result rows.
	 * @param SocialAccountService $accounts The connected accounts.
	 * @param SocialAdapterRegistry $registry The network adapters.
	 * @param SocialBrokerGateway $gateway The brokered egress seam.
	 * @param BudgetService $budget The per-tenant spend budget, for the network that charges to read.
	 * @param IAppConfig $appConfig App config, for the tenant id.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SocialPublicationStore $publications,
		private readonly SocialAccountService $accounts,
		private readonly SocialAdapterRegistry $registry,
		private readonly SocialBrokerGateway $gateway,
		private readonly BudgetService $budget,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Pull every published publication's numbers, and every account's follower
	 * count.
	 *
	 * One row failing never stops the rest: the loop records what it could and
	 * leaves the rest alone, because a pull that aborts on the first dead grant
	 * would leave a whole morning's report empty for one bad account.
	 *
	 * @return array{publications: int, accounts: int, failed: int} What the run did.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 */
	public function pullAll(): array {
		$updated = 0;
		$failed = 0;

		foreach ($this->publications->findAll(filters: ['status' => SocialPublicationStore::PUBLISHED]) as $publication) {
			if ($this->pullOne(publication: $publication) === true) {
				$updated++;
				continue;
			}

			$failed++;
		}

		return [
			'publications' => $updated,
			'accounts' => $this->refreshFollowerCounts(),
			'failed' => $failed,
		];
	}//end pullAll()

	/**
	 * Read one publication's numbers back and store them.
	 *
	 * @param array<string, mixed> $publication The publication row.
	 *
	 * @return bool True when fresh numbers were stored.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 */
	public function pullOne(array $publication): bool {
		$externalId = trim((string)($publication['externalId'] ?? ''));
		$accountId = (string)($publication['accountId'] ?? '');
		$account = $this->accounts->getAccount(accountId: $accountId);
		if ($externalId === '' || $account === null) {
			return false;
		}

		$adapter = $this->registry->forNetwork(network: (string)($account['network'] ?? ''));
		if ($adapter === null) {
			return false;
		}

		$call = $adapter->metricsCall(externalId: $externalId, account: $account);
		if ($call === null) {
			// This network reports nothing Pipelinq can reach. That is not a
			// failure and it does not clear the numbers already stored.
			return false;
		}

		$result = $this->read(call: $call, adapter: $adapter, account: $account);
		if ($result->accepted === false) {
			if ($result->failureCode === SocialGatewayResult::RELINK_NEEDED) {
				$this->accounts->markRelinkNeeded(accountId: $accountId, reason: $result->failureReason);
			}

			// The previous numbers are kept. An unreadable pull is not a drop
			// to zero, which would read as a post that lost its engagement.
			return false;
		}

		$publication['metrics'] = $adapter->normaliseMetrics(payload: $result->body, externalId: $externalId);
		$publication['rawMetrics'] = $result->body;
		$publication['metricsAt'] = gmdate('Y-m-d\TH:i:s\Z');
		$publication['cost'] = ((float)($publication['cost'] ?? 0) + $adapter->costPerRead());

		return ($this->publications->save(publication: $publication) !== null);
	}//end pullOne()

	/**
	 * Refresh the follower count on every connected account that can report one.
	 *
	 * @return int How many accounts were updated.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 */
	public function refreshFollowerCounts(): int {
		$updated = 0;

		foreach ($this->accounts->activeAccounts() as $account) {
			$adapter = $this->registry->forNetwork(network: (string)($account['network'] ?? ''));
			$call = $adapter?->followersCall(account: $account);
			if ($adapter === null || $call === null) {
				continue;
			}

			$result = $this->read(call: $call, adapter: $adapter, account: $account);
			if ($result->accepted === false) {
				continue;
			}

			$this->accounts->recordFollowerCount(
				accountId: (string)($account['id'] ?? $account['uuid'] ?? ''),
				followers: $adapter->readFollowers(payload: $result->body),
			);
			$updated++;
		}

		return $updated;
	}//end refreshFollowerCounts()

	/**
	 * Publications ranked by engagement rate, highest first.
	 *
	 * The follower count comes off the account row the same pull recorded, so
	 * the page reads publications once and never walks them to fetch their
	 * accounts one at a time (pipelinq#1781).
	 *
	 * @param string $network A network to limit the ranking to, or an empty string for all.
	 * @param int $limit How many rows to return.
	 *
	 * @return array<int, array<string, mixed>> The ranked rows.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-posts-are-ranked-by-engagement-rate-per-network
	 */
	public function ranking(string $network = '', int $limit = 50): array {
		$followers = [];
		foreach ($this->accounts->listAccounts()['data'] as $account) {
			$id = (string)($account['id'] ?? $account['uuid'] ?? '');
			$followers[$id] = (int)($account['followerCount'] ?? 0);
		}

		$rows = [];
		foreach ($this->publications->findAll() as $publication) {
			if ($network !== '' && (string)($publication['network'] ?? '') !== $network) {
				continue;
			}

			$rows[] = $this->rankRow(publication: $publication, followers: $followers);
		}

		usort(
			$rows,
			static function (array $left, array $right): int {
				// A row with no rate sorts last rather than being dropped: it
				// was published, it just has nothing to say yet.
				$leftRate = ($left['engagementRate'] ?? -1.0);
				$rightRate = ($right['engagementRate'] ?? -1.0);

				return ($rightRate <=> $leftRate);
			}
		);

		return array_slice($rows, 0, max(1, $limit));
	}//end ranking()

	/**
	 * One ranking row: the publication, its engagement and its rate.
	 *
	 * @param array<string, mixed> $publication The publication row.
	 * @param array<string, int> $followers Follower counts by account id.
	 *
	 * @return array<string, mixed> The ranking row.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-posts-are-ranked-by-engagement-rate-per-network
	 */
	public function rankRow(array $publication, array $followers): array {
		$metrics = ($publication['metrics'] ?? []);
		if (is_array($metrics) === false) {
			$metrics = [];
		}

		$engagement = (
			(int)($metrics['likes'] ?? 0)
			+ (int)($metrics['comments'] ?? 0)
			+ (int)($metrics['shares'] ?? 0)
		);
		$accountId = (string)($publication['accountId'] ?? '');
		$audience = (int)($followers[$accountId] ?? 0);

		// No followers recorded means no rate. Dividing anyway would produce an
		// infinity that sorts to the top of every ranking it appears in.
		$rate = null;
		if ($audience > 0) {
			$rate = round(($engagement / $audience), 6);
		}

		return [
			'publicationId' => $this->publications->idOf(publication: $publication),
			'postId' => (string)($publication['postId'] ?? ''),
			'accountId' => $accountId,
			'network' => (string)($publication['network'] ?? ''),
			'url' => (string)($publication['url'] ?? ''),
			'publishedAt' => (string)($publication['publishedAt'] ?? ''),
			'metrics' => $metrics,
			'engagement' => $engagement,
			'followerCount' => $audience,
			'engagementRate' => $rate,
		];
	}//end rankRow()

	/**
	 * Make one metered read through the broker.
	 *
	 * The spend budget is asked BEFORE the call for the network that charges to
	 * read, so an exhausted hard stop costs nothing at all rather than one more
	 * read than it should have.
	 *
	 * @param SocialAdapterCall $call The request.
	 * @param SocialNetworkAdapter $adapter The adapter, for its read cost.
	 * @param array<string, mixed> $account The account, for its credential and owner.
	 *
	 * @return SocialGatewayResult What came back.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-to-x-stops-at-the-tenants-spend-budget
	 */
	private function read(SocialAdapterCall $call, SocialNetworkAdapter $adapter, array $account): SocialGatewayResult {
		$cost = $adapter->costPerRead();
		if ($cost > 0.0) {
			$allowed = $this->budget->canSend(
				tenantId: $this->tenantId(),
				providerId: $adapter->network(),
				estimatedCostEur: $cost,
			);
			if ($allowed === false) {
				return SocialGatewayResult::failed(
					code: SocialGatewayResult::BUDGET_EXHAUSTED,
					reason: 'The spend budget for ' . $adapter->network() . ' is reached, so nothing was read.',
				);
			}
		}

		$owner = trim((string)($account['ownerUserId'] ?? ''));
		$acting = null;
		if ($owner !== '') {
			$acting = $owner;
		}

		$result = $this->gateway->request(
			credentialRef: (string)($account['credentialRef'] ?? ''),
			method: $call->method,
			path: $call->path,
			headers: $call->requestHeaders(),
			body: $call->body(),
			actingUserId: $acting,
		);

		if ($cost > 0.0 && $result->accepted === true) {
			$this->budget->recordSend(
				tenantId: $this->tenantId(),
				providerId: $adapter->network(),
				costEur: $cost,
			);
		}

		return $result;
	}//end read()

	/**
	 * The tenant the spend budget is kept for, matching every other metered
	 * channel in this app.
	 *
	 * @return string The tenant id.
	 */
	private function tenantId(): string {
		$tenantId = $this->appConfig->getValueString(Application::APP_ID, 'tenant_id', '');
		if ($tenantId !== '') {
			return $tenantId;
		}

		return 'default';
	}//end tenantId()
}//end class
