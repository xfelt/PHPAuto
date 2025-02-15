<?php

/**
 * Utility class for running CPLEX models
 */
class CplexRunner {
    /**
     * Executes a CPLEX model using the oplrun executable.
     *
     * @param string $modele Path to the model file to execute
     * @param string $oplRunPath Path to the oplrun executable
     * @return array Parsed results from the model execution
     * @throws Exception if the oplrun command fails or produces no output
     */
    public static function run($modele, $oplRunPath) {
        try {
            // Step 1: Build the command to execute oplrun
            $cmdLine = '"' . $oplRunPath . '" ' . escapeshellarg($modele);

            // Step 2: Execute the oplrun command and capture its output
            $output = shell_exec($cmdLine);
			//uncomment for cplex output
			//file_put_contents('debug_raw_output.log', $output);
            
			// Step 3: Check if the command produced any output
            if (!$output) {
                throw new Exception("No output from CPLEX. Command executed: $cmdLine");
            }

            // Step 4: Parse and return the output
            return self::parseOutput($output);

        } catch (Exception $e) {
            // Catch and rethrow exceptions with additional context
            throw new Exception("Error executing CPLEX: " . $e->getMessage());
        }
    }

    /**
     * Parses the raw output from oplrun to extract meaningful results.
     *
     * @param string $output Raw output from oplrun
     * @return array Parsed results
     * @throws Exception if the output format is invalid
     */
    private static function parseOutput($output) {
        try {
            // Step 1: Split the output into sections using "xxxx" as a delimiter
            $result = explode("xxxx", $output);
            $solution = [];

            // Step 2: Extract runtime information from the first section
            $solution["CplexRunTime"] = self::extractCplexTime($result[0]) . " sec";

            // Step 3: Parse additional results if available
            if (count($result) > 1) {
                foreach (explode("#", str_replace(".", ",", $result[1])) as $data) {
                    $parts = explode(":", $data);
                    if (count($parts) > 1) {
                        $solution[$parts[0]] = $parts[1];
                    }
                }
            }

            // Step 4: Return the parsed results
            return $solution;

        } catch (Exception $e) {
            // Catch and rethrow exceptions with additional context
            throw new Exception("Error parsing CPLEX output: " . $e->getMessage());
        }
    }

    /**
     * Extracts the runtime information from the trace output.
     *
     * @param string $trace Raw trace data from oplrun
     * @return string Extracted runtime in seconds
     * @throws Exception if the runtime cannot be extracted
     */
    private static function extractCplexTime($trace) {
        try {
            // Step 1: Split the trace data into lines
            $lines = explode("\n", $trace);

            // Step 2: Search for the line containing runtime information
            foreach ($lines as $line) {
                if (strpos($line, "Total (root+branch&cut)") !== false) {
                    return trim(explode("sec.", $line)[0]);
                }
            }

            // Step 3: Return -1 if no runtime information is found
            return -1;

        } catch (Exception $e) {
            // Catch and rethrow exceptions with additional context
            throw new Exception("Error extracting CPLEX runtime: " . $e->getMessage());
        }
    }
}
