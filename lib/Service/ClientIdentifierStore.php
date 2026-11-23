<?php

/**
 * Pipelinq ClientIdentifierStore.
 *
 * Reads a client and writes its checked identifiers back, always as the
 * calling user, so OpenRegister's object authorisation decides: a client the
 * user may not read answers 403, and storing the outcome of a check needs the
 * user's update right on the client.
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
 *
 * @spec openspec/changes/clients-registry-numbers-and-enrichment/specs/client-registry-numbers/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The client a check reads, and the write of its outcome, with the user's rights.
 *
 * @spec openspec/changes/clients-registry-numbers-and-enrichment/specs/client-registry-numbers/spec.md
 */
class ClientIdentifierStore {

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService The object store, read and written as the user.
	 * @param IAppConfig             $appConfig     The client register and schema.
	 * @param IL10N                  $l10n          The user's language.
	 * @param LoggerInterface        $logger        The logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The client as the user may read it, or a refusal.
	 *
	 * Fails closed: while the app has no client register and schema there is
	 * no client to read.
	 *
	 * @param string $clientId The client's id.
	 *
	 * @return array{status?: int, message?: string, data?: array<string, mixed>} The client's data, or a status.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.4
	 */
	public function read(string $clientId): array {
		$location = $this->location();
		if (trim($clientId) === '' || $location === null) {
			return $this->notFound();
		}

		[$register, $schema] = $location;

		try {
			$object = $this->objectService->find(id: $clientId, options: new ObjectFindOptions(register: $register, schema: $schema));
		} catch (NotAuthorizedException $e) {
			return ['status' => 403, 'message' => $this->l10n->t('You may not open this client.')];
		} catch (Throwable $e) {
			$this->logger->debug('Pipelinq: client not read for an identifier check', ['exception' => $e->getMessage()]);
			return $this->notFound();
		}

		if ($object === null) {
			return $this->notFound();
		}

		return ['data' => $object->getObject()];
	}//end read()

	/**
	 * Write the client with its checked list back as the user.
	 *
	 * @param string               $clientId The client's id.
	 * @param array<string, mixed> $data     The client as read, with the checked list.
	 *
	 * @return array{status: int, message: string}|null The refusal, or null when it was saved.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.4
	 */
	public function write(string $clientId, array $data): ?array {
		$location = $this->location();
		if ($location === null) {
			return $this->notFound();
		}

		[$register, $schema] = $location;
		unset($data['@self'], $data['id']);
		try {
			$this->objectService->saveObject(
				object: $data,
				options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $register, schema: $schema), uuid: $clientId)
			);
		} catch (NotAuthorizedException $e) {
			return ['status' => 403, 'message' => $this->l10n->t('You may not change this client.')];
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: storing an identifier check failed', ['clientId' => $clientId, 'exception' => $e->getMessage()]);
			return ['status' => 500, 'message' => $this->l10n->t('The outcome of the check could not be saved.')];
		}

		return null;
	}//end write()

	/**
	 * The refusal for a client that is not there.
	 *
	 * @return array{status: int, message: string} A 404.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.4
	 */
	public function notFound(): array {
		return ['status' => 404, 'message' => $this->l10n->t('Client not found')];
	}//end notFound()

	/**
	 * The client register and schema, or null while the app is not configured.
	 *
	 * Fails closed: without both there is no client to read, so nothing is checked.
	 *
	 * @return array{0: string, 1: string}|null The register and schema.
	 */
	private function location(): ?array {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema   = $this->appConfig->getValueString(AppIdentity::APP_ID, 'client_schema', '');
		if ($register === '' || $schema === '') {
			return null;
		}

		return [$register, $schema];
	}//end location()
}//end class
