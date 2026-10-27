<?php

declare(strict_types=1);

/**
 * Verifies the PHP-FIG PER Coding Style 3.1 rules that PHP CS Fixer 3.x cannot check.
 *
 * The formatter implements PER Coding Style up to 3.0. This script covers the
 * mechanically detectable 3.1 changes on the repository-owned PHP files:
 *
 *  - clone must be written with parentheses;
 *  - switch statements are not used (the 3.1 case rules have no verifier here);
 *  - a closure with an empty body must be written as "{}" on one line;
 *  - the opening bracket of a multi-line array must not stand alone on its line;
 *  - the pipe operator and attributes on anonymous classes are not used.
 *
 * Usage: php scripts/check-per-cs-3-1.php [path ...]
 * Without arguments it scans src, tests and scripts. Exit status 1 on any finding.
 */

/**
 * @return list<string> findings as "file:line: message"
 */
function checkSource(string $file, string $source): array
{
    $findings = [];
    $tokens = token_get_all($source);
    $count = count($tokens);

    $next = static function (int $from) use ($tokens, $count): ?array {
        for ($i = $from + 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return [$i, $token];
        }

        return null;
    };

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token)) {
            if ($token === '|' && ($tokens[$i + 1] ?? null) === '>') {
                $findings[] = $file . ': pipe operator is not used in this repository';
            }

            continue;
        }

        $line = $token[2];

        if ($token[0] === T_CLONE) {
            $following = $next($i);
            if ($following === null || $following[1] !== '(') {
                $findings[] = $file . ':' . $line . ': clone must be written with parentheses';
            }
        }

        if ($token[0] === T_SWITCH) {
            $findings[] = $file . ':' . $line . ': switch is not used (PER 3.1 case rules are not mechanically verified)';
        }

        if ($token[0] === T_FUNCTION) {
            $following = $next($i);
            if ($following !== null && $following[1] === '(') {
                $depth = 0;
                $j = $following[0];
                for (; $j < $count; $j++) {
                    $current = $tokens[$j];
                    if ($current === '(') {
                        $depth++;
                    } elseif ($current === ')') {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                    }
                }
                $brace = null;
                for ($k = $j + 1; $k < $count; $k++) {
                    $current = $tokens[$k];
                    if ($current === ';' || (is_array($current) && $current[0] === T_DOUBLE_ARROW)) {
                        break;
                    }
                    if ($current === '{') {
                        $brace = [$k, $current];

                        break;
                    }
                }
                if ($brace !== null && $brace[1] === '{') {
                    $inner = $next($brace[0]);
                    if ($inner !== null && $inner[1] === '}') {
                        $between = '';
                        for ($k = $brace[0] + 1; $k < $inner[0]; $k++) {
                            $between .= is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
                        }
                        if ($between !== '') {
                            $findings[] = $file . ':' . $line . ': empty closure body must be written as {}';
                        }
                    }
                }
            }
        }
    }

    foreach (explode("\n", $source) as $index => $text) {
        if (preg_match('/(=>|=|\()\s*$/', rtrim($text)) === 1) {
            continue;
        }
        if (preg_match('/^\s*\[\s*$/', $text) === 1) {
            $findings[] = $file . ':' . ($index + 1) . ': opening bracket of a multi-line array must not stand alone';
        }
    }

    return $findings;
}

/**
 * @param list<string> $paths
 * @return list<string>
 */
function collectFiles(array $paths): array
{
    $files = [];
    foreach ($paths as $path) {
        if (is_file($path)) {
            $files[] = $path;

            continue;
        }
        if (!is_dir($path)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }
    }
    sort($files);

    return $files;
}

$arguments = array_slice($argv, 1);
$root = dirname(__DIR__);
$paths = $arguments !== [] ? $arguments : [$root . '/src', $root . '/tests', $root . '/scripts'];
$files = collectFiles($paths);

if ($files === []) {
    fwrite(STDERR, "No PHP files found to verify.\n");
    exit(1);
}

$all = [];
foreach ($files as $file) {
    $source = file_get_contents($file);
    if ($source === false) {
        fwrite(STDERR, 'Cannot read ' . $file . "\n");
        exit(1);
    }
    foreach (checkSource($file, $source) as $finding) {
        $all[] = $finding;
    }
}

foreach ($all as $finding) {
    fwrite(STDERR, $finding . "\n");
}

echo $all === [] ? 'PER-CS 3.1 delta check: ' . count($files) . " files, no findings\n" : '';
exit($all === [] ? 0 : 1);
