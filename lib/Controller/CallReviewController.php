<?php

/**
 * Pipelinq CallReviewController.
 *
 * POST /api/call-reviews/{id}/coaching-points/{index}/agree: the agent whose
 * call was reviewed agrees one coaching point.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
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
 *
 * @spec openspec/changes/contact-moments-call-review/specs/call-review/spec.md#requirement-the-agent-agrees-the-coaching-points-req-crv-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Service\CallReview\CoachingPointAgreement;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The agent's Agree on a coaching point (task 2.4). The write goes through
 * OpenRegister as the caller, so the review's authorization block still decides;
 * the status follows on save in CallReviewSaveListener.
 *
 * @spec openspec/changes/contact-moments-call-review/specs/call-review/spec.md#requirement-the-agent-agrees-the-coaching-points-req-crv-003
 */
class CallReviewController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest               $request       The request.
	 * @param IUserSession           $userSession   The user session.
	 * @param IAppConfig             $appConfig     App config (register, schema).
	 * @param ObjectServiceInterface $objectService OpenRegister objects.
	 * @param CoachingPointAgreement $agreement     The agreement rule.
	 * @param ITimeFactory           $timeFactory   The clock.
	 * @param IL10N                  $l10n          Translations.
	 * @param LoggerInterface        $logger        The logger.
	 *
	 * @spec openspec/changes/contact-moments-call-review/specs/call-review/spec.md#requirement-the-agent-agrees-the-coaching-points-req-crv-003
	 */
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private IAppConfig $appConfig,
		private ObjectServiceInterface $objectService,
		private CoachingPointAgreement $agreement,
		private ITimeFactory $timeFactory,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Agree one coaching point of a review of the caller's own call.
	 *
	 * @param string $id    The review's id.
	 * @param int    $index The coaching point's position, from 0.
	 *
	 * @return JSONResponse The stored review, or a refusal.
	 *
	 * @spec openspec/changes/contact-moments-call-review/specs/call-review/spec.md#requirement-the-agent-agrees-the-coaching-points-req-crv-003
	 */
	#[NoAdminRequired]
	public function agree(string $id, int $index): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => $this->l10n->t('Log in to agree a coaching point.')], Http::STATUS_UNAUTHORIZED);
		}

		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'callReview_schema', '');
		$review = $this->loadReview(id: $id, register: $register, schema: $schema);
		if ($review === null) {
			return new JSONResponse(['message' => $this->l10n->t('This review was not found.')], Http::STATUS_NOT_FOUND);
		}

		$refusal = $this->agreement->refusal(review: $review, index: $index, userId: $user->getUID());
		if ($refusal !== null) {
			return $this->refused(code: $refusal);
		}

		$review['coachingPoints'] = $this->agreement->agree(
			points: (array)$review['coachingPoints'],
			index: $index,
			now: $this->timeFactory->now()->format(DATE_ATOM)
		);
		unset($review['@self']);

		try {
			$saved = $this->objectService->saveObject(object: $review, register: $register, schema: $schema, uuid: $id);
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: a coaching point could not be agreed', ['reviewId' => $id, 'exception' => $e->getMessage()]);
			return new JSONResponse(['message' => $this->l10n->t('The coaching point could not be agreed.')], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse((array)$saved->jsonSerialize());
	}//end agree()

	/**
	 * The review's data, or null when it cannot be read by the caller.
	 *
	 * @param string $id       The review's id.
	 * @param string $register The register id.
	 * @param string $schema   The call review schema id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function loadReview(string $id, string $register, string $schema): ?array {
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$found = $this->objectService->find(id: $id, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$found = null;
		}

		if ($found === null) {
			return null;
		}

		return (array)$found->getObject();
	}//end loadReview()

	/**
	 * The response for a refused agreement.
	 *
	 * @param string $code One of CoachingPointAgreement's REFUSED_* codes.
	 *
	 * @return JSONResponse
	 */
	private function refused(string $code): JSONResponse {
		if ($code === CoachingPointAgreement::REFUSED_NO_SUCH_POINT) {
			return new JSONResponse(['message' => $this->l10n->t('This coaching point was not found.')], Http::STATUS_NOT_FOUND);
		}

		if ($code === CoachingPointAgreement::REFUSED_NOT_SHARED) {
			return new JSONResponse(['message' => $this->l10n->t('This review has not been shared with you yet.')], Http::STATUS_CONFLICT);
		}

		return new JSONResponse(
			['message' => $this->l10n->t('Only the agent whose call was reviewed can agree a coaching point.')],
			Http::STATUS_FORBIDDEN
		);
	}//end refused()
}//end class
