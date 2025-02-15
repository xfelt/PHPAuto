<?php

if (!defined('SETTINGS_INCLUDED')) {
    define('SETTINGS_INCLUDED', true);
}

/**
 * Detect the path to the oplrun executable across different environments.
 * Supports Windows, Linux, and macOS platforms.
 *
 * @return string Path to the oplrun executable
 * @throws Exception if oplrun cannot be found
 */
function detectOplrunPath(): string {
    $os = PHP_OS_FAMILY; // Detect the operating system (Windows, Linux, or Darwin for macOS)

    try {
        // Step 1: Check if the environment variable OPLRUN_PATH is set and valid
        if (getenv('OPLRUN_PATH') && file_exists(getenv('OPLRUN_PATH'))) {
            return getenv('OPLRUN_PATH');
        }

        // Step 2: Use system commands to locate oplrun based on the OS
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
                if (file_exists(trim($path))) {
                    return trim($path); // Return the first valid path
                }
            }
        }

        // Step 3: Fallback to common installation directories for oplrun
        $commonPaths = [];
        if ($os === 'Windows') {
            $commonPaths = [
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
                "/Applications/IBM/ILOG/CPLEX/bin/oplrun",
                "/usr/local/bin/oplrun",
            ];
        }

        foreach ($commonPaths as $path) {
            if (file_exists($path)) {
                return $path; // Return the first valid fallback path
            }
        }

        // If no path is found, throw an exception
        throw new Exception("oplrun executable not found in PATH, environment variable, or common directories.");

    } catch (Exception $e) {
        // Log the error to a file in the logs directory
        $logFile = __DIR__ . '/../logs/error_' . date('Ymd_His') . '.log';
        file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Error detecting oplrun: " . $e->getMessage() . PHP_EOL, FILE_APPEND);

        // Rethrow the exception for the calling script to handle
        throw $e;
    }
}

// Configuration array with dynamically detected OPLRUN path
return [
    'WORK_DIR' => __DIR__ . '/../data/', // Directory for input files like BOM and supplier lists
    'LOGS_DIR' => __DIR__ . '/../logs/', // Directory for log output
    'MODELE' => __DIR__ . '/../models/', // Directory for CPLEX model files
    'OPLRUN' => detectOplrunPath(), // Automatically detect oplrun executable
];
