<?php

/**
 * Pipelinq Case Type Contribution Provider
 *
 * Declares pipelinq's `ticket` to dossiq as a case type. A ticket IS a case:
 * dossiq owns case management, and pipelinq contributes the kind of work it
 * handles rather than running a parallel system that merely resembles one.
 *
 * Dossiq discovers this class by convention FQCN
 * (`OCA\{Namespace}\Dossiq\CaseTypeContributionProvider`) and duck-types it via
 * method_exists(), never instanceof — the same mechanism portaliq uses for
 * PortalContributionProvider (hydra ADR-046).
 *
 * 🔴 THIS CLASS IS DELIBERATELY PLAIN.
 * No dossiq imports, no `implements` clause, no info.xml dependency, no
 * constructor dependencies. Without dossiq installed it is inert and pipelinq
 * behaves exactly as before. An interface would read better and would make
 * pipelinq hard-depend on dossiq, which is the coupling this pattern exists to
 * avoid: dossiq is declared `required: false` in the manifest.
 *
 * The three ticket kinds stay ONE case type with a discriminator, mirroring the
 * storage: unify-ticket-supertype folded request, complaint and interaction
 * into a single `ticket` schema keyed by `ticketType`. Declaring three case
 * types here would re-split, on dossiq's side, exactly what that change joined.
 *
 * @category Dossiq
 * @package  OCA\Pipelinq\Dossiq
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/request-management/spec.md#requirement-request-status-lifecycle-mvp
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Dossiq;

/**
 * Declares the case types pipelinq contributes to dossiq.
 *
 * Pure data: no I/O, no container, nothing that can fail. A provider that
 * throws costs every other app its case types, so this one cannot.
 *
 * @spec openspec/specs/request-management/spec.md#requirement-request-status-lifecycle-mvp
 */
class CaseTypeContributionProvider {

	/**
	 * The case types pipelinq contributes.
	 *
	 * @return array<int,array<string,mixed>> The declarations.
	 *
	 * @spec openspec/specs/request-management/spec.md#requirement-request-status-lifecycle-mvp
	 */
	public function getCaseTypes(): array {
		return [
			[
				'identifier' => 'pipelinq-ticket',
				'title' => 'Ticket',
				'description' => 'Customer contact handled by pipelinq: a request, a complaint or a logged interaction.',
				'register' => 'pipelinq',
				'schema' => 'ticket',
				// The discriminator unify-ticket-supertype introduced. Kept as
				// ONE case type with a subtype rather than three case types,
				// because the storage is one schema and dossiq should not be
				// told a different shape than the data has.
				'discriminator' => 'ticketType',
				'subtypes' => [
					[
						'value' => 'request',
						'title' => 'Request',
					],
					[
						'value' => 'complaint',
						'title' => 'Complaint',
					],
					[
						'value' => 'interaction',
						'title' => 'Contact moment',
					],
				],
				'assigneeProperty' => 'assignee',
				'statusProperty' => 'status',
			],
		];
	}//end getCaseTypes()
}//end class
