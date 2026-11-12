<?php

/**
 * Pipelinq KeywordIntelligenceController.
 *
 * The read side of the keyword derivations, and the single write that turns a
 * proposal into a `keywordTarget`.
 *
 * ONE READ SERVES THE PAGE. The Keywords page renders four sections and every
 * one of them comes out of this single response. Fanning out one request per
 * proposal before the page paints is the failure pipelinq#1781 already removed
 * from the blast performance page, and the derivations all run over the same
 * rows anyway.
 *
 * Authentication is not authorization: search demand is marketing data, a CRM
 * capability, so the same privilege check the blast and Search Console
 * endpoints apply is applied here.
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
 * @spec openspec/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
use OCA\Pipelinq\Service\Search\KeywordProposals;
use OCA\Pipelinq\Service\Search\KeywordTargetService;
use OCA\Pipelinq\Util\ReadsTypedRequestParameters;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use UnexpectedValueException;

/**
 * Keyword proposals, and the confirmation that turns one into a target.
 *
 * @spec openspec/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
 */
class KeywordIntelligenceController extends Controller {
	use ReadsTypedRequestParameters;

	/**
	 * App-config key overriding the impression floor every derivation uses.
	 *
	 * @var string
	 */
	public const FLOOR_KEY = KeywordProposals::FLOOR_KEY;

	/**
	 * The request parameters of a proposals read.
	 *
	 * @var array<string, array{0: string, 1: mixed}>
	 */
	private const WINDOW_PARAMETERS = [
		'from' => ['?string', null],
		'to' => ['?string', null],
		'property' => ['?string', null],
	];

	/**
	 * The request parameters of a confirmation, in the stored payload's order.
	 *
	 * @var array<string, array{0: string, 1: mixed}>
	 */
	private const CONFIRM_PARAMETERS = [
		'term' => ['string', ''],
		'status' => ['string', 'watch'],
		'proposalKind' => ['string', 'manual'],
		'intent' => ['string', ''],
		'targetPageRef' => ['string', ''],
		'property' => ['string', ''],
		'notes' => ['string', ''],
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request Request.
	 * @param KeywordProposals $proposals The derivations for a window.
	 * @param KeywordTargetService $targets The single write path.
	 * @param CrmPrivilegeCheck $privilege The session user and their CRM privilege.
	 *
	 * @spec openspec/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
	 */
	public function __construct(
		IRequest $request,
		private readonly KeywordProposals $proposals,
		private readonly KeywordTargetService $targets,
		private readonly CrmPrivilegeCheck $privilege,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Every derivation for a window, in one response.
	 *
	 * Reads `from` and `to` (`YYYY-MM-DD`) and `property` from the request; a
	 * non-string value is a bare 400, as the dispatcher answers it.
	 *
	 * @return Response `{from, to, buckets[], strikingDistance[], cannibalisation[], gaps[], crawl, confirmedTerms[]}`.
	 *
	 * @spec openspec/specs/marketing-keyword-intelligence/spec.md#requirement-queries-are-grouped-into-position-buckets
	 */
	#[NoAdminRequired]
	public function proposals(): Response {
		$window = $this->typedRequestParameters(spec: self::WINDOW_PARAMETERS);
		if ($window === null) {
			return new Response(Http::STATUS_BAD_REQUEST);
		}

		$refusal = $this->privilege->refusal();
		if ($refusal !== null) {
			return $refusal;
		}

		$body = $this->proposals->forWindow(window: $window);
		$body['confirmedTerms'] = $this->targets->confirmedTerms();
		return new JSONResponse($body);
	}//end proposals()

	/**
	 * The keyword targets already confirmed.
	 *
	 * @return JSONResponse `{targets[]}`.
	 *
	 * @spec openspec/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
	 */
	#[NoAdminRequired]
	public function targets(): JSONResponse {
		$refusal = $this->privilege->refusal();
		if ($refusal !== null) {
			return $refusal;
		}

		return new JSONResponse(['targets' => $this->targets->all()]);
	}//end targets()

	/**
	 * Confirm a proposal into a keyword target.
	 *
	 * Reads `term`, `status` (`use-more`, `use-less` or `watch`), `proposalKind`,
	 * `intent`, `targetPageRef`, `property` and `notes` from the request; a
	 * non-string value is a bare 400, as the dispatcher answers it.
	 *
	 * @return Response The stored target, or the refusal.
	 *
	 * @spec openspec/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
	 */
	#[NoAdminRequired]
	public function confirm(): Response {
		$payload = $this->typedRequestParameters(spec: self::CONFIRM_PARAMETERS);
		if ($payload === null) {
			return new Response(Http::STATUS_BAD_REQUEST);
		}

		$refusal = $this->privilege->refusal();
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$saved = $this->targets->confirm(payload: $payload, uid: $this->privilege->actingUserId());
		} catch (UnexpectedValueException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		if ($saved === null) {
			return new JSONResponse(['error' => 'The keyword target could not be stored.'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse($saved, Http::STATUS_CREATED);
	}//end confirm()
}//end class
