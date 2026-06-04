import pandas as pd

from pathlib import Path
import sys


repo = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(repo / "src"))

from GraphGenerator import GraphGenerator


explicit = pd.DataFrame(
    [
        {"solver_status": "OPTIMAL", "mip_gap": 0.0, "comparison_admissible": 1},
        {"solver_status": "FEASIBLE", "mip_gap": 0.8, "comparison_admissible": 1},
        {"solver_status": "FEASIBLE", "mip_gap": 1.2, "comparison_admissible": 0},
        {"solver_status": "INFEASIBLE", "comparison_admissible": 0},
    ]
)
assert len(GraphGenerator.comparison_admissible(explicit)) == 2

legacy = pd.DataFrame(
    [
        {"solver_status": "OPTIMAL", "mip_gap": 0.0},
        {"solver_status": "FEASIBLE", "mip_gap": 1.0},
        {"solver_status": "FEASIBLE", "mip_gap": 1.01},
        {"solver_status": "FEASIBLE", "mip_gap": None},
    ]
)
admissible = GraphGenerator.comparison_admissible(legacy)
assert len(admissible) == 2
assert admissible["mip_gap"].tolist() == [0.0, 1.0]

print("Graph comparison-admissibility tests passed.")
