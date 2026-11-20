<?php

/**
 * Pipelinq McpRecordUpdate.
 *
 * The steps every curated MCP update tool takes, written once: refuse a bad
 * call, read the stored record, save the merged record, and answer each
 * outcome in the MCP envelope.
 *
 * Validation runs before anything is read, the read before the write, and a
 * failure at any step comes back as `invalid_arguments`, `not_found`,
 * `forbidden` or `internal_error`, never as a partial success. Which record is
 * read and how it is saved stays with the service that owns the type: the
 * reads and writes are handed in as callables, so a ticket still saves through
 * TicketService::save() and a lead through ObjectService.
 *
 * @category Mcp
 * @package  OCA\Pipelinq\Mcp
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/mcp-full-action-coverage/specs/crm-mcp-tool-surface/spec.md#requirement-mcp-provider-exposes-rbac-guarded-crm-write-tools
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Mcp;

use Exception;
use InvalidArgumentException;

/**
 * Refuse, read and save one record for an MCP update tool.
 *
 * @spec openspec/changes/mcp-full-action-coverage/specs/crm-mcp-tool-surface/spec.md#requirement-mcp-provider-exposes-rbac-guarded-crm-write-tools
 */
class McpRecordUpdate {

	/**
	 * Constructor.
	 *
	 * @param McpAnswer $mcp Shapes the answers.
	 */
	public function __construct(
		private readonly McpAnswer $mcp,
	) {
	}//end __construct()

	/**
	 * The fields that carry a value: neither null nor an empty string.
	 *
	 * @param array<string, string|int|float|null> $fields The optional fields.
	 *
	 * @return array<string, string|int|float>
	 *
	 * @spec openspec/changes/mcp-full-action-coverage/specs/crm-mcp-tool-surface/spec.md#requirement-mcp-provider-exposes-rbac-guarded-crm-write-tools
	 */
	public function filled(array $fields): array {
		return array_filter($fields, static fn (mixed $value): bool => $value !== null && $value !== '');
	}//end filled()

	/**
	 * The invalid_arguments refusal of an update call, or null when it may proceed.
	 *
	 * An update needs an id and at least one field to change. A lifecycle
	 * state is never written by an update tool: any `status` is refused with
	 * `$statusRefusal`, which names the transition tools that move it.
	 *
	 * @param string               $id            The trimmed record id.
	 * @param array<string, mixed> $changes       The fields that carry a value.
	 * @param string|null          $status        The status argument.
	 * @param string               $statusRefusal What a status argument is answered with.
	 *
	 * @return array<string, mixed>|null The envelope, or null.
	 *
	 * @spec openspec/changes/mcp-full-action-coverage/specs/crm-mcp-tool-surface/spec.md#requirement-lifecycle-transitions-are-curated-tools-one-per-action
	 */
	public function refusal(string $id, array $changes, ?string $status, string $statusRefusal): ?array {
		if ($id === '') {
			return $this->mcp->error(code: 'invalid_arguments', message: 'Required argument id is missing.');
		}

		if ($status !== null && $status !== '') {
			return $this->mcp->error(code: 'invalid_arguments', message: $statusRefusal);
		}

		if ($changes === []) {
			return $this->mcp->error(code: 'invalid_arguments', message: 'Give at least one field to change.');
		}

		return null;
	}//end refusal()

	/**
	 * The stored record as an array, or the not_found / forbidden envelope.
	 *
	 * @param callable(): mixed $find    Reads the record; null or a "not found" failure means there is none.
	 * @param string            $subject The kind of record, for the message.
	 *
	 * @return array<string, mixed> The record, or an MCP error envelope under `error`.
	 *
	 * @spec openspec/changes/mcp-full-action-coverage/specs/crm-mcp-tool-surface/spec.md#requirement-mcp-provider-exposes-rbac-guarded-crm-write-tools
	 */
	public function stored(callable $find, string $subject): array {
		try {
			$record = $find();
		} catch (Exception $e) {
			if ($this->isNotFound(error: $e) === false) {
				return $this->mcp->fromException(operation: "read {$subject}", exception: $e);
			}

			$record = null;
		}

		if ($record === null) {
			return $this->mcp->error(code: 'not_found', message: "No {$subject} with that id.");
		}

		return $this->mcp->toArray(item: $record);
	}//end stored()

	/**
	 * The saved record as an array, or the invalid_arguments / forbidden / internal_error envelope.
	 *
	 * @param callable(): mixed $save      Saves the record and returns it.
	 * @param string            $operation What the save does, for the log, e.g. `update lead`.
	 *
	 * @return array<string, mixed> The saved record, or an MCP error envelope under `error`.
	 *
	 * @spec openspec/changes/mcp-full-action-coverage/specs/crm-mcp-tool-surface/spec.md#requirement-mcp-provider-exposes-rbac-guarded-crm-write-tools
	 */
	public function saved(callable $save, string $operation): array {
		try {
			return $this->mcp->toArray(item: $save());
		} catch (InvalidArgumentException $e) {
			return $this->mcp->error(code: 'invalid_arguments', message: $e->getMessage());
		} catch (Exception $e) {
			return $this->mcp->fromException(operation: $operation, exception: $e);
		}
	}//end saved()

	/**
	 * Whether a read failure means the record does not exist (for this caller).
	 *
	 * @param Exception $error The failure.
	 *
	 * @return bool
	 */
	private function isNotFound(Exception $error): bool {
		$class = get_class($error);
		$short = substr($class, (int)strrpos('\\' . $class, '\\'));

		return $short === 'DoesNotExistException' || stripos($error->getMessage(), 'not found') !== false;
	}//end isNotFound()
}//end class
