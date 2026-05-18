<?php

/**
 * Loads environment variables from a .env file into $_ENV.
 *
 * Parses each line of the file as a key=value pair. Lines that are empty
 * or begin with '#' (comments) are skipped. The parsed values are stored
 * in $_ENV so they can be accessed throughout the application.
 *
 * @param string $path Absolute or relative path to the .env file.
 * @return void
 */
function loadEnv(string $path): void {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}
