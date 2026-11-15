<?php

/**
 * Contract of SocialAccountService
 *
 * The public surface of SocialAccountService that other components call, so they depend
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
 * Contract of SocialAccountService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SocialAccountServiceInterface {

	/**
	 * Fields a client may set when creating or updating an account. Everything
	 * else, and `credentialRef` above all, is written by this service.
	 *
	 * @var array<int, string>
	 */
	public const CLIENT_WRITABLE = [
		'network',
		'kind',
		'handle',
		'displayName',
		'profileUrl',
		'externalAccountId',
		'instanceBaseUrl',
		'clientId',
		'publishMode',
	];

	/**
	 * Record the credential a completed connection produced.
	 *
	 * EXACTLY ONE FIELD is taken from the payload, and even that one is
	 * verified against the broker before it is written. A payload carrying a
	 * token, a refresh token or a client secret changes nothing at all.
	 *
	 * @param string $accountId The account.
	 * @param string $uid The caller, who must own the credential too.
	 * @param array<string, mixed> $payload The client's payload.
	 *
	 * @return array{error?: string, account?: array<string, mixed>} The updated account, or an error.
	 *
	 * @spec openspec/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
	 */
	public function attachCredential(string $accountId, string $uid, array $payload): array;

	/**
	 * The parameters the browser needs to start, or restart, a connection.
	 *
	 * Nothing is written here and no outbound call is made. The browser posts
	 * these to OpenRegister's own connect endpoint with the user's session, so
	 * the authorization code never passes through Pipelinq.
	 *
	 * @param string $accountId The account to connect.
	 * @param string $uid The caller.
	 * @param string $returnUrl A path on this instance to come back to.
	 *
	 * @return array{error?: string, connect?: array<string, mixed>} The parameters, or an error.
	 *
	 * @spec openspec/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
	 */
	public function connectRequest(string $accountId, string $uid, string $returnUrl): array;

	/**
	 * Create an account row, before anything is connected.
	 *
	 * @param array<string, mixed> $payload The client's fields.
	 * @param string $uid The user creating it, stamped as the owner.
	 *
	 * @return array{error?: string, account?: array<string, mixed>} The created account, or an error.
	 *
	 * @spec openspec/specs/social-accounts/spec.md#requirement-a-personal-account-belongs-to-the-person-who-connected-it
	 */
	public function createAccount(array $payload, string $uid): array;

	/**
	 * One account, or null.
	 *
	 * @param string $accountId The account UUID or slug.
	 *
	 * @return array<string, mixed>|null The account, or null.
	 *
	 * @spec openspec/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
	 */
	public function getAccount(string $accountId): ?array;

	/**
	 * Every account, with each network's readiness.
	 *
	 * @return array{data: array<int, array<string, mixed>>, readiness: array<string, array{state: string, reason: string}>}
	 *         The accounts and the per-network readiness.
	 *
	 * @spec openspec/specs/social-accounts/spec.md#requirement-a-network-with-no-filing-says-so-instead-of-failing-quietly
	 */
	public function listAccounts(): array;

	/**
	 * Whether this user may publish as, reconnect or revoke this account.
	 *
	 * A `person` account admits its owner and a Nextcloud administrator. An
	 * `organisation` account admits anyone the app's own privileged-group
	 * policy admits, which is the company's marketers.
	 *
	 * @param string $uid The caller.
	 * @param array<string, mixed> $account The account.
	 *
	 * @return bool True when the caller may act on it.
	 *
	 * @spec openspec/specs/social-accounts/spec.md#requirement-a-personal-account-belongs-to-the-person-who-connected-it
	 */
	public function mayActOn(string $uid, array $account): bool;

	/**
	 * Revoke a connection: clear the reference, disable the row, keep the history.
	 *
	 * The row is disabled rather than deleted on purpose. Every publication
	 * that already went out names this account, and deleting it would leave
	 * those pointing at nothing.
	 *
	 * @param string $accountId The account.
	 * @param string $uid The caller.
	 *
	 * @return array{error?: string, account?: array<string, mixed>} The updated account, or an error.
	 *
	 * @spec openspec/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
	 */
	public function revoke(string $accountId, string $uid): array;

	/**
	 * Re-read one account's status from the broker and store what it says.
	 *
	 * The broker is the authority on whether a grant still works, so this
	 * mirrors rather than decides. An account with no credential is left alone.
	 *
	 * @param string $accountId The account.
	 *
	 * @return array<string, mixed>|null The updated account, or null.
	 *
	 * @spec openspec/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
	 */
	public function syncStatus(string $accountId): ?array;
}//end interface
