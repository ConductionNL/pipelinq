<?php

/**
 * Pipelinq OpenDealsController.
 *
 * The open deals of one contact or client: how many, and their total value.
 * The contact page shows them as one KPI, the value in the reporting
 * currency with the count beside it. It used to show "Open deals" twice,
 * once as a count and once as an unformatted amount (pipelinq review E2).
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
 * @spec openspec/changes/detail-pages-read-at-a-glance/specs/client-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * Counts and sums the open deals of a contact or client.
 *
 * @spec openspec/specs/client-management/spec.md
 */
class OpenDealsController extends Controller {

	/**
	 * The lead field per party kind, and the app-config key of its schema.
	 *
	 * @var array<string, string>
	 */
	private const PARTIES = [
		'contact' => 'contact_schema',
		'client' => 'client_schema',
	];

	/**
	 * Upper bound on open deals read for one party.
	 *
	 * @var int
	 */
	private const LIMIT = 1000;

	/**
	 * Constructor.
	 *
	 * @param IRequest               $request       The request.
	 * @param IAppConfig             $appConfig     Source of the register and schema ids.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param IUserSession           $userSession   The signed-in user.
	 */
	public function __construct(
		IRequest $request,
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/analytics/open-deals?contact={id} or ?client={id}.
	 *
	 * Everything is read under the user's own OpenRegister RBAC: a party they
	 * cannot read answers 404, and only the deals they may see are counted.
	 *
	 * @return JSONResponse `{openDealCount, openDealValue}` or an error.
	 *
	 * @spec openspec/changes/detail-pages-read-at-a-glance/specs/client-management/spec.md
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		[$field, $partyId] = $this->party();
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$leadSchema = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');
		if ($field === '' || $register === '' || $leadSchema === '') {
			return new JSONResponse(['error' => 'Name one contact or client'], Http::STATUS_BAD_REQUEST);
		}

		$partySchema = $this->appConfig->getValueString(Application::APP_ID, self::PARTIES[$field], '');
		if ($this->canRead(id: $partyId, register: $register, schema: $partySchema) === false) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$rows = $this->objectService->findAll(
				config: [
					'filters' => ['register' => $register, 'schema' => $leadSchema, $field => $partyId, 'status' => 'open'],
					'limit' => self::LIMIT,
				]
			);
		} catch (Throwable) {
			return new JSONResponse(['error' => 'Deals unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse($this->summarise(rows: $rows, field: $field, partyId: $partyId));
	}//end index()

	/**
	 * Count and sum the open deals of the party.
	 *
	 * Each row is checked again rather than trusted to match the query.
	 *
	 * @param iterable<mixed> $rows    The rows OpenRegister returned.
	 * @param string          $field   The lead field naming the party.
	 * @param string          $partyId The party id.
	 *
	 * @return array{openDealCount: int, openDealValue: float}
	 */
	private function summarise(iterable $rows, string $field, string $partyId): array {
		$count = 0;
		$value = 0.0;
		foreach ($rows as $row) {
			$data = $this->toArray(row: $row);
			if (($data['status'] ?? '') !== 'open' || (string)($data[$field] ?? '') !== $partyId) {
				continue;
			}

			$count++;
			$value += (float)($data['value'] ?? 0);
		}

		return ['openDealCount' => $count, 'openDealValue' => round($value, 2)];
	}//end summarise()

	/**
	 * The party the request names: its lead field and id.
	 *
	 * @return array{0: string, 1: string} The field and id, or ['', ''] when none or both are named.
	 */
	private function party(): array {
		$named = [];
		foreach (array_keys(self::PARTIES) as $field) {
			$id = trim((string)$this->request->getParam($field, ''));
			if ($id !== '') {
				$named[] = [$field, $id];
			}
		}

		if (count($named) !== 1) {
			return ['', ''];
		}

		return $named[0];
	}//end party()

	/**
	 * Whether the user may read the party, under their RBAC.
	 *
	 * @param string $id       The party id.
	 * @param string $register The register id.
	 * @param string $schema   The party schema id.
	 *
	 * @return bool True when it exists and is readable.
	 */
	private function canRead(string $id, string $register, string $schema): bool {
		if ($schema === '') {
			return false;
		}

		try {
			return $this->objectService->find(id: $id, register: $register, schema: $schema, _audit: false) !== null;
		} catch (Throwable) {
			return false;
		}
	}//end canRead()

	/**
	 * Normalise an OpenRegister row into a plain array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed> The data, [] when unusable.
	 */
	private function toArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$data = $row->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return [];
	}//end toArray()
}//end class
