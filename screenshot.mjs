import puppeteer from "puppeteer";
import fs from "node:fs";
import path from "node:path";

const url = process.argv[2] || "http://localhost:3000";
const label = process.argv[3];
const viewport = process.argv[4] || "desktop";

const dir = "./temporary screenshots";
if (!fs.existsSync(dir)) fs.mkdirSync(dir);

let n = 1;
while (fs.existsSync(path.join(dir, `screenshot-${n}${label ? "-" + label : ""}.png`))) n++;
const fileName = `screenshot-${n}${label ? "-" + label : ""}.png`;

const sizes = {
  desktop: { width: 1440, height: 900 },
  mobile: { width: 390, height: 844 },
};

const browser = await puppeteer.launch({ args: ["--autoplay-policy=no-user-gesture-required"] });
const page = await browser.newPage();
await page.setViewport(sizes[viewport] || sizes.desktop);
await page.goto(url, { waitUntil: "networkidle0" });
await new Promise((r) => setTimeout(r, 2000));
await page.screenshot({ path: path.join(dir, fileName), fullPage: true });
await browser.close();
console.log(`Saved ${path.join(dir, fileName)}`);
