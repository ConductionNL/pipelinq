<?php

/**
 * Pipelinq ContactMomentFilingService.
 *
 * One telephone call about three cases is one contact moment filed on three
 * cases. Filing onto a further case is an act: it appends a reference, records
 * who did it and when, and leaves the contact moment's content alone. It never
 * creates a second record, because two records are two things to correct when
 * the caller says something different afterwards.
 *
 * Four refusals live here, and each of them names what it refused:
 *
 *   - the same case twice, which would make a set that is not one;
 *   - a primary outside the set, which would make a surface show a case the
 *     contact moment is not on;
 *   - emptying the set, which would leave a contact moment nowhere;
 *   - removing the primary without naming the next one, which would leave the
 *     app to pick, and picking by position is exactly what the primary exists
 *     to prevent.
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
 * @spec openspec/changes/one-contact-moment-on-several-cases/specs/contactmomenten/spec.md#requirement-filing-onto-a-further-case-is-an-act-not-a-copy-req-cms-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * File one contact moment onto several cases, and take it off again.
 *
 * @spec openspec/changes/one-contact-moment-on-several-cases/specs/contactmomenten/spec.md#requirement-filing-onto-a-further-case-is-an-act-not-a-copy-req-cms-004
 */
class ContactMomentFilingService {
	/**
	 * Constructor.
	 *
	 * @param TicketService $ticketService Resolver for the unified `ticket` supertype.
	 * @param IUserSession $userSession The acting user, recorded in the trail.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly TicketService $ticketService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The cases a stored contact moment is filed on, in order, deduplicated.
	 *
	 * Reads `caseReferences` and falls back to the single `caseReference` for a
	 * row the migration has not reached yet, so a caller never has to know
	 * which shape a given row is in.
	 *
	 * @param array<string, mixed> $moment The stored contact moment.
	 *
	 * @return array<int, string> The ordered set of case references.
	 *
	 * @spec openspec/changes/one-contact-moment-on-several-cases/specs/contactmomenten/spec.md#requirement-a-contact-moment-references-a-case-semantically-req-cmd-002
	 */
	public function caseSetOf(array $moment): array {
		$set = ($moment['caseReferences'] ?? null);
		if (is_array($set) === false || $set === []) {
			$single = trim((string)($moment['caseReference'] ?? ''));
			$set = [];
			if ($single !== '') {
				$set = [$single];
			}
		}

		$ordered = [];
		foreach ($set as $reference) {
			$reference = trim((string)$reference);
			if ($reference !== '' && in_array($reference, $ordered, true) === false) {
				$ordered[] = $reference;
			}
		}

		return $ordered;
	}//end caseSetOf()

	/**
	 * The case a surface shows when it can show only one.
	 *
	 * Never answered by position: a stored primary that is still a member wins,
	 * and only a row that has no usable primary falls back to the first
	 * member, which is what the migration writes anyway.
	 *
	 * @param array<string, mixed> $moment The stored contact moment.
	 *
	 * @return string The primary case reference, or '' when the set is empty.
	 *
	 * @spec openspec/changes/one-contact-moment-on-several-cases/specs/contactmomenten/spec.md#requirement-one-reference-is-the-primary-one-and-it-is-named-req-cms-002
	 */
	public function primaryOf(array $moment): string {
		$set = $this->caseSetOf(moment: $moment);
		$primary = trim((string)($moment['primaryCaseReference'] ?? ''));

		if ($primary !== '' && in_array($primary, $set, true) === true) {
			return $primary;
		}

		return ($set[0] ?? '');
	}//end primaryOf()

	/**
	 * File an existing contact moment onto one further case.
	 *
	 * @param string $momentId The contact moment's uuid.
	 * @param string $caseId The case to file it onto.
	 *
	 * @return array<string, mixed> `status` plus either `contactMoment` or `error`.
	 *
	 * @spec openspec/changes/one-contact-moment-on-several-cases/specs/contactmomenten/spec.md#requirement-filing-onto-a-further-case-is-an-act-not-a-copy-req-cms-004
	 */
	public function fileOnAlsoCase(string $momentId, string $caseId): array {
		$caseId = trim($caseId);
		if ($caseId === '') {
			return ['status' => 400, 'error' => 'A case is required.'];
		}

		$moment = $this->read(momentId: $momentId);
		if ($moment === null) {
			return ['status' => 404, 'error' => 'That contact moment could not be read.'];
		}

		$set = $this->caseSetOf(moment: $moment);
		if (in_array($caseId, $set, true) === true) {
			return [
				'status' => 409,
				'error' => "This contact moment is already filed on {$caseId}.",
			];
		}

		$set[] = $caseId;

		return $this->write(
			moment: $moment,
			set: $set,
			primary: $this->primaryOf(moment: $moment),
			trail: ['case' => $caseId, 'act' => 'filed'],
		);
	}//end fileOnAlsoCase()

	/**
	 * Take a contact moment off one case.
	 *
	 * @param string $momentId The contact moment's uuid.
	 * @param string $caseId The case to take it off.
	 * @param string|null $newPrimary The next primary, required when the
	 *   primary itself is being removed.
	 *
	 * @return array<string, mixed> `status` plus either `contactMoment` or `error`.
	 *
	 * @spec openspec/changes/one-contact-moment-on-several-cases/specs/contactmomenten/spec.md#requirement-a-contact-moment-cannot-be-left-with-no-case-req-cms-006
	 */
	public function unfileFromCase(string $momentId, string $caseId, ?string $newPrimary = null): array {
		$caseId = trim($caseId);
		$moment = $this->read(momentId: $momentId);
		if ($moment === null) {
			return ['status' => 404, 'error' => 'That contact moment could not be read.'];
		}

		$set = $this->caseSetOf(moment: $moment);
		if (in_array($caseId, $set, true) === false) {
			return ['status' => 404, 'error' => "This contact moment is not filed on {$caseId}."];
		}

		if (count($set) === 1) {
			return [
				'status' => 409,
				'error' => 'A contact moment has to stay on at least one case.',
			];
		}

		$remaining = array_values(array_filter($set, static fn (string $r): bool => $r !== $caseId));
		$primary = $this->primaryOf(moment: $moment);
		$newPrimary = trim((string)$newPrimary);

		if ($primary === $caseId) {
			if ($newPrimary === '') {
				return [
					'status' => 409,
					'error' => 'Removing the primary case needs the next primary named in the same act.',
				];
			}

			if (in_array($newPrimary, $remaining, true) === false) {
				return [
					'status' => 409,
					'error' => "The new primary has to be one of the remaining cases; {$newPrimary} is not.",
				];
			}

			$primary = $newPrimary;
		}

		return $this->write(
			moment: $moment,
			set: $remaining,
			primary: $primary,
			trail: ['case' => $caseId, 'act' => 'unfiled'],
		);
	}//end unfileFromCase()

	/**
	 * What to say about a contact moment that is on more than one case.
	 *
	 * A case the reader may not see is reported as a count, never by title: the
	 * marker exists to warn an editor, not to leak the docket of a case they
	 * have no business reading.
	 *
	 * @param array<string, mixed> $moment The stored contact moment.
	 * @param string $hostId The case the reader is looking from.
	 * @param callable(string): bool $mayRead Answers whether the reader may see a case.
	 *
	 * @return array{shared: bool, alsoOnCases: array<int, string>, hiddenCount: int}
	 *   The marker.
	 *
	 * @spec openspec/changes/one-contact-moment-on-several-cases/specs/contactmomenten/spec.md#requirement-a-shared-contact-moment-says-so-before-it-is-edited-req-cms-005
	 */
	public function sharedMarker(array $moment, string $hostId, callable $mayRead): array {
		$others = array_values(
			array_filter(
				$this->caseSetOf(moment: $moment),
				static fn (string $reference): bool => $reference !== $hostId
			)
		);

		$visible = [];
		$hidden = 0;
		foreach ($others as $reference) {
			if ($mayRead($reference) === true) {
				$visible[] = $reference;
				continue;
			}

			// Counted, never named: the count says this moment is also filed
			// elsewhere without disclosing a case the reader may not open.
			$hidden++;
		}

		return [
			'shared' => ($others !== []),
			'alsoOnCases' => $visible,
			'hiddenCount' => $hidden,
		];
	}//end sharedMarker()

	/**
	 * Read one contact moment as a plain array.
	 *
	 * RBAC stays on: a caller who may not read the contact moment gets null,
	 * and every act above then answers 404 rather than writing.
	 *
	 * @param string $momentId The contact moment's uuid.
	 *
	 * @return array<string, mixed>|null The stored row, or null.
	 */
	private function read(string $momentId): ?array {
		$momentId = trim($momentId);
		if ($momentId === '' || $this->ticketService->isConfigured() === false) {
			return null;
		}

		try {
			$row = $this->ticketService->getObjectService()->find(id: $momentId);
		} catch (Throwable $e) {
			$this->logger->debug(
				'ContactMomentFilingService: could not read the contact moment',
				['uuid' => $momentId, 'exception' => $e->getMessage()]
			);

			return null;
		}

		if ($row === null) {
			return null;
		}

		$data = $row->jsonSerialize();

		if (is_array($data) === false) {
			return null;
		}

		return $data;
	}//end read()

	/**
	 * Write the set, the primary, the mirror and the trail in one save.
	 *
	 * @param array<string, mixed> $moment The stored contact moment.
	 * @param array<int, string> $set The new ordered set.
	 * @param string $primary The new primary.
	 * @param array{case: string, act: string} $trail The act to record.
	 *
	 * @return array<string, mixed> `status` plus either `contactMoment` or `error`.
	 */
	private function write(array $moment, array $set, string $primary, array $trail): array {
		$moment['caseReferences'] = array_values($set);
		$moment['primaryCaseReference'] = $primary;
		// The mirror: a reader that knows only the single-reference shape has
		// to resolve the right case, and the primary is that case.
		$moment['caseReference'] = $primary;

		$filings = ($moment['caseFilings'] ?? []);
		if (is_array($filings) === false) {
			$filings = [];
		}

		$filings[] = [
			'case' => $trail['case'],
			'act' => $trail['act'],
			'by' => ($this->userSession->getUser()?->getUID() ?? ''),
			'at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
		];
		$moment['caseFilings'] = $filings;

		try {
			$saved = $this->ticketService->save(
				ticketType: TicketService::TYPE_CONTACTMOMENT,
				payload: $moment,
				uuid: (string)($moment['id'] ?? $moment['uuid'] ?? ''),
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'ContactMomentFilingService: the filing act could not be saved',
				['exception' => $e->getMessage()]
			);

			return ['status' => 500, 'error' => 'The filing could not be saved.'];
		}

		$data = $saved->jsonSerialize();
		if (is_array($data) === false) {
			$data = $moment;
		}

		return [
			'status' => 200,
			'contactMoment' => $data,
		];
	}//end write()
}//end class
