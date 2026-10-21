<?php

/**
 * Pipelinq RelationshipController.
 *
 * GET /api/relationship-types: the types an agent can pick.
 * POST /api/relationships: a new relationship between two parties.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/relationship-types-on-the-server/specs/contact-relationship-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use DomainException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\RelationshipTypeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Serves the relationship types and creates relationships.
 *
 * @spec openspec/changes/relationship-types-on-the-server/specs/contact-relationship-mapping/spec.md#requirement-the-server-owns-the-relationship-types
 */
class RelationshipController extends Controller {

	/**
	 * HTTP status per refusal.
	 *
	 * @var array<string, int>
	 */
	private const STATUS = [
		'incomplete' => Http::STATUS_BAD_REQUEST,
		'unknown-type' => Http::STATUS_BAD_REQUEST,
		'self' => Http::STATUS_BAD_REQUEST,
		'duplicate' => Http::STATUS_CONFLICT,
		'refused' => Http::STATUS_FORBIDDEN,
	];

	/**
	 * The message per refusal, English source strings.
	 *
	 * @var array<string, string>
	 */
	private const MESSAGES = [
		'incomplete' => 'Choose who the relationship is with and what kind it is.',
		'unknown-type' => 'This kind of relationship is not known.',
		'self' => 'A relationship needs two different parties.',
		'duplicate' => 'This relationship already exists.',
		'refused' => 'You may not add this relationship.',
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest                $request The request.
	 * @param RelationshipTypeService $types   The types and the create.
	 * @param IL10N                   $l10n    Translates the refusals.
	 */
	public function __construct(
		IRequest $request,
		private readonly RelationshipTypeService $types,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The relationship types.
	 *
	 * @return JSONResponse `{ results: [{ value, label, category }] }`.
	 *
	 * @spec openspec/changes/relationship-types-on-the-server/specs/contact-relationship-mapping/spec.md#requirement-the-server-owns-the-relationship-types
	 */
	#[NoAdminRequired]
	public function types(): JSONResponse {
		return new JSONResponse(['results' => $this->types->options()]);
	}//end types()

	/**
	 * Create a relationship. OpenRegister decides whether the user may.
	 *
	 * The body carries fromContact, fromType, toContact, type, notes, startDate, endDate and strength.
	 *
	 * @return JSONResponse The saved relationship, or `{ status, message }` naming the refusal.
	 *
	 * @spec openspec/changes/relationship-types-on-the-server/specs/contact-relationship-mapping/spec.md#requirement-the-server-refuses-an-unknown-or-repeated-relationship
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$fields = [];
		foreach (['fromContact', 'fromType', 'toContact', 'type', 'notes', 'startDate', 'endDate', 'strength'] as $key) {
			$fields[$key] = $this->request->getParam($key);
		}

		try {
			$saved = $this->types->create(fields: $fields);
		} catch (DomainException $e) {
			$status = $e->getMessage();
			return new JSONResponse(
				['status' => $status, 'message' => $this->l10n->t(self::MESSAGES[$status] ?? self::MESSAGES['refused'])],
				(self::STATUS[$status] ?? Http::STATUS_BAD_REQUEST)
			);
		}

		return new JSONResponse($saved, Http::STATUS_CREATED);
	}//end create()
}//end class
