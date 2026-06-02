<?php

/**
 * Pipelinq EntityActivityService.
 *
 * Aggregates a single entity's activity instances — internal notes and linked
 * contactmomenten — into one paginated, reverse-chronological result set for
 * the Activity REST API. Composes the existing ActivityTimelineService (for
 * contactmomenten) and NotesService (for notes) rather than re-querying
 * OpenRegister or the comments backend directly (ADR-012 deduplication).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
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
 * @spec openspec/changes/entity-notes/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Service that builds the unified entity activity feed (notes + contactmomenten).
 *
 * @spec openspec/changes/entity-notes/tasks.md#task-2
 */
class EntityActivityService
{
    /**
     * Entity types that may carry an activity feed.
     *
     * @var array<int,string>
     */
    public const VALID_ENTITY_TYPES = ['client', 'contact', 'lead', 'request'];

    /**
     * Activity type filters accepted by the API.
     *
     * @var array<int,string>
     */
    public const VALID_TYPES = ['all', 'notes', 'contactmomenten'];

    /**
     * Default page size for the activity feed.
     */
    private const DEFAULT_LIMIT = 20;

    /**
     * Maximum page size for the activity feed.
     */
    private const MAX_LIMIT = 100;

    /**
     * Per-source fetch ceiling used when collecting items before merge/paginate.
     */
    private const SOURCE_CEILING = 500;

    /**
     * Constructor.
     *
     * @param ActivityTimelineService $timelineService The shared CRM activity aggregator.
     * @param NotesService            $notesService    The entity notes service.
     * @param LoggerInterface         $logger          The logger.
     */
    public function __construct(
        private ActivityTimelineService $timelineService,
        private NotesService $notesService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Build the merged, sorted, paginated activity feed for an entity.
     *
     * @param string $entityType The entity type (client|contact|lead|request).
     * @param string $entityId   The entity UUID.
     * @param string $type       The activity filter (all|notes|contactmomenten).
     * @param int    $page       The 1-based page number.
     * @param int    $limit      The requested page size.
     *
     * @return array{total: int, page: int, pages: int, results: array<int,array<string,mixed>>}
     *
     * @throws \InvalidArgumentException When the entity type is not in the allowlist.
     *
     * @spec openspec/changes/entity-notes/tasks.md#task-2
     */
    public function getActivity(string $entityType, string $entityId, string $type, int $page, int $limit): array
    {
        if (in_array($entityType, self::VALID_ENTITY_TYPES, true) === false) {
            throw new InvalidArgumentException('Invalid entity type');
        }

        $type  = $this->normaliseType(type: $type);
        $page  = max(1, $page);
        $limit = $this->normaliseLimit(limit: $limit);

        $items = [];
        if ($type === 'all' || $type === 'contactmomenten') {
            $items = array_merge($items, $this->collectContactmomenten(entityType: $entityType, entityId: $entityId));
        }

        if ($type === 'all' || $type === 'notes') {
            $items = array_merge($items, $this->collectNotes(entityType: $entityType, entityId: $entityId));
        }

        // Sort reverse-chronologically (newest first); items without a
        // timestamp sort last.
        usort(
            $items,
            static function (array $left, array $right): int {
                return strcmp((string) ($right['timestamp'] ?? ''), (string) ($left['timestamp'] ?? ''));
            }
        );

        return $this->paginate(items: $items, page: $page, limit: $limit);
    }//end getActivity()

    /**
     * Collect linked contactmomenten for an entity in the public activity shape.
     *
     * Delegates to {@see ActivityTimelineService::getTimeline()} with the
     * contactmoment type filter, then projects each timeline item onto the
     * Activity API item shape.
     *
     * @param string $entityType The entity type.
     * @param string $entityId   The entity UUID.
     *
     * @return array<int,array<string,mixed>> The normalised contactmoment items.
     */
    private function collectContactmomenten(string $entityType, string $entityId): array
    {
        try {
            $timeline = $this->timelineService->getTimeline(
                entityType: $entityType,
                entityId: $entityId,
                params: [
                    'types'  => ['contactmoment'],
                    '_page'  => 1,
                    '_limit' => self::SOURCE_CEILING,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'EntityActivityService: failed to collect contactmomenten',
                ['exception' => $e->getMessage()]
            );
            return [];
        }

        $items = [];
        foreach ($timeline['items'] as $item) {
            $items[] = [
                'type'      => 'contactmoment',
                'id'        => (string) ($item['id'] ?? ''),
                'subject'   => (string) ($item['title'] ?? ''),
                'channel'   => (string) ($item['metadata']['channel'] ?? ''),
                'agent'     => $this->nullableString(value: ($item['user'] ?? null)),
                'timestamp' => $this->nullableString(value: ($item['date'] ?? null)),
                'summary'   => (string) ($item['description'] ?? ''),
            ];
        }

        return $items;
    }//end collectContactmomenten()

    /**
     * Collect internal notes for an entity in the public activity shape.
     *
     * Notes are stored as Nextcloud comments keyed by the `pipelinq_{type}`
     * object type (see {@see NotesService}).
     *
     * @param string $entityType The entity type.
     * @param string $entityId   The entity UUID.
     *
     * @return array<int,array<string,mixed>> The normalised note items.
     */
    private function collectNotes(string $entityType, string $entityId): array
    {
        try {
            $notes = $this->notesService->getNotes(
                objectType: 'pipelinq_'.$entityType,
                objectId: $entityId
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'EntityActivityService: failed to collect notes',
                ['exception' => $e->getMessage()]
            );
            return [];
        }

        $items = [];
        foreach ($notes as $note) {
            if (is_array($note) === false) {
                continue;
            }

            $items[] = [
                'type'      => 'note',
                'id'        => (string) ($note['id'] ?? ''),
                'subject'   => (string) ($note['message'] ?? ''),
                'channel'   => null,
                'agent'     => $this->nullableString(value: ($note['authorId'] ?? null)),
                'timestamp' => $this->nullableString(value: ($note['timestamp'] ?? null)),
                'summary'   => (string) ($note['message'] ?? ''),
            ];
        }

        return $items;
    }//end collectNotes()

    /**
     * Slice a sorted item list into the requested page envelope.
     *
     * @param array<int,array<string,mixed>> $items The sorted activity items.
     * @param int                            $page  The 1-based page number.
     * @param int                            $limit The page size.
     *
     * @return array{total: int, page: int, pages: int, results: array<int,array<string,mixed>>}
     */
    private function paginate(array $items, int $page, int $limit): array
    {
        $total = count($items);

        $pages = 1;
        if ($total !== 0) {
            $pages = (int) ceil($total / $limit);
        }

        $page  = min($page, $pages);
        $start = (($page - 1) * $limit);
        if ($start < 0) {
            $start = 0;
        }

        return [
            'total'   => $total,
            'page'    => $page,
            'pages'   => $pages,
            'results' => array_values(array_slice($items, $start, $limit)),
        ];
    }//end paginate()

    /**
     * Normalise the type filter, defaulting unknown values to 'all'.
     *
     * @param string $type The raw type filter.
     *
     * @return string The normalised filter (all|notes|contactmomenten).
     */
    private function normaliseType(string $type): string
    {
        $type = strtolower(trim($type));
        if (in_array($type, self::VALID_TYPES, true) === false) {
            return 'all';
        }

        return $type;
    }//end normaliseType()

    /**
     * Clamp a raw limit into the [1, MAX_LIMIT] range.
     *
     * @param int $limit The requested page size.
     *
     * @return int The clamped page size.
     */
    private function normaliseLimit(int $limit): int
    {
        if ($limit <= 0) {
            return self::DEFAULT_LIMIT;
        }

        if ($limit > self::MAX_LIMIT) {
            return self::MAX_LIMIT;
        }

        return $limit;
    }//end normaliseLimit()

    /**
     * Coerce a value to a non-empty string, or null.
     *
     * @param mixed $value The raw value.
     *
     * @return string|null The string value, or null when empty.
     */
    private function nullableString(mixed $value): ?string
    {
        if (is_scalar($value) === false) {
            return null;
        }

        $stringValue = (string) $value;
        if ($stringValue === '') {
            return null;
        }

        return $stringValue;
    }//end nullableString()
}//end class
