<?php

/**
 * Isolated PathMatcher tests.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Stephen Nielson <snielson@discoverandchange.com>
 * @copyright Copyright (c) 2026 Discover and Change, Inc. <snielson@discoverandchange.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\FileManifest;

use OpenEMR\FileManifest\PathMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PathMatcherIsolatedTest extends TestCase
{
    /**
     * @return array<string, array{list<string>, string, bool}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function matchProvider(): array
    {
        return [
            'double star matches deep paths' => [['sites/**'], 'sites/default/sqlconf.php', true],
            'double star matches direct children' => [['sites/**'], 'sites/index.php', true],
            'double star does not match sibling prefix' => [['sites/**'], 'sitesx/index.php', false],
            'double star does not match the bare directory name' => [['sites/**'], 'sites', false],
            'single star stays within one segment' => [['*.log'], 'error.log', true],
            'single star does not cross separators' => [['*.log'], 'logs/error.log', false],
            'question mark matches one character' => [['file?.txt'], 'file1.txt', true],
            'question mark does not match separators' => [['file?.txt'], 'file/.txt', false],
            'exact pattern matches exactly' => [['config.php'], 'config.php', true],
            'exact pattern does not match subpaths' => [['config.php'], 'dir/config.php', false],
            'any of multiple patterns matches' => [['a/**', 'b/**'], 'b/c.txt', true],
            'no patterns matches nothing' => [[], 'anything.txt', false],
            'regex metacharacters in path are literal' => [['a.b/**'], 'axb/c.txt', false],
        ];
    }

    /**
     * @param list<string> $patterns
     */
    #[DataProvider('matchProvider')]
    public function testMatches(array $patterns, string $path, bool $expected): void
    {
        $matcher = new PathMatcher($patterns);
        $this->assertSame($expected, $matcher->matches($path));
    }

    public function testEmptyPatternIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        new PathMatcher(['']);
    }
}
