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

		$case = $this->startCase(intake: $intake, request: $this->request(ticketId: $ticketId, ticket: $ticket));
		if ($case === null) {
			return ['status' => 'intake-failed'];
		}

		$saved = $this->saveConverted(ticketId: $ticketId, ticket: $this->asConverted(ticket: $ticket, caseId: $case['caseReference']));
		return ['status' => $this->statusAfter(saved: $saved)] + $case;
	}//end convert()

	/**
	 * Start the Woo request; the case reference and URL, or null when the
	 * intake refused (logged) or answered without a case id.
	 *
	 * @param object               $intake  dossiq's intake.
	 * @param array<string, mixed> $request The C5 request.
	 *
	 * @return array{caseReference: string, caseUrl: string}|null
	 */
	private function startCase(object $intake, array $request): ?array {
		try {
			$result = $intake->start($request);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: Woo request intake refused the conversion',
				['ticket' => $request['originReference'], 'reason' => $e->getMessage()]
			);
			return null;
		}

		return $this->caseOf(result: $result);
	}//end startCase()

	/**
	 * The case an intake answer names; null without a case id.
	 *
	 * @param mixed $result The intake's answer.
	 *
	 * @return array{caseReference: string, caseUrl: string}|null
	 */
	private function caseOf(mixed $result): ?array {
		if (is_array($result) === false || (string)($result['caseId'] ?? '') === '') {
			return null;
		}

		return ['caseReference' => (string)$result['caseId'], 'caseUrl' => (string)($result['caseUrl'] ?? '')];
	}//end caseOf()

	/**
	 * The ticket as a converted request carrying its case reference.
	 *
	 * @param array<string, mixed> $ticket The ticket.
	 * @param string               $caseId The case id.
	 *
	 * @return array<string, mixed>
	 */
	private function asConverted(array $ticket, string $caseId): array {
		$ticket['ticketType'] = TicketService::TYPE_REQUEST;
		$ticket['status'] = self::STATUS_CONVERTED;
		$ticket['caseReference'] = $caseId;

		return $ticket;
	}//end asConverted()

	/**
	 * Write the converted ticket; false (logged) when the write fails.
	 *
	 * @param string               $ticketId The ticket id.
	 * @param array<string, mixed> $ticket   The converted ticket.
	 *
	 * @return bool
	 */
	private function saveConverted(string $ticketId, array $ticket): bool {
		try {
			$this->tickets->save(schemaKey: self::TICKET, data: $ticket, id: $ticketId);
		} catch (Throwable $e) {
			// The case exists; only the ticket write failed. Say so honestly.
			$this->logger->warning('Pipelinq: converted ticket not saved', ['ticket' => $ticketId, 'reason' => $e->getMessage()]);
			return false;
		}

		return true;
	}//end saveConverted()

	/**
	 * The status after a conversion: converted, or converted-unsynced when
	 * the case exists but the ticket write failed.
	 *
	 * @param bool $saved Whether the ticket write succeeded.
	 *
	 * @return string
	 */
	private function statusAfter(bool $saved): string {
		if ($saved === false) {
			return 'converted-unsynced';
		}

		return self::STATUS_CONVERTED;
	}//end statusAfter()

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

		$intake = $this->resolvedIntake();
		if (is_object($intake) === false || method_exists($intake, 'start') === false) {
			return null;
		}

		return $intake;
	}//end intake()

	/**
	 * The container's entry for the intake class; null (logged at debug)
	 * when it cannot build it.
	 *
	 * @return mixed
	 */
	private function resolvedIntake(): mixed {
		try {
			return $this->container->get($this->intakeClass);
		} catch (Throwable $e) {
			$this->logger->debug('Pipelinq: Woo request intake not resolvable', ['reason' => $e->getMessage()]);
			return null;
		}
	}//end resolvedIntake()
}//end class
