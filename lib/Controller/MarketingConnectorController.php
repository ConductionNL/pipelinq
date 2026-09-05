<?php

/**
 * Pipelinq MarketingConnectorController.
 *
 * The two reads that sit next to the keyword and competitor pages: the Matomo
 * report, and the connection audit.
 *
 * Neither endpoint returns a secret and neither can: the Matomo token lives in
 * the credential broker and Pipelinq only ever holds a reference to it, and
 * the audit reads public follower lists.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
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

namespace OCA\Pipelinq\Controller;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Matomo\MatomoReportService;
use OCA\Pipelinq\Service\Social\ConnectionAuditService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The Matomo report and the connection audit.
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-matomo-is-read-through-a-source-with-the-token-as-a-credential-reference
 */
class MarketingConnectorController extends Controller {

	/**
	 * The window used when the caller names none.
	 *
	 * @var int
	 */
	public const DEFAULT_WINDOW_DAYS = 28;

	/**
	 * Constructor.
	 *
	 * @param string $appName App name.
	 * @param IRequest $request Request.
	 * @param MatomoReportService $matomo The Matomo reader.
	 * @param ConnectionAuditService $audit The connection audit.
	 * @param ITimeFactory $time Time factory for the default window.
	 * @param IUserSession $userSession Session.
	 * @param ObjectOwnerAccessPolicy $policy CRM privilege check.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-matomo-is-read-through-a-source-with-the-token-as-a-credential-reference
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly MatomoReportService $matomo,
		private readonly ConnectionAuditService $audit,
		private readonly ITimeFactory $time,
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The Matomo campaign, referrer and goal reports for a window.
	 *
	 * @param string|null $from Window start `YYYY-MM-DD`.
	 * @param string|null $to Window end `YYYY-MM-DD`.
	 *
	 * @return JSONResponse `{connected, reason, from, to, campaigns[], referrerTypes[], goals}`.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-three-matomo-reports-matched-to-the-campaigns-we-already-mint
	 */
	#[NoAdminRequired]
	public function matomo(?string $from = null, ?string $to = null): JSONResponse {
		$refusal = $this->refuseUnprivileged();
		if ($refusal !== null) {
			return $refusal;
		}

		[$start, $end] = $this->window(from: $from, to: $to);

		return new JSONResponse($this->matomo->report(from: $start, to: $end));
	}//end matomo()

	/**
	 * The stored connection audit, and optionally a fresh run of it.
	 *
	 * @param string $refresh `true` to re-run the audit before answering.
	 *
	 * @return JSONResponse `{rows[], summary}`.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-the-connection-audit-page-reads-one-collection-and-renders-the-reasons
	 */
	#[NoAdminRequired]
	public function connectionAudit(string $refresh = 'false'): JSONResponse {
		$refusal = $this->refuseUnprivileged();
		if ($refusal !== null) {
			return $refusal;
		}

		$summary = null;
		if ($refresh === 'true') {
			$summary = $this->audit->run();
		}

		return new JSONResponse(['rows' => $this->audit->rows(), 'summary' => $summary]);
	}//end connectionAudit()

	/**
	 * The window: valid explicit dates win, else the last 28 days.
	 *
	 * @param string|null $from Requested start.
	 * @param string|null $to Requested end.
	 *
	 * @return array{0: string, 1: string} from, to.
	 */
	private function window(?string $from, ?string $to): array {
		$today = (new DateTimeImmutable('@' . $this->time->getTime()))->setTimezone(new DateTimeZone('UTC'));
		$end = ($this->day(value: $to) ?? $today);
		$start = ($this->day(value: $from) ?? $end->modify('-' . self::DEFAULT_WINDOW_DAYS . ' days'));
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
	 * @return DateTimeImmutable|null UTC midnight, or null.
	 */
	private function day(?string $value): ?DateTimeImmutable {
		if ($value === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $matches) !== 1) {
			return null;
		}

		if (checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1]) === false) {
			return null;
		}

		return new DateTimeImmutable((trim($value) . 'T00:00:00'), new DateTimeZone('UTC'));
	}//end day()

	/**
	 * Refuse a caller without the CRM privilege.
	 *
	 * @return JSONResponse|null The refusal, or null when the caller may proceed.
	 */
	private function refuseUnprivileged(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end refuseUnprivileged()
}//end class
