<?php
/**
 * Lightweight Offline-First .env Parser & Environment Loader
 * Pure PHP Native — No external composer dependencies required.
 */

if (!function_exists('load_env')) {
    /**
     * Load and parse key-value pairs from an environment file into PHP environment
     *
     * @param string|null $filePath Absolute or relative path to .env file
     * @return bool True if file was loaded, false otherwise
     */
    function load_env($filePath = null) {
        static $loaded = false;
        if ($loaded && $filePath === null) {
            return true;
        }

        if ($filePath === null) {
            $filePath = dirname(__DIR__) . '/.env';
        }

        if (!file_exists($filePath) || !is_readable($filePath)) {
            return false;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return false;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines or comments
            if ($line === '' || strpos($line, '#') === 0 || strpos($line, ';') === 0) {
                continue;
            }

            // Must contain an '=' separator
            if (strpos($line, '=') !== false) {
                list($name, $value) = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);

                // Strip surrounding single or double quotes
                if (preg_match('/^([\'"])(.*)\1$/', $value, $matches)) {
                    $value = $matches[2];
                }

                // Register into PHP environment if not already overridden by system OS
                putenv("{$name}={$value}");
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }

        $loaded = true;
        return true;
    }
}

if (!function_exists('env')) {
    /**
     * Retrieve environment variable with type-casting and fallback default
     *
     * @param string $key Variable name
     * @param mixed $default Fallback value if variable is not defined
     * @return mixed
     */
    function env($key, $default = null) {
        $value = getenv($key);
        if ($value === false) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }

        if ($value === null) {
            return $default;
        }

        // Parse boolean and special values
        $lower = strtolower(trim((string)$value));
        switch ($lower) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'empty':
            case '(empty)':
                return '';
            case 'null':
            case '(null)':
                return null;
        }

        return $value;
    }
}

// Automatically load .env if available upon inclusion
load_env();
