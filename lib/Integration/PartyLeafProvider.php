<?php

/**
 * Pipelinq PartyLeafProvider.
 *
 * The data half of the `pipelinq-party` leaf: a party's typed fields and its
 * standing indicators, resolved live, for any host object a consuming app
 * renders them on.
 *
 * A consuming app places the leaf rather than querying pipelinq's register. It
 * declares no party field of its own and holds no indicator. Its own lookup
 * against an external register may SET an indicator value; it does not become
 * a second indicator model.
 *
 * @category Integration
 * @package  OCA\Pipelinq\Integration
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
 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md#requirement-a-consuming-app-shall-read-party-fields-and-indicators-through-a-leaf-req-pfi-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Integration;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\PartyIndicatorService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read a party's typed fields and indicators through one contract.
 *
 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md#requirement-a-consuming-app-shall-read-party-fields-and-indicators-through-a-leaf-req-pfi-007
 */
class PartyLeafProvider {
	/**
	 * The leaf id a host declares in its schema's `linkedTypes`.
	 *
	 * @var string
	 */
	public const LEAF_ID = 'pipelinq-party';

	/**
	 * The render-surface id that draws the leaf's data.
	 *
	 * @var string
	 */
	public const PANEL_ID = 'pipelinq-party-panel';

	/**
	 * Upper bound on the declared fields read for one party kind.
	 *
	 * @var int
	 */
	private const FIELD_LIMIT = 200;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config, holding the schema ids.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param PartyIndicatorService $indicatorService Resolves a party's indicators.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly PartyIndicatorService $indicatorService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * One party's declared fields, its values and its live indicators.
	 *
	 * @param string $partyId The party record's uuid.
	 *
	 * @return array<string, mixed> `status` plus either the panel or `error`.
	 *
	 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md#requirement-a-consuming-app-shall-read-party-fields-and-indicators-through-a-leaf-req-pfi-007
	 */
	public function describe(string $partyId): array {
		$partyId = trim($partyId);
		if ($partyId === '') {
			return ['status' => 400, 'error' => 'A party is required.'];
		}

		$party = $this->readParty(partyId: $partyId);
		if ($party === null) {
			// RBAC answered, or the party does not exist. Deliberately the
			// same answer for both: telling them apart tells an unauthorised
			// caller that the party exists.
			return ['status' => 403, 'error' => 'You may not read this party.'];
		}

		$kind = trim((string)($party['partyKind'] ?? $party['type'] ?? ''));
		$values = ($party['fieldValues'] ?? []);
		if (is_array($values) === false) {
			$values = [];
		}

		$fields = [];
		foreach ($this->declaredFields(kind: $kind) as $declared) {
			$key = (string)($declared['key'] ?? '');
			if ($key === '') {
				continue;
			}

			$fields[] = [
				'key' => $key,
				'label' => (string)($declared['label'] ?? $key),
				'fieldKind' => (string)($declared['fieldKind'] ?? 'text'),
				'required' => (bool)($declared['required'] ?? false),
				'order' => (int)($declared['order'] ?? 0),
				'choices' => (array)($declared['choices'] ?? []),
				// Unset is rendered as unset. A field with no value must not
				// come back looking like one somebody filled in.
				'value' => ($values[$key] ?? null),
			];
		}

		usort($fields, static fn (array $a, array $b): int => ($a['order'] <=> $b['order']));

		return [
			'status' => 200,
			'party' => [
				'id' => $partyId,
				'kind' => $kind,
				'parentOrganisation' => (string)($party['parentOrganisation'] ?? ''),
				'organisationPath' => (string)($party['organisationPath'] ?? ''),
			],
			'fields' => $fields,
			'indicators' => $this->indicatorService->resolve(partyId: $partyId),
		];
	}//end describe()

	/**
	 * The fields declared for one party kind.
	 *
	 * @param string $kind The party kind code.
	 *
	 * @return array<int, array<string, mixed>> The declared fields.
	 */
	private function declaredFields(string $kind): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'partyFieldSet_schema', '');
		if ($register === '' || $schema === '') {
			return [];
		}

		$filters = ['register' => $register, 'schema' => $schema];
		if ($kind !== '') {
			$filters['partyKind'] = $kind;
		}

		try {
			$rows = $this->objectService->findAll(['filters' => $filters, 'limit' => self::FIELD_LIMIT]);
		} catch (Throwable $e) {
			$this->logger->warning(
				'PartyLeafProvider: the field set read failed',
				['kind' => $kind, 'exception' => $e->getMessage()]
			);

			return [];
		}

		$fields = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$fields[] = $row;
				continue;
			}

			if (($row instanceof \JsonSerializable) === true) {
				$data = $row->jsonSerialize();
				if (is_array($data) === true) {
					$fields[] = $data;
				}
			}
		}

		return $fields;
	}//end declaredFields()

	/**
	 * Read the party, with RBAC on.
	 *
	 * @param string $partyId The party record's uuid.
	 *
	 * @return array<string, mixed>|null The party, or null.
	 */
	private function readParty(string $partyId): ?array {
		try {
			$entity = $this->objectService->find(id: $partyId);
		} catch (Throwable $e) {
			$this->logger->debug(
				'PartyLeafProvider: the party could not be read',
				['party' => $partyId, 'exception' => $e->getMessage()]
			);

			return null;
		}

		if ($entity === null) {
			return null;
		}

		$data = $entity->jsonSerialize();

		if (is_array($data) === false) {
			return null;
		}

		return $data;
	}//end readParty()
}//end class
