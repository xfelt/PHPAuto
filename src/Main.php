<?php

// Include required files
require_once __DIR__ . '/FileUtils.php'; // File utilities
require_once __DIR__ . '/CplexRunner.php'; // CPLEX runner

/**
 * Map strategy and model type to the corresponding .mod file.
 *
 * @param string $strategy Strategy type (EMISCAP or EMISTAXE)
 * @param string $modelType Model type (PLM or NLM)
 * @return string Path to the corresponding .mod file
 */
function getModelFile(string $strategy, string $modelType): string {
    $modelMap = [
        "EMISCAP" => [
            "PLM" => "RUNS_SupEmis_Cplex_PLM_Cap.mod",
            "NLM" => "RUNS_SupEmis_Cp_NLM_Cap.mod"
        ],
        "EMISTAXE" => [
            "PLM" => "RUNS_SupEmis_Cplex_PLM_Tax.mod",
            "NLM" => "RUNS_SupEmis_Cp_NLM_Tax.mod"
        ]
    ];

    if (!isset($modelMap[$strategy][$modelType])) {
        throw new Exception("No model file found for strategy: $strategy and model type: $modelType");
    }

    return $modelMap[$strategy][$modelType];
}

/**
 * Generate test configurations based on the base configuration and strategy.
 *
 * @param array $baseConfig Parsed base configuration from CSV
 * @return array Generated test configurations
 */
function generateConfigurationsFromCSV(array $baseConfig) {
    $tabRuns = [];
    $sequence = 1;

    foreach ($baseConfig as $config) {
        $items = (int)$config['items'];
		$suppliers = (int)$config['suppliers'];
		$serviceTimes = (int)$config['service_times'];
        $strategy = $config['strategy'];
        $modelType = $config['model_type']; // PLM or NLM
        $strategyValues = explode(',', $config['strategy_values']);
		echo "Strategy: {$config['strategy']}, Values: " . implode(', ', $strategyValues) . "\n";
        $maxCapacity = (int)$config['max_capacity']; // Boolean flag for capacity

        $supplierListFile = FileUtils::getSupplierListFile($items); // Dynamically select supplier list
        $supplierDetailsFile = FileUtils::getSupplierDetailsFile($maxCapacity); // Dynamically select details file

        foreach ($strategyValues as $value) {
            // Include `model_type` in the PREFIXE for clarity
            $prefix = sprintf("%02d-%02d-%02d-%s-%s-%d", $items, $suppliers, $serviceTimes, $strategy, $modelType, $sequence);

            $tabRun = [
                "PREFIXE" => $prefix,
                "_NODE_FILE_" => "bom_supemis_{$items}.csv",
                "_NODE_SUPP_FILE_" => $supplierListFile,
                "_SUPP_DETAILS_FILE_" => $supplierDetailsFile,
                "_NBSUPP_" => $suppliers,
                "_SERVICE_T_" => $serviceTimes,
                "MODEL_FILE" => getModelFile($strategy, $modelType) // Determine the correct model file
            ];

            // Add strategy-specific parameters
            if ($strategy === "EMISCAP") {
                $tabRun["_EMISCAP_"] = (int)$value;
                $tabRun["_EMISTAXE_"] = 0.01; // Default for EMISCAP
            } elseif ($strategy === "EMISTAXE") {
                $tabRun["_EMISCAP_"] = 2500000; // Default for EMISTAXE
                $tabRun["_EMISTAXE_"] = (float)$value;
            }

            $tabRuns[] = $tabRun;
            $sequence++;
        }
    }

    return $tabRuns;
}

try {
    // -----------------------------------------------------
    // STEP 1: Load Configuration Settings
    // -----------------------------------------------------
    $config = include __DIR__ . '/../config/settings.php';
	echo "Configuration loaded successfully.\n";

	// STEP 2: Create a Timestamped Log Subfolder
    // -----------------------------------------------------
    // Create a new folder inside the logs directory for this run.
    // This prevents older logs from being overwritten.
    $timestamp = date('Ymd_His');
    // Build the subfolder path using DIRECTORY_SEPARATOR for compatibility.
    $logSubfolder = rtrim($config['LOGS_DIR'], '/\\') . DIRECTORY_SEPARATOR . $timestamp . DIRECTORY_SEPARATOR;
    if (!is_dir($logSubfolder)) {
        mkdir($logSubfolder, 0755, true); // Create the directory if it doesn't exist.
    }
    echo "Log subfolder created: " . $logSubfolder . "\n";
	
	define('ERROR_LOG_FILE', $logSubfolder . 'error_' . date('Ymd_His') . '.log');
    echo "Error log file set to: " . ERROR_LOG_FILE . "\n";

    // -----------------------------------------------------
    // STEP 3: Load Base Configuration from CSV
    // -----------------------------------------------------
    $baseConfigFile = __DIR__ . '/../config/baseConfig.csv';
    if (!file_exists($baseConfigFile)) {
        throw new Exception("Base configuration file not found: $baseConfigFile");
    }

    $baseConfig = [];
    if (($handle = fopen($baseConfigFile, "r")) !== false) {
    $headers = fgetcsv($handle); // Read the header row
    while (($row = fgetcsv($handle)) !== false) {
        // Validate row consistency
        if (count($headers) !== count($row)) {
            throw new Exception(
                "Mismatch in baseConfig.csv: Header count (" . count($headers) . ") does not match row count (" . count($row) . ")."
            );
        }
        $baseConfig[] = array_combine($headers, $row); // Combine headers with row values
    }
    fclose($handle);
	}
    // Uncomment the following line to debug the CSV parsing:
    // print_r($baseConfig); exit;
	
    // -----------------------------------------------------
    // STEP 4: Generate Test Configurations from CSV Data
    // -----------------------------------------------------
    $tabRuns = generateConfigurationsFromCSV($baseConfig);

    // -----------------------------------------------------
    // STEP 5: Process Each Configuration Run
    // -----------------------------------------------------
    foreach ($tabRuns as $run) {
        echo "Processing run: " . $run["PREFIXE"] . PHP_EOL;

		// ----------------------------
		// Check if the BOM file exists
		// ----------------------------
		// The BOM file is defined in the run array under the key "_NODE_FILE_".
		// It is expected to be located in the working directory ($config['WORK_DIR']).
		$bomFilePath = $config['WORK_DIR'] . $run["_NODE_FILE_"];
		if (!file_exists($bomFilePath)) {
			$errorMsg = "BOM file not found for run " . $run["PREFIXE"] . ": " . $bomFilePath;
			echo "Error: " . $errorMsg . "\n";
			file_put_contents(ERROR_LOG_FILE, "[" . date('Y-m-d H:i:s') . "] " . $errorMsg . PHP_EOL, FILE_APPEND);
			continue;  // Skip processing this run
		}

        // Apply the dictionary to the model file
        $modeleFile = FileUtils::applyDictionnary(
            $config['MODELE'] . $run['MODEL_FILE'], // Use the model file specified in tabRuns
            $run,
            $run['PREFIXE'],
            $config['WORK_DIR'] // Destination working directory (where you copy and modify the file)
        );

        // Execute the CPLEX model
        $result = CplexRunner::run($modeleFile, $config['OPLRUN']);

        // Save the results to a log file in the timestamped subfolder.
        $logFile = $logSubfolder . $run["PREFIXE"] . "_result.log";
        file_put_contents($logFile, print_r($result, true));

        echo "Run completed for: " . $run["PREFIXE"] . ". Results saved to: $logFile\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    file_put_contents(ERROR_LOG_FILE, "[" . date('Y-m-d H:i:s') . "] " . $e->getMessage() . PHP_EOL, FILE_APPEND);
    exit(1);
}
