<?php

/*
 * This file is part of PHP CS Fixer.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *     Dariusz Rumiński <dariusz.ruminski@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace PhpCsFixer\Test;

use GeckoPackages\PHPUnit\Constraints\SameStringsConstraint;
use PhpCsFixer\Cache\NullCacheManager;
use PhpCsFixer\Differ\SebastianBergmannDiffer;
use PhpCsFixer\Error\Error;
use PhpCsFixer\Error\ErrorsManager;
use PhpCsFixer\FileRemoval;
use PhpCsFixer\Fixer\FixerInterface;
use PhpCsFixer\FixerFactory;
use PhpCsFixer\Linter\Linter;
use PhpCsFixer\Linter\LinterInterface;
use PhpCsFixer\Runner\Runner;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\WhitespacesFixerConfig;
use Prophecy\Argument;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Integration test base class.
 *
 * This test searches for '.test' fixture files in the given directory.
 * Each fixture file will be parsed and tested against the expected result.
 *
 * Fixture files have the following format:
 *
 * --TEST--
 * Example test description.
 * --RULESET--
 * {"@PSR2": true, "strict": true}
 * --CONFIG--*
 * {"indent": "    ", "lineEnding": "\n"}
 * --SETTINGS--*
 * {"key": "value"} # optional extension point for custom IntegrationTestCase class
 * --REQUIREMENTS--*
 * {"php": 50600**, "hhvm": false***}
 * --EXPECT--
 * Expected code after fixing
 * --INPUT--*
 * Code to fix
 *
 *   * Section or any line in it may be omitted.
 *  ** PHP minimum version. Default to current running php version (no effect).
 * *** HHVM compliant flag. Default to true. Set to false to skip test under HHVM.
 *
 * @author SpacePossum
 */
abstract class AbstractIntegrationTestCase extends \PHPUnit_Framework_TestCase
{
    /**
     * @var LinterInterface
     */
    protected $linter;

    /**
     * @var FileRemoval
     */
    private static $fileRemoval;

    public static function setUpBeforeClass()
    {
        $tmpFile = static::getTempFile();
        self::$fileRemoval = new FileRemoval();
        self::$fileRemoval->observe($tmpFile);

        if (!is_file($tmpFile)) {
            $dir = dirname($tmpFile);

            if (!is_dir($dir)) {
                $fs = new Filesystem();
                $fs->mkdir($dir, 0766);
            }
        }
    }

    public static function tearDownAfterClass()
    {
        $tmpFile = static::getTempFile();

        self::$fileRemoval->delete($tmpFile);
    }

    protected function setUp()
    {
        $this->linter = $this->getLinter();
    }

    /**
     * @dataProvider getTests
     *
     * @see doTest()
     *
     * @param IntegrationCase $case
     */
    public function testIntegration(IntegrationCase $case)
    {
        $this->doTest($case);
    }

    /**
     * Creates test data by parsing '.test' files.
     *
     * @return IntegrationCase[][]
     */
    public function getTests()
    {
        $fixturesDir = realpath(static::getFixturesDir());
        if (!is_dir($fixturesDir)) {
            throw new \UnexpectedValueException(sprintf('Given fixture dir "%s" is not a directory.', $fixturesDir));
        }

        $factory = new IntegrationCaseFactory();
        $tests = [];

        foreach (Finder::create()->files()->in($fixturesDir) as $file) {
            if ('test' !== $file->getExtension()) {
                continue;
            }

            $tests[] = [
                $factory->create($file),
            ];
        }

        return $tests;
    }

    /**
     * Returns the full path to directory which contains the tests.
     *
     * @return string
     */
    protected static function getFixturesDir()
    {
        throw new \BadMethodCallException('Method "getFixturesDir" must be overridden by the extending class.');
    }

    /**
     * Returns the full path to the temporary file where the test will write to.
     *
     * @return string
     */
    protected static function getTempFile()
    {
        throw new \BadMethodCallException('Method "getTempFile" must be overridden by the extending class.');
    }

    /**
     * Applies the given fixers on the input and checks the result.
     *
     * It will write the input to a temp file. The file will be fixed by a Fixer instance
     * configured with the given fixers. The result is compared with the expected output.
     * It checks if no errors were reported during the fixing.
     *
     * @param IntegrationCase $case
     */
    protected function doTest(IntegrationCase $case)
    {
        if (defined('HHVM_VERSION') && false === $case->getRequirement('hhvm')) {
            $this->markTestSkipped('HHVM is not supported.');
        }

        if (PHP_VERSION_ID < $case->getRequirement('php')) {
            $this->markTestSkipped(sprintf('PHP %d (or later) is required for "%s", current "%d".', $case->getRequirement('php'), $case->getFileName(), PHP_VERSION_ID));
        }

        $input = $case->getInputCode();
        $expected = $case->getExpectedCode();

        $input = $case->hasInputCode() ? $input : $expected;

        $tmpFile = static::getTempFile();

        if (false === @file_put_contents($tmpFile, $input)) {
            throw new IOException(sprintf('Failed to write to tmp. file "%s".', $tmpFile));
        }

        $errorsManager = new ErrorsManager();
        $fixers = $this->createFixers($case);
        $runner = new Runner(
            new \ArrayIterator([new \SplFileInfo($tmpFile)]),
            $fixers,
            new SebastianBergmannDiffer(),
            null,
            $errorsManager,
            $this->linter,
            false,
            new NullCacheManager()
        );

        Tokens::clearCache();
        $result = $runner->fix();
        $changed = array_pop($result);

        if (!$errorsManager->isEmpty()) {
            $errors = $errorsManager->getExceptionErrors();
            $this->assertEmpty($errors, sprintf('Errors reported during fixing of file "%s": %s', $case->getFileName(), $this->implodeErrors($errors)));

            $errors = $errorsManager->getInvalidErrors();
            $this->assertEmpty($errors, sprintf('Errors reported during linting before fixing file "%s": %s.', $case->getFileName(), $this->implodeErrors($errors)));

            $errors = $errorsManager->getLintErrors();
            $this->assertEmpty($errors, sprintf('Errors reported during linting after fixing file "%s": %s.', $case->getFileName(), $this->implodeErrors($errors)));
        }

        if (!$case->hasInputCode()) {
            $this->assertEmpty(
                $changed,
                sprintf(
                    "Expected no changes made to test \"%s\" in \"%s\".\nFixers applied:\n%s.\nDiff.:\n%s.",
                    $case->getTitle(),
                    $case->getFileName(),
                    $changed === null ? '[None]' : implode(',', $changed['appliedFixers']),
                    $changed === null ? '[None]' : $changed['diff']
                )
            );

            return;
        }

        $this->assertNotEmpty($changed, sprintf('Expected changes made to test "%s" in "%s".', $case->getTitle(), $case->getFileName()));
        $fixedInputCode = file_get_contents($tmpFile);
        $this->assertThat(
            $fixedInputCode,
            new SameStringsConstraint($expected),
            sprintf(
                "Expected changes do not match result for \"%s\" in \"%s\".\nFixers applied:\n%s.",
                $case->getTitle(),
                $case->getFileName(),
                $changed === null ? '[None]' : implode(',', $changed['appliedFixers'])
            )
        );

        if (1 < count($fixers)) {
            $tmpFile = static::getTempFile();
            if (false === @file_put_contents($tmpFile, $input)) {
                throw new IOException(sprintf('Failed to write to tmp. file "%s".', $tmpFile));
            }

            $runner = new Runner(
                new \ArrayIterator([new \SplFileInfo($tmpFile)]),
                array_reverse($fixers),
                new SebastianBergmannDiffer(),
                null,
                $errorsManager,
                $this->linter,
                false,
                new NullCacheManager()
            );

            Tokens::clearCache();
            $runner->fix();
            $fixedInputCodeWithReversedFixers = file_get_contents($tmpFile);

            // If output is different depends on rules order - we need to verify that the rules are ordered by priority.
            // If not, any order is valid.
            if ($fixedInputCode !== $fixedInputCodeWithReversedFixers) {
                $this->assertGreaterThan(
                    1,
                    count(array_unique(array_map(
                        function (FixerInterface $fixer) {
                            return $fixer->getPriority();
                        },
                        $fixers
                    ))),
                    sprintf(
                        'Rules priorities are not differential enough. If rules would be used in reverse order then final output would be different than the expected one. For that, different priorities must be set up for used rules to ensure stable order of them. In "%s".',
                        $case->getFileName()
                    )
                );
            }
        }

        // run the test again with the `expected` part, this should always stay the same
        $this->testIntegration(
            new IntegrationCase(
                $case->getFileName(),
                $case->getTitle().' "--EXPECT-- part run"',
                $case->getSettings(),
                $case->getRequirements(),
                $case->getConfig(),
                $case->getRuleset(),
                $case->getExpectedCode(),
                null
            )
        );
    }

    /**
     * @param IntegrationCase $case
     *
     * @return FixerInterface[]
     */
    private function createFixers(IntegrationCase $case)
    {
        $config = $case->getConfig();

        return FixerFactory::create()
            ->registerBuiltInFixers()
            ->useRuleSet($case->getRuleset())
            ->setWhitespacesConfig(
                new WhitespacesFixerConfig($config['indent'], $config['lineEnding'])
            )
            ->getFixers();
    }

    /**
     * @param Error[] $errors
     *
     * @return string
     */
    private function implodeErrors(array $errors)
    {
        $errorStr = '';
        foreach ($errors as $error) {
            $errorStr .= sprintf("%d: %s\n", $error->getType(), $error->getFilePath());
        }

        return $errorStr;
    }

    /**
     * @return LinterInterface
     */
    private function getLinter()
    {
        static $linter = null;

        if (null === $linter) {
            if (getenv('SKIP_LINT_TEST_CASES')) {
                $linterProphecy = $this->prophesize(\PhpCsFixer\Linter\LinterInterface::class);
                $linterProphecy
                    ->lintSource(Argument::type('string'))
                    ->willReturn($this->prophesize(\PhpCsFixer\Linter\LintingResultInterface::class)->reveal());
                $linterProphecy
                    ->lintFile(Argument::type('string'))
                    ->willReturn($this->prophesize(\PhpCsFixer\Linter\LintingResultInterface::class)->reveal());
                $linterProphecy
                    ->isAsync()
                    ->willReturn(false);

                $linter = $linterProphecy->reveal();
            } else {
                $linter = new Linter();
            }
        }

        return $linter;
    }
}