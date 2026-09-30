<?php

/**
 * Pipelinq Woo Request Conversion Service
 *
 * A KCC employee turns a question about a dossier into a Woo request (hydra
 * woo-citizen-journey J4.6, C5). dossiq owns the one creation path,
 * `OCA\Dossiq\Woo\WooRequestIntake::start()`; pipelinq only calls it. The call
 * is duck-typed: `class_exists` plus the container, never a hard dependency.
 * Without dossiq the conversion is unavailable and nothing is written.
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
 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-turns-a-question-into-a-woo-request-req-qcd-008
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Service\Portal\MainRegisterReader;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Calls dossiq's Woo request intake for a question ticket and records the case.
 *
 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-turns-a-question-into-a-woo-request-req-qcd-008
 */
class WooRequestConversionService {
	/**
	 * dossiq's one Woo request creation path (C5).
	 *
	 * @var string
	 */
	public const INTAKE_CLASS = 'OCA\\Dossiq\\Woo\\WooRequestIntake';

	/**
	 * The `origin` pipelinq passes to the intake (C5).
	 *
	 * @var string
	 */
	public const ORIGIN = 'pipelinq';

	/**
	 * The ticket status after a conversion.
	 *
	 * @var string
	 */
	public const STATUS_CONVERTED = 'converted';

	/**
	 * The pipelinq schema key of the ticket supertype.
	 *
	 * @var string
	 */
	private const TICKET = 'ticket';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container   Resolves dossiq's intake when installed.
	 * @param MainRegisterReader $tickets     Writes the converted ticket.
	 * @param LoggerInterface    $logger      Logger.
	 * @param string             $intakeClass The intake class; a test names a stand-in.
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-turns-a-question-into-a-woo-request-req-qcd-008
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly MainRegisterReader $tickets,
		private readonly LoggerInterface $logger,
		private readonly string $intakeClass = self::INTAKE_CLASS,
	) {
	}//end __construct()

	/**
	 * What the ticket page may offer for this ticket.
	 *
	 * @param array<string, mixed> $ticket The ticket, read under the employee's RBAC.
	 *
	 * @return array{available: bool, canConvert: bool, status: string, caseReference: string}
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-turns-a-question-into-a-woo-request-req-qcd-008
	 */
	public function availability(array $ticket): array {
		$available = ($this->intake() !== null);

		return [
			'available' => $available,
			'canConvert' => ($available === true && $this->isConvertible(ticket: $ticket) === true),
			'status' => (string)($ticket['status'] ?? ''),
			'caseReference' => (string)($ticket['caseReference'] ?? ''),
		];
	}//end availability()

	/**
	 * Start a Woo request for the ticket and mark the ticket converted.
	 *
	 * @param string               $ticketId The ticket id.
	 * @param array<string, mixed> $ticket   The ticket, read under the employee's RBAC.
	 *
	 * @return array{status: string, caseReference?: string, caseUrl?: string}
	 *   `converted`, `not-available` (no dossiq), `not-convertible` (no dossier
	 *   snapshot, or already converted), `intake-failed`, or `converted-unsynced`
	 *   when the case exists but the ticket write failed.
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-turns-a-question-into-a-woo-request-req-qcd-008
	 */
	public function convert(string $ticketId, array $ticket): array {
		$intake = $this->intake();
		if ($intake === null) {
			return ['status' => 'not-available'];
		}

		if ($this->isConvertible(ticket: $ticket) === false) {
			return ['status' => 'not-convertible'];
		}

		try {
			$result = $intake->start($this->request(ticketId: $ticketId, ticket: $ticket));
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: Woo request intake refused the conversion', ['ticket' => $ticketId, 'reason' => $e->getMessage()]);
			return ['status' => 'intake-failed'];
		}

		$caseId = '';
		$caseUrl = '';
		if (is_array($result) === true) {
			$caseId = (string)($result['caseId'] ?? '');
			$caseUrl = (string)($result['caseUrl'] ?? '');
		}

		if ($caseId === '') {
			return ['status' => 'intake-failed'];
		}

		$ticket['ticketType'] = TicketService::TYPE_REQUEST;
		$ticket['status'] = self::STATUS_CONVERTED;
		$ticket['caseReference'] = $caseId;
		try {
			$this->tickets->save(schemaKey: self::TICKET, data: $ticket, id: $ticketId);
		} catch (Throwable $e) {
			// The case exists; only the ticket write failed. Say so honestly.
			$this->logger->warning('Pipelinq: converted ticket not saved', ['ticket' => $ticketId, 'reason' => $e->getMessage()]);
			return ['status' => 'converted-unsynced', 'caseReference' => $caseId, 'caseUrl' => $caseUrl];
		}

		return ['status' => self::STATUS_CONVERTED, 'caseReference' => $caseId, 'caseUrl' => $caseUrl];
	}//end convert()

	/**
	 * The request array C5 defines, built from the ticket.
	 *
	 * @param string               $ticketId The ticket id.
	 * @param array<string, mixed> $ticket   The ticket.
	 *
	 * @return array<string, mixed>
	 */
	private function request(string $ticketId, array $ticket): array {
		$reference = (array)($ticket['subjectReference'] ?? []);

		return [
			'subjectRef' => (string)($ticket['portalSubject'] ?? ''),
			'collectionId' => (string)($reference['id'] ?? ''),
			'onderwerp' => (string)($ticket['title'] ?? ''),
			'omschrijving' => (string)($ticket['description'] ?? ''),
			'periodeVan' => null,
			'periodeTot' => null,
			'origin' => self::ORIGIN,
			'originReference' => $ticketId,
		];
	}//end request()

	/**
	 * Whether a ticket is a question about a dossier that is not converted yet.
	 *
	 * @param array<string, mixed> $ticket The ticket.
	 *
	 * @return bool
	 */
	private function isConvertible(array $ticket): bool {
		$reference = ($ticket['subjectReference'] ?? null);

		return is_array($reference) === true
			&& (string)($reference['id'] ?? '') !== ''
			&& (string)($ticket['portalSubject'] ?? '') !== ''
			&& (string)($ticket['status'] ?? '') !== self::STATUS_CONVERTED
			&& (string)($ticket['caseReference'] ?? '') === '';
	}//end isConvertible()

	/**
	 * dossiq's intake, or null when dossiq is not installed.
	 *
	 * @return object|null
	 */
	private function intake(): ?object {
		if (class_exists($this->intakeClass) === false) {
			return null;
		}

		try {
			$intake = $this->container->get($this->intakeClass);
		} catch (Throwable $e) {
			$this->logger->debug('Pipelinq: Woo request intake not resolvable', ['reason' => $e->getMessage()]);
			return null;
		}

		if (is_object($intake) === false || method_exists($intake, 'start') === false) {
			return null;
		}

		return $intake;
	}//end intake()
}//end class
