<?php

namespace OpenEMR\Tools\Coverage;

use ReflectionMethod;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Report\PHP;
use SebastianBergmann\FileIterator\Facade as FileIteratorFacade;
use PHPUnit\Framework\Attributes\Test;
use function microtime;
use function register_shutdown_function;

class CoverageHelper
{
    public static function resolveCoverageId(string $baseClass, string|int $dataName): string
    {
        return $baseClass . self::resolveTestMethod($baseClass) . self::resolveTestDataSet($dataName);
    }

    private static function resolveTestMethod(string $baseClass): string
    {
        $stack = debug_backtrace();

        // Get the first class in the stack which is baseClass, then get its first test method
        foreach ($stack as $t) {
            if (!isset($t['object'], $t['class']) || $t['class'] !== $baseClass) {
                continue;
            }

            // The test method is the first one in the backtrace which has the Test attribute
            $ref = new ReflectionMethod($t['object'], $t['function']);
            $attributes = $ref->getAttributes();
            foreach ($attributes as $attr) {
                if ($attr->getName() === Test::class) {
                    return '::' . $t['function'];
                }
            }
        }

        return '';
    }

    public static function createTargetedCodeCoverage(string $shutdownExportBasePath) {
        $filter = new Filter();
        $filter->includeFiles([
            __DIR__ . '/../../../apis/dispatch.php',
            __DIR__ . '/../../../_rest_config.php',
            __DIR__ . '/../../../_rest_routes.inc.php',
            __DIR__ . '/../../../oauth2/authorize.php',
            __DIR__ . '/../../../oauth2/provider/.well-known/discovery.php',
            __DIR__ . '/../../../oauth2/provider/jwk.php'
        ]);
        $filter->includeDirectory(
            __DIR__ . '/../../../src/'
        );
        $coverage = new CodeCoverage((new Selector())->forLineCoverage($filter), $filter);
        // When the process is shut down, dump a partial coverage report in PHP format
        register_shutdown_function(function () use ($shutdownExportBasePath, $coverage): void {
            $coverage->stop();
            // now clean it all up.
            $id = (string)microtime(as_float: true);
            $covPath = $shutdownExportBasePath . '/' . $id . '.cov';
            (new PHP())->process($coverage, $covPath);
        });
        return $coverage;
    }

    /**
     * @param string[] $dirs - List of directories to collect coverage from
     * @param $shutdownExportBasePath - Directory where the coverage report will be dumped
     */
    public static function createCoverageForDirectories(
        array  $dirs,
        string $shutdownExportBasePath,
    ): CodeCoverage
    {
        // Determine from what directories we want coverage to be collected
        $filter = new Filter();
        foreach ($dirs as $dir) {
            foreach ((new FileIteratorFacade())->getFilesAsArray($dir) as $file) {
                $filter->includeFile($file);
            }
        }

        $coverage = new CodeCoverage((new Selector())->forLineCoverage($filter), $filter);

        // When the process is shut down, dump a partial coverage report in PHP format
        register_shutdown_function(function () use ($shutdownExportBasePath, $coverage): void {
            $id = (string)microtime(as_float: true);
            $covPath = $shutdownExportBasePath . '/' . $id . '.cov';
            (new PHP())->process($coverage, $covPath);
        });

        return $coverage;
    }

    private static function resolveTestDataSet(string|int $dataName): string
    {
        return !empty($dataName) ? '#' . $dataName : '';
    }

    public static function setupCodeCoverageIfEnabled()
    {
        if (getenv("ENABLE_REMOTE_COVERAGE")) {
// grab the http header for X-Coverage-Id
            $coverageRootPath = defined('COVERAGE_ROOT_PATH') ? COVERAGE_ROOT_PATH : __DIR__ . '/../../../coverage';
            $coverageId = null;
            $coverageId = $_SERVER['HTTP_X_COVERAGE_ID'] ?? '';

            if ($coverageId != null) {
                error_log("Coverage id is " . $coverageId);
                // TODO: expand to other code systems... excludeDirectory was deprecated so we have to provide a list of all the places we want to include
                $coverage = CoverageHelper::createTargetedCodeCoverage($coverageRootPath);
                $coverage->start($coverageId);
            }
        }
    }
}
