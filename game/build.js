#!/usr/bin/env node
import * as esbuild from "esbuild";
import { existsSync, mkdirSync } from "fs";
import { dirname, join } from "path";
import { fileURLToPath } from "url";

const __dirname = dirname(fileURLToPath(import.meta.url));
const outDir = join(__dirname, "dist");
const outFile = join(outDir, "game.js");

if (!existsSync(outDir)) mkdirSync(outDir, { recursive: true });

const isWatch = process.argv.includes("--watch");

const ctx = await esbuild.context({
  entryPoints: [join(__dirname, "app.js")],
  bundle: true,
  format: "iife",
  outfile: outFile,
  target: ["es2020"],
  minify: !isWatch,
  sourcemap: isWatch
});

if (isWatch) {
  await ctx.watch();
  console.log("Watching... built to", outFile);
} else {
  await ctx.rebuild();
  console.log("Built to", outFile);
  ctx.dispose();
}
