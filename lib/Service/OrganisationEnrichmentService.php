<?php

/**
 * Pipelinq OrganisationEnrichmentService.
 *
 * Fills the empty registry fields of organisations from the KvK Handelsregister:
 * it looks each selected organisation up, proposes a value for every field the
 * register knows and the organisation lacks (or holds differently), and writes
 * the proposals a user accepts.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Organisation enrichment from the KvK.
 *
 * @spec openspec/changes/organisation-enrichment/specs/client-management/spec.md
 */
class OrganisationEnrichmentService {

	/**
	 * The most organisations one request looks up.
	 *
	 * @var int
	 */
	public const MAX_ORGANISATIONS = 50;

	/**
	 * The client fields a proposal may fill.
	 *
	 * @var array<int, string>
	 */
	public const FIELDS = ['kvkNumber', 'legalForm', 'place'];

	/**
	 * KvK legal form wording to the client's legal form code.
	 *
	 * @var array<string, string>
	 */
	private const LEGAL_FORMS = [
		'eenmanszaak' => 'eenmanszaak',
		'vennootschap onder firma' => 'vof',
		'maatschap' => 'maatschap',
		'commanditaire vennootschap' => 'cv',
		'besloten vennootschap' => 'bv',
		'naamloze vennootschap' => 'nv',
		'coöperatie' => 'cooperatie',
		'cooperatie' => 'cooperatie',
		'vereniging' => 'vereniging',
		'stichting' => 'stichting',
	];

	/**
	 * Constructor.
	 *
	 * @param KvkApiClient           $kvk           The KvK lookup.
	 * @param ObjectServiceInterface $objectService Reads and saves the clients.
	 * @param IAppConfig             $appConfig     The register and schema ids.
	 * @param LoggerInterface        $logger        The logger.
	 */
	public function __construct(
		private KvkApiClient $kvk,
		private readonly ObjectServiceInterface $objectService,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Look the organisations up and propose values for their empty fields.
	 *
	 * A person is never looked up: personal data from the BRP is not filled
	 * in bulk, so only clients of type organization take part.
	 *
	 * @param array<int, string> $clientIds The selected clients.
	 *
	 * @return array{proposals: array<int, array<string, mixed>>, notFound: array<int, string>} Proposals and unknown names.
	 *
	 * @spec openspec/changes/organisation-enrichment/specs/client-management/spec.md
	 */
	public function propose(array $clientIds): array {
		$proposals = [];
		$notFound = [];
		foreach (array_slice($clientIds, 0, self::MAX_ORGANISATIONS) as $clientId) {
			$client = $this->readClient(clientId: (string)$clientId);
			if ($client === null || ($client['type'] ?? '') !== 'organization') {
				continue;
			}

			$company = $this->lookUp(client: $client);
			if ($company === null) {
				$notFound[] = (string)($client['name'] ?? '');
				continue;
			}

			foreach ($this->found(company: $company) as $field => $value) {
				$current = (string)($client[$field] ?? '');
				if ($value === '' || $current === $value) {
					continue;
				}

				$proposals[] = [
					'clientId' => (string)$clientId,
					'name' => (string)($client['name'] ?? ''),
					'field' => $field,
					'current' => $current,
					'found' => $value,
					'source' => 'KVK',
					'differs' => ($current !== ''),
				];
			}
		}//end foreach

		return ['proposals' => $proposals, 'notFound' => $notFound];
	}//end propose()

	/**
	 * Write the accepted proposals onto their clients.
	 *
	 * @param array<int, array{clientId?: string, field?: string, value?: string}> $changes The accepted proposals.
	 *
	 * @return array{updated: int} How many fields were written.
	 *
	 * @spec openspec/changes/organisation-enrichment/specs/client-management/spec.md
	 */
	public function apply(array $changes): array {
		$byClient = [];
		foreach ($changes as $change) {
			$field = (string)($change['field'] ?? '');
			$clientId = (string)($change['clientId'] ?? '');
			if ($clientId === '' || in_array($field, self::FIELDS, true) === false) {
				continue;
			}

			$byClient[$clientId][$field] = (string)($change['value'] ?? '');
		}

		$updated = 0;
		foreach ($byClient as $clientId => $fields) {
			$client = $this->readClient(clientId: (string)$clientId);
			if ($client === null || ($client['type'] ?? '') !== 'organization') {
				continue;
			}

			$data = array_merge($client, $fields);
			unset($data['@self']);

			[$register, $schema] = $this->config();
			$this->objectService->saveObject(
				object: $data,
				register: $register,
				schema: $schema,
				uuid: (string)$clientId
			);
			$updated += count($fields);
		}

		return ['updated' => $updated];
	}//end apply()

	/**
	 * The fields a KvK result fills, by client field.
	 *
	 * @param array<string, mixed> $company A KvK search row.
	 *
	 * @return array<string, string> Client field to value.
	 */
	private function found(array $company): array {
		$address = (array)($company['adres']['binnenlandsAdres'] ?? ($company['adres'] ?? []));
		$legalForm = mb_strtolower(trim((string)($company['rechtsvorm'] ?? '')));

		return [
			'kvkNumber' => (string)($company['kvkNummer'] ?? ''),
			'legalForm' => (self::LEGAL_FORMS[$legalForm] ?? ''),
			'place' => (string)($address['plaats'] ?? ''),
		];
	}//end found()

	/**
	 * The KvK row for a client: by its KvK number when it has one, else by
	 * its exact name. Null when the register holds no such company.
	 *
	 * @param array<string, mixed> $client The client.
	 *
	 * @return array<string, mixed>|null The row.
	 */
	private function lookUp(array $client): ?array {
		$kvkNumber = trim((string)($client['kvkNumber'] ?? ''));
		if ($kvkNumber !== '') {
			foreach ($this->kvk->companyByNumber(kvkNumber: $kvkNumber) as $row) {
				if ((string)($row['kvkNummer'] ?? '') === $kvkNumber) {
					return $row;
				}
			}

			return null;
		}

		$name = trim((string)($client['name'] ?? ''));
		if ($name === '') {
			return null;
		}

		foreach ($this->kvk->searchByName(name: $name) as $row) {
			if (mb_strtolower(trim((string)($row['naam'] ?? ''))) === mb_strtolower($name)) {
				return $row;
			}
		}

		return null;
	}//end lookUp()

	/**
	 * A client by id, as the caller may read it, or null.
	 *
	 * @param string $clientId The client id.
	 *
	 * @return array<string, mixed>|null The client.
	 */
	private function readClient(string $clientId): ?array {
		if ($clientId === '') {
			return null;
		}

		[$register, $schema] = $this->config();
		try {
			$object = $this->objectService->find(id: $clientId, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$this->logger->debug('Pipelinq: client not read for enrichment', ['exception' => $e->getMessage()]);
			return null;
		}

		if ($object === null) {
			return null;
		}

		$data = $object->jsonSerialize();
		if (is_array($data) === false) {
			return null;
		}

		return $data;
	}//end readClient()

	/**
	 * The register and client schema ids.
	 *
	 * @return array{0: string, 1: string} Register and schema.
	 */
	private function config(): array {
		return [
			$this->appConfig->getValueString(Application::APP_ID, 'register', ''),
			$this->appConfig->getValueString(Application::APP_ID, 'client_schema', ''),
		];
	}//end config()
}//end class
