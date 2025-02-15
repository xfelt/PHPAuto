# Supplier Selection with Emissions – Optimization Script

This project is designed to run optimization models for supplier selection under emissions constraints using IBM ILOG CPLEX. The script processes multiple test instances (or “runs”) based on a base configuration CSV file, applies parameter substitutions to a CPLEX model file, executes the model, and logs the results.

---

## Table of Contents

1. [Project Structure](#project-structure)
2. [File and Folder Descriptions](#file-and-folder-descriptions)
   - [config Folder](#config-folder)
   - [src Folder](#src-folder)
   - [models Folder](#models-folder)
   - [data Folder](#data-folder)
   - [logs Folder](#logs-folder)
3. [Workflow and Script Explanation](#workflow-and-script-explanation)
4. [Interpretation of CPLEX Output](#interpretation-of-cplex-output)
5. [Creating and Modifying Instances](#creating-and-modifying-instances)
6. [Dependencies and Requirements](#dependencies-and-requirements)
7. [How to Run the Script](#how-to-run-the-script)
8. [Troubleshooting](#troubleshooting)

---

## Project Structure

SupplierSelection/ ├── config/ │ ├── settings.php # Configuration file that sets up directories and detects oplrun. │ └── baseConfig.csv # CSV file defining the base test instances (runs). ├── data/ │ ├── bom_supemis_X.csv # Bill of Materials (BOM) files; X corresponds to the number of items. │ ├── supp_list_X.csv # Supplier list files; X corresponds to the number of items (defines accepted suppliers). │ ├── supp_details_supeco.csv # Default supplier details file (includes supplier capacities). │ └── supp_details_supeco_grdCapacity.csv # Supplier details file for high capacity instances. ├── models/ │ ├── RUNS_SupEmis_Cplex_PLM_Cap.mod # Pseudo-linear model for emission cap strategy. │ ├── RUNS_SupEmis_Cplex_PLM_Tax.mod # Pseudo-linear model for emission tax strategy. │ ├── RUNS_SupEmis_CP_NLM_Cap.mod # Non-linear model for emission cap strategy. │ └── RUNS_SupEmis_CP_NLM_Tax.mod # Non-linear model for emission tax strategy. ├── logs/ │ └── (Timestamped subfolders with result logs and error logs are created here.) ├── src/ │ ├── Main.php # Main script that orchestrates the workflow. │ ├── FileUtils.php # Utility functions for file operations (dictionary substitution, directory creation, file selection). │ ├── CplexRunner.php # Executes the CPLEX model and parses its output. │ └── Logger.php # (Optional) Additional logging utilities. └── README.md # This file.

markdown
Copier

---

## File and Folder Descriptions

### **config Folder**
- **settings.php:**  
  - Contains configuration parameters such as paths for data, logs, and models.
  - Detects the location of the `oplrun` executable.
- **baseConfig.csv:**  
  - Defines test instances with columns:
    - **items:** Number of items in the BOM.
    - **suppliers:** Number of suppliers to consider (e.g., 10 vs. 20).
    - **service_times:** Service time required by the client.
    - **strategy:** Either `EMISCAP` (emission cap) or `EMISTAXE` (emission tax).
    - **model_type:** Either `PLM` (Pseudo Linear Model) or `NLM` (Non-Linear Model).
    - **strategy_values:** Comma-separated values for parameters (e.g., `"2500000,2200000"` or `"0.01,0.02"`).
    - **max_capacity:** A flag (0 for default or 1 for high capacity) to select the appropriate supplier details file.

### **src Folder**
- **Main.php:**  
  - Orchestrates the workflow:
    - Loads configuration and baseConfig.csv.
    - Generates test runs.
    - Applies dictionary substitutions to model files.
    - Executes the models using CPLEX.
    - Logs results into a timestamped subfolder.
- **FileUtils.php:**  
  - Contains utility methods:
    - `applyDictionnary()`: Copies and modifies model files based on run parameters.
    - `createDirectoryIfNotExists()`: Ensures directories exist.
    - `getSupplierListFile($items)`: Returns the supplier list filename (e.g., `"supp_list_5.csv"`).
    - `getSupplierDetailsFile($maxCapacity)`: Returns either `"supp_details_supeco.csv"` or `"supp_details_supeco_grdCapacity.csv"` based on the capacity flag.
- **CplexRunner.php:**  
  - Executes the CPLEX model:
    - Builds and executes the command for `oplrun`.
    - Handles quoting of paths.
    - Parses the output from CPLEX.
- **Logger.php:**  
  - (Optional) Provides additional logging functionality if needed.

### **models Folder**
- Contains CPLEX model files (`.mod`) for each strategy and model type:
  - **Pseudo-linear models:**  
    - `RUNS_SupEmis_Cplex_PLM_Cap.mod`
    - `RUNS_SupEmis_Cplex_PLM_Tax.mod`
  - **Non-linear models:**  
    - `RUNS_SupEmis_CP_NLM_Cap.mod`
    - `RUNS_SupEmis_CP_NLM_Tax.mod`

### **data Folder**
- Contains input files:
  - **BOM Files:**  
    - Named like `bom_supemis_X.csv` (where X is the number of items).
  - **Supplier List Files:**  
    - Named like `supp_list_X.csv` (X is the number of items).
  - **Supplier Details Files:**  
    - `supp_details_supeco.csv` (default)
    - `supp_details_supeco_grdCapacity.csv` (for high capacity cases)

### **logs Folder**
- Stores log files:
  - A new subfolder is created per execution run, named with the current timestamp.
  - Each run’s result log and error log are saved in this subfolder.

---

## Workflow and Script Explanation

1. **Configuration Loading:**  
   `settings.php` provides necessary paths and detects `oplrun`.

2. **Timestamped Logging:**  
   A timestamped subfolder is created in the logs folder to store run logs and error logs, ensuring that previous logs are preserved.

3. **Base Configuration Reading:**  
   `baseConfig.csv` is parsed to load test instance parameters.

4. **Test Instance Generation:**  
   `generateConfigurationsFromCSV()` processes the CSV rows, splits `strategy_values` to create multiple runs per row, and assigns a unique `PREFIXE` for each run.

5. **Processing Each Run:**  
   For each run:
   - The appropriate model file (from the models folder) is selected.
   - The model file is copied and modified using `applyDictionnary()`.
   - The modified model is executed via CPLEX using `oplrun`.
   - Results are logged into the timestamped subfolder.

6. **Error Handling:**  
   Errors are caught and logged into a uniquely named error log file in the same timestamped folder.

---

## Interpretation of CPLEX Output

After each run, the output from CPLEX is captured and logged. Below are two examples:

### **Example 1: Pseudo-Linear Model Output (e.g., 05-10-01-EMISCAP-PLM-1)**
```plaintext
Array
(
    [CplexRunTime] => Total (root+branch&cut) =    0,06 sec
    [Result] =>  <51582 51555 27 2,2537e+6>
    [CS] => 51555
    [A] => [0,2,5,10,4,6]
    [E] =>  2253700
    [DELIVER] => 
S10=>P2
S3=>P4
S4=>P4
S10=>P4
S4=>P5
)
CplexRunTime: Total time taken by CPLEX to solve the model.
Result: A summary of numerical outputs (objective value, decision metrics, etc.).
CS: A key solution parameter (e.g., a decision variable or cost metric).
A: An array representing decision variable assignments.
E: Total emissions (or a similar cost metric) computed.
DELIVER: Detailed supplier-to-product assignments (e.g., "S10=>P2" means supplier 10 is assigned to product 2).
Example 2: Non-Linear Model Output (e.g., 05-20-01-EMISTAXE-NLM-8)
plaintext
Copier
Array
(
    [CplexRunTime] => -1 sec
    [Result] =>  <82403 82380 23 1,4938e+6>
    [TS] => 82380-0
    [E] =>  1493800
    [DELIVER] => 
S10=>P2
S10=>P4
S10=>P5
)
CplexRunTime: A value of -1 sec indicates the runtime wasn’t captured (likely due to format differences in non-linear models).
Result: Numerical outputs summarizing the solution.
TS: A model-specific metric (its meaning depends on your model).
E: The computed emissions or cost.
DELIVER: The detailed mapping of assignments.
Note: The exact interpretation of each key depends on your model’s formulation.

Creating and Modifying Instances
To Create New Instances:
Update baseConfig.csv:

Add new rows with the following columns:
items: Number of BOM items.
suppliers: Number of suppliers (e.g., 10 or 20).
service_times: Service time requirement.
strategy: EMISCAP or EMISTAXE.
model_type: PLM or NLM.
strategy_values: Comma-separated list of parameter values (e.g., "2500000,2200000").
max_capacity: 0 for default supplier details, 1 for high capacity.
Ensure Required Files Exist:

BOM Files: Place files like bom_supemis_X.csv in the data/ folder.
Supplier List Files: Place files like supp_list_X.csv in the data/ folder.
Supplier Details Files: Ensure both supp_details_supeco.csv and supp_details_supeco_grdCapacity.csv are available in the data/ folder.
Model Files: Ensure the appropriate .mod files are present in the models/ folder:
Pseudo-linear: RUNS_SupEmis_Cplex_PLM_Cap.mod, RUNS_SupEmis_Cplex_PLM_Tax.mod
Non-linear: RUNS_SupEmis_CP_NLM_Cap.mod, RUNS_SupEmis_CP_NLM_Tax.mod
Modify Mapping (if necessary):

If using new model files, update the mapping in the function (e.g., getModelFile()) in your code to reflect the new file names.
Dependencies and Requirements
PHP (7.4 or later): Ensure PHP is installed and properly configured.
IBM ILOG CPLEX Optimization Studio: Must be installed, and the oplrun executable should be accessible (via PATH or OPLRUN_PATH).
CSV Files: The project depends on a properly formatted baseConfig.csv for test instance definitions.
File Permissions: Ensure that the data, models, and logs folders have correct read/write permissions.
Cloud-Synced Directories: If using a cloud storage solution (e.g., Google Drive), ensure sync issues do not affect file access.
How to Run the Script
Set Up the Project:
Follow the project structure above and place all files in their respective directories.
Configure Your Environment:
Edit config/settings.php to ensure paths are correct (WORK_DIR, LOGS_DIR, MODELE) and that oplrun is detected.
Run the Main Script:
Open a terminal, navigate to the src folder, and run:
bash
Copier
php Main.php
Check the Logs:
A new subfolder (named with a timestamp) will be created in the logs/ folder, containing:
Result log files for each run (e.g., 05-10-01-EMISCAP-PLM-1_result.log).
An error log file (e.g., error_20230810_153045.log).
Troubleshooting
Missing Files:
Verify that BOM, supplier list, and model files exist in their respective folders.
OPLRUN Issues:
If the script cannot find or execute oplrun, ensure that it is installed and accessible via PATH or the OPLRUN_PATH variable in settings.php.
CSV Parsing Errors:
Ensure that baseConfig.csv is properly formatted (each row must have the same number of columns as the header).
File Permissions:
Adjust permissions if you encounter access errors.
Output Issues:
If CPLEX output (e.g., runtime of -1 sec) is unexpected, verify the model’s output format and adjust the parsing logic accordingly.
Interpretation of CPLEX Output
After each run, the output from CPLEX is logged. Here are two example outputs and their interpretations:

Example 1: Pseudo-Linear Model Output (05-10-01-EMISCAP-PLM-1)
plaintext
Copier
Array
(
    [CplexRunTime] => Total (root+branch&cut) =    0,06 sec
    [Result] =>  <51582 51555 27 2,2537e+6>
    [CS] => 51555
    [A] => [0,2,5,10,4,6]
    [E] =>  2253700
    [DELIVER] => 
S10=>P2
S3=>P4
S4=>P4
S10=>P4
S4=>P5
)
CplexRunTime: The total computation time.
Result: Summary numerical output (objective, solution metrics, etc.).
CS: A key solution metric.
A: Array of decision values.
E: Total emissions or cost.
DELIVER: Detailed mapping of supplier-to-product assignments.
Example 2: Non-Linear Model Output (05-20-01-EMISTAXE-NLM-8)
plaintext
Copier
Array
(
    [CplexRunTime] => -1 sec
    [Result] =>  <82403 82380 23 1,4938e+6>
    [TS] => 82380-0
    [E] =>  1493800
    [DELIVER] => 
S10=>P2
S10=>P4
S10=>P5
)
CplexRunTime: A value of -1 sec indicates that the runtime was not captured properly (possibly due to different output formatting).
Result: Summary numerical output.
TS: A model-specific metric (exact meaning depends on your model).
E: Total emissions or cost.
DELIVER: Supplier-to-product assignment details.
Note: The interpretation of each output key depends on your model’s design.

Conclusion
This README provides a comprehensive guide covering project structure, file descriptions, workflow, instance creation, dependencies, execution instructions, and interpretation of the CPLEX output. Use it as a reference for maintaining and extending the project.

End of README