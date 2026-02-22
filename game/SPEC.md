# DDMRP Supply Chain Optimizer - Game Spec

**Target URL:** `swiftediflow.com/DDMRP`
**Format:** Single-page static HTML/CSS/JS (no build step, no backend)
**Audience:** Supply-chain professionals, academics, and students
**Tone:** Serious game - professional, data-rich, visually clean
**Style:** Use hyphen with spaces ( - ) for breaks; do not use em dash (—).

---

## 1. Game Loop (10 Steps)

1. **Welcome modal** - 30-second explainer: "You are a supply-chain manager. Choose suppliers and position DDMRP buffers to minimize total cost AND carbon emissions under a carbon policy."
2. **Player picks a scenario** - Selects BOM size (Small / Medium / Large) and carbon policy (Tax / Cap / Hybrid). This sets the difficulty and the target thresholds.
3. **Round starts** - A simplified Bill of Materials (BOM) tree appears on screen (5, 8, or 12 nodes depending on size). Each node represents a component. A 90-second countdown begins.
4. **Supplier selection phase** - For each component node, player clicks to cycle through 2–3 supplier options shown in a side panel. Each supplier card shows: unit cost, lead time, and CO₂ intensity (kg CO₂ / unit). Selecting a supplier immediately updates the live dashboard.
5. **Buffer positioning phase** - Player clicks nodes to toggle them as DDMRP buffer positions (highlighted ring). Buffers decouple lead time but add holding cost. Dashboard updates instantly.
6. **Policy constraint check** - A colored bar shows whether current emissions satisfy the active carbon policy. Red = violation, amber = marginal, green = compliant. If Tax policy: a cost penalty is added. If Cap policy: the bar blocks submission if emissions exceed the cap.
7. **Submit round** - Player clicks "Submit Plan." If policy constraints are violated under Cap/Hybrid, a rejection pop-up explains why. Otherwise, the system calculates final KPIs.
8. **Results animation** - Animated counters roll to show Total Cost, Total Emissions, Days Inventory Outstanding (DIO), and a comparison to the "optimal" (pre-computed) solution. A percentage gap is highlighted.
9. **Evaluation** - Stars (1–5), a letter grade, and a badge are awarded based on scoring rules (see §5). A "Manager Approval Meter" fills proportionally. Pop-up shows personalized feedback.
10. **Replay or advance** - Player can retry the same scenario to improve, or move to the next difficulty tier. Cumulative score persists across rounds (session only, no server).

---

## 2. UI Wireframe (Text)

```
┌──────────────────────────────────────────────────────────────────┐
│  HEADER BAR                                                      │
│  [SwiftediFlow logo]   DDMRP Supply Chain Optimizer   [?] [↻]   │
├────────────────────────────────┬─────────────────────────────────┤
│                                │  DASHBOARD (right panel)        │
│   BOM TREE (center canvas)     │  ┌───────────────────────────┐  │
│                                │  │ Total Cost     $342,100 ▼ │  │
│       ┌───[FG]───┐             │  │ Emissions   12.4M kg CO₂ ▼│  │
│       │          │             │  │ DIO              48 days ▼│  │
│    [C1]●      [C2]○           │  │ Buffers Active       3 / 8│  │
│     │            │             │  │ Policy Status   ██████░░  │  │
│   [C3]○       [C4]●           │  └───────────────────────────┘  │
│     │                          │                                 │
│   [RM1]○                      │  SUPPLIER PANEL (below dash)    │
│                                │  ┌───────────────────────────┐  │
│  ● = buffer    ○ = pass-thru  │  │ Component: C1             │  │
│                                │  │ ┌─────┐ ┌─────┐ ┌─────┐  │  │
│  (click node to select it;     │  │ │Sup A│ │Sup B│ │Sup C│  │  │
│   click again to toggle buffer)│  │ │$12  │ │$9   │ │$14  │  │  │
│                                │  │ │4 day│ │7 day│ │3 day│  │  │
│                                │  │ │Low ☁│ │Hi ☁ │ │Med ☁│  │  │
│                                │  │ └──✓──┘ └─────┘ └─────┘  │  │
│                                │  └───────────────────────────┘  │
├────────────────────────────────┴─────────────────────────────────┤
│  BOTTOM BAR                                                      │
│  ⏱ 0:47  │ Round 1 of 3 │ Score: 2,340 │ ★★★☆☆  │ [Submit Plan] │
└──────────────────────────────────────────────────────────────────┘
```

### Key UI elements

| Element | Location | Purpose |
|---------|----------|---------|
| BOM tree canvas | Center | Interactive node graph; click to select component or toggle buffer |
| Dashboard | Right panel, top | Live KPI gauges (cost, emissions, DIO, buffer count, policy bar) |
| Supplier panel | Right panel, bottom | Shows supplier cards for the currently selected component |
| Timer | Bottom bar, left | 90-second countdown per round |
| Score strip | Bottom bar, center | Running score + star rating |
| Submit button | Bottom bar, right | Locks in decisions; triggers evaluation |
| Help (?) | Header, right | Opens tutorial overlay with step-by-step walkthrough |
| Reset (↻) | Header, right | Restarts current round |

---

## 3. Core Metrics and How They Update

### 3.1 Total Cost ($)

```
TotalCost = Σ (unit_cost[supplier_i] × demand[i])
           + Σ (holding_cost × buffer_stock[i])      ← only for buffered nodes
           + carbon_tax × total_emissions             ← only under Tax / Hybrid policy
```

- **Updates:** Instantly on every supplier change or buffer toggle.
- **Display:** Animated counter with green/red delta arrow vs. previous value.

### 3.2 Total Emissions (kg CO₂)

```
TotalEmissions = Σ (co2_intensity[supplier_i] × demand[i])
```

- **Updates:** Instantly on every supplier change.
- **Display:** Gauge that fills from green → amber → red as it approaches the cap.

### 3.3 Policy Compliance

| Policy | Rule | Visual |
|--------|------|--------|
| **Tax** | No hard limit; `tax_rate × emissions` added to cost | Cost bar shows tax portion in orange |
| **Cap** | Emissions must be ≤ cap_value; submission blocked otherwise | Red/green bar + lock icon on Submit |
| **Hybrid** | Cap applies AND remaining emissions taxed | Both visuals combined |

### 3.4 Days Inventory Outstanding (DIO)

```
DIO = Σ (lead_time[i] for all nodes NOT decoupled by a downstream buffer)
      × (avg_inventory_factor)
```

- **Updates:** On buffer toggle (adding a buffer shortens effective lead-time upstream).
- **Display:** Horizontal bar; lower is better.

### 3.5 Buffer Count

- Simple count of toggled buffer nodes.
- Displayed as `active / total_nodes`.
- Each buffer adds holding cost but decouples variability.

### 3.6 Optimality Gap (%)

```
Gap = ((player_cost - optimal_cost) / optimal_cost) × 100
```

- Shown only after submission, during the results animation.
- Optimal values are **pre-computed and embedded** in the JS data for each scenario.

---

## 4. Tooltip & Pop-up Plan

### 4.1 Hover Tooltips (appear on mouseover, 200ms delay)

| Trigger | Tooltip Content |
|---------|----------------|
| BOM node (unbuffered) | "**Component C3** - Demand: 1,200 units/period. Lead time flows through to parent. Click to select, double-click to toggle buffer." |
| BOM node (buffered) | "**Buffer at C1** - Decouples upstream variability. Holding cost: $2,400/period. Reduces effective lead time for downstream nodes." |
| Supplier card | "**Supplier B** - Unit cost $9, Lead time 7 days, CO₂ intensity 18 kg/unit. Cheaper but slower and dirtier." |
| Cost metric | "Total cost includes procurement + buffer holding + any carbon tax penalty." |
| Emissions gauge | "Total CO₂ emitted across all selected suppliers. Must stay below cap in Cap/Hybrid policy." |
| DIO bar | "Days Inventory Outstanding - measures how long inventory sits in your supply chain. Buffers reduce DIO by decoupling." |
| Policy bar | "Your current emissions vs. the policy limit. Green = compliant, Red = violation." |
| Timer | "Time remaining this round. You can still submit after time expires, but you lose the time bonus." |
| Star rating | "Based on cost gap to optimal, emissions compliance, and time bonus. See scoring rules in Help." |

### 4.2 Contextual Pop-ups (modal, triggered by events)

| Trigger | Pop-up Title | Content |
|---------|-------------|---------|
| Game start | "Welcome, Supply Chain Manager" | Brief scenario intro + objectives + "Got it" button. ~60 words. |
| First node click | "Selecting Suppliers" | "Each component needs a supplier. Compare cost, lead time, and CO₂ intensity. There's no single best choice - it's a trade-off." |
| First buffer toggle | "DDMRP Buffers Explained" | "Placing a buffer here decouples upstream variability from downstream demand. It adds holding cost but reduces your DIO and protects service levels." |
| Emissions exceed cap | "⚠ Emissions Over Cap" | "Your current plan emits X kg CO₂, which exceeds the cap of Y. Switch to lower-emission suppliers or the Submit button stays locked." |
| Submit (Cap violated) | "Plan Rejected" | "Your emissions of X exceed the cap of Y. Revise supplier choices to reduce emissions by at least Z." |
| Submit (success) | "Plan Submitted!" | Transitions to results screen with animated KPI reveal. |
| Results revealed | "Performance Review" | Shows stars, gap %, badge, and a 1–2 sentence feedback message tailored to the gap range. |
| All rounds complete | "Campaign Complete" | Summary of all rounds, cumulative score, final badge, and share prompt. |

### 4.3 Inline Micro-tips (non-blocking, bottom-of-screen toast)

- "Tip: Buffers are most effective at points with high lead-time variability." (appears after 20s if no buffer placed)
- "Tip: A Hybrid policy means you face BOTH a cap and a tax." (appears when Hybrid is selected)
- "Tip: Cheaper suppliers often have higher emissions." (appears after selecting the cheapest supplier 3 times in a row)

---

## 5. Evaluation System (Scoring Rules)

### 5.1 Per-Round Score (0–1000 points)

| Component | Weight | Calculation |
|-----------|--------|-------------|
| **Cost optimality** | 40% | `400 × max(0, 1 - gap/50)` where gap = % above optimal cost. Perfect = 400 pts; ≥50% gap = 0 pts. |
| **Emissions compliance** | 30% | Under Tax: `300 × max(0, 1 - emissions/baseline)`. Under Cap: 300 if compliant, 0 if not. Hybrid: weighted blend. |
| **DIO efficiency** | 15% | `150 × max(0, 1 - (player_DIO - optimal_DIO) / optimal_DIO)` |
| **Time bonus** | 15% | `150 × (seconds_remaining / 90)` - full bonus if submitted instantly, 0 if timer expired. |

### 5.2 Star Rating

| Stars | Score Range | Label |
|-------|------------|-------|
| ★☆☆☆☆ | 0–199 | "Needs Improvement" |
| ★★☆☆☆ | 200–399 | "Developing" |
| ★★★☆☆ | 400–599 | "Competent" |
| ★★★★☆ | 600–799 | "Proficient" |
| ★★★★★ | 800–1000 | "Expert Optimizer" |

### 5.3 Manager Approval Meter

- A vertical gauge in the results screen that fills from 0% to 100%.
- `Approval = round_score / 10` (so 0–100%).
- Visual: fills with a gradient (red → amber → green).
- At 80%+, a "Manager Approved ✓" stamp animates onto the screen.

### 5.4 Badges (awarded once per session)

| Badge | Condition | Icon concept |
|-------|-----------|-------------|
| **Green Champion** | Achieved ≤ 70% of baseline emissions in any round | Leaf icon |
| **Cost Hawk** | Achieved ≤ 5% cost gap to optimal in any round | Dollar-target icon |
| **Buffer Master** | Matched exact optimal buffer placement in any round | Shield icon |
| **Speed Demon** | Submitted with ≥ 60 seconds remaining and scored ≥ 600 | Lightning icon |
| **Triple Threat** | Earned 4+ stars on all 3 difficulty tiers | Trophy icon |

### 5.5 Cumulative Score

- Sum of all round scores across the session.
- Displayed in the bottom bar and on the final "Campaign Complete" screen.
- No persistence beyond the browser session (no cookies, no server).

---

## 6. Non-Goals (Explicitly Excluded)

| Excluded Feature | Rationale |
|-----------------|-----------|
| **Backend / API** | Must be static files only; no server logic. |
| **User accounts / login** | No authentication needed; zero friction. |
| **Database / persistence** | Session-only state; no cookies or localStorage required. |
| **Real CPLEX solver** | Optimal values are **pre-computed and hardcoded** in a JS data object. |
| **Multi-player / leaderboard** | Adds server dependency; out of scope. |
| **Mobile-first responsive** | Desktop-first; basic tablet support is nice-to-have but not required. |
| **Accessibility (WCAG AAA)** | Target WCAG AA (sufficient contrast, keyboard nav, aria labels). Full AAA is out of scope. |
| **Internationalization (i18n)** | English only. |
| **Sound effects / music** | Keep it silent and professional. |
| **Complex BOM editing** | Player cannot modify the BOM structure - only select suppliers and toggle buffers. |
| **Stochastic / probabilistic simulation** | All data is deterministic; no Monte Carlo or demand variability simulation. |
| **Print / PDF export** | Results are on-screen only. |

---

## 7. Technical Stack

| Layer | Choice | Rationale |
|-------|--------|-----------|
| **Markup** | Single `index.html` | Simplest deployment; one file to upload. |
| **Styling** | Embedded `<style>` or one `style.css` | Clean, professional look; CSS variables for theming. |
| **Logic** | Vanilla JS (ES6) in `<script>` or one `game.js` | No framework dependency; runs everywhere. |
| **Graph rendering** | SVG in-DOM (hand-coded or tiny lib like `dagre-d3` via CDN) | BOM tree visualization; interactive nodes. |
| **Animations** | CSS transitions + `requestAnimationFrame` for counters | Smooth, "optimization feel" without heavy libs. |
| **Data** | Embedded JSON object in JS | Pre-computed optimal values, supplier data, BOM structures. |
| **Deployment** | Upload `index.html` + `style.css` + `game.js` to `swiftediflow.com/DDMRP/` | Works on any static host including NetworkSolutions. |

---

## 8. Data Model (Embedded in JS)

```javascript
const SCENARIOS = {
  small: {
    nodes: [
      { id: "FG", name: "Finished Good", demand: 1000, canBuffer: false, children: ["C1","C2"] },
      { id: "C1", name: "Sub-Assembly A", demand: 1000, canBuffer: true, children: ["RM1"] },
      { id: "C2", name: "Sub-Assembly B", demand: 1000, canBuffer: true, children: [] },
      { id: "RM1", name: "Raw Material 1", demand: 2000, canBuffer: true, children: [] }
      // ... 5 nodes total
    ],
    suppliers: {
      "C1": [
        { name: "AlphaSteel",  cost: 12, leadTime: 4, co2: 8  },
        { name: "BetaAlloy",   cost: 9,  leadTime: 7, co2: 18 },
        { name: "GammaMetal",  cost: 14, leadTime: 3, co2: 5  }
      ],
      // ... per component
    },
    policies: {
      tax:    { rate: 0.02 },
      cap:    { limit: 0.70 },       // 70% of baseline
      hybrid: { rate: 0.01, limit: 0.80 }
    },
    optimal: {                        // pre-computed reference
      cost: 298400,
      emissions: 1950000,
      dio: 42,
      buffers: ["C1", "RM1"]
    }
  },
  medium: { /* 8 nodes */ },
  large:  { /* 12 nodes */ }
};
```

---

## 9. File Manifest (Deliverables)

```
DDMRP/
├── index.html      ← entry point; includes meta tags, structure
├── style.css       ← all styling; CSS variables; responsive basics
├── game.js         ← game logic, data, rendering, evaluation
└── assets/
    └── logo.svg    ← SwiftediFlow logo (optional)
```

Total: **3–4 files**, all static, all under 200 KB combined.
