import csv
import subprocess
import sys
import tempfile
from pathlib import Path


repo = Path(__file__).resolve().parents[1]
generator = repo / "src" / "generate_article_tables.py"

with tempfile.TemporaryDirectory() as temp_dir:
    results_dir = Path(temp_dir)
    csv_path = results_dir / "consolidated_results.csv"
    tables_dir = results_dir / "tables"
    tables_dir.mkdir()
    with csv_path.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(
            handle,
            fieldnames=[
                "experiment",
                "instance_id",
                "tax_rate",
                "cap_value",
                "cap_level",
                "total_emissions",
                "total_cost_with_tax",
                "buffer_count",
                "solver_status",
                "mip_gap",
                "comparison_admissible",
            ],
        )
        writer.writeheader()
        writer.writerow(
            {
                "experiment": "carbon_hybrid",
                "instance_id": "bom_5",
                "tax_rate": "100",
                "cap_value": "1848210",
                "cap_level": "70%",
                "solver_status": "INFEASIBLE",
                "comparison_admissible": "0",
            }
        )
        writer.writerow(
            {
                "experiment": "carbon_tax_sweep",
                "instance_id": "bom_5",
                "tax_rate": "0",
                "total_emissions": "2000000",
                "solver_status": "OPTIMAL",
                "comparison_admissible": "1",
            }
        )
        writer.writerow(
            {
                "experiment": "carbon_tax_sweep",
                "instance_id": "bom_5",
                "tax_rate": "50",
                "total_emissions": "1900000",
                "solver_status": "FEASIBLE",
                "mip_gap": "0.8",
                "comparison_admissible": "1",
            }
        )
        writer.writerow(
            {
                "experiment": "carbon_tax_sweep",
                "instance_id": "bom_5",
                "tax_rate": "100",
                "total_emissions": "1800000",
                "solver_status": "FEASIBLE",
                "mip_gap": "1.2",
                "comparison_admissible": "0",
            }
        )

    stability_path = tables_dir / "decision_stability_summary.csv"
    with stability_path.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(
            handle,
            fieldnames=[
                "anchor_run_id",
                "instance_id",
                "source_experiment",
                "strategy",
                "tax_rate",
                "cap_level",
                "probes_completed",
                "minimum_buffer_jaccard_similarity",
                "minimum_supplier_jaccard_similarity",
                "maximum_allocation_l1_normalized",
                "maximum_objective_degradation_pct",
            ],
        )
        writer.writeheader()
        writer.writerow(
            {
                "anchor_run_id": "TAX-bom_5-50.00",
                "instance_id": "bom_5",
                "source_experiment": "carbon_tax_sweep",
                "strategy": "EMISTAXE",
                "tax_rate": "50",
                "cap_level": "none",
                "probes_completed": "3",
                "minimum_buffer_jaccard_similarity": "0.75",
                "minimum_supplier_jaccard_similarity": "0.33",
                "maximum_allocation_l1_normalized": "1.11",
                "maximum_objective_degradation_pct": "0.8",
            }
        )

    subprocess.run([sys.executable, str(generator), str(results_dir)], check=True)
    table = (results_dir / "tables_tex" / "tab_hybrid.tex").read_text(encoding="utf-8")

    assert "INFEASIBLE" in table
    assert "Status" in table
    assert " -- " in table
    tax_table = (results_dir / "tables_tex" / "tab_tax_sweep.tex").read_text(encoding="utf-8")
    assert "$EmisTax=50$" in tax_table
    assert "$EmisTax=100$" not in tax_table
    stability_table = (results_dir / "tables_tex" / "tab_decision_stability.tex").read_text(
        encoding="utf-8"
    )
    assert "Near-optimal decision-degeneracy diagnostic" in stability_table
    assert "0.33" in stability_table

print("Article table reporting and comparison-admissibility tests passed.")
