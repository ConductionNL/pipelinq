<?php

/**
 * Test stub for OCA\OpenRegister\Service\Deferral\DeferredListenerContext.
 *
 * Mirrors the real value object's public surface
 * (openregister/lib/Service/Deferral/DeferredListenerContext.php) so tests can
 * construct a real instance and hand it to a job's runDeferred().
 *
 * NOTE: the real class is `final`. The stub is not, because PHPUnit cannot mock
 * a final class — but nothing in this app subclasses it, and the constructor
 * plus the four accessors are byte-identical to the real ones, so a test that
 * passes here passes against the real class.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs\Service\Deferral
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deferral;

if (class_exists(DeferredListenerContext::class) === false) {
	/**
	 * Stub class for DeferredListenerContext — used only in standalone unit tests.
	 */
	class DeferredListenerContext {

		/**
		 * Wrap a captured acting context.
		 *
		 * @param string|null $userId Acting user id at dispatch time.
		 * @param string|null $orgUuid Active organisation uuid at dispatch time.
		 * @param array<int, array<string, mixed>> $entries Per-object entries.
		 *
		 * @return void
		 */
		public function __construct(
			private readonly ?string $userId = null,
			private readonly ?string $orgUuid = null,
			private readonly array $entries = [],
		) {
		}//end __construct()

		/**
		 * The acting user id captured at dispatch time.
		 *
		 * @return string|null
		 */
		public function getUserId(): ?string {
			return $this->userId;
		}//end getUserId()

		/**
		 * The active organisation uuid captured at dispatch time.
		 *
		 * @return string|null
		 */
		public function getOrganisationUuid(): ?string {
			return $this->orgUuid;
		}//end getOrganisationUuid()

		/**
		 * The per-object entries the job must process.
		 *
		 * @return array<int, array<string, mixed>>
		 */
		public function getEntries(): array {
			return $this->entries;
		}//end getEntries()

		/**
		 * Serialize to the array shape stored in `oc_jobs.argument`.
		 *
		 * @return array<string, mixed>
		 */
		public function toJobArguments(): array {
			return [
				'userId' => $this->userId,
				'organisationUuid' => $this->orgUuid,
				'entries' => array_values($this->entries),
			];
		}//end toJobArguments()

		/**
		 * Rebuild a context from a job's argument payload.
		 *
		 * @param mixed $argument Raw job argument.
		 *
		 * @return self
		 */
		public static function fromJobArguments(mixed $argument): self {
			if (is_array($argument) === false) {
				return new self(userId: null, orgUuid: null, entries: []);
			}

			$entries = [];
			foreach (($argument['entries'] ?? []) as $rawEntry) {
				if (is_array($rawEntry) === true) {
					$entries[] = $rawEntry;
				}
			}

			return new self(
				userId: ($argument['userId'] ?? null),
				orgUuid: ($argument['organisationUuid'] ?? null),
				entries: $entries
			);
		}//end fromJobArguments()
	}
}
