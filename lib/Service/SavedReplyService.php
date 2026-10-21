<?php

/**
 * Pipelinq SavedReplyService.
 *
 * The team's saved replies for one channel, ready to insert: active ones
 * only, the party's language first, and the `{{client.name}}`,
 * `{{contact.name}}`, `{{ticket.title}}` and `{{agent.name}}` placeholders
 * filled from the record the agent answers on.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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
 * @spec openspec/changes/answer-and-saved-replies-on-the-server/specs/messaging-saved-replies/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IUserSession;
use Throwable;

/**
 * Lists saved replies with their placeholders filled.
 *
 * @spec openspec/changes/answer-and-saved-replies-on-the-server/specs/messaging-saved-replies/spec.md#requirement-the-server-offers-saved-replies-filled-for-the-record
 */
class SavedReplyService {

	/**
	 * Upper bound on saved replies read.
	 *
	 * @var int
	 */
	private const LIMIT = 200;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 * @param IAppConfig             $appConfig     App config, source of the register and schema ids.
	 * @param IUserSession           $userSession   The signed-in user, the agent.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * The saved replies for a channel, as template options.
	 *
	 * @param string $channel   The channel (`portal`, `email`, `sms`, `whatsapp`).
	 * @param string $ticketId  A ticket the agent answers on, or ''.
	 * @param string $contactId A contact the agent writes to, or ''.
	 * @param string $clientId  A client the agent writes to, or ''.
	 *
	 * @return array<int, array{uuid: string, name: string, values: array{body: string}}> The options.
	 *
	 * @spec openspec/changes/answer-and-saved-replies-on-the-server/specs/messaging-saved-replies/spec.md#requirement-the-server-offers-saved-replies-filled-for-the-record
	 */
	public function forChannel(string $channel, string $ticketId = '', string $contactId = '', string $clientId = ''): array {
		$ticket = $this->read(type: 'ticket', id: $ticketId);
		if ($contactId === '') {
			$contactId = (string)($ticket['contact'] ?? '');
		}

		$contact = $this->read(type: 'contact', id: $contactId);
		if ($clientId === '') {
			$clientId = (string)($ticket['client'] ?? $contact['client'] ?? '');
		}

		$client = $this->read(type: 'client', id: $clientId);
		$values = [
			'ticket.title' => (string)($ticket['title'] ?? ''),
			'client.name' => (string)($client['name'] ?? ''),
			'contact.name' => (string)($contact['name'] ?? ''),
			'agent.name' => $this->agentName(),
		];
		$party = $contact;
		if ($party === []) {
			$party = $client;
		}

		$language = (string)($party['correspondenceLanguage'] ?? $party['language'] ?? '');

		$options = [];
		foreach (self::forLanguage(replies: $this->replies(), channel: $channel, language: $language) as $reply) {
			$options[] = [
				'uuid' => (string)$reply['id'],
				'name' => (string)($reply['title'] ?? ''),
				'values' => ['body' => self::fill(body: (string)($reply['body'] ?? ''), values: $values)],
			];
		}

		return $options;
	}//end forChannel()

	/**
	 * The active replies of a channel, the given language first, then by title.
	 *
	 * @param array<int, array<string, mixed>> $replies  The stored replies.
	 * @param string                           $channel  The channel.
	 * @param string                           $language The party's language, '' for none.
	 *
	 * @return array<int, array<string, mixed>> The replies on offer.
	 *
	 * @spec openspec/changes/answer-and-saved-replies-on-the-server/specs/messaging-saved-replies/spec.md#requirement-the-server-offers-saved-replies-filled-for-the-record
	 */
	public static function forLanguage(array $replies, string $channel, string $language): array {
		$wanted = strtolower($language);
		$offered = array_values(
			array_filter(
				$replies,
				static fn (array $reply): bool => ($reply['active'] ?? true) !== false
					&& is_array($reply['channels'] ?? null) === true
					&& in_array($channel, $reply['channels'], true) === true
			)
		);
		usort(
			$offered,
			static function (array $a, array $b) use ($wanted): int {
				$aFirst = $wanted !== '' && strtolower((string)($a['language'] ?? '')) === $wanted;
				$bFirst = $wanted !== '' && strtolower((string)($b['language'] ?? '')) === $wanted;
				if ($aFirst === true && $bFirst === false) {
					return -1;
				}

				if ($bFirst === true && $aFirst === false) {
					return 1;
				}

				return strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
			}
		);

		return $offered;
	}//end forLanguage()

	/**
	 * Fill `{{group.key}}` placeholders; an unknown or empty one stays as written.
	 *
	 * @param string                $body   The reply text.
	 * @param array<string, string> $values The values by placeholder.
	 *
	 * @return string The filled text.
	 *
	 * @spec openspec/changes/answer-and-saved-replies-on-the-server/specs/messaging-saved-replies/spec.md#requirement-the-server-offers-saved-replies-filled-for-the-record
	 */
	public static function fill(string $body, array $values): string {
		return (string)preg_replace_callback(
			'/\{\{\s*([a-z]+\.[a-z]+)\s*\}\}/',
			static function (array $match) use ($values): string {
				$value = trim((string)($values[$match[1]] ?? ''));
				if ($value === '') {
					return $match[0];
				}

				return $value;
			},
			$body
		);
	}//end fill()

	/**
	 * The stored saved replies.
	 *
	 * @return array<int, array<string, mixed>> The replies, with their id.
	 */
	private function replies(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'savedReply_schema', '');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $this->objectService->findAll(config: ['filters' => ['register' => $register, 'schema' => $schema], 'limit' => self::LIMIT]);
		} catch (Throwable) {
			return [];
		}

		$replies = [];
		foreach ($rows as $row) {
			$data = $this->rowData(row: $row);
			if ($data['id'] !== '') {
				$replies[] = $data;
			}
		}

		return $replies;
	}//end replies()

	/**
	 * Read one record as the signed-in user.
	 *
	 * @param string $type The object type: ticket, contact or client.
	 * @param string $id   The uuid, '' for none.
	 *
	 * @return array<string, mixed> The record, [] when absent or unreadable.
	 */
	private function read(string $type, string $id): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $type . '_schema', '');
		if ($id === '' || $register === '' || $schema === '') {
			return [];
		}

		try {
			$object = $this->objectService->find(id: $id, register: $register, schema: $schema);
		} catch (Throwable) {
			return [];
		}

		if ($object === null) {
			return [];
		}

		return (array)$object->getObject();
	}//end read()

	/**
	 * The signed-in user's name, for `{{agent.name}}`.
	 *
	 * @return string The display name, else the user id, '' when signed out.
	 */
	private function agentName(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		$name = (string)$user->getDisplayName();
		if ($name === '') {
			$name = $user->getUID();
		}

		return $name;
	}//end agentName()

	/**
	 * A findAll row as data with its id.
	 *
	 * @param mixed $row An array or an object entity.
	 *
	 * @return array<string, mixed> The data, `id` '' when it has none.
	 */
	private function rowData(mixed $row): array {
		if (is_array($row) === true) {
			$row['id'] = (string)($row['id'] ?? '');
			return $row;
		}

		$data = (array)$row->getObject();
		$data['id'] = (string)($data['id'] ?? $row->getUuid());
		return $data;
	}//end rowData()
}//end class
