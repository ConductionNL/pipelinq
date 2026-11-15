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
 * @spec openspec/specs/party-kind-registry/spec.md#requirement-a-party-link-with-an-unaccepted-kind-shall-be-refused-on-the-write-req-pkr-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\PartyLinkServiceInterface;
use OCA\Pipelinq\Util\PartyObjectRead;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Write and end party links, under the registry's rule.
 *
 * @spec openspec/specs/party-kind-registry/spec.md#requirement-a-kind-declared-single-shall-refuse-a-second-holder-on-one-record-req-pkr-005
 */
class PartyLinkService implements PartyLinkServiceInterface {
	use PartyObjectRead;

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
	 * @spec openspec/specs/party-kind-registry/spec.md#requirement-a-party-link-with-an-unaccepted-kind-shall-be-refused-on-the-write-req-pkr-004
	 */
	public function link(string $recordType, string $recordId, string $party, string $kind): array {
		return $this->linkDraft(draft: ['party' => trim($party), 'kind' => trim($kind), 'recordType' => trim($recordType), 'recordId' => trim($recordId)]);
	}//end link()

	/**
	 * Save a trimmed link draft, unless it is refused.
	 *
	 * @param array{party: string, kind: string, recordType: string, recordId: string} $draft The trimmed link.
	 *
	 * @return array<string, mixed> `status` plus either `partyLink` or `error`.
	 */
	private function linkDraft(array $draft): array {
		$refusal = $this->linkRefusal(draft: $draft);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->saveLink(draft: $draft);
	}//end linkDraft()

	/**
	 * Why a link may not be written: missing input, or the registry refuses the kind. Null when it may.
	 *
	 * @param array<string, string> $draft The trimmed party, kind, recordType (`<app>:<schema>:<type>`) and recordId.
	 *
	 * @return array<string, mixed>|null `status` plus `error`, or null.
	 */
	private function linkRefusal(array $draft): array|null {
		if ($draft['recordType'] === '' || $draft['recordId'] === '' || $draft['party'] === '') {
			return ['status' => 400, 'error' => 'A record type, a record and a party are required.'];
		}

		$answer = $this->registry->mayLink(recordType: $draft['recordType'], recordId: $draft['recordId'], kind: $draft['kind'], party: $draft['party']);
		if ($answer['allowed'] === false) {
			// 409, not 400: the write is well formed and refused on policy,
			// and the message is meant to be shown to the handler.
			return ['status' => 409, 'error' => $answer['reason']];
		}

		return null;
	}//end linkRefusal()

	/**
	 * Write a party link valid from today.
	 *
	 * @param array<string, string> $draft The trimmed party, kind, recordType and recordId.
	 *
	 * @return array<string, mixed> `status` plus either `partyLink` or `error`.
	 */
	private function saveLink(array $draft): array {
		$link = $draft + ['validFrom' => (new DateTimeImmutable('today'))->format('Y-m-d')];

		$saved = $this->write(object: $link);
		if ($saved === false) {
			return ['status' => 500, 'error' => 'The party link could not be saved.'];
		}

		return ['status' => 201, 'partyLink' => $link];
	}//end saveLink()

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

		$data = $this->readLinkData(linkId: $linkId);
		if ($data === null) {
			return ['status' => 404, 'error' => 'That party link could not be read.'];
		}

		$data['validUntil'] = (new DateTimeImmutable('today'))->format('Y-m-d');

		if ($this->write(object: $data, uuid: $linkId) === false) {
			return ['status' => 500, 'error' => 'The party link could not be ended.'];
		}

		return ['status' => 200, 'partyLink' => $data];
	}//end end()

	/**
	 * A party link's data, or null when it cannot be read.
	 *
	 * @param string $linkId The link's uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function readLinkData(string $linkId): array|null {
		return $this->readLogged(id: $linkId, failure: 'PartyLinkService: the link could not be read', idKey: 'uuid');
	}//end readLinkData()

	/**
	 * Write every accepted row of a batch, refusing the rest by the same rule.
	 *
	 * @param string $recordType The `<app>:<schema>:<type>` literal.
	 * @param string $recordId The record.
	 * @param array<int, array<string, string>> $rows Each with `party` and `kind`.
	 *
	 * @return array<string, mixed> `status`, the rows that landed and the rows refused.
	 *
	 * @spec openspec/specs/party-kind-registry/spec.md#requirement-a-party-link-with-an-unaccepted-kind-shall-be-refused-on-the-write-req-pkr-004
	 */
	public function import(string $recordType, string $recordId, array $rows): array {
		return $this->importRows(record: ['recordType' => trim($recordType), 'recordId' => trim($recordId)], rows: $rows);
	}//end import()

	/**
	 * Judge a batch for a record, write the accepted rows and report the refused ones.
	 *
	 * @param array{recordType: string, recordId: string} $record The trimmed record type and record.
	 * @param array<int, array<string, string>>           $rows   Each with `party` and `kind`.
	 *
	 * @return array<string, mixed> `status`, the rows that landed and the rows refused.
	 */
	private function importRows(array $record, array $rows): array {
		$verdict = $this->registry->judgeBatch(
			recordType: $record['recordType'],
			recordId: $record['recordId'],
			rows: $rows,
		);

		return [
			'status' => 200,
			'linked' => $this->writeAccepted(accepted: $verdict['accepted'], record: $record),
			'refused' => $verdict['refused'],
		];
	}//end importRows()

	/**
	 * Write a link valid from today for every accepted row; the links that landed.
	 *
	 * @param array<int, array<string, string>> $accepted The accepted rows, each with `party` and `kind`.
	 * @param array<string, string>             $record   The trimmed recordType and recordId.
	 *
	 * @return list<array<string, mixed>> The links written.
	 */
	private function writeAccepted(array $accepted, array $record): array {
		$landed = [];
		foreach ($accepted as $row) {
			$link = ['party' => $row['party'], 'kind' => $row['kind']] + $record + ['validFrom' => (new DateTimeImmutable('today'))->format('Y-m-d')];
			if ($this->write(object: $link) === true) {
				$landed[] = $link;
			}
		}

		return $landed;
	}//end writeAccepted()

	/**
	 * Save one party link.
	 *
	 * @param array<string, mixed> $object The link.
	 * @param string|null $uuid The link's uuid, for an update.
	 *
	 * @return bool True when the write landed.
	 */
	private function write(array $object, ?string $uuid = null): bool {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'partyLink_schema', '');
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
