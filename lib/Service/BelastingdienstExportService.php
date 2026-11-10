<?php

/**
 * Pipelinq BelastingdienstExportService.
 *
 * Formats kassakoppelingAuditLog entries into the Kassakoppeling-compliant
 * export pack the Dutch tax authority (Belastingdienst) ingests during a
 * POS compliance audit. Renders both XML (the canonical submission format)
 * and JSON (developer-friendly), prefixed with a metadata manifest that
 * documents the export window, entry count, register list and per-register
 * chain integrity status.
 *
 * Pure formatter: no IO. The audit service supplies a pre-loaded entry list
 * and date range; the exporter computes the manifest (counts + registers +
 * chain integrity) and emits a string body. Stamping `exportedAt` and
 * downloading the file are the audit service's responsibility.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/pos-kassakoppeling-audit/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DOMDocument;
use DOMElement;

/**
 * Format kassakoppelingAuditLog entries for the Belastingdienst audit pack.
 *
 * Output shape (XML):
 *
 * ```xml
 * <KassakoppelingExport>
 *   <Metadata>
 *     <ExportDate>2026-05-21T18:00:00+00:00</ExportDate>
 *     <EntryCount>50</EntryCount>
 *     <DateRange from="2026-05-01" to="2026-05-31"/>
 *     <RegisterList>REG-001, REG-002</RegisterList>
 *     <ChainIntegrity>valid</ChainIntegrity>
 *     <SignatureAlgorithm>HMAC-SHA256</SignatureAlgorithm>
 *   </Metadata>
 *   <Entries>
 *     <Entry>…</Entry>
 *   </Entries>
 * </KassakoppelingExport>
 * ```
 *
 * Output shape (JSON):
 *
 * ```json
 * { "exportMetadata": {…}, "entries": [{…}] }
 * ```
 *
 * @spec openspec/specs/pos-kassakoppeling-audit/spec.md
 */
class BelastingdienstExportService {
	/**
	 * Signature algorithm advertised in the export metadata.
	 *
	 * @var string
	 */
	public const SIGNATURE_ALGORITHM = 'HMAC-SHA256';

	/**
	 * Constructor.
	 *
	 * @param KassakoppelingSignatureService $signature The signature primitive
	 *                                                  used to verify per-register
	 *                                                  chain integrity.
	 */
	public function __construct(
		private KassakoppelingSignatureService $signature,
	) {
	}//end __construct()

	/**
	 * Render the export pack as XML.
	 *
	 * The XML body is built with DOMDocument so attribute / element escaping
	 * is handled correctly without manual string concatenation. Returns a
	 * pretty-printed UTF-8 string with the XML declaration header.
	 *
	 * @param array<int, array<string, mixed>> $entries The entries.
	 * @param array<string, mixed>|null $manifest Optional pre-computed
	 *                                            manifest (computed when null).
	 *
	 * @return string The XML body.
	 *
	 * @spec openspec/specs/pos-kassakoppeling-audit/spec.md
	 */
	public function exportAsXml(array $entries, ?array $manifest = null): string {
		if ($manifest === null) {
			$manifest = $this->buildManifest(entries: $entries, fromDate: '', toDate: '');
		}

		$document = new DOMDocument('1.0', 'UTF-8');
		$document->formatOutput = true;

		$root = $document->createElement('KassakoppelingExport');
		$document->appendChild($root);
		$root->appendChild($this->xmlMetadata(document: $document, manifest: $manifest));
		$root->appendChild($this->xmlEntries(document: $document, entries: $entries));

		return (string)$document->saveXML();
	}//end exportAsXml()

	/**
	 * The XML Metadata element of a manifest.
	 *
	 * @param DOMDocument          $document The document.
	 * @param array<string, mixed> $manifest The manifest.
	 *
	 * @return DOMElement The element.
	 */
	private function xmlMetadata(DOMDocument $document, array $manifest): DOMElement {
		$metadata = $document->createElement('Metadata');
		$metadata->appendChild($document->createElement('ExportDate', (string)$manifest['exportDate']));
		$metadata->appendChild($document->createElement('EntryCount', (string)$manifest['entryCount']));
		$metadata->appendChild($this->xmlDateRange(document: $document, dateRange: $manifest['dateRange']));
		$registerList = $document->createElement('RegisterList');
		$registerList->appendChild($document->createTextNode(implode(', ', (array)($manifest['registerList'] ?? []))));
		$metadata->appendChild($registerList);
		$metadata->appendChild($document->createElement('ChainIntegrity', (string)$manifest['chainIntegrity']));
		if (($manifest['chainStatus'] ?? '') !== '') {
			$metadata->appendChild($document->createElement('ChainStatus', (string)$manifest['chainStatus']));
		}

		$metadata->appendChild($document->createElement('SignatureAlgorithm', self::SIGNATURE_ALGORITHM));
		return $metadata;
	}//end xmlMetadata()

	/**
	 * The XML DateRange element.
	 *
	 * @param DOMDocument          $document  The document.
	 * @param array<string, mixed> $dateRange The `from` / `to` bounds.
	 *
	 * @return DOMElement The element.
	 */
	private function xmlDateRange(DOMDocument $document, array $dateRange): DOMElement {
		$element = $document->createElement('DateRange');
		$element->setAttribute('from', (string)$dateRange['from']);
		$element->setAttribute('to', (string)$dateRange['to']);
		return $element;
	}//end xmlDateRange()

	/**
	 * The XML Entries element.
	 *
	 * @param DOMDocument                      $document The document.
	 * @param array<int, array<string, mixed>> $entries  The entries.
	 *
	 * @return DOMElement The element.
	 */
	private function xmlEntries(DOMDocument $document, array $entries): DOMElement {
		$entriesNode = $document->createElement('Entries');
		foreach ($entries as $entry) {
			$entriesNode->appendChild($this->xmlEntry(document: $document, entry: $entry));
		}

		return $entriesNode;
	}//end xmlEntries()

	/**
	 * One XML Entry element, its fields in canonical order.
	 *
	 * @param DOMDocument          $document The document.
	 * @param array<string, mixed> $entry    The entry.
	 *
	 * @return DOMElement The element.
	 */
	private function xmlEntry(DOMDocument $document, array $entry): DOMElement {
		$entryNode = $document->createElement('Entry');
		foreach ($this->canonicalEntry(entry: $entry) as $field => $value) {
			$child = $document->createElement($field);
			$child->appendChild($document->createTextNode($this->xmlValue(value: $value)));
			$entryNode->appendChild($child);
		}

		return $entryNode;
	}//end xmlEntry()

	/**
	 * A canonical field value as XML text: booleans as `true` / `false`, null as ''.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The text.
	 */
	private function xmlValue(mixed $value): string {
		if ($value === true) {
			return 'true';
		}

		if ($value === false) {
			return 'false';
		}

		return (string)($value ?? '');
	}//end xmlValue()

	/**
	 * Render the export pack as pretty-printed JSON.
	 *
	 * @param array<int, array<string, mixed>> $entries The entries.
	 * @param array<string, mixed>|null $manifest Optional pre-computed
	 *                                            manifest (computed when null).
	 *
	 * @return string The JSON body.
	 *
	 * @spec openspec/specs/pos-kassakoppeling-audit/spec.md
	 */
	public function exportAsJson(array $entries, ?array $manifest = null): string {
		if ($manifest === null) {
			$manifest = $this->buildManifest(entries: $entries, fromDate: '', toDate: '');
		}

		$entriesPayload = [];
		foreach ($entries as $entry) {
			$entriesPayload[] = $this->canonicalEntry(entry: $entry);
		}

		$payload = [
			'exportMetadata' => [
				'exportDate' => (string)$manifest['exportDate'],
				'entryCount' => (int)$manifest['entryCount'],
				'dateRange' => $manifest['dateRange'],
				'registerList' => array_values((array)($manifest['registerList'] ?? [])),
				'chainIntegrity' => (string)$manifest['chainIntegrity'],
				'chainStatus' => (string)($manifest['chainStatus'] ?? ''),
				'signatureAlgorithm' => self::SIGNATURE_ALGORITHM,
			],
			'entries' => $entriesPayload,
		];

		$encoded = json_encode($payload, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		if ($encoded === false) {
			return '{}';
		}

		return $encoded;
	}//end exportAsJson()

	/**
	 * Build the export-manifest metadata.
	 *
	 * Computes the entry count, unique register list (sorted), exportDate
	 * (current UTC), per-register chain integrity (via
	 * KassakoppelingSignatureService::verifyHashChain) and a human-readable
	 * chainStatus message when the chain is broken.
	 *
	 * @param array<int, array<string, mixed>> $entries The entries.
	 * @param string $fromDate The lower bound (YYYY-MM-DD).
	 * @param string $toDate The upper bound (YYYY-MM-DD).
	 *
	 * @return array<string, mixed> The manifest.
	 *
	 * @spec openspec/specs/pos-kassakoppeling-audit/spec.md
	 */
	public function buildManifest(array $entries, string $fromDate, string $toDate): array {
		$registers = $this->registerList(entries: $entries);
		$perRegisterStatus = $this->perRegisterStatus(entries: $entries, registers: $registers);
		return [
			'exportDate' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
			'entryCount' => count($entries),
			'dateRange' => ['from' => $fromDate, 'to' => $toDate],
			'registerList' => $registers,
			'chainIntegrity' => $this->chainIntegrity(perRegisterStatus: $perRegisterStatus),
			'chainStatus' => $this->chainStatus(perRegisterStatus: $perRegisterStatus),
			'perRegisterStatus' => $perRegisterStatus,
			'signatureAlgorithm' => self::SIGNATURE_ALGORITHM,
		];
	}//end buildManifest()

	/**
	 * The distinct non-empty register numbers of the entries, sorted.
	 *
	 * @param array<int, array<string, mixed>> $entries The entries.
	 *
	 * @return array<int, string> The register numbers.
	 */
	private function registerList(array $entries): array {
		$registers = [];
		foreach ($entries as $entry) {
			$register = (string)($entry['registerNumber'] ?? '');
			if ($register !== '' && in_array($register, $registers, true) === false) {
				$registers[] = $register;
			}
		}

		sort($registers);
		return $registers;
	}//end registerList()

	/**
	 * The hash-chain verification of every register, keyed by register.
	 *
	 * @param array<int, array<string, mixed>> $entries   The entries.
	 * @param array<int, string>               $registers The register numbers.
	 *
	 * @return array<string, array<string, mixed>> The verification results.
	 */
	private function perRegisterStatus(array $entries, array $registers): array {
		$perRegisterStatus = [];
		foreach ($registers as $register) {
			$forRegister = $this->registerEntries(entries: $entries, register: $register);
			$perRegisterStatus[$register] = $this->signature->verifyHashChain(entries: $forRegister);
		}

		return $perRegisterStatus;
	}//end perRegisterStatus()

	/**
	 * One register's entries in timestamp order.
	 *
	 * @param array<int, array<string, mixed>> $entries  The entries.
	 * @param string                           $register The register number.
	 *
	 * @return array<int, array<string, mixed>> The register's entries.
	 */
	private function registerEntries(array $entries, string $register): array {
		$forRegister = array_values(
			array_filter($entries, fn (array $row): bool => (string)($row['registerNumber'] ?? '') === $register)
		);
		usort(
			$forRegister,
			fn (array $left, array $right): int => strcmp((string)($left['timestamp'] ?? ''), (string)($right['timestamp'] ?? ''))
		);
		return $forRegister;
	}//end registerEntries()

	/**
	 * `invalid` when any register's chain is broken, else `valid`.
	 *
	 * @param array<string, array<string, mixed>> $perRegisterStatus The verification results.
	 *
	 * @return string The chain integrity.
	 */
	private function chainIntegrity(array $perRegisterStatus): string {
		foreach ($perRegisterStatus as $result) {
			if ($result['chainValid'] === false) {
				return 'invalid';
			}
		}

		return 'valid';
	}//end chainIntegrity()

	/**
	 * Where the first broken register's chain breaks, or '' when none is broken.
	 *
	 * @param array<string, array<string, mixed>> $perRegisterStatus The verification results.
	 *
	 * @return string The chain status.
	 */
	private function chainStatus(array $perRegisterStatus): string {
		foreach ($perRegisterStatus as $register => $result) {
			if ($result['chainValid'] === false) {
				return 'Broken at entry ' . ((int)($result['brokenAt'] ?? 0)) . ' on register ' . $register;
			}
		}

		return '';
	}//end chainStatus()

	/**
	 * Project an entry into the canonical export field order.
	 *
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return array<string, mixed> The canonical projection.
	 */
	private function canonicalEntry(array $entry): array {
		return [
			'id' => (string)($entry['id'] ?? $entry['uuid'] ?? ''),
			'timestamp' => (string)($entry['timestamp'] ?? ''),
			'operatorId' => (string)($entry['operatorId'] ?? ''),
			'registerNumber' => (string)($entry['registerNumber'] ?? ''),
			'action' => (string)($entry['action'] ?? ''),
			'amount' => (int)($entry['amount'] ?? 0),
			'itemCount' => (int)($entry['itemCount'] ?? 0),
			'taxAmount' => (int)($entry['taxAmount'] ?? 0),
			'transactionUuid' => (string)($entry['transactionUuid'] ?? ''),
			'description' => (string)($entry['description'] ?? ''),
			'signature' => (string)($entry['signature'] ?? ''),
			'previousHash' => (string)($entry['previousHash'] ?? ''),
			'currentHash' => (string)($entry['currentHash'] ?? ''),
			'verified' => $entry['verified'] ?? null,
			'exportedAt' => (string)($entry['exportedAt'] ?? ''),
		];
	}//end canonicalEntry()
}//end class
