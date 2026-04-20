<?php
/**
 * Integration tests for CustomSniffs\Sniffs\Commenting\SpecTagSniff.
 *
 * Invokes the phpcs binary against fixture files and asserts that the
 * expected warning messages are emitted. Using the real binary avoids
 * fragile dependency on PHP_CodeSniffer's internal autoloader.
 *
 * @package CustomSniffs\Tests
 */

namespace CustomSniffs\Tests\Commenting;

use PHPUnit\Framework\TestCase;

/**
 * SpecTagSniffTest — integration tests for the @spec docblock sniff.
 */
class SpecTagSniffTest extends TestCase
{


    /**
     * Project root (three levels up from this file).
     *
     * @return string Absolute path.
     */
    private function projectRoot(): string
    {
        return dirname(__DIR__, 3);

    }//end projectRoot()


    /**
     * Run phpcs against a fixture copied into a non-test path and decode JSON.
     *
     * The sniff intentionally skips paths containing "/tests/", so we copy
     * each fixture into a scratch file outside the tests directory for the
     * assertion pass.
     *
     * @param string $fixture Filename relative to fixtures/.
     *
     * @return array<int,string> List of warning messages reported.
     */
    private function runSniff(string $fixture): array
    {
        $projectRoot = $this->projectRoot();
        $sniffPath   = $projectRoot.'/phpcs-custom-sniffs/CustomSniffs/Sniffs/Commenting/SpecTagSniff.php';
        $fixtureSrc  = __DIR__.'/fixtures/'.$fixture;

        self::assertFileExists($sniffPath, 'Sniff file must exist');
        self::assertFileExists($fixtureSrc, 'Fixture file must exist');

        $scratchDir = sys_get_temp_dir().'/spec-sniff-scratch-'.uniqid();
        mkdir($scratchDir, 0700, true);
        $scratchFile = $scratchDir.'/'.$fixture;
        copy($fixtureSrc, $scratchFile);

        $rulesetFile = $scratchDir.'/ruleset.xml';
        file_put_contents(
            $rulesetFile,
            '<?xml version="1.0"?><ruleset name="Test"><rule ref="'.$sniffPath.'"/></ruleset>'
        );

        $phpcs = $projectRoot.'/vendor/bin/phpcs';
        $cmd   = escapeshellarg($phpcs)
            .' --standard='.escapeshellarg($rulesetFile)
            .' --report=json '
            .escapeshellarg($scratchFile);

        $output = shell_exec($cmd.' 2>&1');

        // Best-effort cleanup.
        @unlink($scratchFile);
        @unlink($rulesetFile);
        @rmdir($scratchDir);

        $decoded = json_decode((string) $output, true);
        self::assertIsArray($decoded, 'phpcs must return valid JSON, got: '.$output);

        $messages = [];
        foreach (($decoded['files'] ?? []) as $fileReport) {
            foreach (($fileReport['messages'] ?? []) as $entry) {
                if (($entry['type'] ?? '') === 'WARNING') {
                    $messages[] = $entry['message'];
                }
            }
        }

        return $messages;

    }//end runSniff()


    /**
     * A class without @spec must be flagged along with its public method; private must not.
     *
     * @return void
     */
    public function testClassWithoutSpecProducesWarnings(): void
    {
        $messages = $this->runSniff('class_without_spec.php');

        $hasClassWarning  = false;
        $hasMethodWarning = false;
        $hasPrivate       = false;
        foreach ($messages as $message) {
            if (strpos($message, 'Class ClassWithoutSpec is missing @spec') !== false) {
                $hasClassWarning = true;
            }

            if (strpos($message, 'ClassWithoutSpec::doSomething()') !== false) {
                $hasMethodWarning = true;
            }

            if (strpos($message, 'internalHelper') !== false) {
                $hasPrivate = true;
            }
        }

        self::assertTrue($hasClassWarning, 'Class without @spec should be flagged');
        self::assertTrue($hasMethodWarning, 'Public method without @spec should be flagged');
        self::assertFalse($hasPrivate, 'Private method must NOT be flagged');

    }//end testClassWithoutSpecProducesWarnings()


    /**
     * A class with @spec should only flag the un-annotated default-public method.
     *
     * @return void
     */
    public function testClassWithSpecOnlyFlagsUnannotatedDefaultPublic(): void
    {
        $messages = $this->runSniff('class_with_spec.php');

        $hasClassWarning   = false;
        $hasDoSomething    = false;
        $hasConstructor    = false;
        $hasProtected      = false;
        $hasDefaultPublic  = false;
        foreach ($messages as $message) {
            if (strpos($message, 'Class ClassWithSpec is missing @spec') !== false) {
                $hasClassWarning = true;
            }

            if (strpos($message, 'ClassWithSpec::doSomething()') !== false) {
                $hasDoSomething = true;
            }

            if (strpos($message, '__construct') !== false) {
                $hasConstructor = true;
            }

            if (strpos($message, 'protectedHelper') !== false) {
                $hasProtected = true;
            }

            if (strpos($message, 'defaultPublic') !== false) {
                $hasDefaultPublic = true;
            }
        }

        self::assertFalse($hasClassWarning, 'Class with @spec should not be flagged');
        self::assertFalse($hasDoSomething, 'Public method with @spec should not be flagged');
        self::assertFalse($hasConstructor, 'Magic method must NOT be flagged');
        self::assertFalse($hasProtected, 'Protected method must NOT be flagged');
        self::assertTrue($hasDefaultPublic, 'Default-public method without @spec should be flagged');

    }//end testClassWithSpecOnlyFlagsUnannotatedDefaultPublic()


}//end class
