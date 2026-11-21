<?php

/**
 * Pipelinq CallerResolver.
 *
 * Finds who a phone number belongs to in pipelinq, and what is open for them.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Calls
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/kcc-agent-panel/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Calls;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\PhoneNormaliser;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves a caller's number to pipelinq clients and contacts, with their open tickets.
 *
 * The comparison is EXACT over full E.164 numbers. Not a suffix, not the last
 * nine digits: +31612345678 and +49612345678 are different people, and an
 * agent says the name on screen out loud before anybody can check it.
 *
 * It reads in system context, because a call arrives from a telephony
 * platform and no user is signed in while it is resolved. What it returns is
 * stored on the mirrored call, which only the server reads.
 *
 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-a-call-event-is-mirrored-and-resolved-once
 */
class CallerResolver {
	/**
	 * How many clients or contacts are read per schema. An install with more
	 * rows than this misses matches beyond it: the same bound the screen pop has.
	 */
	private const SCAN_LIMIT = 500;

	/**
	 * How many open tickets are stored per match.
	 */
	private const TICKET_LIMIT = 10;

	/**
	 * Ticket statuses that mean the matter is over.
	 */
	private const CLOSED_STATUSES = ['resolved', 'completed', 'rejected', 'converted', 'closed'];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Reads which register and schemas pipelinq uses.
	 * @param ContainerInterface $container Finds OpenRegister's object service at run time.
	 * @param PhoneNormaliser $phoneNormaliser Brings a stored number to E.164.
	 * @param LoggerInterface $logger Logger. A caller's number MUST NOT be written here.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private ContainerInterface $container,
		private PhoneNormaliser $phoneNormaliser,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The clients and contacts that have this number, each with their open tickets.
	 *
	 * @param string $e164 The caller's number in E.164. Empty means withheld: nobody matches.
	 *
	 * @return array<int, array{type: string, id: string, name: string, openTickets: array<int, array{id: string, title: string, status: string}>}>
	 *
	 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-a-call-event-is-mirrored-and-resolved-once
	 */
	public function inPipelinq(string $e164): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($e164 === '' || $register === '') {
			return [];
		}

		$matches = [];
		foreach (['client', 'contact'] as $type) {
			$schema = $this->appConfig->getValueString(Application::APP_ID, $type . '_schema', '');
			if ($schema === '') {
				continue;
			}

			foreach ($this->read(register: $register, schema: $schema, filters: [], limit: self::SCAN_LIMIT) as $row) {
				$id = (string)($row['id'] ?? ($row['uuid'] ?? ''));
				if ($id === '' || $this->hasNumber(row: $row, e164: $e164) === false) {
					continue;
				}

				$matches[] = [
					'type' => $type,
					'id' => $id,
					'name' => (string)($row['name'] ?? ''),
					'openTickets' => $this->openTickets(register: $register, type: $type, id: $id),
				];
			}
		}//end foreach

		return $matches;
	}//end inPipelinq()

	/**
	 * Whether a client or contact carries exactly this number.
	 *
	 * @param array<string, mixed> $row The client or contact.
	 * @param string $e164 The caller's number.
	 *
	 * @return bool True on an exact E.164 match.
	 */
	private function hasNumber(array $row, string $e164): bool {
		foreach (['phone', 'phoneNumber', 'mobile', 'telephone'] as $field) {
			$stored = ($row[$field] ?? '');
			if (is_string($stored) === false || trim($stored) === '') {
				continue;
			}

			if ($this->phoneNormaliser->normaliseForOrg($stored)['e164'] === $e164) {
				return true;
			}
		}

		return false;
	}//end hasNumber()

	/**
	 * The tickets of one client or contact that are not closed.
	 *
	 * @param string $register The register.
	 * @param string $type Either client or contact: also the ticket field that points at them.
	 * @param string $id Their id.
	 *
	 * @return array<int, array{id: string, title: string, status: string}>
	 */
	private function openTickets(string $register, string $type, string $id): array {
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'ticket_schema', '');
		if ($schema === '') {
			return [];
		}

		$open = [];
		foreach ($this->read(register: $register, schema: $schema, filters: [$type => $id], limit: 100) as $ticket) {
			$status = (string)($ticket['status'] ?? 'new');
			// Checked again here: a store that ignored the filter must not put
			// somebody else's ticket beside this caller.
			if (($ticket[$type] ?? '') !== $id || in_array($status, self::CLOSED_STATUSES, true) === true) {
				continue;
			}

			$open[] = [
				'id' => (string)($ticket['id'] ?? ($ticket['uuid'] ?? '')),
				'title' => (string)($ticket['title'] ?? ''),
				'status' => $status,
			];
			if (count($open) === self::TICKET_LIMIT) {
				break;
			}
		}

		return $open;
	}//end openTickets()

	/**
	 * Read rows of one schema in system context, as plain arrays.
	 *
	 * @param string $register The register.
	 * @param string $schema The schema.
	 * @param array<string, mixed> $filters Extra filters.
	 * @param int $limit Max rows.
	 *
	 * @return array<int, array<string, mixed>> The rows; empty when the read failed.
	 */
	private function read(string $register, string $schema, array $filters, int $limit): array {
		try {
			$rows = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService')->findAll(
				config: [
					'filters' => array_merge(['register' => $register, 'schema' => $schema], $filters),
					'limit' => $limit,
				],
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable $e) {
			$this->logger->warning('CallerResolver: a read failed', ['schema' => $schema, 'exception' => $e->getMessage()]);
			return [];
		}

		$out = [];
		foreach ((array)$rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$out[] = $row;
			}
		}

		return $out;
	}//end read()
}//end class
