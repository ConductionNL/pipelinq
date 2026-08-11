<?php

/**
 * Pipelinq NaviConversationStore.
 *
 * Per-user storage for the Navi conversation: a short list of turns (`query`
 * plus detected `intent`) that lets a follow-up question be answered in the
 * context of the question before it.
 *
 * WHY USER PREFERENCES AND NOT THE DISTRIBUTED CACHE. The obvious home for
 * something this ephemeral is `ICacheFactory::createDistributed()`, and that is
 * what the first cut used. It does not work: on an instance with no memcache
 * configured — the Nextcloud default — `createDistributed()` hands back a
 * `NullCache`, whose `set()` silently succeeds and whose `get()` always returns
 * null. Nothing errors, nothing logs, and every follow-up is answered as if it
 * were the first question. Unit tests that inject a working cache cannot see
 * it; the live e2e round trip did. So the record lives in the user's
 * preferences instead, which are backed by the database on every deployment.
 *
 * Still NO new schema and no migration (the design intent recorded in
 * NaviService's docblock holds): one small preference row per user, holding one
 * conversation, bounded three ways —
 *   - at most self::MAX_TURNS turns are retained;
 *   - each retained query is truncated to self::MAX_QUERY_LENGTH characters;
 *   - a record older than self::TTL is ignored on read, since preferences carry
 *     no expiry of their own.
 * Asking a question in a new conversation overwrites the row, so the storage a
 * user occupies is constant, not cumulative. This is user configuration, not a
 * log: nothing here is an audit trail and losing it costs only the thread of
 * one chat.
 *
 * Storing one record per user also makes cross-user isolation STRUCTURAL — the
 * conversation identifier is not part of the address, so it cannot be used to
 * reach another user's row at all. The explicit owner check is kept as defence
 * in depth.
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

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Durable, user-scoped store for Navi conversation turns.
 *
 * @spec openspec/specs/dashboard/spec.md#conversational-follow-up
 */
class NaviConversationStore
{
    /**
     * User-preference key holding the current conversation record.
     *
     * @var string
     */
    public const PREFERENCE_KEY = 'navi_conversation';

    /**
     * Age, in seconds, past which a stored conversation is ignored. Enforced
     * here on read: user preferences have no expiry of their own.
     *
     * @var int
     */
    public const TTL = 3600;

    /**
     * Maximum number of turns retained per conversation. Older turns are
     * dropped so a long chat cannot grow the record without bound.
     *
     * @var int
     */
    public const MAX_TURNS = 10;

    /**
     * Maximum length of a retained query. Only the opening words matter for
     * carrying a subject forward, and this is user config, not a transcript.
     *
     * @var int
     */
    public const MAX_QUERY_LENGTH = 240;

    /**
     * Constructor.
     *
     * @param IConfig         $config      Nextcloud configuration (user preferences).
     * @param ITimeFactory    $timeFactory Clock, so the TTL is testable.
     * @param LoggerInterface $logger      Logger.
     */
    public function __construct(
        private readonly IConfig $config,
        private readonly ITimeFactory $timeFactory,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Read the retained turns of this user's current conversation.
     *
     * Returns [] — a fresh conversation — when there is no record, when the
     * record belongs to a different conversation, when it has aged past the
     * TTL, or when its stored owner is not the reading user.
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

        $raw = $this->config->getUserValue(
            userId: $userId,
            appName: Application::APP_ID,
            key: self::PREFERENCE_KEY,
            default: ''
        );
        if ($raw === '') {
            return [];
        }

        $record = json_decode($raw, true);
        if (is_array($record) === false) {
            return [];
        }

        if ($this->isUsable(record: $record, userId: $userId, conversationId: $conversationId) === false) {
            return [];
        }

        return $this->turnsOf(record: $record);
    }//end read()

    /**
     * Append one turn to this user's conversation and store it.
     *
     * Overwrites whatever the user's single record held, so starting a new
     * conversation reclaims the space the previous one used.
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

        $compacted = [];
        foreach ($history as $entry) {
            $compacted[] = $this->compactTurn(turn: $entry);
        }

        $record = [
            'owner'          => $userId,
            'conversationId' => $conversationId,
            'updatedAt'      => $this->timeFactory->getTime(),
            'turns'          => $compacted,
        ];

        $this->config->setUserValue(
            userId: $userId,
            appName: Application::APP_ID,
            key: self::PREFERENCE_KEY,
            value: (string) json_encode($record)
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
     * Whether a decoded record may answer for this user and conversation.
     *
     * @param array<string, mixed> $record         Decoded record.
     * @param string               $userId         Authenticated user id.
     * @param string               $conversationId Conversation being asked about.
     *
     * @return bool
     */
    private function isUsable(array $record, string $userId, string $conversationId): bool
    {
        if (($record['owner'] ?? null) !== $userId) {
            $this->logger->warning(
                message: '[NaviConversationStore] record owner mismatch, ignored',
                context: ['userId' => $userId]
            );
            return false;
        }

        if (($record['conversationId'] ?? null) !== $conversationId) {
            return false;
        }

        $updatedAt = (int) ($record['updatedAt'] ?? 0);
        return ($this->timeFactory->getTime() - $updatedAt) <= self::TTL;
    }//end isUsable()

    /**
     * Reduce a turn to the two fields the conversation actually uses, with the
     * query truncated, so the stored record stays small and predictable.
     *
     * @param array<string, mixed> $turn A turn as held in memory.
     *
     * @return array{query: string, intent: string}
     */
    private function compactTurn(array $turn): array
    {
        return [
            'query'  => mb_substr((string) ($turn['query'] ?? ''), 0, self::MAX_QUERY_LENGTH),
            'intent' => (string) ($turn['intent'] ?? 'unknown'),
        ];
    }//end compactTurn()

    /**
     * Extract the turn list from a decoded record.
     *
     * @param array<string, mixed> $record Decoded record.
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
}//end class
