<?php

/**
 * Pipelinq EventLogQuery.
 *
 * One page of the CTI webhook event log that is asked for.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Cti
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/cti-screenpop-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti;


/**
 * The filters and the page of an event log listing.
 *
 * @spec openspec/specs/cti-screenpop-adapter/spec.md
 */
final class EventLogQuery {
	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $filters Optional filters (platform, event_type, external_call_id, signature_valid).
	 * @param int                 $limit   Page size.
	 * @param int                 $offset  Offset.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $filters,
		public readonly int $limit,
		public readonly int $offset,
	) {
	}//end __construct()

	/**
	 * The findAll config: the non-empty filters scoped to the event log
	 * schema, the page, newest first.
	 *
	 * @param string $register The register.
	 * @param string $schema   The event log schema.
	 *
	 * @return array<string,mixed> The config.
	 *
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md
	 */
	public function config(string $register, string $schema): array {
		return [
			'filters' => array_merge(
				['register' => $register, 'schema' => $schema],
				array_filter(
					$this->filters,
					static fn ($v): bool => ($v !== null && $v !== '')
				)
			),
			'limit' => $this->limit,
			'offset' => $this->offset,
			'sort' => ['received_at' => 'desc'],
		];
	}//end config()
}//end class
