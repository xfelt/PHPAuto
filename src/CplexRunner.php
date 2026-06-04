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
     * Parses raw oplrun output that has already been captured, without re-running CPLEX.
     * Use this when the caller has already executed oplrun and holds the output string.
     *
     * @param string $output Raw output previously captured from oplrun
     * @return array Parsed results
     */
    public static function parse($output) {
        if (!$output) {
            return [];
        }
        return self::parseOutput($output);
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
            $solution = [];
            $normalizedOutput = str_replace(["\r\n", "\r"], "\n", $output);
            $sections = preg_split('/^\s*xxxx\s*$/m', $normalizedOutput);

            $runtimeSection = $sections[0] ?? '';
            $solution["CplexRunTime"] = self::extractCplexTime($runtimeSection) . " sec";
            $solution = array_merge($solution, self::extractSolverMetadata($normalizedOutput));

            if (count($sections) < 2) {
                return $solution;
            }

            $payload = $sections[1];
            if ($payload === null || trim($payload) === '') {
                return $solution;
            }

            if (preg_match_all('/#([A-Za-z0-9_]+)\s*:?-?\s*([^#]*)/', $payload, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $key = trim($match[1]);
                    $rawValue = trim($match[2]);

                    if (strcasecmp($key, 'DELIVER') === 0) {
                        $deliveries = array_filter(array_map('trim', preg_split('/\n+/', $rawValue)));
                        $solution['DELIVER'] = array_values($deliveries);
                        continue;
                    }

                    if ($key === 'Result' && preg_match_all('/<([^>]+)>/', $rawValue, $vectorMatches)) {
                        // The Result line is "#Result <labels>: <values>". The first <...> is the
                        // textual label list (fct_obj, tot_cst, ...); the numeric values live in the
                        // last <...> group. Always parse the values vector, never the label.
                        $vector = end($vectorMatches[1]);
                        $components = preg_split('/\s+/', trim(str_replace(',', '.', $vector)));
                        // Positional labels depend on the model: the multi-objective model emits a
                        // 5-field vector (fctObj, TotalCost, DIO, WIP, Emiss) while the tax/cap models
                        // emit 4 fields (fctObj, TotalCost, leadTime, Emiss). Emissions are always last.
                        if (count($components) >= 5) {
                            $labels = ['Objective', 'TotalCost', 'DIO', 'WIP', 'Emissions'];
                        } else {
                            $labels = ['Objective', 'TotalCost', 'LeadTime', 'Emissions'];
                        }
                        $resultData = [];
                        foreach ($labels as $index => $label) {
                            if (isset($components[$index])) {
                                $resultData[$label] = self::normalizeScalar($components[$index]);
                            }
                        }
                        // Emissions always come from the final component, regardless of arity.
                        if (!empty($components)) {
                            $resultData['Emissions'] = self::normalizeScalar(end($components));
                        }
                        if (!empty($resultData)) {
                            $solution['Result'] = $resultData;
                        }
                        continue;
                    }

                    $solution[$key] = self::normalizeValue($rawValue);
                }
            }

            return $solution;

        } catch (Exception $e) {
            throw new Exception("Error parsing CPLEX output: " . $e->getMessage());
        }
    }

    /**
     * Normalizes composite values by detecting arrays or scalar numbers.
     *
     * @param string $value Raw value captured from the opl output
     * @return mixed Normalized PHP value
     */
    private static function normalizeValue($value) {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (strlen($value) >= 2 && $value[0] === '[' && substr($value, -1) === ']') {
            $inner = trim($value, '[]');
            if ($inner === '') {
                return [];
            }

            $items = array_map('trim', explode(',', $inner));
            return array_map([self::class, 'normalizeScalar'], $items);
        }

        return self::normalizeScalar($value);
    }

    /**
     * Converts scalar strings to numeric values when possible while keeping other strings intact.
     *
     * @param string $value Scalar value captured from the opl output
     * @return mixed Float, int, or original string depending on detectability
     */
    private static function normalizeScalar($value) {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $normalized = str_replace(',', '.', $value);
        if (is_numeric($normalized)) {
            return $normalized + 0;
        }

        return $value;
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
				// For PLM (CPLEX) models:
				if (strpos($line, "Total (root+branch&cut)") !== false) {
					// Extract the part before "sec." and trim whitespace.
					return trim(explode("sec.", $line)[0]);
				}
				// For CP optimiser (NLM) models:
				if (strpos($line, "Time spent in solve") !== false) {
					// Assume format: "Time spent in solve : 4.23 sec"
					$parts = explode(":", $line);
					if (preg_match('/Time spent in solve\s*:\s*([\d,\.]+)s/', $line, $matches)) {
						$timeValue = trim($matches[1]);
                        return $timeValue;
					}
				}
			}
            // Step 3: Return -1 if no runtime information is found
            return -1;

        } catch (Exception $e) {
            // Catch and rethrow exceptions with additional context
            throw new Exception("Error extracting CPLEX runtime: " . $e->getMessage());
        }
    }

    /**
     * Extract termination status and optimality gap from CPLEX/CP Optimizer output.
     *
     * A returned incumbent is not necessarily optimal: CP Optimizer prints the incumbent
     * after a time-limit termination, so status must be derived from the solver trace.
     */
    private static function extractSolverMetadata(string $output): array {
        $metadata = [
            'status' => 'UNKNOWN',
            'termination_reason' => 'UNKNOWN',
            'mip_gap' => null,
        ];

        if (preg_match_all('/gap is\s*([\d,.]+)%/i', $output, $gapMatches) && !empty($gapMatches[1])) {
            $lastGap = end($gapMatches[1]);
            $metadata['mip_gap'] = (float)str_replace(',', '.', $lastGap);
        }

        $hasSolution = preg_match('/^\s*OBJECTIVE\s*:/mi', $output) === 1
            || preg_match('/#Result\s*</i', $output) === 1;

        if (preg_match('/Search terminated by limit|time limit (?:exceeded|reached)|time limit abort/i', $output)) {
            $metadata['status'] = $hasSolution ? 'FEASIBLE' : 'TIMEOUT';
            $metadata['termination_reason'] = 'TIME_LIMIT';
            return $metadata;
        }

        if (preg_match('/Infeasibility|\binfeasible\b|model has no solution|\bno solution\b|integer infeasible/i', $output)) {
            $metadata['status'] = 'INFEASIBLE';
            $metadata['termination_reason'] = 'INFEASIBLE';
            return $metadata;
        }

        if (preg_match('/Best objective\s*:.*\(optimal\b|integer optimal solution|optimal solution found/i', $output)
            || ($hasSolution && preg_match('/Total \(root\+branch&cut\)/i', $output))) {
            $metadata['status'] = 'OPTIMAL';
            $metadata['termination_reason'] = 'OPTIMAL';
            $metadata['mip_gap'] = 0.0;
            return $metadata;
        }

        if ($hasSolution) {
            $metadata['status'] = 'FEASIBLE';
            $metadata['termination_reason'] = 'SOLUTION_RETURNED';
        }

        return $metadata;
    }
}
