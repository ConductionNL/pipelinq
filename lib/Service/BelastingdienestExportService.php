<?php

/**
 * Pipelinq BelastingdienestExportService.
 *
 * Formats Kassakoppeling audit log entries for Belastingdienst export in
 * XML and JSON formats, with chain-integrity metadata manifest.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Export service for Belastingdienst Kassakoppeling audit log.
 *
 * Produces XML and JSON exports suitable for submission to the Dutch
 * tax authority. Includes a manifest block with entry count, date range,
 * register list and hash-chain integrity status.
 *
 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.3
 */
class BelastingdienestExportService
{
    /**
     * Export format version string embedded in the export manifest.
     */
    private const EXPORT_VERSION = '1.0';

    /**
     * Constructor.
     *
     * @param KassakoppelingSignatureService $signatureService The signature service.
     */
    public function __construct(
        private readonly KassakoppelingSignatureService $signatureService,
    ) {
    }//end __construct()

    /**
     * Export audit entries as a Kassakoppeling-compliant XML document.
     *
     * The root element is <KassakoppelingExport>, with a <Manifest> block
     * and one <AuditEntry> element per log record.
     *
     * @param array<int,array<string,mixed>> $entries Audit log entries ordered by timestamp.
     *
     * @return string UTF-8 encoded XML document string.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.3
     */
    public function exportAsXml(array $entries): string
    {
        $manifest = $this->buildManifest($entries);

        $doc               = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $root = $doc->createElement('KassakoppelingExport');
        $root->setAttribute('version', self::EXPORT_VERSION);
        $root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $doc->appendChild($root);

        // --- Manifest block ---
        $manifestEl = $doc->createElement('Manifest');
        $this->appendTextChild($doc, $manifestEl, 'ExportDate', $manifest['exportDate']);
        $this->appendTextChild($doc, $manifestEl, 'EntryCount', (string) $manifest['entryCount']);
        $this->appendTextChild($doc, $manifestEl, 'DateRangeFrom', $manifest['dateRange']['from'] ?? '');
        $this->appendTextChild($doc, $manifestEl, 'DateRangeTo', $manifest['dateRange']['to'] ?? '');
        $this->appendTextChild($doc, $manifestEl, 'ChainIntegrity', $manifest['chainIntegrity']);

        $registersEl = $doc->createElement('Registers');
        foreach ($manifest['registers'] as $reg) {
            $this->appendTextChild($doc, $registersEl, 'Register', $reg);
        }

        $manifestEl->appendChild($registersEl);
        $root->appendChild($manifestEl);

        // --- Entry elements ---
        $entriesEl = $doc->createElement('AuditEntries');
        foreach ($entries as $entry) {
            $entryEl = $doc->createElement('AuditEntry');
            $this->appendTextChild($doc, $entryEl, 'Id', (string) ($entry['id'] ?? ''));
            $this->appendTextChild($doc, $entryEl, 'OperatorId', (string) ($entry['operatorId'] ?? ''));
            $this->appendTextChild($doc, $entryEl, 'RegisterNumber', (string) ($entry['registerNumber'] ?? ''));
            $this->appendTextChild($doc, $entryEl, 'Action', (string) ($entry['action'] ?? ''));
            $this->appendTextChild($doc, $entryEl, 'Amount', (string) ($entry['amount'] ?? '0'));
            $this->appendTextChild($doc, $entryEl, 'ItemCount', (string) ($entry['itemCount'] ?? '0'));
            $this->appendTextChild($doc, $entryEl, 'TaxAmount', (string) ($entry['taxAmount'] ?? '0'));
            $this->appendTextChild($doc, $entryEl, 'Timestamp', (string) ($entry['timestamp'] ?? ''));
            $this->appendTextChild($doc, $entryEl, 'TransactionUuid', (string) ($entry['transactionUuid'] ?? ''));
            $this->appendTextChild($doc, $entryEl, 'Signature', (string) ($entry['signature'] ?? ''));
            $this->appendTextChild($doc, $entryEl, 'PreviousHash', (string) ($entry['previousHash'] ?? ''));
            $this->appendTextChild($doc, $entryEl, 'CurrentHash', (string) ($entry['currentHash'] ?? ''));
            $this->appendTextChild($doc, $entryEl, 'Description', htmlspecialchars((string) ($entry['description'] ?? ''), ENT_XML1));
            $this->appendTextChild($doc, $entryEl, 'Verified', ($entry['verified'] === null ? 'null' : ($entry['verified'] ? 'true' : 'false')));
            $this->appendTextChild($doc, $entryEl, 'ExportedAt', (string) ($entry['exportedAt'] ?? ''));
            $entriesEl->appendChild($entryEl);
        }

        $root->appendChild($entriesEl);

        $xml = $doc->saveXML();
        if ($xml === false) {
            return '<?xml version="1.0" encoding="UTF-8"?><KassakoppelingExport />';
        }

        return $xml;
    }//end exportAsXml()

    /**
     * Export audit entries as a structured JSON document.
     *
     * @param array<int,array<string,mixed>> $entries Audit log entries ordered by timestamp.
     *
     * @return string Pretty-printed JSON string.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.3
     */
    public function exportAsJson(array $entries): string
    {
        $manifest = $this->buildManifest($entries);

        $export = [
            'version'    => self::EXPORT_VERSION,
            'manifest'   => $manifest,
            'auditEntries' => array_values($entries),
        ];

        $json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return '{}';
        }

        return $json;
    }//end exportAsJson()

    /**
     * Build an export manifest describing the batch of entries.
     *
     * @param array<int,array<string,mixed>> $entries Audit log entries.
     *
     * @return array<string,mixed> Associative array with keys:
     *                             - exportDate (ISO 8601)
     *                             - entryCount (int)
     *                             - dateRange (from/to ISO 8601)
     *                             - registers (string[])
     *                             - chainIntegrity ('valid'|'invalid'|'empty')
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.3
     */
    public function buildManifest(array $entries): array
    {
        if ($entries === []) {
            return [
                'exportDate'     => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
                'entryCount'     => 0,
                'dateRange'      => ['from' => null, 'to' => null],
                'registers'      => [],
                'chainIntegrity' => 'empty',
            ];
        }

        $timestamps = array_filter(
            array_column($entries, 'timestamp'),
            static fn($v): bool => $v !== null && $v !== '',
        );
        sort($timestamps);

        $registers = array_unique(
            array_filter(
                array_column($entries, 'registerNumber'),
                static fn($v): bool => $v !== null && $v !== '',
            )
        );
        sort($registers);

        $chainValid = false;
        try {
            $chainValid = $this->signatureService->verifyHashChain($entries);
        } catch (\Throwable) {
            // Signing key not configured — chain cannot be verified.
            $chainValid = false;
        }

        return [
            'exportDate'     => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'entryCount'     => count($entries),
            'dateRange'      => [
                'from' => ($timestamps === [] ? null : reset($timestamps)),
                'to'   => ($timestamps === [] ? null : end($timestamps)),
            ],
            'registers'      => array_values($registers),
            'chainIntegrity' => $chainValid ? 'valid' : 'invalid',
        ];
    }//end buildManifest()

    /**
     * Append a text-content child element to a DOMNode.
     *
     * @param \DOMDocument $doc    The document.
     * @param \DOMElement  $parent The parent element.
     * @param string       $tag    The element tag name.
     * @param string       $text   The text content.
     *
     * @return void
     */
    private function appendTextChild(\DOMDocument $doc, \DOMElement $parent, string $tag, string $text): void
    {
        $el = $doc->createElement($tag);
        $el->appendChild($doc->createTextNode($text));
        $parent->appendChild($el);
    }//end appendTextChild()
}//end class
