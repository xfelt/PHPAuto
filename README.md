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

<ul>
  <li><strong>SupplierSelection/</strong>
    <ul>
      <li><strong>config/</strong>
        <ul>
          <li><code>settings.php</code> – Configuration file that sets up directories and detects <code>oplrun</code>.</li>
          <li><code>baseConfig.csv</code> – CSV file defining the base test instances (runs).</li>
        </ul>
      </li>
      <li><strong>data/</strong>
        <ul>
          <li><code>bom_supemis_X.csv</code> – Bill of Materials (BOM) files; X corresponds to the number of items.</li>
          <li><code>supp_list_X.csv</code> – Supplier list files; X corresponds to the number of items (defines accepted suppliers).</li>
          <li><code>supp_details_supeco.csv</code> – Default supplier details file (includes supplier capacities).</li>
          <li><code>supp_details_supeco_grdCapacity.csv</code> – Supplier details file for high capacity instances.</li>
        </ul>
      </li>
      <li><strong>models/</strong>
        <ul>
          <li><code>RUNS_SupEmis_Cplex_PLM_Cap.mod</code> – Pseudo-linear model for emission cap strategy.</li>
          <li><code>RUNS_SupEmis_Cplex_PLM_Tax.mod</code> – Pseudo-linear model for emission tax strategy.</li>
          <li><code>RUNS_SupEmis_CP_NLM_Cap.mod</code> – Non-linear model for emission cap strategy.</li>
          <li><code>RUNS_SupEmis_CP_NLM_Tax.mod</code> – Non-linear model for emission tax strategy.</li>
        </ul>
      </li>
      <li><strong>logs/</strong>
        <ul>
          <li>(Timestamped subfolders with result logs and error logs are created here.)</li>
        </ul>
      </li>
      <li><strong>src/</strong>
        <ul>
          <li><code>Main.php</code> – Main script that orchestrates the workflow.</li>
          <li><code>FileUtils.php</code> – Utility functions for file operations (dictionary substitution, directory creation, file selection).</li>
          <li><code>CplexRunner.php</code> – Executes the CPLEX model and parses its output.</li>
          <li><code>Logger.php</code> – (Optional) Additional logging utilities.</li>
        </ul>
      </li>
      <li><code>README.md</code> – Project documentation.</li>
    </ul>
  </li>
</ul>



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

After each run, the output from CPLEX is captured and logged. We can find the following elements :
* **CplexRunTime:** Total time taken by CPLEX to solve the model.
* **Result:** A summary of outputs <Objectif function , Total Costs, lead time, emissions>.
* **CS/TS:** The total costs depending on the model.
* **A:** An array representing decision variable assignments.
* **E:** Total emissions computed.
* **DELIVER:** Detailed supplier-to-product assignments (e.g., "S10=>P2" means supplier 10 is assigned to product 2).
  
Below are two examples:

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
```
  
### **Example 2: Non-Linear Model Output (e.g., 05-20-01-EMISTAXE-NLM-8)**
```plaintext
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
```


## Creating and Modifying Instances

### To Create New Instances:
1. **Update `baseConfig.csv`:**  
   - Add new rows with the following columns:
     - **items:** Number of BOM items.
     - **suppliers:** Number of suppliers (e.g., 10 or 20).
     - **service_times:** Service time requirement.
     - **strategy:** Either `EMISCAP` or `EMISTAXE`.
     - **model_type:** Either `PLM` (Pseudo Linear Model) or `NLM` (Non-Linear Model).
     - **strategy_values:** Comma-separated list of parameter values (e.g., `"2500000,2200000"` or `"0.01,0.02"`).
     - **max_capacity:** 0 for default supplier details, 1 for high capacity supplier details.

2. **Ensure Required Files Exist:**
   - **BOM Files:**  
     Place BOM files (e.g., `bom_supemis_5.csv`) in the `data/` folder.
   - **Supplier List Files:**  
     Place supplier list files (e.g., `supp_list_5.csv`) in the `data/` folder.
   - **Supplier Details Files:**  
     Ensure both `supp_details_supeco.csv` and `supp_details_supeco_grdCapacity.csv` are present in the `data/` folder.
   - **Model Files:**  
     Ensure the appropriate `.mod` files are in the `models/` folder:
     - **Pseudo-linear models:**  
       `RUNS_SupEmis_Cplex_PLM_Cap.mod`, `RUNS_SupEmis_Cplex_PLM_Tax.mod`
     - **Non-linear models:**  
       `RUNS_SupEmis_CP_NLM_Cap.mod`, `RUNS_SupEmis_CP_NLM_Tax.mod`

3. **Modify Mapping (if necessary):**
   - If using new model files, update the mapping function (e.g., `getModelFile()`) in your code to map the new `strategy`/`model_type` combinations to the correct file names.

## Dependencies and Requirements

- **PHP (7.4 or later):**  
  Ensure PHP is installed and properly configured.
- **IBM ILOG CPLEX Optimization Studio:**  
  Must be installed, and the `oplrun` executable should be accessible (via PATH or defined in `settings.php`).
- **CSV Files:**  
  The project relies on a properly formatted `baseConfig.csv` for instance definitions.
- **File Permissions:**  
  Ensure that the `data`, `models`, and `logs` folders have the proper read/write permissions.
- **Cloud-Synced Directories:**  
  If using cloud storage (e.g., Google Drive), ensure sync issues do not interfere with file access.

---

## How to Run the Script

1. **Set Up the Project:**  
   Follow the project structure above and place all files in their respective directories.
2. **Configure Your Environment:**  
   Edit `config/settings.php` to ensure paths (WORK_DIR, LOGS_DIR, MODELE) are correct and that `oplrun` is detected.
3. **Run the Main Script:**  
   Open a terminal, navigate to the `src` folder, and run:
   ```bash
   php Main.php
4. **Check the Logs:**  
   A new subfolder (named with the current timestamp) will be created in the `logs/` folder. This folder contains:
   - **Result Log Files:**  
     E.g., `05-10-01-EMISCAP-PLM-1_result.log` for each run.
   - **Error Log File:**  
     E.g., `error_YYYYMMDD_HHMMSS.log` for errors during the run.

- **Output Issues:**  
  If the CPLEX output (e.g., runtime of -1 sec) is unexpected, check your model’s output format and adjust the parsing logic accordingly.
  
## Troubleshooting

- **Missing Files:**  
  Verify that BOM, supplier list, and model files exist in their respective folders.

- **OPLRUN Issues:**  
  If the script cannot find or execute `oplrun`, ensure that it is installed and accessible via PATH or the `OPLRUN_PATH` variable in `settings.php`.

- **CSV Parsing Errors:**  
  Ensure that `baseConfig.csv` is properly formatted (each row must have the same number of columns as the header).

- **File Permissions:**  
  Adjust permissions if you encounter access errors.

- **Output Issues:**  
  If CPLEX output (e.g., runtime of -1 sec) is unexpected, verify the model’s output format and adjust the parsing logic accordingly.

Conclusion
This README provides a comprehensive guide covering project structure, file descriptions, workflow, instance creation, dependencies, execution instructions, and interpretation of the CPLEX output. Use it as a reference for maintaining and extending the project.

End of README
