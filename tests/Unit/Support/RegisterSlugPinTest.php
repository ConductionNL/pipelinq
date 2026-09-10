<?php

/**
 * No code under lib/ pins a superseded register slug.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The case that has never once been caught.
 *
 * A consumer pinned to a superseded register slug on a MIGRATED instance does
 * not raise. `openregister_registers` has no row with that slug, so the read
 * matches nothing and returns an empty result that is byte-for-byte what a
 * healthy, empty register returns. There is no exception, no 404, no log line
 * that separates the two. Every other guard in this repository watches
 * behaviour, and this defect has no behaviour to watch: it is a feature that
 * quietly stops happening.
 *
 * So the guard is static, and it is repo-wide rather than diff-scoped. Diff
 * scope is right for debt a PR could reasonably be asked to carry; it is wrong
 * here, because all five of the references it was written for were typed BEFORE
 * the register was renamed and will therefore never appear in a diff. A
 * diff-scoped version of this test passes on a repository full of the defect.
 *
 * ## What it does NOT catch
 *
 * It reads lines, not data flow. A superseded slug arriving from app config,
 * from a manifest, or through more than one assignment is invisible to it, as is
 * a `match` arm built at run time. That is why
 * {@see \OCA\Pipelinq\Tests\Unit\Service\ConnectorRegisterResolutionTest} exists
 * beside it: this guard stops the literal being TYPED, and that one stops the
 * resolved slug being IGNORED.
 *
 * Measured on the mutation that reinstated `private const
 * OPENCONNECTOR_REGISTER_SLUG = 'openconnector';` in `ExportUploadService` and
 * used it at the read. Three tests reddened: this one, naming
 * `lib/Service/Export/ExportUploadService.php:66`, plus the migrated-instance
 * case and the pre-existing upload test. The unmigrated-instance case still
 * passed, because on an unmigrated instance the pinned literal happens to be the
 * right answer.
 *
 * The reverse measurement matters just as much and is recorded here rather than
 * only in the other file. When the mutation was instead made INSIDE
 * `ConnectorSourceRegister::slugOrNull()` — `return 'openconnector';` — this
 * guard stayed GREEN while 14 behavioural tests reddened. A `return` is not
 * register position, and no widening of these patterns would change that without
 * flagging the ~60 correct uses of the word `openconnector` in this repository's
 * prose and class names. Neither guard is sufficient on its own; that is why
 * there are two.
 */
class RegisterSlugPinTest extends TestCase {

	/**
	 * Superseded register slug => the canonical slug replacing it.
	 *
	 * Only the register this app actually reads. openregister owns the full
	 * fleet map in `lib/Support/RegisterSlugAliases.php`, which is not published
	 * to consumers, and copying all ten here would put a second copy of that
	 * truth in a repository that does not own it. What this guard needs is
	 * narrower anyway: the slugs THIS app could plausibly type.
	 *
	 * @var array<string, string>
	 */
	private const SUPERSEDED = ['openconnector' => 'integriq'];

	/**
	 * Files allowed to name a superseded slug, and why.
	 *
	 * Each entry must be a genuine exception — a file that exists in order to
	 * name the old slug — not a deferral. Anything else belongs in a resolver
	 * call.
	 *
	 * `FleetAppId` is the standing example, and it is the distinction this whole
	 * mechanism turns on: it maps APP IDS, whose source of truth is
	 * `IAppManager`, not register slugs, whose source of truth is
	 * `openregister_registers`. Two different repair steps move them and either
	 * can run first, so one cannot be used to predict the other. Pipelinq has
	 * felt that difference already: `AppointmentDepositService` and
	 * `AppointmentPaymentProvider` resolve `OCA\OpenConnector\Service\PaymentService`
	 * by FQCN, which is a THIRD name again, moved by neither step.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED = [
		'lib/Support/FleetAppId.php' => 'app ids, not register slugs; a different question with a different source of truth',
	];

	/**
	 * Source patterns that put a string literal in REGISTER position.
	 *
	 * Deliberately narrow. A slug is only a defect where it identifies a
	 * register; the same word in a log message, a skip reason, an app id or a
	 * PSR-4 namespace is not this defect, and a guard that flagged those would be
	 * turned off. `openconnector` appears about 60 times in this repository's
	 * prose and class names, and every one of those is correct.
	 *
	 * @var list<string>
	 */
	private const REGISTER_POSITION = [
		'/setRegister\(\s*(?:register:\s*)?\'([a-zA-Z0-9_-]+)\'/',
		'/\bregister:\s*\'([a-zA-Z0-9_-]+)\'/',
		'/\'register\'\s*=>\s*\'([a-zA-Z0-9_-]+)\'/',
		'/\bconst\s+[A-Z0-9_]*REGISTER[A-Z0-9_]*\s*=\s*\'([a-zA-Z0-9_-]+)\'/',
		'/\$[a-zA-Z0-9_]*(?:[Rr]egister|[Ss]lug)[a-zA-Z0-9_]*\s*=\s*\'([a-zA-Z0-9_-]+)\'/',
	];

	/**
	 * No file under lib/ names a superseded register slug in register position.
	 *
	 * @return void
	 */
	public function testNoSourceFilePinsASupersededRegisterSlug(): void {
		$findings = [];
		foreach ($this->sourceFiles() as $relative => $absolute) {
			if (isset(self::ALLOWED[$relative]) === true) {
				continue;
			}

			// NOT FILE_SKIP_EMPTY_LINES. Skipping blank lines renumbers every
			// line after the first one, so `$index + 1` stops being the line
			// number and becomes the count of non-blank lines. Measured on
			// openregister's reconciler: a pin on line 590 was reported as line
			// 528, because 62 blank lines preceded it. A guard that names the
			// wrong line is a guard whose next reader concludes it is broken.
			$lines = file($absolute, FILE_IGNORE_NEW_LINES);
			if ($lines === false) {
				continue;
			}

			foreach ($lines as $index => $line) {
				foreach (self::REGISTER_POSITION as $pattern) {
					if (preg_match($pattern, $line, $matches) !== 1) {
						continue;
					}

					$slug = strtolower($matches[1]);
					if (isset(self::SUPERSEDED[$slug]) === false) {
						continue;
					}

					$findings[] = sprintf(
						'%s:%d pins the superseded register slug \'%s\'. Resolve \'%s\' through '
						. 'RegisterSlugResolverInterface::resolve() instead (in this app, through '
						. 'ConnectorSourceRegister), and branch on isResolved(), because reading with a slug '
						. 'this instance does not carry returns zero rows, not an error.',
						$relative,
						($index + 1),
						$slug,
						self::SUPERSEDED[$slug]
					);
				}
			}
		}

		$this->assertSame([], $findings, "Superseded register slugs are pinned:\n" . implode("\n", $findings));
	}//end testNoSourceFilePinsASupersededRegisterSlug()

	/**
	 * The guard actually looks at something.
	 *
	 * A file walker that silently finds no files is the classic hollow green: the
	 * assertion above would pass on an empty list forever. This pins the walker
	 * to a floor well below the real count, so a broken path fails here rather
	 * than passing there.
	 *
	 * @return void
	 */
	public function testTheGuardScansTheSourceTree(): void {
		$files = $this->sourceFiles();

		$this->assertGreaterThan(100, count($files), 'The walker must see lib/, or the guard above cannot fail.');
		foreach ([
			'lib/Service/ConnectorSourceRegister.php',
			'lib/Service/Egress/ConnectorEgress.php',
			'lib/Service/Export/ExportUploadService.php',
			'lib/Service/Export/ExportDestinationService.php',
			'lib/Service/Marketing/Transport/ConnectorSourceTransport.php',
			'lib/Service/Marketing/MailTransportService.php',
		] as $written) {
			$this->assertArrayHasKey(
				$written,
				$files,
				'The walker must reach ' . $written . ', which is one of the files this guard was written for.'
			);
		}
	}//end testTheGuardScansTheSourceTree()

	/**
	 * The patterns match a pinned slug when one is present.
	 *
	 * Watched failing is not enough on its own once the tree is clean: from then
	 * on the guard passes whether or not its regexes still work. This feeds each
	 * register-position form a known-bad line and requires a match, so a regex
	 * that stops matching reddens immediately instead of going quiet.
	 *
	 * @return void
	 */
	public function testEachRegisterPositionPatternStillMatches(): void {
		$samples = [
			'/setRegister\(\s*(?:register:\s*)?\'([a-zA-Z0-9_-]+)\'/' => "\$objectService->setRegister('openconnector');",
			'/\bregister:\s*\'([a-zA-Z0-9_-]+)\'/'                    => "\$svc->find(id: \$id, register: 'openconnector', schema: 'source');",
			'/\'register\'\s*=>\s*\'([a-zA-Z0-9_-]+)\'/'              => "'filters' => ['register' => 'openconnector', 'schema' => 'source'],",
			'/\bconst\s+[A-Z0-9_]*REGISTER[A-Z0-9_]*\s*=\s*\'([a-zA-Z0-9_-]+)\'/' => "\tprivate const OPENCONNECTOR_REGISTER_SLUG = 'openconnector';",
			'/\$[a-zA-Z0-9_]*(?:[Rr]egister|[Ss]lug)[a-zA-Z0-9_]*\s*=\s*\'([a-zA-Z0-9_-]+)\'/' => "\t\t\$registerSlug = 'openconnector';",
		];

		foreach (self::REGISTER_POSITION as $pattern) {
			$this->assertArrayHasKey($pattern, $samples, 'Every register-position pattern needs a known-bad sample.');
			$this->assertSame(
				1,
				preg_match($pattern, $samples[$pattern], $matches),
				'Pattern must match its known-bad sample: ' . $pattern
			);
			$this->assertArrayHasKey(
				strtolower($matches[1]),
				self::SUPERSEDED,
				'The sample must capture a slug this guard calls superseded: ' . $pattern
			);
		}
	}//end testEachRegisterPositionPatternStillMatches()

	/**
	 * Every PHP file under lib/, keyed by repository-relative path.
	 *
	 * @return array<string, string> Relative path => absolute path.
	 */
	private function sourceFiles(): array {
		$root = dirname(__DIR__, 3);
		$lib = $root . '/lib';

		$files = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($lib, RecursiveDirectoryIterator::SKIP_DOTS)
		);
		foreach ($iterator as $file) {
			if (($file instanceof SplFileInfo) === false || $file->isFile() === false) {
				continue;
			}

			if ($file->getExtension() !== 'php') {
				continue;
			}

			$path = $file->getPathname();
			$files[ltrim(str_replace($root, '', $path), '/')] = $path;
		}

		return $files;
	}//end sourceFiles()
}//end class
