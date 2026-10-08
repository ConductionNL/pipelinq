<?php

/**
 * Pipelinq PartyLinkService.
 *
 * The one write path for a party link, so the picker, an import, an API call
 * and a flow are all judged by the same rule. A second write path is how a
 * rule stops being one.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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
 * @spec openspec/changes/party-kinds-accepted-per-case-type/specs/party-kind-registry/spec.md#requirement-a-party-link-with-an-unaccepted-kind-shall-be-refused-on-the-write-req-pkr-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Write and end party links, under the registry's rule.
 *
 * @spec openspec/changes/party-kinds-accepted-per-case-type/specs/party-kind-registry/spec.md#requirement-a-kind-declared-single-shall-refuse-a-second-holder-on-one-record-req-pkr-005
 */
class PartyLinkService {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config, holding the schema ids.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param PartyKindRegistryService $registry The vocabulary and the rule.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly PartyKindRegistryService $registry,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Link a party to a record under a kind.
	 *
	 * @param string $recordType The `<app>:<schema>:<type>` literal.
	 * @param string $recordId The record.
	 * @param string $party The party.
	 * @param string $kind The party kind code.
	 *
	 * @return array<string, mixed> `status` plus either `partyLink` or `error`.
	 *
	 * @spec openspec/changes/party-kinds-accepted-per-case-type/specs/party-kind-registry/spec.md#requirement-a-party-link-with-an-unaccepted-kind-shall-be-refused-on-the-write-req-pkr-004
	 */
	public function link(string $recordType, string $recordId, string $party, string $kind): array {
		$recordType = trim($recordType);
		$recordId = trim($recordId);
		$party = trim($party);
		$kind = trim($kind);

		if ($recordType === '' || $recordId === '' || $party === '') {
			return ['status' => 400, 'error' => 'A record type, a record and a party are required.'];
		}

		$answer = $this->registry->mayLink(
			recordType: $recordType,
			recordId: $recordId,
			kind: $kind,
			party: $party,
		);

		if ($answer['allowed'] === false) {
			// 409, not 400: the write is well formed and refused on policy,
			// and the message is meant to be shown to the handler.
			return ['status' => 409, 'error' => $answer['reason']];
		}

		$link = [
			'party' => $party,
			'kind' => $kind,
			'recordType' => $recordType,
			'recordId' => $recordId,
			'validFrom' => (new DateTimeImmutable('today'))->format('Y-m-d'),
		];

		$saved = $this->write(object: $link);
		if ($saved === false) {
			return ['status' => 500, 'error' => 'The party link could not be saved.'];
		}

		return ['status' => 201, 'partyLink' => $link];
	}//end link()

	/**
	 * End a party link, without deleting the record of it.
	 *
	 * Ending rather than deleting: replacing a single-kind holder is an
	 * explicit act, and who held the kind until when is part of the file.
	 *
	 * @param string $linkId The link's uuid.
	 *
	 * @return array<string, mixed> `status` plus either `partyLink` or `error`.
	 */
	public function end(string $linkId): array {
		$linkId = trim($linkId);
		if ($linkId === '') {
			return ['status' => 400, 'error' => 'A party link is required.'];
		}

		try {
			$entity = $this->objectService->find(id: $linkId);
		} catch (Throwable $e) {
			$this->logger->debug(
				'PartyLinkService: the link could not be read',
				['uuid' => $linkId, 'exception' => $e->getMessage()]
			);
			$entity = null;
		}

		if ($entity === null) {
			return ['status' => 404, 'error' => 'That party link could not be read.'];
		}

		$data = $entity->jsonSerialize();
		if (is_array($data) === false) {
			return ['status' => 404, 'error' => 'That party link could not be read.'];
		}

		$data['validUntil'] = (new DateTimeImmutable('today'))->format('Y-m-d');

		if ($this->write(object: $data, uuid: $linkId) === false) {
			return ['status' => 500, 'error' => 'The party link could not be ended.'];
		}

		return ['status' => 200, 'partyLink' => $data];
	}//end end()

	/**
	 * Write every accepted row of a batch, refusing the rest by the same rule.
	 *
	 * @param string $recordType The `<app>:<schema>:<type>` literal.
	 * @param string $recordId The record.
	 * @param array<int, array<string, string>> $rows Each with `party` and `kind`.
	 *
	 * @return array<string, mixed> `status`, the rows that landed and the rows refused.
	 *
	 * @spec openspec/changes/party-kinds-accepted-per-case-type/specs/party-kind-registry/spec.md#requirement-a-party-link-with-an-unaccepted-kind-shall-be-refused-on-the-write-req-pkr-004
	 */
	public function import(string $recordType, string $recordId, array $rows): array {
		$verdict = $this->registry->judgeBatch(
			recordType: trim($recordType),
			recordId: trim($recordId),
			rows: $rows,
		);

		$landed = [];
		foreach ($verdict['accepted'] as $row) {
			$link = [
				'party' => $row['party'],
				'kind' => $row['kind'],
				'recordType' => trim($recordType),
				'recordId' => trim($recordId),
				'validFrom' => (new DateTimeImmutable('today'))->format('Y-m-d'),
			];

			if ($this->write(object: $link) === true) {
				$landed[] = $link;
			}
		}

		return [
			'status' => 200,
			'linked' => $landed,
			'refused' => $verdict['refused'],
		];
	}//end import()

	/**
	 * Save one party link.
	 *
	 * @param array<string, mixed> $object The link.
	 * @param string|null $uuid The link's uuid, for an update.
	 *
	 * @return bool True when the write landed.
	 */
	private function write(array $object, ?string $uuid = null): bool {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'partyLink_schema', '');
		if ($register === '' || $schema === '') {
			$this->logger->warning('PartyLinkService: the party link surface is not configured');

			return false;
		}

		try {
			$this->objectService->saveObject(
				object: $object,
				register: $register,
				schema: $schema,
				uuid: $uuid,
			);

			return true;
		} catch (Throwable $e) {
			$this->logger->error(
				'PartyLinkService: the party link could not be saved',
				['exception' => $e->getMessage()]
			);

			return false;
		}
	}//end write()
}//end class
