<?php

/**
 * Pipelinq ClientIdentifierService.
 *
 * The official numbers of an organisation client (KvK, RSIN, VAT, DUNS, OIN):
 * their formats, checked on every save, and their register checks, run on
 * request. KvK is checked through OpenRegister's KvK leaf; VAT through
 * shillinq's VIES service when shillinq is installed. pipelinq keeps no
 * register client of its own (ADR-012, one path per external register).
 *
 * This class is the contract's face: the format rules live in
 * ClientIdentifierFormat, the register checks in ClientIdentifierChecks and
 * the read and write of the client in ClientIdentifierStore.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Kernel\Contracts\ClientIdentifierServiceInterface;
use OCP\IL10N;

/**
 * Formats and register checks of a client's identifiers.
 *
 * @spec openspec/changes/clients-registry-numbers-and-enrichment/specs/client-registry-numbers/spec.md
 */
class ClientIdentifierService implements ClientIdentifierServiceInterface {

	/**
	 * The schemes a register check exists for.
	 *
	 * @var array<int, string>
	 */
	private const CHECKABLE = ['kvk', 'vat'];

	/**
	 * Constructor.
	 *
	 * @param ClientIdentifierFormat $format The format rules.
	 * @param ClientIdentifierChecks $checks The register checks.
	 * @param ClientIdentifierStore  $store  The client, read and written as the user.
	 * @param IL10N                  $l10n   The user's language.
	 */
	public function __construct(
		private readonly ClientIdentifierFormat $format,
		private readonly ClientIdentifierChecks $checks,
		private readonly ClientIdentifierStore $store,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Why a number does not match its scheme's format, or null when it does.
	 *
	 * @param string $scheme The scheme: kvk, rsin, vat, duns or oin.
	 * @param string $value  The number.
	 *
	 * @return string|null The problem, in the user's language, or null.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.1
	 */
	public function validateFormat(string $scheme, string $value): ?string {
		return $this->format->validateFormat(scheme: $scheme, value: $value);
	}//end validateFormat()

	/**
	 * Every format problem in a client's identifiers list.
	 *
	 * @param mixed $identifiers The client's `identifiers` value.
	 *
	 * @return array<int, string> The problems; empty when the list is valid.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.1
	 */
	public function problemsIn(mixed $identifiers): array {
		return $this->format->problemsIn(identifiers: $identifiers);
	}//end problemsIn()

	/**
	 * Check a KvK number against the KvK register, through OpenRegister's KvK leaf.
	 *
	 * @param string $value The KvK number.
	 *
	 * @return array{checkStatus: string, checkedAt: string, checkSource: string} The outcome.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.2
	 */
	public function checkKvk(string $value): array {
		return $this->checks->checkKvk(value: $value);
	}//end checkKvk()

	/**
	 * What the KvK register says about a KvK number, as client fields.
	 *
	 * @param string $value The KvK number.
	 *
	 * @return array{checkStatus: string, facts: array<string, mixed>|null} The outcome, and the facts when found.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-4.1
	 */
	public function kvkFacts(string $value): array {
		return $this->checks->kvkFacts(value: $value);
	}//end kvkFacts()

	/**
	 * Check a VAT number through shillinq's VIES service; unchecked without shillinq.
	 *
	 * @param string $value The VAT number.
	 *
	 * @return array{checkStatus: string, checkedAt: string, checkSource: string, validUntil?: string} The outcome.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.3
	 */
	public function checkVat(string $value): array {
		return $this->checks->checkVat(value: $value);
	}//end checkVat()

	/**
	 * Whether the calling user may open the client: a read with their rights.
	 *
	 * @param string $clientId The client's id.
	 *
	 * @return bool True when the client exists and the user may read it.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.4
	 */
	public function canAccessClient(string $clientId): bool {
		return isset($this->store->read(clientId: $clientId)['data']) === true;
	}//end canAccessClient()

	/**
	 * Check one identifier of a client against its register and store the outcome.
	 *
	 * The client is read and written as the calling user, so OpenRegister's
	 * object authorisation decides: a client the user may not read answers 403,
	 * and the write of the outcome needs the user's update right on it.
	 *
	 * @param string $clientId The client's id.
	 * @param string $scheme   The identifier's scheme.
	 * @param string $value    The identifier's number.
	 *
	 * @return array{status: int, identifier?: array<string, mixed>, message?: string} The HTTP status and the checked identifier.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.4
	 */
	public function check(string $clientId, string $scheme, string $value): array {
		if (in_array($scheme, self::CHECKABLE, true) === false) {
			return ['status' => 400, 'message' => $this->l10n->t('Only a KvK number and a VAT number can be checked against a register.')];
		}

		$client = $this->store->read(clientId: $clientId);
		if (isset($client['data']) === false) {
			return $client;
		}

		$identifiers = array_values((array)($client['data']['identifiers'] ?? []));
		$number = $this->format->normalise(scheme: $scheme, value: $value);
		$index = $this->indexOf(identifiers: $identifiers, wanted: ['scheme' => $scheme, 'number' => $number]);
		if ($index === null) {
			return ['status' => 404, 'message' => $this->l10n->t('This client has no such number.')];
		}

		$outcome = $this->checks->checkScheme(scheme: $scheme, value: $number);
		$checked = array_merge($identifiers[$index], $outcome);
		if (isset($outcome['validUntil']) === false) {
			unset($checked['validUntil']);
		}

		$identifiers[$index] = $checked;
		$data = $client['data'];
		$data['identifiers'] = $identifiers;
		$refusal = $this->store->write(clientId: $clientId, data: $data);
		if ($refusal !== null) {
			return $refusal;
		}

		return ['status' => 200, 'identifier' => $checked];
	}//end check()

	/**
	 * The position of an identifier in the list, or null.
	 *
	 * @param array<int, mixed>                     $identifiers The list.
	 * @param array{scheme: string, number: string} $wanted      The scheme and the normalised number.
	 *
	 * @return int|null The index.
	 */
	private function indexOf(array $identifiers, array $wanted): ?int {
		foreach ($identifiers as $index => $identifier) {
			if (is_array($identifier) === true
				&& ($identifier['scheme'] ?? null) === $wanted['scheme']
				&& $this->format->normalise(scheme: $wanted['scheme'], value: (string)($identifier['value'] ?? '')) === $wanted['number']
			) {
				return $index;
			}
		}

		return null;
	}//end indexOf()
}//end class
