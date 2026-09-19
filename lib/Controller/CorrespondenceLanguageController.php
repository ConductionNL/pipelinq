<?php

/**
 * Pipelinq CorrespondenceLanguageController.
 *
 * The published resolver: given a party, which language to write in and which
 * rule produced that answer. A consuming app calls this rather than reading
 * `correspondenceLanguage` off the record and deciding for itself, because a
 * caller that reads the property directly has to reimplement the three rules
 * and will get the unset case wrong.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/correspondence-language-per-party/specs/parties/spec.md#requirement-the-resolver-is-published-and-no-caller-reads-the-property-directly-req-pcl-005
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\CorrespondenceLanguageService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Routes for the correspondence language and its resolver.
 *
 * @spec openspec/changes/correspondence-language-per-party/specs/parties/spec.md#requirement-the-language-to-write-in-resolves-with-its-reason-req-pcl-003
 */
class CorrespondenceLanguageController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param CorrespondenceLanguageService $service The resolver.
	 */
	public function __construct(
		IRequest $request,
		private readonly CorrespondenceLanguageService $service,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The tags this instance can render, and its default.
	 *
	 * @return JSONResponse The picker's options.
	 *
	 * @spec openspec/changes/correspondence-language-per-party/specs/parties/spec.md#requirement-the-selectable-languages-are-the-ones-the-instance-can-render-req-pcl-002
	 */
	#[NoAdminRequired]
	public function available(): JSONResponse {
		return new JSONResponse(
			[
				'languages' => $this->service->available(),
				'instanceDefault' => $this->service->instanceDefault(),
			],
			200
		);
	}//end available()

	/**
	 * The language to write to a party in, and the rule that produced it.
	 *
	 * @param string $partyId The party record's uuid.
	 *
	 * @return JSONResponse The answer, or the refusal.
	 *
	 * @spec openspec/changes/correspondence-language-per-party/specs/parties/spec.md#requirement-the-language-to-write-in-resolves-with-its-reason-req-pcl-003
	 */
	#[NoAdminRequired]
	public function resolve(string $partyId): JSONResponse {
		return $this->respond(result: $this->service->resolve(partyId: $partyId));
	}//end resolve()

	/**
	 * Set or clear a party's stated preference.
	 *
	 * @param string $partyId The party record's uuid.
	 * @param string $language The BCP 47 tag, or '' to clear it.
	 *
	 * @return JSONResponse The new answer, or the refusal naming the tag.
	 *
	 * @spec openspec/changes/correspondence-language-per-party/specs/parties/spec.md#requirement-a-party-carries-the-language-it-asked-to-be-written-in-req-pcl-001
	 */
	#[NoAdminRequired]
	public function setPreference(string $partyId, string $language = ''): JSONResponse {
		return $this->respond(
			result: $this->service->setPreference(partyId: $partyId, tag: $language)
		);
	}//end setPreference()

	/**
	 * Turn a service result into a JSON response.
	 *
	 * @param array<string, mixed> $result The service's answer.
	 *
	 * @return JSONResponse The response.
	 */
	private function respond(array $result): JSONResponse {
		$status = (int)($result['status'] ?? 200);
		unset($result['status']);

		return new JSONResponse($result, $status);
	}//end respond()
}//end class
