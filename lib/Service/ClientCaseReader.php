<?php

/**
 * Pipelinq ClientCaseReader.
 *
 * The cases of a client, as the client page lists them (boards PqKlant and
 * PqOrganisatie: the Zaken card, Open zaken and the Zaken tab). Cases live in
 * dossiq. Pipelinq holds no dossiq party uuid; the client and dossiq's party
 * share a master entity (`masterEntityRef`), and dossiq's case leaf answers on
 * that key, as pipelinq's TicketLeafProvider answers dossiq.
 *
 * Read only, and soft: without dossiq, or without a master entity, the client
 * has no cases to show and the answer says why.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * @spec openspec/changes/client-page-as-the-relation-boards/specs/client-detail/spec.md#requirement-the-client-page-lists-the-clients-cases
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads a client's cases from dossiq by the client's master entity.
 *
 * @spec openspec/changes/client-page-as-the-relation-boards/specs/client-detail/spec.md#requirement-the-client-page-lists-the-clients-cases
 */
class ClientCaseReader {
	/**
	 * Dossiq's case leaf, resolved by name: it exists exactly when dossiq is installed.
	 *
	 * @var string
	 */
	public const LEAF_CLASS = 'OCA\\Dossiq\\Integration\\CaseLeafProvider';

	/**
	 * The most rows one answer carries.
	 *
	 * @var int
	 */
	public const MAX_LIMIT = 100;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig         $appConfig The app config, holding the client schema.
	 * @param ContainerInterface $container Resolves OpenRegister's object service and dossiq's leaf.
	 * @param LoggerInterface    $logger    PSR logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The client's cases: `status` (HTTP) plus `results`, `total` and
	 * `available` (whether dossiq answered), or `message`.
	 *
	 * @param string $clientId The client uuid.
	 * @param string $status   `open`, `closed` or `all`.
	 * @param int    $limit    The most rows to return.
	 *
	 * @return array<string, mixed> The answer.
	 *
	 * @spec openspec/changes/client-page-as-the-relation-boards/specs/client-detail/spec.md#requirement-the-client-page-lists-the-clients-cases
	 */
	public function casesOf(string $clientId, string $status, int $limit): array {
		$read = $this->client(clientId: $clientId);
		if (isset($read['client']) === false) {
			return $read;
		}

		$ref = trim((string)($read['client']['masterEntityRef'] ?? ''));
		$leaf = $this->leaf();
		if ($ref === '' || $leaf === null) {
			return ['status' => 200, 'results' => [], 'total' => 0, 'available' => ($leaf !== null)];
		}

		$answer = $leaf->byMasterEntity($ref, $status);
		if ((int)($answer['status'] ?? 500) !== 200) {
			return ['status' => (int)($answer['status'] ?? 500), 'message' => (string)($answer['error'] ?? 'Operation failed')];
		}

		$cases = array_values((array)($answer['cases'] ?? []));
		$limit = max(1, min($limit, self::MAX_LIMIT));

		return ['status' => 200, 'results' => array_slice($cases, 0, $limit), 'total' => count($cases), 'available' => true];
	}//end casesOf()

	/**
	 * The client, read as the caller, under `client`; or an answer with the
	 * status that says why not (403 not allowed, 404 not found, 500 failed).
	 *
	 * @param string $clientId The client uuid.
	 *
	 * @return array<string, mixed> `client`, or `status` and `message`.
	 */
	private function client(string $clientId): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');
		if ($clientId === '' || $register === '' || $schema === '') {
			return ['status' => 400, 'message' => 'Missing client'];
		}

		try {
			$object = $this->container->get('OCA\OpenRegister\Service\ObjectService')->find(
				id: $clientId,
				register: $register,
				schema: $schema,
				_rbac: true,
				_multitenancy: true,
			);
		} catch (NotAuthorizedException $e) {
			return ['status' => 403, 'message' => 'Forbidden'];
		} catch (Throwable $e) {
			return $this->refusal(clientId: $clientId, error: $e);
		}

		if ($object === null) {
			return ['status' => 404, 'message' => 'Client not found'];
		}

		return ['client' => $this->asArray(object: $object)];
	}//end client()

	/**
	 * The answer for a client read that threw: 404 for a client that does
	 * not exist (OCP's DoesNotExistException, matched by name), else 500.
	 *
	 * @param string    $clientId The client uuid.
	 * @param Throwable $error    What the read threw.
	 *
	 * @return array<string, mixed> `status` and `message`.
	 */
	private function refusal(string $clientId, Throwable $error): array {
		if (str_ends_with(get_class($error), '\\DoesNotExistException') === true) {
			return ['status' => 404, 'message' => 'Client not found'];
		}

		$this->logger->warning('ClientCaseReader: reading the client failed', ['clientId' => $clientId, 'exception' => $error->getMessage()]);
		return ['status' => 500, 'message' => 'Operation failed'];
	}//end refusal()

	/**
	 * A stored object as an array.
	 *
	 * @param mixed $object An array, or an entity that serialises to one.
	 *
	 * @return array<string, mixed> The fields.
	 */
	private function asArray(mixed $object): array {
		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			return (array)$object->jsonSerialize();
		}

		return (array)$object;
	}//end asArray()

	/**
	 * Dossiq's case leaf, or null when dossiq is not installed.
	 *
	 * @return object|null The leaf.
	 */
	protected function leaf(): ?object {
		if (class_exists(self::LEAF_CLASS) === false) {
			return null;
		}

		try {
			return $this->container->get(self::LEAF_CLASS);
		} catch (Throwable $e) {
			$this->logger->warning('ClientCaseReader: dossiq case leaf unavailable', ['exception' => $e->getMessage()]);
			return null;
		}
	}//end leaf()
}//end class
