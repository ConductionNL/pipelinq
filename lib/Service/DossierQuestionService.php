<?php

/**
 * Pipelinq Dossier Question Service
 *
 * A resident asks the municipality a question about their own Woo dossier
 * (hydra woo-citizen-journey C4). The dossier is an opencatalogi `collection`;
 * this service reads it once, server side, checks that the resident owns it,
 * and files a portal request ticket carrying a snapshot of what the resident
 * saw. It never reads the dossier again for that ticket.
 *
 * The resident's replies to the answer land in the ticket's `portalReplies`,
 * the same list pipelinq's own portal writes (PortalRequestService::addReply).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\Portal\MainRegisterReader;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Files a question about a dossier and records the resident's replies.
 *
 * Both entry points return null when the resident may not act on the target:
 * the caller answers 404 for a foreign object and a missing one alike, so no
 * id can be probed (hydra woo-citizen-journey, Identity).
 *
 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
 */
class DossierQuestionService {
	/**
	 * The `subjectReference.type` of a dossier snapshot (C4).
	 *
	 * @var string
	 */
	public const SUBJECT_TYPE = 'opencatalogi.collection';

	/**
	 * The opencatalogi register that holds dossiers and publications (C1).
	 *
	 * @var string
	 */
	public const DOSSIER_REGISTER = 'publication';

	/**
	 * The opencatalogi schema of a resident's dossier (C1).
	 *
	 * @var string
	 */
	public const DOSSIER_SCHEMA = 'collection';

	/**
	 * The opencatalogi schema of a publication.
	 *
	 * @var string
	 */
	public const PUBLICATION_SCHEMA = 'publication';

	/**
	 * The anonymous read of one public publication in opencatalogi.
	 *
	 * @var string
	 */
	public const PUBLIC_PUBLICATION_PATH = '/index.php/apps/opencatalogi/api/search/';

	/**
	 * The ticket channel a portal question is filed under.
	 *
	 * @var string
	 */
	public const CHANNEL_PORTAL = 'portal';

	/**
	 * The pipelinq schema key of the ticket supertype.
	 *
	 * @var string
	 */
	private const TICKET = 'ticket';

	/**
	 * Status a ticket has while the handler waits for the resident.
	 *
	 * @var string
	 */
	private const STATUS_AWAITING_CUSTOMER = 'awaiting_customer';

	/**
	 * Status a ticket returns to when the resident replies.
	 *
	 * @var string
	 */
	private const STATUS_IN_PROGRESS = 'in_progress';

	/**
	 * Constructor.
	 *
	 * @param MainRegisterReader     $tickets       Reads and writes pipelinq tickets.
	 * @param ObjectServiceInterface $objectService Reads the opencatalogi dossier and its publications.
	 * @param IURLGenerator          $urlGenerator  Builds the public publication links.
	 * @param ITimeFactory           $time          The clock.
	 * @param LoggerInterface        $logger        Logger.
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
	 */
	public function __construct(
		private readonly MainRegisterReader $tickets,
		private readonly ObjectServiceInterface $objectService,
		private readonly IURLGenerator $urlGenerator,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * File a question about a dossier the resident owns.
	 *
	 * @param string $subjectRef   The verified portal subject reference.
	 * @param string $collectionId The dossier id the resident names.
	 * @param string $question     The question text.
	 * @param string $title        An optional subject line; the dossier title stands in.
	 *
	 * @return array<string, mixed>|null The new ticket's id and status, or null when the dossier is not the resident's.
	 *
	 * @throws \RuntimeException When the ticket cannot be saved.
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
	 */
	public function ask(string $subjectRef, string $collectionId, string $question, string $title = ''): ?array {
		$collection = $this->ownedCollection(subjectRef: $subjectRef, collectionId: $collectionId);
		if ($collection === null) {
			return null;
		}

		$snapshot = $this->snapshot(collectionId: $collectionId, collection: $collection);
		$subject = trim($title);
		if ($subject === '') {
			$subject = $snapshot['title'];
		}

		$saved = $this->tickets->save(
			schemaKey: self::TICKET,
			data: [
				'ticketType' => TicketService::TYPE_REQUEST,
				'title' => $subject,
				'description' => $question,
				'channel' => self::CHANNEL_PORTAL,
				'status' => 'new',
				'occurredAt' => $this->time->getDateTime()->format(DATE_ATOM),
				'portalSubject' => $subjectRef,
				'subjectReference' => $snapshot,
			]
		);

		return [
			'id' => $this->idOf(object: $saved),
			'status' => (string)($saved['status'] ?? 'new'),
		];
	}//end ask()

	/**
	 * Add the resident's reply to their own question.
	 *
	 * @param string $subjectRef The verified portal subject reference.
	 * @param string $ticketId   The ticket the resident replies on.
	 * @param string $message    The reply text.
	 *
	 * @return array<string, mixed>|null The ticket's id, status and replies, or null when it is not the resident's question.
	 *
	 * @throws \RuntimeException When the ticket cannot be saved.
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-replies-to-an-answer-req-qcd-004
	 */
	public function reply(string $subjectRef, string $ticketId, string $message): ?array {
		$ticket = $this->tickets->find(schemaKey: self::TICKET, id: $ticketId);
		if ($ticket === null || $this->isOwnQuestion(ticket: $ticket, subjectRef: $subjectRef) === false) {
			return null;
		}

		$replies = [];
		if (is_array($ticket['portalReplies'] ?? null) === true) {
			$replies = array_values($ticket['portalReplies']);
		}

		$replies[] = [
			'message' => $message,
			'createdAt' => $this->time->getDateTime()->format(DATE_ATOM),
		];
		$ticket['portalReplies'] = $replies;
		if (($ticket['status'] ?? null) === self::STATUS_AWAITING_CUSTOMER) {
			$ticket['status'] = self::STATUS_IN_PROGRESS;
		}

		$saved = $this->tickets->save(schemaKey: self::TICKET, data: $ticket, id: $ticketId);

		return [
			'id' => $ticketId,
			'status' => (string)($saved['status'] ?? ($ticket['status'] ?? '')),
			'portalReplies' => $replies,
		];
	}//end reply()

	/**
	 * The snapshot of a dossier as the resident sees it now (C4).
	 *
	 * Each item's publication is read for its title and linked to its public
	 * page. An item whose publication cannot be read keeps the title the
	 * dossier stored for it, its note, or the publication id, and gets no link.
	 *
	 * @param string               $collectionId The dossier id.
	 * @param array<string, mixed> $collection   The dossier object.
	 *
	 * @return array{type: string, id: string, title: string, items: array<int, array{title: string, url: string}>}
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-question-keeps-the-dossier-as-it-was-when-asked-req-qcd-002
	 */
	public function snapshot(string $collectionId, array $collection): array {
		$items = [];
		foreach ((array)($collection['items'] ?? []) as $item) {
			if (is_array($item) === false) {
				continue;
			}

			$items[] = $this->snapshotItem(item: $item);
		}

		return [
			'type' => self::SUBJECT_TYPE,
			'id' => $collectionId,
			'title' => (string)($collection['title'] ?? ''),
			'items' => $items,
		];
	}//end snapshot()

	/**
	 * The dossier, when the resident owns it.
	 *
	 * Read without RBAC: the portal subject has no Nextcloud account, and the
	 * owner comparison below is the authorisation.
	 *
	 * @param string $subjectRef   The verified portal subject reference.
	 * @param string $collectionId The dossier id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function ownedCollection(string $subjectRef, string $collectionId): ?array {
		if ($subjectRef === '' || $collectionId === '') {
			return null;
		}

		$collection = $this->read(schema: self::DOSSIER_SCHEMA, id: $collectionId);
		if ($collection === null || (string)($collection['owner'] ?? '') !== $subjectRef) {
			return null;
		}

		return $collection;
	}//end ownedCollection()

	/**
	 * One snapshot item: the publication's title and its public link.
	 *
	 * @param array<string, mixed> $item A dossier item (C1).
	 *
	 * @return array{title: string, url: string}
	 */
	private function snapshotItem(array $item): array {
		$publicationId = (string)($item['publication'] ?? '');
		$publication = null;
		if ($publicationId !== '') {
			$publication = $this->read(schema: self::PUBLICATION_SCHEMA, id: $publicationId);
		}

		// The publication's title now; else the title the dossier kept when the
		// item was added (C1), else the resident's note.
		$title = (string)($publication['title'] ?? '');
		if ($title === '') {
			$title = (string)($item['title'] ?? '');
		}

		if ($title === '') {
			$title = (string)($item['note'] ?? '');
		}

		if ($title === '') {
			$title = $publicationId;
		}

		$url = '';
		if ($publication !== null) {
			$url = $this->urlGenerator->getAbsoluteURL(self::PUBLIC_PUBLICATION_PATH.rawurlencode($publicationId));
		}

		return ['title' => $title, 'url' => $url];
	}//end snapshotItem()

	/**
	 * Whether a ticket is a portal question asked by this resident.
	 *
	 * @param array<string, mixed> $ticket     The ticket.
	 * @param string               $subjectRef The verified portal subject reference.
	 *
	 * @return bool
	 */
	private function isOwnQuestion(array $ticket, string $subjectRef): bool {
		return $subjectRef !== ''
			&& (string)($ticket['portalSubject'] ?? '') === $subjectRef
			&& is_array($ticket['subjectReference'] ?? null) === true;
	}//end isOwnQuestion()

	/**
	 * Read one opencatalogi object as an array, or null.
	 *
	 * @param string $schema The opencatalogi schema slug.
	 * @param string $id     The object id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function read(string $schema, string $id): ?array {
		try {
			$entity = $this->objectService->find(
				id: $id,
				register: self::DOSSIER_REGISTER,
				schema: $schema,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->debug('Pipelinq: dossier object not readable', ['schema' => $schema, 'reason' => $e->getMessage()]);
			return null;
		}

		if ($entity === null) {
			return null;
		}

		// The object's own data, never the serialised entity: the dossier's
		// `owner` property is the resident, while the entity's metadata owner
		// is a Nextcloud user.
		return $entity->getObject();
	}//end read()

	/**
	 * The id of a saved object.
	 *
	 * @param array<string, mixed> $object The saved object.
	 *
	 * @return string
	 */
	private function idOf(array $object): string {
		foreach ([($object['id'] ?? null), ($object['uuid'] ?? null), ($object['@self']['id'] ?? null)] as $candidate) {
			if (is_string($candidate) === true && $candidate !== '') {
				return $candidate;
			}
		}

		return '';
	}//end idOf()
}//end class
