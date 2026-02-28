# DDMRP Supply Chain Optimizer (Game)

Serious game for supply-chain professionals: choose suppliers and position DDMRP buffers to minimize cost and emissions.

## Quick start

```bash
cd game
npm install
npm run build
npm run preview
```

Then open http://localhost:8080

## Scripts

| Command | Description |
|---------|-------------|
| `npm run build` | Bundle app to `dist/game.js` (required before opening) |
| `npm run dev` | Watch mode: rebuild on file changes |
| `npm run preview` | Serve static files on port 8080 |
| `npm test` | Run Vitest tests |

## Structure

- `index.html` - Entry point
- `app.js` - Main logic (imports from `src/`)
- `src/data.js` - BOM scenarios, supplier library, constants
- `src/ddmrp.js` - Pure DDMRP math (computeBuffers, explodeBomDemand, etc.)
- `src/state.js` - Game state and helpers
- `src/engine.js` - calculateTotals, computeScore, optimizer
- `tooltips.json` - Tooltip content (used via `data-tooltip-id`)
- `tests/` - Vitest unit tests

## Test mode

Add `?test=1` to the URL to show the DDMRP validity panel and run deterministic tests.
