<?php

/**
 * Every `<slug>_schema` app-config key a service reads is one the install writes.
 *
 * SettingsLoadService writes one key per slug in SCHEMA_SLUGS (renamed through
 * SCHEMA_CONFIG_KEYS where the persisted key kept an older name). A service
 * that reads a key for a slug not listed there finds '' forever, and most
 * readers turn that into a silent skip: the party panel answered "no
 * indicators", the WIP endpoint answered 400, the forecast job ran against
 * nothing. Measured on 2026-09-27: 51 keys read and never written. This test
 * closes the list. A key may only be read without being written when it is
 * named below, with the reason.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/openregister-integration/spec.md#requirement-register-configuration-file
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\SettingsLoadService;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

class SchemaConfigKeysAreWrittenTest extends TestCase {

	private const LIB_DIR = __DIR__ . '/../../../lib';

	/**
	 * Keys that are read on purpose without the install writing them.
	 *
	 * Each entry names why. Remove the entry when the reader goes, and this
	 * test says so when the reader is already gone.
	 *
	 * @var array<string, string>
	 */
	private const READ_WITHOUT_WRITE = [
		// Legacy alias tried before `client_schema`; the fallback is the real key.
		'customer_schema' => 'SegmentService tries it first and falls back to client_schema',
		// The email and calendar link sources of the activity timeline. No
		// fragment declares either schema since email-calendar-sync moved to
		// the leaf; the timeline skips an empty source.
		'emailLink_schema' => 'ActivityTimelineService source with no declared schema',
		'calendarLink_schema' => 'ActivityTimelineService source with no declared schema',
		// `callback` is an SLA scope (`appliesTo`) and a target kind, not a
		// schema; the three readers skip the surface while the key is empty.
		'callback_schema' => 'SLA callback surface, no schema declared',
		// The webhook queue schema was retired with the MDM sync queue; the
		// processor job is still registered and drains nothing.
		'webhookQueue_schema' => 'WebhookProcessorJob reads a retired schema',
		// A zaak lives in another app; the key is set by an administrator.
		'zaak_schema' => 'cross-app lookup configured by hand',
		// The AVG request family moved to OpenRegister's data-subject-request
		// register; the migration reads the legacy keys of the instance it runs on.
		'avgVerzoek_schema' => 'legacy key read by MigrateAvgVerzoekenToOrDsar',
		'bewijsItem_schema' => 'legacy key read by MigrateAvgVerzoekenToOrDsar',
		'redactieActie_schema' => 'legacy key read by MigrateAvgVerzoekenToOrDsar',
		'weigering_schema' => 'legacy key read by MigrateAvgVerzoekenToOrDsar',
	];

	/**
	 * @return void
	 */
	public function testEveryKeyAServiceReadsIsWrittenByTheInstallOrListedWithAReason(): void {
		$written = $this->keysTheInstallWrites();
		$read = $this->keysTheCodeReads();

		// Positive controls: the scan found the readers, and the install writes the obvious key.
		$this->assertArrayHasKey(key: 'lead_schema', array: $read);
		$this->assertContains(needle: 'lead_schema', haystack: $written);
		$this->assertGreaterThan(expected: 60, actual: count($read));

		$unwritten = array_diff_key($read, array_flip($written));
		$unexplained = array_diff_key($unwritten, self::READ_WITHOUT_WRITE);

		$lines = [];
		foreach ($unexplained as $key => $files) {
			$lines[] = $key . ' <- ' . implode(', ', $files);
		}

		$this->assertSame(
			expected: [],
			actual: $lines,
			message: "Read by a service, never written by the install (add the slug to SCHEMA_SLUGS, or name the key in READ_WITHOUT_WRITE with a reason):\n"
				. implode("\n", $lines)
		);
	}//end testEveryKeyAServiceReadsIsWrittenByTheInstallOrListedWithAReason()

	/**
	 * @return void
	 */
	public function testTheAllowlistOnlyNamesKeysSomethingStillReads(): void {
		$read = $this->keysTheCodeReads();
		$written = $this->keysTheInstallWrites();

		$stale = [];
		foreach (array_keys(self::READ_WITHOUT_WRITE) as $key) {
			if (isset($read[$key]) === false) {
				$stale[] = $key . ' (no reader left)';
			}

			if (in_array($key, $written, true) === true) {
				$stale[] = $key . ' (the install writes it now)';
			}
		}

		$this->assertSame(expected: [], actual: $stale, message: 'Remove from READ_WITHOUT_WRITE: ' . implode(', ', $stale));
	}//end testTheAllowlistOnlyNamesKeysSomethingStillReads()

	/**
	 * The keys SettingsLoadService writes: one per slug, renamed through SCHEMA_CONFIG_KEYS.
	 *
	 * @return string[]
	 */
	private function keysTheInstallWrites(): array {
		$reflection = new ReflectionClass(SettingsLoadService::class);
		$slugs = $reflection->getConstant('SCHEMA_SLUGS');
		$this->assertIsArray(actual: $slugs);

		$written = [];
		foreach ($slugs as $slug) {
			$written[] = (SettingsLoadService::SCHEMA_CONFIG_KEYS[$slug] ?? $slug . '_schema');
		}

		return $written;
	}//end keysTheInstallWrites()

	/**
	 * Every `'<slug>_schema'` literal under lib/, outside the two files that write them.
	 *
	 * @return array<string, string[]> Key to the files (relative to lib/) that read it.
	 */
	private function keysTheCodeReads(): array {
		$skip = ['Service/SettingsLoadService.php', 'Service/SettingsService.php'];
		$read = [];
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(realpath(self::LIB_DIR)));
		foreach ($iterator as $file) {
			if (($file instanceof SplFileInfo) === false || $file->isFile() === false || $file->getExtension() !== 'php') {
				continue;
			}

			$relative = substr($file->getPathname(), strlen(realpath(self::LIB_DIR)) + 1);
			if (in_array($relative, $skip, true) === true) {
				continue;
			}

			preg_match_all("/['\"]([A-Za-z]+_schema)['\"]/", (string)file_get_contents($file->getPathname()), $matches);
			foreach ($matches[1] as $key) {
				$read[$key][] = $relative;
			}
		}

		foreach ($read as $key => $files) {
			$read[$key] = array_values(array_unique($files));
		}

		ksort($read);
		return $read;
	}//end keysTheCodeReads()
}//end class
