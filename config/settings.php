<?php

if (!defined('SETTINGS_INCLUDED')) {
    define('SETTINGS_INCLUDED', true);
}

// Optionally allow per-user overrides without editing this file.
// Create a config/settings.local.php file returning ['OPLRUN_OVERRIDE' => '/path/to/oplrun'].
$settingsOverridePath = __DIR__ . '/settings.local.php';

/**
 * Resolve a manual override for the oplrun executable provided via settings.local.php.
 */
function getManualOplrunOverride(?string $overridePath): ?string
{
    static $cachedOverride = false;

    if ($cachedOverride !== false) {
        return $cachedOverride;
    }

    if ($overridePath === null || !is_file($overridePath)) {
        return $cachedOverride = null;
    }

    $data = include $overridePath;
    if (is_array($data) && array_key_exists('OPLRUN_OVERRIDE', $data)) {
        $rawOverride = (string) $data['OPLRUN_OVERRIDE'];
        $normalized = normalizeExecutablePath($rawOverride);
        if ($normalized !== null) {
            return $cachedOverride = $normalized;
        }

        throw new Exception('Invalid OPLRUN_OVERRIDE path provided in settings.local.php: ' . $rawOverride);
    }

    return $cachedOverride = null;
}

/**
 * Determine the cache file path for a detected oplrun executable.
 * The cache is stored in the system temporary directory and namespaced
 * per OS family and user to avoid conflicts between different machines.
 */
function getOplrunCacheFile(): string
{
    $user = get_current_user();
    if ($user === false || $user === '') {
        $user = 'unknown';
    }

    $hash = md5(PHP_OS_FAMILY . '|' . $user);

    return rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR . 'phpauto_oplrun_' . $hash . '.cache';
}

/**
 * Validate that a provided path exists and is executable.
 *
 * @param string $path
 * @return string|null Normalized path if valid, otherwise null
 */
function normalizeExecutablePath(string $path): ?string
{
    $path = trim($path);
    if ($path === '') {
        return null;
    }

    // Allow shell quoting characters to be stripped when users copy/paste.
    $path = trim($path, "\"'");

    if (!file_exists($path)) {
        return null;
    }

    if (!is_file($path)) {
        return null;
    }

    if (PHP_OS_FAMILY === 'Windows') {
        // On Windows, executable extensions are sufficient; avoid false negatives from is_executable.
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension !== 'exe' && $extension !== 'bat' && $extension !== 'cmd') {
            return null;
        }
    } elseif (!is_executable($path)) {
        return null;
    }

    $realPath = realpath($path);

    return $realPath !== false ? $realPath : $path;
}

/**
 * Detect the path to the oplrun executable across different environments.
 * Supports Windows, Linux, and macOS platforms.
 *
 * @return string Path to the oplrun executable
 * @throws Exception if oplrun cannot be found
 */
function detectOplrunPath(): string {
    static $memoizedPath = null;

    if ($memoizedPath !== null) {
        return $memoizedPath;
    }

    $os = PHP_OS_FAMILY; // Detect the operating system (Windows, Linux, or Darwin for macOS)
    global $settingsOverridePath;
    $cacheFile = getOplrunCacheFile();

    try {
        // Step 0: Manual override via config/settings.local.php
        $manualOverride = getManualOplrunOverride($settingsOverridePath);
        if ($manualOverride !== null) {
            return $memoizedPath = $manualOverride;
        }

        // Step 1: Check if the environment variable OPLRUN_PATH is set and valid
        $envPath = getenv('OPLRUN_PATH');
        if ($envPath !== false) {
            $normalizedEnv = normalizeExecutablePath($envPath);
            if ($normalizedEnv !== null) {
                writeOplrunCache($cacheFile, $normalizedEnv);
                return $memoizedPath = $normalizedEnv;
            }
        }

        // Step 2: Reuse cached detection results when available
        if (is_readable($cacheFile)) {
            $cachedContents = file_get_contents($cacheFile);
            if ($cachedContents !== false) {
                $cached = normalizeExecutablePath($cachedContents);
                if ($cached !== null) {
                    return $memoizedPath = $cached;
                }
            }
        }

        // Step 3: Use system commands to locate oplrun based on the OS
        if ($os === 'Windows') {
            $result = shell_exec("where oplrun.exe");
        } elseif ($os === 'Linux' || $os === 'Darwin') {
            $result = shell_exec("which oplrun");
        } else {
            throw new Exception("Unsupported operating system: $os");
        }

        // Process the output of the system command
        if ($result) {
            $paths = explode("\n", trim($result)); // Split by line in case of multiple results
            foreach ($paths as $path) {
                $normalized = normalizeExecutablePath($path);
                if ($normalized !== null) {
                    writeOplrunCache($cacheFile, $normalized);
                    return $memoizedPath = $normalized; // Return the first valid path
                }
            }
        }

        // Step 4: Fallback to common installation directories for oplrun
        $commonPaths = [];
        if ($os === 'Windows') {
            $commonPaths = [
                "C:\\Program Files\\IBM\\ILOG\\CPLEX_Studio221\\opl\\bin\\x64_win64\\oplrun.exe",
                "C:\\Program Files\\IBM\\ILOG\\CPLEX_Studio222\\opl\\bin\\x64_win64\\oplrun.exe",
                "C:\\Program Files\\IBM\\ILOG\\CPLEX_Studio201\\opl\\bin\\x64_win64\\oplrun.exe",
            ];
        } elseif ($os === 'Linux') {
            $commonPaths = [
                "/opt/ibm/ILOG/CPLEX/bin/oplrun",
                "/usr/local/bin/oplrun",
                "/usr/bin/oplrun",
            ];
        } elseif ($os === 'Darwin') { // macOS
            $commonPaths = [
                "/Applications/IBM/ILOG/CPLEX_Studio221/opl/bin/x86-64_osx/oplrun",
                "/Applications/IBM/ILOG/CPLEX_Studio221/opl/bin/arm64_osx/oplrun",
                "/Applications/IBM/ILOG/CPLEX/bin/oplrun",
                "/usr/local/bin/oplrun",
            ];
        }

        foreach ($commonPaths as $path) {
            $normalized = normalizeExecutablePath($path);
            if ($normalized !== null) {
                writeOplrunCache($cacheFile, $normalized);
                return $memoizedPath = $normalized; // Return the first valid fallback path
            }
        }

        // If no path is found, throw an exception
        throw new Exception("oplrun executable not found in PATH, environment variable, or common directories.");

    } catch (Exception $e) {
        // Log the error to a file in the logs directory
        $logFile = __DIR__ . '/../logs/error_' . date('Ymd_His') . '.log';
        file_put_contents(
            $logFile,
            "[" . date('Y-m-d H:i:s') . "] Error detecting oplrun: " . $e->getMessage() . PHP_EOL,
            FILE_APPEND
        );

        // Rethrow the exception for the calling script to handle
        throw $e;
    }
}

/**
 * Helper to persist the detected oplrun path while tolerating unwritable temp dirs.
 */
function writeOplrunCache(string $cacheFile, string $path): void
{
    $directory = dirname($cacheFile);
    if (!is_dir($directory)) {
        // Best effort: ignore failure to create directories.
        @mkdir($directory, 0777, true);
    }

    if (is_writable($directory)) {
        @file_put_contents($cacheFile, $path);
    }
}

// Configuration array with dynamically detected OPLRUN path
return [
    'WORK_DIR' => __DIR__ . '/../data/', // Directory for input files like BOM and supplier lists
    'LOGS_DIR' => __DIR__ . '/../logs/', // Directory for log output
    'MODELE' => __DIR__ . '/../models/', // Directory for CPLEX model files
    'OPLRUN' => detectOplrunPath(), // Automatically detect oplrun executable
];
