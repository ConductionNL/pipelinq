<?php

/**
 * Pipelinq Question Detail Service
 *
 * What a resident reads on the detail of their own question about a Woo
 * dossier (hydra woo-citizen-journey J4.2): the conversation as a timeline,
 * and the dossier as it was when they asked as a list of documents with their
 * links. When an employee turned the question into a Woo request, the request
 * leads that list.
 *
 * The portal calls both through the provider methods the `myQuestions`
 * collection names (`timeline`, `itemList`), and only after it read the
 * ticket under the resident's own scope. So the id is already proven; this
 * service still answers nothing for a ticket that is not a portal question.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Portal
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
 * @spec openspec/changes/question-detail-on-the-portal/specs/dossier-questions/spec.md#requirement-a-resident-reads-their-question-the-dossier-it-was-about-and-the-answers-req-qdp-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCA\Pipelinq\Service\WooRequestConversionService;
use OCP\IL10N;

/**
 * Builds the timeline and the item list of one portal question.
 *
 * @spec openspec/changes/question-detail-on-the-portal/specs/dossier-questions/spec.md#requirement-a-resident-reads-their-question-the-dossier-it-was-about-and-the-answers-req-qdp-001
 */
class QuestionDetailService {
	/**
	 * The pipelinq schema key of the ticket supertype.
	 *
	 * @var string
	 */
	private const TICKET = 'ticket';

	/**
	 * Where the resident's Woo request opens: the portal site, with the case
	 * named in the fragment the portal reads on load (`#open=app/collection/id`).
	 * `mijnZaken` is dossiq's case collection for a resident; the Woo request
	 * pipelinq's conversion starts is one of its cases.
	 *
	 * @var string
	 */
	public const WOO_REQUEST_LINK = '/index.php/apps/portaliq/site#open=dossiq/mijnZaken/';

	/**
	 * Constructor.
	 *
	 * @param MainRegisterReader $tickets Reads the pipelinq ticket.
	 * @param IL10N              $l10n    Translates the lines the resident reads.
	 *
	 * @spec openspec/changes/question-detail-on-the-portal/specs/dossier-questions/spec.md#requirement-a-resident-reads-their-question-the-dossier-it-was-about-and-the-answers-req-qdp-001
	 */
	public function __construct(
		private readonly MainRegisterReader $tickets,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The conversation on one question: the question, every answer, every
	 * reply, and the conversion into a Woo request, each with its moment.
	 *
	 * An answer comes from `portalAnswers`. The current `customerMessage` is
	 * added when that list does not end with it (a question answered before
	 * the list existed, or an answer saved elsewhere); it then carries the
	 * ticket's last change as its moment, as the request detail does.
	 *
	 * @param string $ticketId The question, already proven to be the resident's.
	 *
	 * @return array<int, array{id: string, occurredAt: string, message: string}> The entries, oldest first.
	 *
	 * @spec openspec/changes/question-detail-on-the-portal/specs/dossier-questions/spec.md#requirement-a-resident-reads-their-question-the-dossier-it-was-about-and-the-answers-req-qdp-001
	 */
	public function timeline(string $ticketId): array {
		$ticket = $this->question(ticketId: $ticketId);
		if ($ticket === null) {
			return [];
		}

		$updated = $this->text(value: ($ticket['@self']['updated'] ?? ''));
		$entries = [];
		$question = $this->text(value: ($ticket['description'] ?? ''));
		if ($question !== '') {
			$asked = $this->text(value: ($ticket['occurredAt'] ?? ''));
			$entries[] = $this->entry(id: 'question', moment: $asked, message: $this->l10n->t('You asked: %s', [$question]));
		}

		$entries = array_merge($entries, $this->answers(ticket: $ticket, updated: $updated), $this->replies(ticket: $ticket));
		if ($this->isConverted(ticket: $ticket) === true) {
			$entries[] = $this->entry(id: 'converted', moment: $updated, message: $this->l10n->t('Your question is now a Woo request.'));
		}

		return $entries;
	}//end timeline()

	/**
	 * Every answer with its moment, and the current answer when the list does
	 * not end with it.
	 *
	 * @param array<string, mixed> $ticket  The question.
	 * @param string               $updated The ticket's last change.
	 *
	 * @return array<int, array{id: string, occurredAt: string, message: string}>
	 */
	private function answers(array $ticket, string $updated): array {
		$entries = [];
		$last = '';
		foreach ($this->listOf(value: ($ticket['portalAnswers'] ?? null)) as $index => $answer) {
			$last = $this->text(value: ($answer['message'] ?? ''));
			if ($last !== '') {
				$sent = $this->text(value: ($answer['createdAt'] ?? ''));
				$entries[] = $this->entry(id: 'answer-'.$index, moment: $sent, message: $this->l10n->t('Answer: %s', [$last]));
			}
		}

		$current = $this->text(value: ($ticket['customerMessage'] ?? ''));
		if ($current !== '' && $current !== $last) {
			$entries[] = $this->entry(id: 'answer', moment: $updated, message: $this->l10n->t('Answer: %s', [$current]));
		}

		return $entries;
	}//end answers()

	/**
	 * The resident's replies, each with its moment.
	 *
	 * @param array<string, mixed> $ticket The question.
	 *
	 * @return array<int, array{id: string, occurredAt: string, message: string}>
	 */
	private function replies(array $ticket): array {
		$entries = [];
		foreach ($this->listOf(value: ($ticket['portalReplies'] ?? null)) as $index => $reply) {
			$message = $this->text(value: ($reply['message'] ?? ''));
			if ($message !== '') {
				$sent = $this->text(value: ($reply['createdAt'] ?? ''));
				$entries[] = $this->entry(id: 'reply-'.$index, moment: $sent, message: $this->l10n->t('Your reply: %s', [$message]));
			}
		}

		return $entries;
	}//end replies()

	/**
	 * What the question is about: the Woo request it became, when it did, and
	 * the documents of the dossier as they were when the resident asked, each
	 * with its public link when it had one.
	 *
	 * @param string $ticketId The question, already proven to be the resident's.
	 *
	 * @return array<int, array{id: string, title: string, url: string, note: string}> The items.
	 *
	 * @spec openspec/changes/question-detail-on-the-portal/specs/dossier-questions/spec.md#requirement-a-converted-question-links-to-the-woo-request-req-qdp-002
	 */
	public function dossierItems(string $ticketId): array {
		$ticket = $this->question(ticketId: $ticketId);
		if ($ticket === null) {
			return [];
		}

		$items = [];
		$case = $this->text(value: ($ticket['caseReference'] ?? ''));
		if ($case !== '' && $this->isConverted(ticket: $ticket) === true) {
			$items[] = [
				'id' => $case,
				'title' => $this->l10n->t('Your Woo request'),
				'url' => self::WOO_REQUEST_LINK.rawurlencode($case),
				'note' => $this->l10n->t('Your question is now this Woo request. Follow it under your cases.'),
			];
		}

		$reference = ($ticket['subjectReference'] ?? []);
		foreach ($this->listOf(value: ($reference['items'] ?? null)) as $item) {
			$title = $this->text(value: ($item['title'] ?? ''));
			if ($title !== '') {
				$items[] = ['id' => '', 'title' => $title, 'url' => $this->text(value: ($item['url'] ?? '')), 'note' => ''];
			}
		}

		return $items;
	}//end dossierItems()

	/**
	 * The ticket when it is a question a resident asked on the portal, else null.
	 *
	 * @param string $ticketId The ticket id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function question(string $ticketId): ?array {
		$ticket = $this->tickets->find(schemaKey: self::TICKET, id: $ticketId);
		if ($ticket === null || is_array($ticket['subjectReference'] ?? null) === false
			|| $this->text(value: ($ticket['portalSubject'] ?? '')) === ''
		) {
			return null;
		}

		return $ticket;
	}//end question()

	/**
	 * Whether an employee turned the question into a Woo request.
	 *
	 * @param array<string, mixed> $ticket The ticket.
	 *
	 * @return bool
	 */
	private function isConverted(array $ticket): bool {
		return ($ticket['status'] ?? null) === WooRequestConversionService::STATUS_CONVERTED;
	}//end isConverted()

	/**
	 * One timeline entry.
	 *
	 * @param string $id      A stable key within this question.
	 * @param string $moment  The moment, ISO 8601, or '' when unknown.
	 * @param string $message What the resident reads.
	 *
	 * @return array{id: string, occurredAt: string, message: string}
	 */
	private function entry(string $id, string $moment, string $message): array {
		return ['id' => $id, 'occurredAt' => $moment, 'message' => $message];
	}//end entry()

	/**
	 * The array entries of a list, or [] when it is not one.
	 *
	 * @param mixed $value The candidate list.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function listOf(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_values(array_filter($value, 'is_array'));
	}//end listOf()

	/**
	 * A trimmed string, or '' when the value is not one.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function text(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end text()
}//end class
