<?php

/**
 * Contract of ListObjectStore
 *
 * The public surface of ListObjectStore that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

/**
 * Contract of ListObjectStore.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ListObjectStoreInterface {

	/**
	 * Fetch every object matching a filter set.
	 *
	 * The filters are re-applied in PHP after the query. OpenRegister's
	 * filter DSL ignores a key it does not recognise, and an ignored filter
	 * returns rows that were never asked for while looking exactly like a
	 * correct result.
	 *
	 * @param string $schemaSlug The schema to read from.
	 * @param array<string, string> $filters Field-value pairs.
	 *
	 * @return array<int, array<string, mixed>> Plain payloads.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
	 */
	public function findAll(string $schemaSlug, array $filters = []): array;

	/**
	 * Extract the canonical id from an entity payload.
	 *
	 * @param array<string, mixed>|null $payload Entity payload.
	 *
	 * @return string Identifier, or an empty string.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Ordered fallback lookup over id keys; extraction adds no clarity.
	 */
	public function idOf(?array $payload): string;

	/**
	 * Persist an object, creating it when no id is given.
	 *
	 * @param string $schemaSlug The schema to write to.
	 * @param array<string, mixed> $payload The payload to store.
	 * @param string|null $id Existing id when updating.
	 *
	 * @return array<string, mixed>|null Saved row, or null on failure.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
	 */
	public function save(string $schemaSlug, array $payload, ?string $id = null): ?array;
}//end interface
