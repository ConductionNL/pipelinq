<?php

/**
 * Pipelinq ObjectPageReader.
 *
 * Reads matching OpenRegister objects page by page until a short page, the
 * row cap (both set by the owner), a non-list answer or a failed read. A failed read stops the paging
 * and is kept on `$failure` for the owner to report; the access flags the
 * owner chose are passed to every read.
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
 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-attribution-closes-on-a-paid-invoice-or-on-a-won-lead-and-the-report-says-which
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use Throwable;

/**
 * A paged `findAll` over the OpenRegister object service; built by its owner per read.
 *
 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-attribution-closes-on-a-paid-invoice-or-on-a-won-lead-and-the-report-says-which
 */
final class ObjectPageReader {
	/**
	 * The read that stopped the paging, or null when none failed.
	 *
	 * @var Throwable|null
	 */
	public ?Throwable $failure = null;

	/**
	 * Rows per page.
	 *
	 * @var integer
	 */
	public int $pageSize = 100;

	/**
	 * Never read more than this many rows.
	 *
	 * @var integer
	 */
	public int $maxRows = 500;

	/**
	 * Constructor.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param bool   $withRights    Whether reads apply RBAC and multitenancy.
	 */
	public function __construct(
		private readonly object $objectService,
		private readonly bool $withRights,
	) {
	}//end __construct()

	/**
	 * Every matching object, in page order, as the service answered it.
	 *
	 * @param array<string, mixed> $filters The filters, register and schema included.
	 *
	 * @return array<int, mixed> The objects, unconverted.
	 *
	 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-attribution-closes-on-a-paid-invoice-or-on-a-won-lead-and-the-report-says-which
	 */
	public function readAll(array $filters): array {
		$this->failure = null;
		$rows = [];
		$offset = 0;
		while ($offset < $this->maxRows) {
			$page = $this->fetch(config: ['filters' => $filters, 'limit' => $this->pageSize, 'offset' => $offset]);
			if ($page === null) {
				break;
			}

			$rows = array_merge($rows, $page);
			if (count($page) < $this->pageSize) {
				break;
			}

			$offset += $this->pageSize;
		}

		return $rows;
	}//end readAll()

	/**
	 * One page, as a list; null when the read failed or answered no list.
	 *
	 * @param array<string, mixed> $config The findAll config: filters, limit and offset.
	 *
	 * @return array<int, mixed>|null
	 */
	private function fetch(array $config): ?array {
		try {
			$page = $this->objectService->findAll(
				config: $config,
				_rbac: $this->withRights,
				_multitenancy: $this->withRights
			);
		} catch (Throwable $e) {
			$this->failure = $e;
			return null;
		}

		if (is_iterable($page) === false) {
			return null;
		}

		return array_values(iterator_to_array($page, false));
	}//end fetch()
}//end class
