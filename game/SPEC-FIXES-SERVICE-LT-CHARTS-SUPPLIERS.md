# Specification: Service LT, Optimized Curves, and Optimal Supplier Marking

**Scope:** Analysis and specification only (no code edits).  
**Role:** Algorithm auditor (DDMRP/service LT), state-machine spec writer, UX micro-interaction designer.

---

## A) SERVICE LEAD TIME — Correct Definition & Algorithm

### A.1) Current Implementation (Inferred)

- **`computeDltMap(nodeIds, childrenMap, leadByNode, buffered)`** (app.js ~382–399) computes a single DLT per node via DFS from each node:
  - For node `id`: `memo[id] = leadByNode[id] + max{ dfs(child) : child in children(id), ¬buffered[child] }`.
  - So it **skips buffered children** when taking the max. Effect: at each node we get “my lead time + longest path **from this node downstream** (into the BOM) that does **not** cross a buffer.”
- **Service lead time** is set in `calculateTotals` (~462–463):
  - `rawLead = rawDltByNode[fgId]` (no buffers).
  - `serviceLead = bufferedFlags[fgId] ? 0 : (effDltByNode[fgId] || 0)`.

So the code currently computes: **longest path from FG going downstream (toward components) that stops at buffered nodes**. That is “FG’s segment” in the sense of “FG lead + longest non-buffered chain below FG.” It does **not** compute “longest **remaining** lead time **from** the nearest upstream decoupling point **to** FG.”

### A.2) Correct Definition (Game Rules)

- **If FG is buffered:** `serviceLT = 0`.
- **Else:** `serviceLT =` longest remaining lead time from the nearest upstream decoupling point(s) to FG.
  - A **decoupling point** is any node with a buffer positioned.
  - “Remaining” = from that decoupling point **to** FG (inclusive).
  - So we need: for every path that ends at FG, find the nearest decoupling point on that path (closest to FG), then sum effective lead times from that point to FG; take the **max** over all such segments.

**Requirement:** Buffer toggles must recompute `serviceLT` deterministically and immediately (no async, no stale value).

### A.3) Correct Algorithm (DAG, Parent-Based, With Buffer Boundaries)

**Conventions:**

- BOM edges: `{ parent, child }` — parent consumes child (e.g. FG → SUB → P). So “upstream” from FG = following edges from child to parent (components → FG).
- **effLT[i]** = effective lead time of node `i`:
  - SUB/FG: internal `leadTime`.
  - PURCHASED: `node.leadTime + max{ supplier.leadTime }` over selected (or optimal) suppliers.
- **pathToFG[i]** = sum of effLT along the path from node `i` to FG, **with reset at buffers**: when we cross a buffered node we do **not** add that node’s contribution (so the segment “from decoupling point to FG” is the accumulation from a buffered node up to FG).

**Single reset rule (chosen):**

- **At a buffered node:** the segment that **starts** at this node and goes to FG is: this node’s effLT plus the path to FG **without** adding any **parent’s** path (so the segment starts here).
- **At a non-buffered node:** the segment from this node to FG = this node’s effLT + (parent’s segment to FG if parent is not buffered; else 0).

So:

- `pathToFG[i] = effLT[i] + (parent[i] exists and ¬buffered[i] ? pathToFG[parent[i]] : 0)`.

Then:

- **serviceLT** = if `buffered[fgId]` then `0`, else `max{ pathToFG[i] : i in nodes }`.

**Justification:** A buffered node is a decoupling point: upstream lead time is absorbed there. So “remaining” LT to FG is only from that node to FG. By not adding the parent’s path when the **current** node is buffered, we make `pathToFG[bufferedNode]` = effLT of that node plus path from that node to FG (we do add parent when current is not buffered, so the chain builds correctly from leaves to FG). Taking max over all nodes gives the longest such segment; if no buffer exists on a path, the whole path from leaf to FG is one segment and is still captured.

**Data structures:**

- `parent[id]`: from edges, `parent[e.child] = e.parent`. FG has no parent.
- Process nodes in **topological order from root (FG) downward** so that when we compute `pathToFG[i]`, `pathToFG[parent[i]]` is already computed (FG first, then its children, then their children, etc.).

**Pseudocode:**

```
function pathToFGMap(nodeIds, parentMap, effLT, buffered, fgId):
  pathToFG = {}
  // Topological order: FG first, then nodes whose parent is already processed (BFS from FG)
  order = BFS_from_root(nodeIds, parentMap, fgId)  // fgId is root; order goes FG, then children of FG, etc.
  for i in order:
    if i == fgId:
      pathToFG[i] = effLT[i]
    else:
      p = parentMap[i]
      pathToFG[i] = effLT[i] + (p != null ? pathToFG[p] : 0)
  return pathToFG

function serviceLeadTime(pathToFG, buffered, fgId):
  if buffered[fgId]:
    return 0
  if any(buffered[i] for i in nodeIds):
    return max{ pathToFG[B] : B in nodeIds, buffered[B] }
  return max{ pathToFG[i] : i in nodeIds }
```

**Note:** We need a BFS that starts from FG and visits nodes “down” the graph (from FG to its children, then their children). So we need a **children** map from edges: `children[p].push(c)` for each edge (p, c). Then BFS_from_root = BFS starting at fgId using the children map gives the desired order (FG first, then all descendants level by level). The pseudocode uses full path (no reset) and serviceLT = max over buffered nodes when any buffer exists, else max over all nodes.

### A.4) Small Example BOM

- Nodes: FG (effLT=4), SUB (effLT=3), P (effLT=5). Edges: FG→SUB, SUB→P. So parent[SUB]=FG, parent[P]=SUB.
- **Case 1:** No buffers. pathToFG[FG]=4, pathToFG[SUB]=3+4=7, pathToFG[P]=5+7=12. serviceLT = 12.
- **Case 2:** SUB buffered. pathToFG[FG]=4, pathToFG[SUB]=3+4=7 (we don’t add parent because SUB is buffered—segment starts at SUB), pathToFG[P]=5+7=12. Max over buffered (SUB only)=7. serviceLT = 7.
- **Case 3:** FG buffered. serviceLT = 0 (by rule).

### A.5) Validation Checklist and Invariants

- **Example (2 levels, 2 buffer placements):**  
  FG (4) → A (3) → M (6); FG → B (2) → N (5).  
  - No buffers: pathToFG[FG]=4, A=3+4=7, M=6+7=13, B=2+4=6, N=5+6=11 → serviceLT = 13.  
  - Buffer at A only: pathToFG for A=7, M=13, B=6, N=11; max over buffered (A only)=7 → serviceLT = 7.  
  - Buffer at FG: serviceLT = 0.

- **Invariants:**
  1. If FG buffered ⇒ serviceLT = 0.
  2. Adding a buffer (anywhere) should never **increase** serviceLT (monotonicity).
  3. If no buffers ⇒ serviceLT equals the unbuffered critical-path lead time (longest path from any node to FG).

---

## B) OPTIMIZED CURVES NOT SHOWING — State & Rendering Spec

### B.1) Likely Causes (Diagnosis)

1. **Single-point optimized series:** On each “Optimize” click the code pushes **one** value to `state.history.optimizedCost` and `state.history.optimizedEmissions`. An SVG `<polyline>` with a single point draws no line segment, so the “optimized” curve is effectively invisible.
2. **Scale/visibility:** Min/max are computed over the union of current and optimized series, so a single optimized point can sit on the baseline or at an edge and look like a dot or be clipped.
3. **Elements exist:** `#costLineOptimized` and `#emissionsLineOptimized` are present in HTML and referenced in `el`; they are set in `updateCharts()`. So the main issue is **series length** (and possibly ensuring both series are always drawn when optimized data exists).

### B.2) Required Chart Semantics

- **Two independent time series:**
  - **seriesCurrent:** cost and emissions arrays updated only on **value-changing actions** (supplier, buffer, netflow, demand, period, apply_optimized, round_start). Not updated on “Optimize” click.
  - **seriesOptimized:** updated on “Optimize” click (append one point per run). Option: either “append once and stay” or “append and keep last N” (e.g. 14); spec chooses **append and cap at 14** to mirror current.
- **Rendering:** The chart renderer must **always draw both** polylines when the corresponding series have data. For the optimized series to be visible as a **line**, it must have at least **2 points** (e.g. repeat the single optimized value across the current x-axis length, or keep a short history of optimized points).
- **Legend:** Show both “Current” and “Opt” (already in HTML/CSS).

### B.3) Minimal Data Structure and Rendering Logic

**State (unchanged shape, clarified semantics):**

```text
state.history = {
  cost: number[],           // current config cost per value-changing step (max 14)
  emissions: number[],      // current config emissions per value-changing step (max 14)
  optimizedCost: number[], // one entry per Optimize click (max 14)
  optimizedEmissions: number[]
}
```

**Rendering rules:**

1. **Current series:** `points = pts(state.history.cost, costMin, costMax)` and same for emissions. No change.
2. **Optimized series:**  
   - If `state.history.optimizedCost.length === 0`, set `points=""` (and optionally hide the polyline or keep it empty).  
   - If `state.history.optimizedCost.length >= 1`, then for drawing the polyline use a **normalized length** so the line is visible:  
     - Let `n = state.history.cost.length` (or 1 if 0).  
     - Build an array `optimizedCostDraw` of length `max(2, n)` by either:  
       - Repeating the **last** optimized value to fill `max(2, n)` points, or  
       - Using the actual `optimizedCost` history if length ≥ 2.  
     - Then `points = pts(optimizedCostDraw, costMin, costMax)`. Same for emissions.  
   So the optimized curve is either a flat line at the last optimized value (spanning the same x-range as current) or a multi-point trend if multiple Optimize runs are kept.
3. **Min/Max for scale:** Keep using the union of current and optimized arrays so both series share the same scale and remain visible.
4. **When optimized is cleared** (e.g. after “Apply optimized setup” or buffer/supplier change): `optimizedCost` and `optimizedEmissions` are cleared; optimized polylines get `points=""` and no longer draw.

**Unambiguous spec:**

- **seriesCurrent:** `state.history.cost`, `state.history.emissions`; updated only when `reason` is in `VALUE_CHANGE_REASONS` (and not `optimize_action`).
- **seriesOptimized:** `state.history.optimizedCost`, `state.history.optimizedEmissions`; updated only in the Optimize click handler (append one value, cap at 14).
- **Draw both** in `updateCharts()`: current from `state.history.cost` / `state.history.emissions`; optimized from the same arrays, but when building `pts(..., min, max)` for optimized, use a **draw array** of length `max(2, state.history.cost.length)` by repeating the last optimized value so the polyline has at least two points and renders as a line.

---

## C) OPTIMAL SUPPLIER SELECTION DISCLOSURE — UI Behavior Spec

### C.1) Requirement

- When the user selects a **purchased** node, the supplier list shall show which supplier(s) are part of the **optimized** solution.
- Add a visual mark (e.g. check icon) in the “optimal” styling (same as optimized KPIs: e.g. orange/opt color).
- Support **multi-sourcing:** if the optimized solution selects `n` suppliers for that node, show the mark on each selected supplier row and show quota “Opt: 1/n” (e.g. “Opt: 50%”, “Opt: 33%”).

**Constraints:**

- Do **not** change the user’s current selection; this is a recommendation marker only.
- If `state.optimized` is null (optimized run not done or cleared), no optimal marks appear.
- If the selected node is not purchased, the supplier list is hidden; no supplier marks apply.

### C.2) Data Structure for Optimal Supplier Choice

- **Primary:** Use existing optimizer output. `state.optimized.config.supplierAssignments[nodeId]` is an array of supplier IDs chosen by the optimizer for that node. No new key is strictly required.
- **Optional (for quota display):** Store quotas if the optimizer ever supports non-equal split; for equal split, quota = `1 / selectedSuppliersByNode[nodeId].length`. So:
  - `optimizedConfig.selectedSuppliersByNode[nodeId]` can be defined as an alias for `optimizedConfig.supplierAssignments[nodeId]` (same array).
  - Optional: `optimizedConfig.supplierQuotaByNode[nodeId]` = `1 / supplierAssignments[nodeId].length` for display (“Opt: 33%”).

**Spec:** `state.optimized.config.supplierAssignments` is the source of truth. For a purchased node `nodeId`, optimal supplier IDs = `state.optimized?.config?.supplierAssignments?.[nodeId] ?? []`. Quota for display = `1 / max(1, optimalIds.length)` (e.g. 50% for 2, 33% for 3).

### C.3) UI Rules (Micro-Interaction)

1. **When to show optimal marks:** Only when `state.optimized != null` and the selected node is purchased. If either condition fails, render the supplier list without any “opt” marks.
2. **Per row:** For each supplier row in the list for the selected node:
   - If that supplier’s `id` is in `state.optimized.config.supplierAssignments[selectedNodeId]`, show:
     - A check icon (or equivalent) with the same styling as optimized KPIs (e.g. orange / “opt” color).
     - Optional text: “Opt” or “Opt: &lt;quota&gt;” (e.g. “Opt: 50%”) when multi-sourcing.
   - Do not change the checkbox state (user selection); only add a visual “optimal” indicator.
3. **Styling:** Reuse the same class or color used for optimized KPI values (e.g. `.metric-value-opt`, orange) for the check and “Opt” label so the optimal solution is visually consistent.
4. **Accessibility:** Ensure the “optimal” indicator has an accessible name (e.g. “Part of optimized solution” or “Optimal choice”).

### C.4) Where to Store (Recap)

- **Required:** `state.optimized.config.supplierAssignments[nodeId]` = array of supplier IDs for that node in the optimal solution. Already provided by `runOptimizer`; no new field required.
- **Optional:** Store `optimizedConfig.supplierQuotaByNode[nodeId] = 1/n` for display; otherwise compute as `1 / supplierAssignments[nodeId].length` in the UI.

---

## DELIVERABLES SUMMARY

1. **Service LT:** Correct definition (FG buffered ⇒ 0; else max path from decoupling points to FG), parent-based `pathToFG` with buffer reset, topological order, single reset rule, example BOM, and validation invariants.
2. **Charts:** Two independent series (current vs optimized), optimized series drawn with at least 2 points (repeat last value if needed), same scale, both drawn when data exist; clear semantics for when each series is updated.
3. **Optimal suppliers:** Use `state.optimized.config.supplierAssignments[nodeId]`; show check + optional “Opt: 1/n” on supplier rows for the selected purchased node when `state.optimized` exists; no change to user selection; styling aligned with optimized KPIs.

---

## ACCEPTANCE CRITERIA (Checklist)

1. **Service LT:** With FG buffered, serviceLT is 0.
2. **Service LT:** With no buffers, serviceLT equals the unbuffered critical-path lead time (longest path to FG).
3. **Service LT:** Adding a buffer anywhere never increases serviceLT.
4. **Service LT:** Buffer toggle on any node causes serviceLT to recompute immediately and deterministically.
5. **Service LT:** Example BOM (e.g. FG→A→M, FG→B→N) with two buffer placements yields the specified values (e.g. no buffer ⇒ 13; buffer at A ⇒ 11; buffer at FG ⇒ 0).
6. **Charts:** After “Optimize,” the Cost trend chart shows both the current and the optimized curve (optimized visible as a line, not a single point).
7. **Charts:** After “Optimize,” the Emissions trend chart shows both the current and the optimized curve.
8. **Charts:** Current curve does not change when clicking “Optimize” (only optimized series is updated).
9. **Charts:** Legend shows “Current” and “Opt” and both series use the same min/max scale when both have data.
10. **Suppliers:** When a purchased node is selected and an optimized run exists, each supplier row that is in the optimal solution shows a check (or equivalent) in the optimal color.
11. **Suppliers:** Multi-sourcing: if the optimal solution has n suppliers for that node, each of the n rows shows the mark and optionally “Opt: 1/n” (e.g. “Opt: 50%”).
12. **Suppliers:** Optimal marks do not change the user’s checkbox selection; they are visual only.
13. **Suppliers:** When no optimized run exists (`state.optimized == null`), no optimal marks appear on supplier rows.
14. **Suppliers:** When the selected node is not purchased, the supplier list is hidden and no supplier marks are shown.
15. **End-to-end:** Apply optimized setup clears optimized result and hides optimized KPIs and curves; supplier list no longer shows optimal marks until “Optimize” is run again.
