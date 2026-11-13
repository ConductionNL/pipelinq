<?php

/**
 * Pipelinq contact capture controller.
 *
 * POST /api/contact-capture/parse: pasted text in, proposed contact fields
 * out. It reads and stores nothing, so it needs a signed-in user and no
 * per-object check (D4).
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-pasted-text-becomes-proposed-contact-fields-req-ccc-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Service\ContactCaptureService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Proposes contact fields for pasted text.
 *
 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-pasted-text-becomes-proposed-contact-fields-req-ccc-001
 */
class ContactCaptureController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest              $request     The request.
	 * @param ContactCaptureService $capture     The field proposal.
	 * @param IUserSession          $userSession The caller.
	 *
	 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-pasted-text-becomes-proposed-contact-fields-req-ccc-001
	 */
	public function __construct(
		IRequest $request,
		private readonly ContactCaptureService $capture,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Propose fields for the posted `text`.
	 *
	 * @return JSONResponse The proposal; 400 without text, 413 for more than a signature's worth, 401 without a caller.
	 *
	 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-pasted-text-becomes-proposed-contact-fields-req-ccc-001
	 */
	#[NoAdminRequired]
	public function parse(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Sign in to read contact details.'], Http::STATUS_UNAUTHORIZED);
		}

		$text = $this->request->getParam('text', '');
		if (is_string($text) === false || trim($text) === '') {
			return new JSONResponse(['error' => 'Paste the text to read.'], Http::STATUS_BAD_REQUEST);
		}

		if (mb_strlen($text, 'UTF-8') > ContactCaptureService::MAX_LENGTH) {
			return new JSONResponse(['error' => 'The text is too long to be a signature or a card.'], Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
		}

		return new JSONResponse($this->capture->parse(text: $text, userId: $user->getUID()));
	}//end parse()
}//end class
