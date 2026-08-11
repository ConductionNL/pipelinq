<?php

/**
 * Pipelinq NaviConversationStore.
 *
 * Ephemeral per-user storage for Navi conversations. A conversation is a short
 * list of turns (`query` + detected `intent`) held in the Nextcloud distributed
 * cache under a TTL, which is what lets a follow-up question be answered in the
 * context of the question before it.
 *
 * Two independent barriers keep one user's conversation out of another's
 * answers, because the Navi endpoint is reachable by any authenticated user and
 * the identifier travels in the request body:
 *   1. the cache key is derived from the user id AND the conversation id, so
 *      replaying someone else's identifier addresses a different record;
 *   2. the owning user id is stored inside the record and re-checked on read,
 *      so a record that somehow surfaces under the wrong key is still refused.
 *
 * No register or schema is involved: conversation context is ephemeral by
 * nature and losing it on a cache flush costs nothing but the thread of one
 * chat. Growth is bounded by the TTL and by a maximum number of retained turns.
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/dashboard/spec.md#conversational-follow-up
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * User-scoped, TTL-bounded store for Navi conversation turns.
 *
 * @spec openspec/specs/dashboard/spec.md#conversational-follow-up
 */
class NaviConversationStore
{
    /**
     * Distributed-cache namespace holding conversation records.
     *
     * @var string
     */
    public const CACHE_NAMESPACE = 'pipelinq_navi_conversation';

    /**
     * Lifetime of a conversation record, in seconds. A thread the user stops
     * pursuing expires on its own rather than being retained indefinitely.
     *
     * @var int
     */
    public const TTL = 3600;

    /**
     * Maximum number of turns retained per conversation. Older turns are
     * dropped so a long-running chat cannot grow the record without bound.
     *
     * @var int
     */
    public const MAX_TURNS = 10;

    /**
     * Lazily created cache.
     *
     * @var ICache|null
     */
    private ?ICache $cache = null;

    /**
     * Constructor.
     *
     * @param ICacheFactory   $cacheFactory Factory for the distributed cache.
     * @param LoggerInterface $logger       Logger.
     */
    public function __construct(
        private readonly ICacheFactory $cacheFactory,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Read the retained turns of a conversation belonging to this user.
     *
     * A record whose stored owner is not the reading user is refused and logged
     * rather than returned.
     *
     * @param string      $userId         Authenticated user id.
     * @param string|null $conversationId Conversation identifier, or null.
     *
     * @return array<int, array<string, mixed>> Retained turns, oldest first.
     *
     * @spec openspec/specs/dashboard/spec.md#conversational-follow-up
     */
    public function read(string $userId, ?string $conversationId): array
    {
        if ($conversationId === null || $conversationId === '') {
            return [];
        }

        $raw = $this->cache()->get($this->key(userId: $userId, conversationId: $conversationId));
        if (is_string($raw) === false) {
            return [];
        }

        $record = json_decode($raw, true);
        if (is_array($record) === false) {
            return [];
        }

        if (($record['owner'] ?? null) !== $userId) {
            $this->logger->warning(
                message: '[NaviConversationStore] record owner mismatch, ignored',
                context: ['userId' => $userId]
            );
            return [];
        }

        return $this->turnsOf(record: $record);
    }//end read()

    /**
     * Append one turn to a conversation and store it under the TTL.
     *
     * Only the newest self::MAX_TURNS turns survive.
     *
     * @param string                           $userId         Authenticated user id.
     * @param string|null                      $conversationId Conversation identifier, or null to store nothing.
     * @param array<int, array<string, mixed>> $history        Turns already retained.
     * @param array<string, mixed>             $turn           The turn to append.
     *
     * @return void
     *
     * @spec openspec/specs/dashboard/spec.md#conversational-follow-up
     */
    public function append(string $userId, ?string $conversationId, array $history, array $turn): void
    {
        if ($conversationId === null || $conversationId === '') {
            return;
        }

        $history[] = $turn;
        if (count($history) > self::MAX_TURNS) {
            $history = array_slice($history, -self::MAX_TURNS);
        }

        $this->cache()->set(
            $this->key(userId: $userId, conversationId: $conversationId),
            (string) json_encode(['owner' => $userId, 'turns' => array_values($history)]),
            self::TTL
        );
    }//end append()

    /**
     * Decide what a new turn carries forward from the conversation.
     *
     * A turn that classifies on its own words keeps the intent it was given; a
     * turn that does not (`unknown`) adopts the preceding turn's, provided that
     * one is usable. The preceding query is returned either way, because a turn
     * can name an intent without naming a subject ("And how many of those are
     * overdue?") and the subject then comes from the turn it follows.
     *
     * An empty conversation, a malformed turn or an unrecognised recorded
     * intent all leave the detected intent untouched, so the caller needs no
     * special case for "there is nothing to inherit".
     *
     * @param string                           $detectedIntent Intent detected from the new turn's own words.
     * @param array<int, array<string, mixed>> $history        Retained turns, oldest first.
     *
     * @return array{intent: string, query: string} The effective intent and the
     *         preceding turn's query ('' when there is none).
     *
     * @spec openspec/specs/dashboard/spec.md#conversational-follow-up
     */
    public function carryForward(string $detectedIntent, array $history): array
    {
        $carried = ['intent' => $detectedIntent, 'query' => ''];
        if ($history === []) {
            return $carried;
        }

        $turn = $history[(count($history) - 1)];
        $carried['query'] = (string) ($turn['query'] ?? '');

        $previousIntent = (string) ($turn['intent'] ?? 'unknown');
        $usable         = in_array($previousIntent, NaviService::INTENTS, true);
        if ($detectedIntent === 'unknown' && $usable === true) {
            $carried['intent'] = $previousIntent;
        }

        return $carried;
    }//end carryForward()

    /**
     * Extract the turn list from a decoded record.
     *
     * @param array<string, mixed> $record Decoded cache record.
     *
     * @return array<int, array<string, mixed>>
     */
    private function turnsOf(array $record): array
    {
        $turns = ($record['turns'] ?? []);
        if (is_array($turns) === false) {
            return [];
        }

        return array_values(array_filter($turns, 'is_array'));
    }//end turnsOf()

    /**
     * Derive the cache key for one user's view of one conversation.
     *
     * Both components are hashed together, so the key is fixed-length and
     * neither the user id nor the conversation id shapes it on its own.
     *
     * @param string $userId         Authenticated user id.
     * @param string $conversationId Conversation identifier.
     *
     * @return string
     */
    private function key(string $userId, string $conversationId): string
    {
        return hash('sha256', $userId."\0".$conversationId);
    }//end key()

    /**
     * Lazily resolve the distributed cache.
     *
     * @return ICache
     */
    private function cache(): ICache
    {
        if ($this->cache === null) {
            $this->cache = $this->cacheFactory->createDistributed(self::CACHE_NAMESPACE);
        }

        return $this->cache;
    }//end cache()
}//end class
