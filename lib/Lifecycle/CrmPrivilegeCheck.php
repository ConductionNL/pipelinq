<?php

/**
 * Pipelinq CrmPrivilegeCheck.
 *
 * Whether the session user holds the CRM privilege, answered as the refusal a
 * controller returns.
 *
 * @category Lifecycle
 * @package  OCA\Pipelinq\Lifecycle
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

namespace OCA\Pipelinq\Lifecycle;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;

/**
 * A caller without a session (no user, or one without a UID) is answered 401, a caller without the CRM
 * privilege ({@see ObjectOwnerAccessPolicy::isPrivileged()}) 403.
 *
 * @spec openspec/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
 */
class CrmPrivilegeCheck {
	/**
	 * Constructor.
	 *
	 * @param IUserSession            $userSession The user session.
	 * @param ObjectOwnerAccessPolicy $policy      The CRM privilege check.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
	) {
	}//end __construct()

	/**
	 * The refusal for the session user, or null when they may proceed.
	 *
	 * @return JSONResponse|null
	 *
	 * @spec openspec/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
	 */
	public function refusal(): ?JSONResponse {
		return $this->refusalWith(unauthenticated: ['error' => 'Authentication required'], forbidden: ['error' => 'Forbidden']);
	}//end refusal()

	/**
	 * The refusal for the session user with translated texts, or null when they may proceed.
	 *
	 * @param IL10N $l10n The translator.
	 *
	 * @return JSONResponse|null
	 *
	 * @spec openspec/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
	 */
	public function translatedRefusal(IL10N $l10n): ?JSONResponse {
		return $this->refusalWith(
			unauthenticated: ['error' => $l10n->t('Authentication required')],
			forbidden: ['error' => $l10n->t('Forbidden')]
		);
	}//end translatedRefusal()

	/**
	 * The refusal for the session user under a `message` key, or null when they may proceed.
	 *
	 * @return JSONResponse|null
	 *
	 * @spec openspec/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
	 */
	public function refusalAsMessage(): ?JSONResponse {
		return $this->refusalWith(unauthenticated: ['message' => 'Authentication required'], forbidden: ['message' => 'Forbidden']);
	}//end refusalAsMessage()

	/**
	 * The refusal for the session user with the given bodies, or null when they may proceed.
	 *
	 * @param array<string, string> $unauthenticated The 401 body.
	 * @param array<string, string> $forbidden       The 403 body.
	 *
	 * @return JSONResponse|null
	 *
	 * @spec openspec/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
	 */
	public function refusalWith(array $unauthenticated, array $forbidden): ?JSONResponse {
		$uid = $this->actingUserId();
		if ($uid === '') {
			return new JSONResponse($unauthenticated, Http::STATUS_UNAUTHORIZED);
		}

		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return new JSONResponse($forbidden, Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end refusalWith()

	/**
	 * The session user's id.
	 *
	 * @return string The UID, or an empty string without a session.
	 *
	 * @spec openspec/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
	 */
	public function actingUserId(): string {
		$user = $this->userSession->getUser();
		if ($user instanceof IUser) {
			return $user->getUID();
		}

		return '';
	}//end actingUserId()
}//end class
