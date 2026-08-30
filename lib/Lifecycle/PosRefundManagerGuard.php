<?php

/**
 * Pipelinq PosRefundManagerGuard.
 *
 * OpenRegister lifecycle guard for the posRefund `complete` and `reject`
 * transitions. Both require a POS manager (member of the configured manager
 * group) or a Nextcloud admin. `complete` additionally enforces the cumulative
 * OVER-REFUND CAP: the gross of this refund plus every already-completed refund
 * for the same original transaction may not exceed the original transaction
 * total. The server-authoritative proportional RECOMPUTE of the refund amounts
 * is performed by PosRefundService immediately before the transition (a guard
 * must be read-only); this guard validates the cap against those persisted,
 * server-computed figures. Referenced from the posRefund schema's
 * x-openregister-lifecycle.transitions.{complete,reject}.requires.
 *
 * @category Lifecycle
 * @package  OCA\Pipelinq\Lifecycle
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#2.5
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Guards the posRefund `complete` / `reject` transitions.
 *
 * Manager-only for both; cumulative over-refund cap for `complete`. Fails closed.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires the collaborators the cap
 *  check legitimately needs (access policy, OR container, app config, logger).
 * @SuppressWarnings(PHPMD.StaticAccess)           GuardResult exposes only the
 *  static allow()/deny() factories mandated by OpenRegister's contract.
 *
 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#2.5
 */
class PosRefundManagerGuard implements LifecycleGuardInterface {
	/**
	 * A one-cent tolerance applied to the over-refund cap so floating-point
	 * rounding can never wrongly reject a legitimate full-quantity refund.
	 *
	 * @var float
	 */
	private const CAP_TOLERANCE = 0.01;

	/**
	 * Constructor.
	 *
	 * @param PosAccessPolicy $policy The shared POS access policy.
	 * @param ContainerInterface $container The DI container (OR ObjectService).
	 * @param IAppConfig $appConfig The app config.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private PosAccessPolicy $policy,
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Authorise the complete / reject transition.
	 *
	 * @param array<string, mixed> $object The posRefund payload.
	 * @param string $action The transition action ('complete'|'reject').
	 * @param string $userId The acting user UID.
	 *
	 * @return GuardResult Allow for a manager/admin (and, for complete, within
	 *                     the over-refund cap); deny otherwise.
	 *
	 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#2.5
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($this->policy->isManager(userId: $userId) === false) {
			return GuardResult::deny('Alleen een beheerder mag een retour verwerken.');
		}

		if ($action !== 'complete') {
			return GuardResult::allow();
		}

		return $this->checkOverRefundCap(refund: $object);
	}//end check()

	/**
	 * Verify the cumulative over-refund cap for a refund being completed.
	 *
	 * @param array<string, mixed> $refund The posRefund payload.
	 *
	 * @return GuardResult Allow when within the cap; deny when it would exceed
	 *                     the original transaction total.
	 *
	 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#2.5
	 */
	private function checkOverRefundCap(array $refund): GuardResult {
		$transactionId = (string)($refund['originalTransaction'] ?? '');
		if ($transactionId === '') {
			return GuardResult::deny('Originele kassabon ontbreekt; retour kan niet worden bevestigd.');
		}

		$original = $this->fetchTransaction(id: $transactionId);
		if ($original === null) {
			return GuardResult::deny('Originele kassabon niet gevonden. Kan retour niet verwerken.');
		}

		$originalTotal = (float)($original['total'] ?? 0);
		$refundGross = ((float)($refund['refundAmount'] ?? 0) + (float)($refund['totalTax'] ?? 0));
		$refundId = (string)($refund['id'] ?? $refund['uuid'] ?? '');

		$alreadyRefunded = $this->sumCompletedRefunds(
			transactionId: $transactionId,
			excludeRefundId: $refundId
		);

		if (($alreadyRefunded + $refundGross) > ($originalTotal + self::CAP_TOLERANCE)) {
			return GuardResult::deny(
				'Retourvolume (€' . number_format(($alreadyRefunded + $refundGross), 2, ',', '.') . ') '
				. 'overschrijdt originele totaal (€' . number_format($originalTotal, 2, ',', '.') . ').'
			);
		}

		return GuardResult::allow();
	}//end checkOverRefundCap()

	/**
	 * Fetch the original transaction, or null when missing.
	 *
	 * @param string $id The transaction UUID.
	 *
	 * @return array<string, mixed>|null The transaction, or null.
	 *
	 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#2.5
	 */
	private function fetchTransaction(string $id): ?array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'posTransaction_schema', '');
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$object = $this->container->get('OCA\OpenRegister\Service\ObjectService')->find(
				id: $id,
				register: $register,
				schema: $schema
			);
		} catch (\Throwable $e) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $this->toArray(object: $object);
	}//end fetchTransaction()

	/**
	 * Sum the gross of every completed refund for a transaction, excluding one.
	 *
	 * Fails closed: an unverifiable lookup returns a sentinel that makes the cap
	 * impossible to satisfy (PHP_FLOAT_MAX), denying the completion rather than
	 * allowing an unbounded refund.
	 *
	 * @param string $transactionId The original transaction UUID.
	 * @param string $excludeRefundId The refund being completed (excluded).
	 *
	 * @return float The sum of completed refund gross amounts.
	 *
	 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#2.5
	 */
	private function sumCompletedRefunds(string $transactionId, string $excludeRefundId): float {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'posRefund_schema', '');
		if ($register === '' || $schema === '') {
			return PHP_FLOAT_MAX;
		}

		try {
			$results = $this->container->get('OCA\OpenRegister\Service\ObjectService')->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'originalTransaction' => $transactionId,
						'status' => 'completed',
					],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: refund guard could not sum completed refunds (fail closed)',
				['exception' => $e->getMessage(), 'transaction' => $transactionId]
			);
			return PHP_FLOAT_MAX;
		}

		$sum = 0.0;
		foreach (($results ?? []) as $result) {
			$sibling = $this->toArray(object: $result);
			$id = (string)($sibling['id'] ?? $sibling['uuid'] ?? '');
			if ($id === $excludeRefundId) {
				continue;
			}

			$sum += ((float)($sibling['refundAmount'] ?? 0) + (float)($sibling['totalTax'] ?? 0));
		}

		return round($sum, 2);
	}//end sumCompletedRefunds()

	/**
	 * Normalise an OR object (entity or array) into a plain array.
	 *
	 * @param mixed $object The OR object.
	 *
	 * @return array<string, mixed> The object as an array.
	 */
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$data = $object->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return (array)$object;
	}//end toArray()
}//end class
