<?php

require_once __DIR__ . '/FileUtils.php';
require_once __DIR__ . '/CplexRunner.php';
require_once __DIR__ . '/MultiObjectiveRunner.php';

/**
 * Map strategy and model type to the corresponding multi-objective .mod file.
 */
function getMultiObjModelFile(string $modelType): string {
    $modelMap = [
        "PLM" => "RUNS_SupEmis_MultiObj_PLM.mod",
        "NLM" => "RUNS_SupEmis_MultiObj_NLM.mod"
    ];

    if (!isset($modelMap[$modelType])) {
        throw new Exception("No multi-objective model file found for model type: $modelType");
    }

    return $modelMap[$modelType];
}

/**
 * Generate multi-objective test configurations based on the base configuration.
 */
function generateMultiObjConfigurations(array $baseConfig, int $numParetoPoints = 10) {
    $tabRuns = [];
    $sequence = 1;

    foreach ($baseConfig as $config) {
        $items = (int)$config['items'];
        $suppliers = (int)$config['suppliers'];
        $serviceTimes = (int)$config['service_times'];
        $modelType = $config['model_type']; // PLM or NLM
        $maxCapacity = (int)$config['max_capacity'];

        $supplierListFile = FileUtils::getSupplierListFile($items);
        $supplierDetailsFile = FileUtils::getSupplierDetailsFile($maxCapacity);

        // Create base run configuration for multi-objective optimization
        $prefix = sprintf("%02d-%02d-%02d-MULTIOBJ-%s-%d", $items, $suppliers, $serviceTimes, $modelType, $sequence);

        $tabRun = [
            "PREFIXE" => $prefix,
            "_NODE_FILE_" => "bom_supemis_{$items}.csv",
            "_NODE_SUPP_FILE_" => $supplierListFile,
            "_SUPP_DETAILS_FILE_" => $supplierDetailsFile,
            "_NBSUPP_" => $suppliers,
            "_SERVICE_T_" => $serviceTimes,
            "MODEL_FILE" => getMultiObjModelFile($modelType),
            "NUM_PARETO_POINTS" => $numParetoPoints,
            // Default emission parameters (can be overridden)
            "_EMISCAP_" => 2500000,
            "_EMISTAXE_" => 0.01
        ];

        $tabRuns[] = $tabRun;
        $sequence++;
    }

    return $tabRuns;
}

try {
    // Load Configuration Settings
    $config = include __DIR__ . '/../config/settings.php';
    echo "Configuration loaded successfully.\n";

    // Create a Timestamped Log Subfolder
    $timestamp = date('Ymd_His');
    $logSubfolder = rtrim($config['LOGS_DIR'], '/\\') . DIRECTORY_SEPARATOR . 'multiobj_' . $timestamp . DIRECTORY_SEPARATOR;
    if (!is_dir($logSubfolder)) {
        mkdir($logSubfolder, 0755, true);
    }
    echo "Log subfolder created: " . $logSubfolder . "\n";
    
    define('ERROR_LOG_FILE', $logSubfolder . 'error_' . date('Ymd_His') . '.log');
    echo "Error log file set to: " . ERROR_LOG_FILE . "\n";

    // Load Base Configuration from CSV
    $baseConfigFile = __DIR__ . '/../config/multiObjConfig.csv';
    if (!file_exists($baseConfigFile)) {
        throw new Exception("Multi-objective configuration file not found: $baseConfigFile");
    }

    $baseConfig = [];
    if (($handle = fopen($baseConfigFile, "r")) !== false) {
        $headers = fgetcsv($handle);
        $lineNumber = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $lineNumber++;

            if (count($headers) !== count($row)) {
                throw new Exception(
                    "Mismatch in multiObjConfig.csv on line $lineNumber: Header count (" . count($headers) . ") does not match row count (" . count($row) . ")."
                );
            }

            $configRow = array_map('trim', array_combine($headers, $row));

            $requiredColumns = ['items', 'suppliers', 'service_times', 'model_type', 'max_capacity', 'num_pareto_points'];
            foreach ($requiredColumns as $column) {
                if (!array_key_exists($column, $configRow) || $configRow[$column] === '') {
                    throw new Exception("Missing required value for '$column' in multiObjConfig.csv on line $lineNumber.");
                }
            }

            $configRow['model_type'] = strtoupper($configRow['model_type']);
            $allowedModelTypes = ['PLM', 'NLM'];
            if (!in_array($configRow['model_type'], $allowedModelTypes, true)) {
                throw new Exception("Invalid model type '" . $configRow['model_type'] . "' in multiObjConfig.csv on line $lineNumber.");
            }

            $integerColumns = ['items', 'suppliers', 'service_times', 'max_capacity', 'num_pareto_points'];
            foreach ($integerColumns as $column) {
                if (!ctype_digit($configRow[$column]) || (int)$configRow[$column] <= 0) {
                    throw new Exception("Column '$column' must be a positive integer in multiObjConfig.csv on line $lineNumber.");
                }
            }

            $configRow['items'] = (int)$configRow['items'];
            $configRow['suppliers'] = (int)$configRow['suppliers'];
            $configRow['service_times'] = (int)$configRow['service_times'];
            $configRow['max_capacity'] = (int)$configRow['max_capacity'];
            $configRow['num_pareto_points'] = (int)$configRow['num_pareto_points'];

            $baseConfig[] = $configRow;
        }
        fclose($handle);
    }

    // Generate Test Configurations
    $tabRuns = [];
    foreach ($baseConfig as $config) {
        $numPoints = $config['num_pareto_points'];
        $runs = generateMultiObjConfigurations([$config], $numPoints);
        $tabRuns = array_merge($tabRuns, $runs);
    }

    // Process Each Configuration Run
    foreach ($tabRuns as $run) {
        echo "\n========================================\n";
        echo "Processing multi-objective run: " . $run["PREFIXE"] . "\n";
        echo "========================================\n";

        // Check if the BOM file exists
        $bomFilePath = $config['WORK_DIR'] . $run["_NODE_FILE_"];
        if (!file_exists($bomFilePath)) {
            $errorMsg = "BOM file not found for run " . $run["PREFIXE"] . ": " . $bomFilePath;
            echo "Error: " . $errorMsg . "\n";
            file_put_contents(ERROR_LOG_FILE, "[" . date('Y-m-d H:i:s') . "] " . $errorMsg . PHP_EOL, FILE_APPEND);
            continue;
        }

        $modelFile = $config['MODELE'] . $run['MODEL_FILE'];
        if (!file_exists($modelFile)) {
            $errorMsg = "Model file not found: " . $modelFile;
            echo "Error: " . $errorMsg . "\n";
            file_put_contents(ERROR_LOG_FILE, "[" . date('Y-m-d H:i:s') . "] " . $errorMsg . PHP_EOL, FILE_APPEND);
            continue;
        }

        // Step 1: Find ideal and nadir points
        echo "Step 1: Finding ideal and nadir points...\n";
        $idealNadir = MultiObjectiveRunner::findIdealNadirPoints(
            $run,
            $modelFile,
            $config['WORK_DIR'],
            $config['OPLRUN']
        );
        
        $idealNadirFile = $logSubfolder . $run["PREFIXE"] . "_ideal_nadir.json";
        file_put_contents($idealNadirFile, json_encode($idealNadir, JSON_PRETTY_PRINT));
        echo "Ideal/Nadir points saved to: $idealNadirFile\n";

        // Step 2: Generate Pareto fronts
        $numPoints = $run['NUM_PARETO_POINTS'] ?? 10;
        
        echo "\nStep 2: Generating Cost-DIO Pareto front ($numPoints points)...\n";
        $costDIOPareto = MultiObjectiveRunner::generateCostDIOPareto(
            $run,
            $modelFile,
            $config['WORK_DIR'],
            $config['OPLRUN'],
            $idealNadir,
            $numPoints
        );
        $csvFile = $logSubfolder . $run["PREFIXE"] . "_pareto_cost_dio.csv";
        if (MultiObjectiveRunner::exportParetoToCSV($costDIOPareto, $csvFile, "Cost-DIO")) {
            echo "Cost-DIO Pareto front exported to: $csvFile (" . count($costDIOPareto) . " points)\n";
        }

        echo "\nStep 3: Generating Cost-WIP Pareto front ($numPoints points)...\n";
        $costWIPPareto = MultiObjectiveRunner::generateCostWIPPareto(
            $run,
            $modelFile,
            $config['WORK_DIR'],
            $config['OPLRUN'],
            $idealNadir,
            $numPoints
        );
        $csvFile = $logSubfolder . $run["PREFIXE"] . "_pareto_cost_wip.csv";
        if (MultiObjectiveRunner::exportParetoToCSV($costWIPPareto, $csvFile, "Cost-WIP")) {
            echo "Cost-WIP Pareto front exported to: $csvFile (" . count($costWIPPareto) . " points)\n";
        }

        echo "\nStep 4: Generating Cost-Emissions Pareto front ($numPoints points)...\n";
        $costEmisPareto = MultiObjectiveRunner::generateCostEmissionsPareto(
            $run,
            $modelFile,
            $config['WORK_DIR'],
            $config['OPLRUN'],
            $idealNadir,
            $numPoints
        );
        $csvFile = $logSubfolder . $run["PREFIXE"] . "_pareto_cost_emissions.csv";
        if (MultiObjectiveRunner::exportParetoToCSV($costEmisPareto, $csvFile, "Cost-Emissions")) {
            echo "Cost-Emissions Pareto front exported to: $csvFile (" . count($costEmisPareto) . " points)\n";
        }

        // Save summary
        $summary = [
            'prefix' => $run["PREFIXE"],
            'ideal_nadir' => $idealNadir,
            'pareto_cost_dio_points' => count($costDIOPareto),
            'pareto_cost_wip_points' => count($costWIPPareto),
            'pareto_cost_emissions_points' => count($costEmisPareto)
        ];
        $summaryFile = $logSubfolder . $run["PREFIXE"] . "_summary.json";
        file_put_contents($summaryFile, json_encode($summary, JSON_PRETTY_PRINT));
        echo "\nSummary saved to: $summaryFile\n";
    }

    echo "\n========================================\n";
    echo "Multi-objective optimization completed!\n";
    echo "Results saved in: $logSubfolder\n";
    echo "========================================\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    if (defined('ERROR_LOG_FILE')) {
        file_put_contents(ERROR_LOG_FILE, "[" . date('Y-m-d H:i:s') . "] " . $e->getMessage() . PHP_EOL, FILE_APPEND);
    }
    exit(1);
}
