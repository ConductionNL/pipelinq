<?php

/**
 * Pipelinq PosCartObjects.
 *
 * Reads and writes the sale and its lines for the till's server-side pricing.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\PosCart
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/till-server-priced-lines/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\PosCart;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\Object\ObjectFindTarget;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Util\PayloadToArray;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use Throwable;

/**
 * The sale and its lines as OpenRegister stores them.
 *
 * @spec openspec/changes/till-server-priced-lines/tasks.md#task-1
 */
class PosCartObjects {
	use PayloadToArray;

	/**
	 * Most lines read per sale.
	 */
	private const LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objects   OpenRegister's object service.
	 * @param IAppConfig             $appConfig Holds the register and schema ids.
	 *
	 * @spec openspec/changes/till-server-priced-lines/tasks.md#task-1
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objects,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Whether a schema id is the sale line schema.
	 *
	 * @param string $schemaId The schema id of a stored object.
	 *
	 * @return bool True for a posTransactionLine.
	 *
	 * @spec openspec/changes/till-server-priced-lines/tasks.md#task-1
	 */
	public function isLineSchema(string $schemaId): bool {
		$lineSchema = $this->schemaId(slug: 'posTransactionLine');
		return $lineSchema !== '' && $lineSchema === $schemaId;
	}//end isLineSchema()

	/**
	 * The price display of a sale, or null when the sale cannot be read.
	 *
	 * @param string $transactionId The sale's id.
	 *
	 * @return string|null The stored priceMode.
	 *
	 * @spec openspec/changes/till-server-priced-lines/tasks.md#task-1
	 */
	public function priceModeOf(string $transactionId): ?string {
		try {
			$mode = ($this->transaction(transactionId: $transactionId)['priceMode'] ?? null);
		} catch (Throwable) {
			return null;
		}

		if (is_string($mode) === false) {
			return null;
		}

		return $mode;
	}//end priceModeOf()

	/**
	 * A sale's data.
	 *
	 * @param string $transactionId The sale's id.
	 *
	 * @return array<string, mixed> The sale.
	 *
	 * @throws OCSNotFoundException When the sale is not in this app's register.
	 *
	 * @spec openspec/changes/till-server-priced-lines/tasks.md#task-1
	 */
	public function transaction(string $transactionId): array {
		$found = null;
		if (trim($transactionId) !== '') {
			$found = $this->objects->find(
				id: $transactionId,
				register: $this->registerId(),
				schema: $this->schemaId(slug: 'posTransaction'),
				_rbac: false,
				_multitenancy: false
			);
		}

		if ($found === null) {
			throw new OCSNotFoundException('Transaction not found');
		}

		return $this->toArray(value: $found);
	}//end transaction()

	/**
	 * Every line on a sale.
	 *
	 * @param string $transactionId The sale's id.
	 *
	 * @return array<int, array<string, mixed>> The lines.
	 *
	 * @spec openspec/changes/till-server-priced-lines/tasks.md#task-1
	 */
	public function lines(string $transactionId): array {
		$filters = [
			'register'    => $this->registerId(),
			'schema'      => $this->schemaId(slug: 'posTransactionLine'),
			'transaction' => $transactionId,
		];
		$rows    = $this->objects->findAll(config: ['filters' => $filters, 'limit' => self::LIMIT], _rbac: false, _multitenancy: false);
		return array_values(array_map(fn ($row): array => $this->toArray(value: $row), $rows));
	}//end lines()

	/**
	 * Store a line; the pricing hook writes its figures.
	 *
	 * @param array<string, mixed> $line The line, with its id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/till-server-priced-lines/tasks.md#task-1
	 */
	public function saveLine(array $line): void {
		$uuid = (string)($line['id'] ?? '');
		unset($line['@self']);
		$this->objects->saveObject(
			object: $line,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: $this->registerId(), schema: $this->schemaId(slug: 'posTransactionLine')),
				uuid: $uuid,
			),
			_rbac: false,
			_multitenancy: false,
		);
	}//end saveLine()

	/**
	 * The register id.
	 *
	 * @return string The id, or '' when the register was never imported.
	 */
	private function registerId(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'register', '');
	}//end registerId()

	/**
	 * The schema id stored for a schema slug.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return string The id, or '' when the register was never imported.
	 */
	private function schemaId(string $slug): string {
		return $this->appConfig->getValueString(Application::APP_ID, $slug.'_schema', '');
	}//end schemaId()
}//end class
