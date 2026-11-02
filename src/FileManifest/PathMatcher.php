<?php

/**
 * Matches relative file paths against glob patterns ("sites/**", "*.log").
 *
 * Pattern syntax: "**" matches across directory separators, "*" matches within
 * one path segment, "?" matches a single non-separator character. Patterns are
 * anchored to the full relative path.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Stephen Nielson <snielson@discoverandchange.com>
 * @copyright Copyright (c) 2026 Discover and Change, Inc. <snielson@discoverandchange.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\FileManifest;

final class PathMatcher
{
    /** @var list<non-empty-string> */
    private array $regexes = [];

    /**
     * @param list<string> $patterns
     */
    public function __construct(array $patterns)
    {
        foreach ($patterns as $pattern) {
            if ($pattern === '') {
                throw new \DomainException('Path patterns must not be empty');
            }
            $this->regexes[] = $this->toRegex($pattern);
        }
    }

    public function matches(string $path): bool
    {
        foreach ($this->regexes as $regex) {
            if (preg_match($regex, $path) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return non-empty-string
     */
    private function toRegex(string $pattern): string
    {
        $escaped = preg_quote($pattern, '#');
        $escaped = str_replace('\*\*', "\u{1}", $escaped);
        $escaped = str_replace('\*', '[^/]*', $escaped);
        $escaped = str_replace('\?', '[^/]', $escaped);
        $escaped = str_replace("\u{1}", '.*', $escaped);
        return '#^' . $escaped . '$#';
    }
}
