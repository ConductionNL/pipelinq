<?php

/**
 * Pipelinq TicketAnswerService.
 *
 * Writes an employee's answer to the customer on a request or complaint: the
 * answer becomes the ticket's `customerMessage` (QuestionAnsweredListener tells
 * the resident), it is added to `portalAnswers` with its moment, and the
 * ticket can wait for the customer.
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
 * @spec openspec/changes/answer-and-saved-replies-on-the-server/specs/dossier-questions/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DomainException;
use OCP\AppFramework\Utility\ITimeFactory;
use Throwable;

/**
 * Saves the answer to the customer on a ticket.
 *
 * @spec openspec/changes/answer-and-saved-replies-on-the-server/specs/dossier-questions/spec.md#requirement-the-server-saves-the-answer-to-the-customer
 */
class TicketAnswerService {

	/**
	 * Ticket types that have someone to answer.
	 *
	 * @var array<int, string>
	 */
	public const ANSWERABLE = ['request', 'complaint'];

	/**
	 * The status a ticket waits for the customer in.
	 *
	 * @var string
	 */
	public const WAITING_STATUS = 'awaiting_customer';

	/**
	 * Constructor.
	 *
	 * @param TicketService $tickets Ticket register access.
	 * @param ITimeFactory  $time    Clock, for the answer's moment.
	 */
	public function __construct(
		private readonly TicketService $tickets,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Save an answer on a ticket.
	 *
	 * The ticket is read and written as the signed-in user, so a user who may
	 * not change it is refused by OpenRegister.
	 *
	 * @param string $id              The ticket uuid.
	 * @param string $message         The answer.
	 * @param bool   $waitForCustomer Whether the ticket then waits for the customer.
	 *
	 * @return array<string, mixed> The saved ticket.
	 *
	 * @throws DomainException 'not-found', 'not-answerable', 'empty' or 'refused'.
	 *
	 * @spec openspec/changes/answer-and-saved-replies-on-the-server/specs/dossier-questions/spec.md#requirement-the-server-saves-the-answer-to-the-customer
	 */
	public function answer(string $id, string $message, bool $waitForCustomer): array {
		$message = trim($message);
		if ($message === '') {
			throw new DomainException('empty');
		}

		$ticket = $this->read(id: $id);
		if (in_array((string)($ticket['ticketType'] ?? ''), self::ANSWERABLE, true) === false) {
			throw new DomainException('not-answerable');
		}

		$patch = [
			'customerMessage' => $message,
			'portalAnswers' => self::answersAfter(
				answers: $ticket['portalAnswers'] ?? [],
				message: $message,
				moment: $this->time->getDateTime()->format(DATE_ATOM)
			),
		];
		if ($waitForCustomer === true) {
			$patch['status'] = self::WAITING_STATUS;
		}

		try {
			$saved = $this->tickets->getObjectService()->patchObject(
				objectId: $id,
				data: $patch,
				register: $this->tickets->getRegisterId(),
				schema: $this->tickets->getSchemaId()
			);
		} catch (Throwable) {
			throw new DomainException('refused');
		}

		return array_merge((array)$saved->getObject(), ['id' => $id]);
	}//end answer()

	/**
	 * The answers after adding one, unless it repeats the last.
	 *
	 * @param mixed  $answers The stored answers.
	 * @param string $message The answer.
	 * @param string $moment  When it was given, ISO 8601.
	 *
	 * @return array<int, array<string, string>> The answers.
	 *
	 * @spec openspec/changes/answer-and-saved-replies-on-the-server/specs/dossier-questions/spec.md#requirement-the-server-saves-the-answer-to-the-customer
	 */
	public static function answersAfter(mixed $answers, string $message, string $moment): array {
		$list = [];
		if (is_array($answers) === false) {
			$answers = [];
		}

		foreach ($answers as $answer) {
			if (is_array($answer) === true) {
				$list[] = $answer;
			}
		}

		$last = (string)(end($list)['message'] ?? '');
		if ($list !== [] && $last === $message) {
			return $list;
		}

		$list[] = ['message' => $message, 'createdAt' => $moment];
		return $list;
	}//end answersAfter()

	/**
	 * Read a ticket as the signed-in user.
	 *
	 * @param string $id The ticket uuid.
	 *
	 * @return array<string, mixed> The ticket.
	 *
	 * @throws DomainException 'not-found'.
	 */
	private function read(string $id): array {
		if ($id === '' || $this->tickets->isConfigured() === false) {
			throw new DomainException('not-found');
		}

		try {
			$ticket = $this->tickets->getObjectService()->find(
				id: $id,
				register: $this->tickets->getRegisterId(),
				schema: $this->tickets->getSchemaId()
			);
		} catch (Throwable) {
			$ticket = null;
		}

		if ($ticket === null) {
			throw new DomainException('not-found');
		}

		return (array)$ticket->getObject();
	}//end read()
}//end class
