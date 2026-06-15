<?php

/**
 * Pipelinq RedactionService.
 *
 * Field-level redaction for AVG evidence items. Records each redaction as an
 * auditable RedactieActie (before/after + legal ground) and refuses to redact
 * the data subject's own data without an explicit art. 23 ground — redacting the
 * citizen's own data would amount to withholding the access right (REQ-AVG-006).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Avg
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Avg;

use DateTimeImmutable;
use DateTimeInterface;
use OCP\AppFramework\OCS\OCSBadRequestException;
use Psr\Log\LoggerInterface;

/**
 * Redaction logic for AVG evidence.
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.3
 */
class RedactionService
{
    /**
     * Ground used when intentionally redacting the data subject's own data.
     *
     * @var string
     */
    public const GROUND_OWN_DATA = 'art-23-eigen-gegevens';

    /**
     * Valid redaction grounds.
     *
     * @var string[]
     */
    private const VALID_GROUNDS = [
        'bescherming-rechten-derden',
        'wettelijke-verplichting',
        'bedrijfsgeheim',
        'art-23-eigen-gegevens',
    ];

    /**
     * Constructor.
     *
     * @param AvgRepository   $repository The AVG OR repository.
     * @param LoggerInterface $logger     The logger.
     */
    public function __construct(
        private AvgRepository $repository,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Heuristic: whether a field path / value plausibly contains the data
     * subject's own identifying data.
     *
     * Compares the field path's leaf and the supplied current value against the
     * request's own name and BSN. This is deliberately conservative — it errs
     * toward flagging so the handler is warned before withholding the citizen's
     * own data.
     *
     * @param string               $fieldPath The JSONPath of the field.
     * @param string               $current   The current value at that path.
     * @param array<string, mixed> $request   The parent AvgVerzoek.
     *
     * @return bool True when the field looks like the citizen's own data.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.3
     */
    public function isOwnData(string $fieldPath, string $current, array $request): bool
    {
        $ownName = trim(mb_strtolower((string) ($request['verzoekerNaam'] ?? '')));
        $ownBsn  = trim((string) ($request['verzoekerBsn'] ?? ''));
        $value   = trim(mb_strtolower($current));

        if ($ownName !== '' && $value !== '' && mb_strpos($value, $ownName) !== false) {
            return true;
        }

        if ($ownBsn !== '' && mb_strpos($current, $ownBsn) !== false) {
            return true;
        }

        $leaf = mb_strtolower($fieldPath);
        foreach (['verzoeker', 'aanvrager', 'betrokkene'] as $marker) {
            if (mb_strpos($leaf, $marker) !== false) {
                return true;
            }
        }

        return false;
    }//end isOwnData()

    /**
     * Apply a redaction to an evidence item and record the RedactieActie.
     *
     * Server-authoritative: the before value is read from the persisted evidence,
     * not from the client. Redacting the citizen's own data requires the
     * art-23-eigen-gegevens ground; any other ground is rejected for own data
     * (REQ-AVG-006 sc.3).
     *
     * @param array<string, mixed> $request      The parent AvgVerzoek.
     * @param string               $bewijsItemId The evidence item UUID.
     * @param string               $fieldPath    The JSONPath to redact.
     * @param string               $ground       The redaction ground.
     * @param string               $replacement  The replacement value (optional).
     * @param string               $userId       The acting handler UID.
     *
     * @return array{item: array<string, mixed>, redaction: array<string, mixed>} The updated item + action.
     *
     * @throws OCSBadRequestException When the ground is invalid or own-data is redacted without grounds.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.4
     */
    public function applyRedaction(
        array $request,
        string $bewijsItemId,
        string $fieldPath,
        string $ground,
        string $replacement,
        string $userId
    ): array {
        if (in_array($ground, self::VALID_GROUNDS, true) === false) {
            throw new OCSBadRequestException('Onbekende redactiegrond.');
        }

        $item   = $this->repository->find(schemaKey: AvgRepository::SCHEMA_BEWIJS_ITEM, id: $bewijsItemId);
        $before = $this->valueAtPath(content: (string) ($item['inhoudPreview'] ?? ''), fieldPath: $fieldPath);

        if ($this->isOwnData(fieldPath: $fieldPath, current: $before, request: $request) === true
            && $ground !== self::GROUND_OWN_DATA
        ) {
            throw new OCSBadRequestException(
                'Dit lijken gegevens van de verzoeker zelf. Redactie betekent onthouden van het '
                .'inzagerecht en vereist een AVG-art-23-onderbouwing.'
            );
        }

        $after = $replacement;
        if (trim($after) === '') {
            $after = '[geredigeerd: '.$ground.']';
        }

        $redaction      = [
            'bewijsItemId'   => $bewijsItemId,
            'veldpad'        => $fieldPath,
            'voorWaarde'     => $before,
            'naWaarde'       => $after,
            'uitgevoerdDoor' => $userId,
            'uitgevoerdOp'   => $this->now(),
            'grond'          => $ground,
        ];
        $savedRedaction = $this->repository->save(schemaKey: AvgRepository::SCHEMA_REDACTIE_ACTIE, object: $redaction);

        $item['geredigeerd']   = true;
        $item['redactiereden'] = $ground;
        $item['inhoudPreview'] = $this->replaceAtPath(
            content: (string) ($item['inhoudPreview'] ?? ''),
            fieldPath: $fieldPath,
            before: $before,
            after: $after
        );
        $savedItem = $this->repository->save(
            schemaKey: AvgRepository::SCHEMA_BEWIJS_ITEM,
            object: $item,
            id: $bewijsItemId
        );

        $this->logger->info(
            'Pipelinq AVG: redaction applied',
            ['bewijsItemId' => $bewijsItemId, 'ground' => $ground, 'userId' => $userId]
        );

        return ['item' => $savedItem, 'redaction' => $savedRedaction];
    }//end applyRedaction()

    /**
     * Build the before/after redaction summary for a request's evidence.
     *
     * @param string $verzoekId The parent request UUID.
     *
     * @return array<int, array<string, mixed>> The redaction actions for 4-eyes review.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.4
     */
    public function summary(string $verzoekId): array
    {
        $items = $this->repository->findAll(
            schemaKey: AvgRepository::SCHEMA_BEWIJS_ITEM,
            filters: ['verzoekId' => $verzoekId]
        );

        $itemIds = [];
        foreach ($items as $item) {
            $itemIds[$this->repository->idOf($item)] = true;
        }

        $all      = $this->repository->findAll(schemaKey: AvgRepository::SCHEMA_REDACTIE_ACTIE);
        $relevant = [];
        foreach ($all as $action) {
            if (isset($itemIds[(string) ($action['bewijsItemId'] ?? '')]) === true) {
                $relevant[] = $action;
            }
        }

        return $relevant;
    }//end summary()

    /**
     * Read the current value at a JSONPath inside an evidence content blob.
     *
     * The content preview is treated as JSON when it parses; otherwise the whole
     * preview string is the value (a plain-text preview). For JSON, only simple
     * dot/`$.` paths are resolved (the depth evidence previews use).
     *
     * @param string $content   The evidence content.
     * @param string $fieldPath The JSONPath.
     *
     * @return string The current value (string-cast).
     */
    private function valueAtPath(string $content, string $fieldPath): string
    {
        $decoded = json_decode($content, true);
        if (is_array($decoded) === false) {
            return $content;
        }

        $node = $decoded;
        foreach ($this->pathSegments(fieldPath: $fieldPath) as $segment) {
            if (is_array($node) === true && array_key_exists($segment, $node) === true) {
                $node = $node[$segment];
                continue;
            }

            return '';
        }

        if (is_scalar($node) === true) {
            return (string) $node;
        }

        return (string) json_encode($node);
    }//end valueAtPath()

    /**
     * Replace a value at a JSONPath (or substring fallback) in the content.
     *
     * @param string $content   The evidence content.
     * @param string $fieldPath The JSONPath.
     * @param string $before    The value to replace.
     * @param string $after     The replacement.
     *
     * @return string The redacted content.
     */
    private function replaceAtPath(string $content, string $fieldPath, string $before, string $after): string
    {
        $decoded = json_decode($content, true);
        if (is_array($decoded) === true) {
            $segments = $this->pathSegments(fieldPath: $fieldPath);
            $this->setByPath(node: $decoded, segments: $segments, value: $after);
            return (string) json_encode($decoded);
        }

        if ($before !== '' && mb_strpos($content, $before) !== false) {
            return str_replace($before, $after, $content);
        }

        return $content;
    }//end replaceAtPath()

    /**
     * Set a value at a path inside a nested array, by reference.
     *
     * @param array<string, mixed> $node     The node (by reference).
     * @param array<int, string>   $segments The remaining path segments.
     * @param string               $value    The value to set.
     *
     * @return void
     */
    private function setByPath(array &$node, array $segments, string $value): void
    {
        $key = array_shift($segments);
        if ($key === null) {
            return;
        }

        if (count($segments) === 0) {
            $node[$key] = $value;
            return;
        }

        if (isset($node[$key]) === false || is_array($node[$key]) === false) {
            $node[$key] = [];
        }

        $this->setByPath(node: $node[$key], segments: $segments, value: $value);
    }//end setByPath()

    /**
     * Split a `$.a.b` / `a.b` JSONPath into its segments.
     *
     * @param string $fieldPath The path.
     *
     * @return array<int, string> The segments.
     */
    private function pathSegments(string $fieldPath): array
    {
        $path = ltrim($fieldPath, '$');
        $path = ltrim($path, '.');

        if ($path === '') {
            return [];
        }

        return explode('.', $path);
    }//end pathSegments()

    /**
     * The current time as an ISO 8601 string.
     *
     * @return string The timestamp.
     */
    private function now(): string
    {
        return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
    }//end now()
}//end class
