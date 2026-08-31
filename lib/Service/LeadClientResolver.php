<?php

/**
 * Pipelinq LeadClientResolver.
 *
 * Finds the client a lead belongs to, and mints one only when nothing matches.
 *
 * `lead.client` is required, so a lead without one cannot be saved at all. The
 * tempting fix — create a client per orphaned lead — is the wrong one: a lead
 * titled "Gemeente Amsterdam - CRM implementatie 2026" almost always belongs to
 * a client that already exists, and a second record would split that customer's
 * history across two ids with nothing linking them. Matching first is therefore
 * the behaviour, not an optimisation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/lead-management/spec.md#requirement-lead-crud-mvp
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves the client for a lead, matching before creating.
 *
 * @spec openspec/specs/lead-management/spec.md#requirement-lead-crud-mvp
 */
class LeadClientResolver {

	/**
	 * Constructor.
	 *
	 * @param ContactSyncService $contactSync Client creation, which provisions the required contactsUid.
	 * @param LoggerInterface    $logger      PSR logger.
	 */
	public function __construct(
		private readonly ContactSyncService $contactSync,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Find a stored client whose name occurs in the given title.
	 *
	 * Longest name wins, so "Gemeente Amsterdam" beats "Gemeente" when both
	 * exist and both appear in the title.
	 *
	 * @param string                         $title   The lead title.
	 * @param array<int,array<string,mixed>> $clients Stored clients.
	 *
	 * @return string|null The matching client's uuid, or null when none matches.
	 *
	 * @spec openspec/specs/lead-management/spec.md#requirement-lead-crud-mvp
	 */
	public function match(string $title, array $clients): ?string {
		$haystack = mb_strtolower($title);
		$best = null;
		$bestLength = 0;

		foreach ($clients as $client) {
			$name = trim((string)($client['name'] ?? ''));
			$id = (string)($client['id'] ?? '');
			if ($name === '' || $id === '' || mb_strlen($name) <= $bestLength) {
				continue;
			}

			if (str_contains($haystack, mb_strtolower($name)) === true) {
				$best = $id;
				$bestLength = mb_strlen($name);
			}
		}

		return $best;
	}//end match()

	/**
	 * Create a client for a lead that matched none.
	 *
	 * Routed through ContactSyncService rather than a direct save: `contactsUid`
	 * is required on the client schema and is minted from a Nextcloud contact,
	 * so a raw saveObject() is rejected for the missing property.
	 *
	 * @param string $name The client name, taken from the lead title.
	 *
	 * @return string|null The new client's uuid, or null when creation failed.
	 *
	 * @spec openspec/specs/lead-management/spec.md#requirement-lead-crud-mvp
	 */
	public function create(string $name): ?string {
		try {
			$created = $this->contactSync->createWithContact(
				objectType: 'client',
				form: [
					'name' => $name,
					'type' => 'organization',
					'notes' => 'Created by the lead backfill because this lead had no '
						. 'client when client became required. The name is the lead '
						. 'title and probably needs shortening to the company name.',
				]
			);

			$id = (string)($created['id'] ?? '');
			if ($id === '') {
				return null;
			}

			return $id;
		} catch (Throwable $e) {
			$this->logger->warning(
				'LeadClientResolver: could not create a client',
				[
					'name' => $name,
					'error' => $e->getMessage(),
				]
			);

			return null;
		}//end try
	}//end create()

	/**
	 * Resolve the client for one lead, matching before creating.
	 *
	 * A client created here is appended to $clients so a second lead for the
	 * same customer reuses it rather than minting another.
	 *
	 * @param string                          $title   The lead title.
	 * @param array<int,array<string,mixed>> $clients Stored clients, extended in place.
	 *
	 * @return string|null The client uuid, or null when none could be resolved.
	 *
	 * @spec openspec/specs/lead-management/spec.md#requirement-lead-crud-mvp
	 */
	public function resolve(string $title, array &$clients): ?string {
		$matched = $this->match(title: $title, clients: $clients);
		if ($matched !== null) {
			return $matched;
		}

		// The new client is named after the WHOLE lead title, which is usually
		// too long — "Gemeente Amsterdam - CRM implementatie 2026" names the
		// deal, not the customer. Splitting on the dash is not the fix: the
		// customer leads in that title and trails in "Implementatie ERP -
		// Bakker BV", so either half is a guess that silently mis-names half
		// the records. Nor would splitting help matching, since match() already
		// looks for a client name ANYWHERE in the title and so already sees
		// both halves. The full title is kept because it is lossless, and the
		// note below tells a human this record needs a proper name.
		$name = $title;
		if ($name === '') {
			$name = 'Unknown client';
		}

		$created = $this->create(name: $name);
		if ($created !== null) {
			$clients[] = [
				'id' => $created,
				'name' => $title,
			];
		}

		return $created;
	}//end resolve()
}//end class
