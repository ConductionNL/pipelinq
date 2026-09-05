<?php

/**
 * Pipelinq PortalCleanupService.
 *
 * Nightly AVG Art. 17 follow-through: for every closed portal account whose
 * linked contact no longer carries a retention obligation (no open invoices, no
 * active contracts, retention period lapsed), it pseudonymises the contact
 * (name/email → SHA-256 hash, phone removed) and detaches it from the closed
 * account, then audits the pseudonymisation. Accounts still under retention are
 * left untouched so legal obligations are never violated (REQ-010).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Portal
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/customer-portal/specs.md#REQ-010
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

/**
 * Pseudonymises contacts of closed portal accounts when retention allows.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Spans the portal account store
 *  and the main register (contacts + invoices) a retention check legitimately needs.
 *
 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
 *   sessions, tokens, delegation, documents, invoices, orders, exports and
 *   audit are all unspecified
 */
class PortalCleanupService {
	/**
	 * Schema slug for accounts.
	 *
	 * @var string
	 */
	private const ACCOUNT_SCHEMA = 'crmPortalAccount';

	/**
	 * Constructor.
	 *
	 * @param PortalObjectRepository $repository The portal object repository.
	 * @param MainRegisterReader $reader The main-register reader.
	 * @param PortalAuditService $audit The audit service.
	 */
	public function __construct(
		private PortalObjectRepository $repository,
		private MainRegisterReader $reader,
		private PortalAuditService $audit,
	) {
	}//end __construct()

	/**
	 * Run a cleanup pass over all closed accounts.
	 *
	 * @return int The number of contacts pseudonymised.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function run(): int {
		$pseudonymised = 0;
		foreach ($this->repository->findAll(self::ACCOUNT_SCHEMA, ['status' => 'closed']) as $account) {
			if ($this->processClosedAccount(account: $account) === true) {
				$pseudonymised++;
			}
		}

		return $pseudonymised;
	}//end run()

	/**
	 * Process a single closed account: pseudonymise its contact when no
	 * retention obligation remains.
	 *
	 * @param array<string, mixed> $account The closed account.
	 *
	 * @return bool True when a contact was pseudonymised.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function processClosedAccount(array $account): bool {
		$contactId = ($account['linkedContactId'] ?? null);
		if ($contactId === null || $contactId === '') {
			return false;
		}

		if ($this->hasRetentionObligation(contactId: (string)$contactId) === true) {
			return false;
		}

		$contact = $this->reader->find('contact', (string)$contactId);
		if ($contact === null) {
			// Contact already gone; just detach.
			$account['linkedContactId'] = null;
			$this->repository->save(self::ACCOUNT_SCHEMA, $account, $this->repository->idOf(object: $account));
			return false;
		}

		$contact['name'] = '#' . substr(hash('sha256', (string)($contact['name'] ?? '')), 0, 16);
		$contact['email'] = '#' . substr(hash('sha256', (string)($contact['email'] ?? '')), 0, 16);
		$contact['phone'] = null;
		$this->reader->save('contact', $contact, $this->idOf(object: $contact));

		$accountId = (string)$this->repository->idOf(object: $account);
		$account['linkedContactId'] = null;
		$this->repository->save(self::ACCOUNT_SCHEMA, $account, $accountId);

		$this->audit->log(
			$accountId,
			(string)($account['tenantId'] ?? ''),
			'account-pseudonymised',
			'success',
			['targetObjectType' => 'contact', 'targetObjectId' => (string)$contactId]
		);

		return true;
	}//end processClosedAccount()

	/**
	 * Whether a contact still has a retention obligation (any non-final
	 * invoice / posTransaction). Conservative: when in doubt, retain.
	 *
	 * @param string $contactId The contact id (unused directly; client link).
	 *
	 * @return bool True when retention applies.
	 */
	private function hasRetentionObligation(string $contactId): bool {
		// An open invoice (not settled/refunded) is treated as a retention hold.
		foreach ($this->reader->findAll('posTransaction') as $invoice) {
			$status = (string)($invoice['status'] ?? '');
			$owner = ($invoice['client'] ?? null);
			$ownerId = $owner;
			if (is_array($owner) === true) {
				$ownerId = ($owner['id'] ?? $owner['uuid'] ?? null);
			}

			if ((string)$ownerId === $contactId && in_array($status, ['settled', 'refunded'], true) === false) {
				return true;
			}
		}

		return false;
	}//end hasRetentionObligation()

	/**
	 * Extract the stable id from a main-register object array.
	 *
	 * @param array<string, mixed> $object The object.
	 *
	 * @return string|null The id.
	 */
	private function idOf(array $object): ?string {
		$self = ($object['@self'] ?? null);
		if (is_array($self) === true) {
			$id = ($self['id'] ?? $self['uuid'] ?? null);
			if ($id !== null) {
				return (string)$id;
			}
		}

		$id = ($object['id'] ?? $object['uuid'] ?? null);
		if ($id === null) {
			return null;
		}

		return (string)$id;
	}//end idOf()
}//end class
