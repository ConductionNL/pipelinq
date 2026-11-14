<?php

/**
 * Pipelinq MergedAwayCard.
 *
 * Keeps the address book card of a client or contact that OpenRegister merged
 * into another record, notes in it where the record went, and takes that note
 * off again when the merge is undone, so the card belongs to its own record
 * once more.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Merge
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/clients-reversible-merge/tasks.md#2.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Merge;

use OCA\Pipelinq\Kernel\Contracts\MergedAwayCardInterface;
use OCP\Constants;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IAddressBook;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Notes a merge in the merged-away record's address book card, and takes it off again.
 *
 * The card is never deleted: during the reversal window it is the record's own
 * card, and a reversal must find it where it was.
 *
 * @spec openspec/changes/clients-reversible-merge/tasks.md#2.3
 */
class MergedAwayCard implements MergedAwayCardInterface {

	/**
	 * The start of the note line a merge adds, followed by the surviving record's uuid and `]`.
	 */
	public const MARKER = '[merged-into:';

	/**
	 * Constructor.
	 *
	 * @param IContactsManager $contactsManager The user's address books.
	 * @param IL10N            $l10n            Translates the note.
	 * @param LoggerInterface  $logger          Logs a card that could not be written.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IContactsManager $contactsManager,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Keep a merged-away record's address book card and note in it where the record went.
	 *
	 * A note from an earlier merge of the same card is replaced, not repeated.
	 *
	 * @param string $contactsUid The card's UID.
	 * @param string $intoUuid    The record that stays.
	 * @param string $intoName    Its name.
	 *
	 * @return bool True when the card was found and written.
	 *
	 * @spec openspec/changes/clients-reversible-merge/tasks.md#2.3
	 */
	public function mark(string $contactsUid, string $intoUuid, string $intoName): bool {
		$line = self::MARKER.$intoUuid.'] '.$this->l10n->t('Merged into %s', [$intoName]);
		return $this->rewriteNote(
			contactsUid: $contactsUid,
			rewrite: static fn (string $note): string => trim(self::withoutMarker(note: $note)."\n".$line)
		);
	}//end mark()

	/**
	 * Take the merge note off a card again, so it belongs to its own record once more.
	 *
	 * @param string $contactsUid The card's UID.
	 *
	 * @return bool True when the card was found and written.
	 *
	 * @spec openspec/changes/clients-reversible-merge/tasks.md#2.3
	 */
	public function unmark(string $contactsUid): bool {
		return $this->rewriteNote(
			contactsUid: $contactsUid,
			rewrite: static fn (string $note): string => self::withoutMarker(note: $note)
		);
	}//end unmark()

	/**
	 * A note without the lines a merge added.
	 *
	 * @param string $note The note.
	 *
	 * @return string The note, trimmed.
	 */
	private static function withoutMarker(string $note): string {
		$kept = [];
		foreach (preg_split('/\R/', $note) as $noteLine) {
			if (str_starts_with(trim($noteLine), self::MARKER) === false) {
				$kept[] = $noteLine;
			}
		}

		return trim(implode("\n", $kept));
	}//end withoutMarker()

	/**
	 * Find the card in a writable address book and write its rewritten note.
	 *
	 * Only the NOTE is written; the address book keeps every other property.
	 * An unchanged note is not written again.
	 *
	 * @param string   $contactsUid The card's UID.
	 * @param \Closure $rewrite     Maps the current note to the new one.
	 *
	 * @return bool True when the card was found and holds the new note.
	 */
	private function rewriteNote(string $contactsUid, \Closure $rewrite): bool {
		if (trim($contactsUid) === '' || $this->contactsManager->isEnabled() === false) {
			return false;
		}

		foreach ($this->contactsManager->getUserAddressBooks() as $book) {
			$rewritten = $this->rewriteNoteIn(book: $book, contactsUid: $contactsUid, rewrite: $rewrite);
			if ($rewritten !== null) {
				return $rewritten;
			}
		}

		return false;
	}//end rewriteNote()

	/**
	 * Rewrite the card's note in one address book.
	 *
	 * @param IAddressBook $book        The address book.
	 * @param string       $contactsUid The card's UID.
	 * @param \Closure     $rewrite     Maps the current note to the new one.
	 *
	 * @return bool|null Null when the card is not in this book, otherwise whether it holds the new note.
	 */
	private function rewriteNoteIn(IAddressBook $book, string $contactsUid, \Closure $rewrite): bool|null {
		$card = $this->findCard(addressBook: $book, uid: $contactsUid);
		if ($card === null) {
			return null;
		}

		if ($this->isWritable(addressBook: $book) === false) {
			$this->logger->warning('Pipelinq merge: the card of a merged record is in a read-only address book', ['uid' => $contactsUid]);
			return false;
		}

		return $this->writeNote(book: $book, card: $card, contactsUid: $contactsUid, rewrite: $rewrite);
	}//end rewriteNoteIn()

	/**
	 * Write the rewritten note on a writable card, unless it is unchanged.
	 *
	 * @param IAddressBook         $book        The address book.
	 * @param array<string, mixed> $card        The card.
	 * @param string               $contactsUid The card's UID.
	 * @param \Closure             $rewrite     Maps the current note to the new one.
	 *
	 * @return bool True when the card holds the new note.
	 */
	private function writeNote(IAddressBook $book, array $card, string $contactsUid, \Closure $rewrite): bool {
		$note = self::firstValue(value: ($card['NOTE'] ?? ''));
		$newNote = $rewrite($note);
		if ($newNote === $note) {
			return true;
		}

		try {
			$book->createOrUpdate(['URI' => (string)$card['URI'], 'UID' => $contactsUid, 'NOTE' => $newNote]);
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq merge: the card of a merged record could not be written', ['uid' => $contactsUid, 'error' => $e->getMessage()]);
			return false;
		}

		return true;
	}//end writeNote()

	/**
	 * The card with exactly this UID in an address book.
	 *
	 * @param IAddressBook $addressBook The address book.
	 * @param string       $uid         The card's UID.
	 *
	 * @return array<string, mixed>|null The card, with its URI, or null.
	 */
	private function findCard(IAddressBook $addressBook, string $uid): ?array {
		try {
			$hits = $addressBook->search($uid, ['UID'], ['limit' => 5, 'wildcard' => false]);
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq merge: looking up the card of a merged record failed', ['uid' => $uid, 'error' => $e->getMessage()]);
			return null;
		}

		foreach ($hits as $hit) {
			// A backend may answer a partial match, so the UID is compared.
			if (is_array($hit) === true && self::firstValue(value: ($hit['UID'] ?? '')) === $uid && (string)($hit['URI'] ?? '') !== '') {
				return $hit;
			}
		}

		return null;
	}//end findCard()

	/**
	 * Whether pipelinq may write to an address book.
	 *
	 * @param IAddressBook $addressBook The address book.
	 *
	 * @return bool
	 */
	private function isWritable(IAddressBook $addressBook): bool {
		if ($addressBook->isSystemAddressBook() === true) {
			return false;
		}

		return ($addressBook->getPermissions() & Constants::PERMISSION_UPDATE) !== 0;
	}//end isWritable()

	/**
	 * The first value of a vCard property, which a search answers as a string or a list.
	 *
	 * @param mixed $value The property value.
	 *
	 * @return string
	 */
	private static function firstValue(mixed $value): string {
		if (is_array($value) === true) {
			$value = reset($value);
		}

		if (is_scalar($value) === false) {
			return '';
		}

		return (string)$value;
	}//end firstValue()
}//end class
