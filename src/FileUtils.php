<?php

/**
 * Utility class for file operations
 */
class FileUtils {
    /**
     * Applies a dictionary of key-value pairs to a file, replacing placeholders with actual values.
     *
     * @param string $inputFile Path to the input file to be modified
     * @param array $dictionnary Key-value pairs for replacements
     * @param string $prefixe Prefix to add to the output file name
     * @param string $workDir Directory where the modified file will be saved
     * @return string Path to the modified output file
     * @throws Exception if the input file does not exist or any file operation fails
     */
    public static function applyDictionnary($inputFile, $dictionnary, $prefixe, $workDir) {
        try {
            // Step 1: Check if the input file exists
            if (!file_exists($inputFile)) {
                throw new Exception("File not found: $inputFile");
            }

            // Step 2: Read the contents of the input file into memory
            $content = file_get_contents($inputFile);
            if ($content === false) {
                throw new Exception("Failed to read file: $inputFile");
            }

            // Step 3: Perform all replacements in a single call using str_replace
            $content = str_replace(array_keys($dictionnary), array_values($dictionnary), $content);

            // Step 4: Define the output file path
            $outputFile = $workDir . strtoupper($prefixe) . "_" . basename($inputFile);

            // Step 5: Write the modified content directly to the output file
            if (file_put_contents($outputFile, $content) === false) {
                throw new Exception("Failed to write to file: $outputFile");
            }

            // Step 6: Return the path to the modified file
            return $outputFile;


        } catch (Exception $e) {
            // Catch and rethrow exceptions with additional context
            throw new Exception("Error in applyDictionnary: " . $e->getMessage());
        }
    }

    /**
     * Ensures that a directory exists, creating it if necessary.
     *
     * @param string $dir Path to the directory
     * @throws Exception if the directory cannot be created
     */
    public static function createDirectoryIfNotExists($dir) {
        try {
            // Step 1: Check if the directory already exists
            if (!is_dir($dir)) {
                // Step 2: Attempt to create the directory
                mkdir($dir, 0755, true);
            }
        } catch (Exception $e) {
            // Catch and rethrow exceptions with additional context
            throw new Exception("Error creating directory $dir: " . $e->getMessage());
        }
    }
	
	/**
	* Determine the correct supplier list file based on the number of items.
	*
	* @param int $items Number of items
	* @return string Path to the supplier list file
	*/
	public static function getSupplierListFile(int $items): string {
        return "supp_list_{$items}.csv";
    }
	
	public static function getSupplierDetailsFile(int $maxCapacity): string {
        // If $maxCapacity is greater than 0, return the high capacity details file;
        // otherwise, return the default supplier details file.
        return $maxCapacity > 0 ? "supp_details_supeco_grdCapacity.csv" : "supp_details_supeco.csv";
    }
}
