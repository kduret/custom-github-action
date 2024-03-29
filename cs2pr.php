#!/usr/bin/env php
<?php

/*
 * Turns checkstyle based XML-Reports into Github Pull Request Annotations via the Checks API. This script is meant for use within your GithubAction.
 *
 * (c) Markus Staab <markus.staab@redaxo.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * https://github.com/staabm/annotate-pull-request-from-checkstyle
 */

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
gc_disable();

$version = '1.4.1-dev';

// options
$colorize = false;
$gracefulWarnings = false;
$prefix = '';

// parameters
$params = [];
foreach ($argv as $arg) {
    if (substr($arg, 0, 2) === '--') {
        $option = substr($arg, 2);
        if (preg_match('/^prefix="?(.+)"?/', $option, $matches)) {
           $prefix = $matches[1] . ' ';
        } else {
            switch ($option) {
                case 'graceful-warnings':
                    $gracefulWarnings = true;
                    break;
                case 'colorize':
                    $colorize = true;
                    break;
                default:
                    echo "Unknown option " . $option . "\n";
                    exit(9);
            }
        }
    } else {
        $params[] = $arg;
    }
}

if (count($params) === 1) {
    $xml = stream_get_contents(STDIN);
} elseif (count($params) === 2 && file_exists($params[1])) {
    $xml = file_get_contents($params[1]);
} else {
    echo "cs2pr $version\n";
    echo "Annotate a Github Pull Request based on a Checkstyle XML-report.\n";
    echo "Usage: ". $params[0] ." [OPTION]... <filename>\n";
    echo "\n";
    echo "Supported options:\n";
    echo "  --graceful-warnings   Don't exit with error codes if there are only warnings.\n";
    echo "  --colorize            Colorize the output (still compatible with Github Annotations)\n";
    exit(9);
}

// enable user error handling
libxml_use_internal_errors(true);

$root = @simplexml_load_string($xml);

if ($root === false) {
    $errors = libxml_get_errors();
    if ($errors) {
        fwrite(STDERR, 'Error: '. rtrim($errors[0]->message).' on line '.$errors[0]->line.', column '.$errors[0]->column ."\n\n");
    } elseif (stripos($xml, '<?xml') !== 0) {
        fwrite(STDERR, 'Error: Expecting xml stream starting with a xml opening tag.' ."\n\n");
    } else {
        fwrite(STDERR, 'Error: Unknown error. Expecting checkstyle formatted xml input.' ."\n\n");
    }
    fwrite(STDERR, $xml);

    exit(2);
}

$exit = 0;

foreach ($root as $file) {
    $filename = (string)$file['name'];

    foreach ($file as $error) {
        $type = (string) $error['severity'];
        $line = (string) $error['line'];
        $message = (string) $error['message'];

        $annotateType = annotateType($type);
        annotateCheck($annotateType, relativePath($filename), $line, $prefix . $message, $colorize);

        if (!$gracefulWarnings || $annotateType === 'error') {
            $exit = 1;
        }
    }
}

exit($exit);

/**
 * @param 'error'|'warning' $type
 * @param string $filename
 * @param int $line
 * @param string $message
 * @param boolean $colorize
 */
function annotateCheck($type, $filename, $line, $message, $colorize)
{
    // newlines need to be encoded
    // see https://github.com/actions/starter-workflows/issues/68#issuecomment-581479448
    $message = str_replace("\n", '%0A', $message);

    if ($colorize) {
        echo "\033[".($type==='error' ? '91' : '93')."m\n";
    }
    echo "::{$type} file={$filename},line={$line}::{$message}\n";
    if ($colorize) {
        echo "\033[0m";
    }
}

function relativePath($path)
{
    return str_replace(getcwd().'/', '', $path);
}

function annotateType($type)
{
    if (in_array($type, ['error', 'failure'])) {
        return 'error';
    }
    if (in_array($type, ['info', 'notice'])) {
        return 'notice';
    }
    return 'warning';
}
