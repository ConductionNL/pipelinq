<?php

/**
 * Pipelinq SocialAdvocacyController.
 *
 * The two routes a colleague uses when no application may post to their
 * account: read the prepared text, and confirm they posted it.
 *
 * Neither route requires a marketing group. The person being asked to share
 * may be a developer, an account manager or anyone else with a personal
 * account, and requiring the group would mean the notification they received
 * leads to a refusal. The guard is per object instead:
 * `SocialAdvocacyService` loads the account behind the publication and admits
 * only its owner or an administrator (ADR-005, per-object authorization).
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
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-an-account-no-application-may-post-to-asks-its-owner-to-share
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\SocialAdvocacyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for the prepared-share path.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-an-account-no-application-may-post-to-asks-its-owner-to-share
 */
class SocialAdvocacyController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param SocialAdvocacyService $advocacy The prepared-share path and its per-object guard.
	 * @param IUserSession $userSession Current user session.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly SocialAdvocacyService $advocacy,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/social-publications/{id}/share — the prepared text, the media
	 * and a deep link into the network's own composer.
	 *
	 * @param string $id The publication.
	 *
	 * @return JSONResponse `{share}`, or a refusal.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-an-account-no-application-may-post-to-asks-its-owner-to-share
	 */
	#[NoAdminRequired]
	public function share(string $id): JSONResponse {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			return $this->refuse();
		}

		// The per-object guard runs inside the service: it loads the account
		// behind this publication and admits only its owner or an admin.
		return $this->answer(result: $this->advocacy->shareBundle(publicationId: $id, uid: $uid));
	}//end share()

	/**
	 * POST /api/social-publications/{id}/confirm-share — the owner posted it.
	 *
	 * @param string $id The publication.
	 *
	 * @return JSONResponse `{publication}`, or a refusal.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-an-account-no-application-may-post-to-asks-its-owner-to-share
	 */
	#[NoAdminRequired]
	public function confirmShare(string $id): JSONResponse {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			return $this->refuse();
		}

		return $this->answer(
			result: $this->advocacy->confirmShare(
				publicationId: $id,
				uid: $uid,
				url: (string)$this->request->getParam('url', ''),
			),
		);
	}//end confirmShare()

	/**
	 * Turn a service answer into a response.
	 *
	 * @param array<string, mixed> $result The service's answer.
	 *
	 * @return JSONResponse The response.
	 */
	private function answer(array $result): JSONResponse {
		if (isset($result['error']) === true) {
			return new JSONResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($result);
	}//end answer()

	/**
	 * The single refusal.
	 *
	 * @return JSONResponse A 403.
	 */
	private function refuse(): JSONResponse {
		return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
	}//end refuse()
}//end class
