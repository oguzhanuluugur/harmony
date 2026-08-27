# CLAUDE.md — Frontend Website Guidelines

## First Step (Always)

- **Run the `frontend-design` skill** before writing any frontend code, every session, without exception.

## Reference Images

- If a reference image is provided: replicate layout, spacing, typography, and color precisely. Use placeholder content (images via `https://placehold.co/`, generic text). Do not enhance or extend the design.
- If no reference image: design from scratch with high quality (follow guardrails below).
- Screenshot your output, compare it against the reference, fix any mismatches, re-screenshot. Do a minimum of 2 comparison rounds. Stop only when no visible differences remain or the user confirms.

## Local Server

- **Always serve on localhost** — never screenshot a `file:///` URL.
- Start the dev server: `node serve.mjs` (serves the project root at `http://localhost:3000`)
- `serve.mjs` is located in the project root. Start it in the background before taking any screenshots.
- If the server is already running, do not launch a second instance.

## Screenshot Workflow

- Puppeteer is installed at `C:/Users/nateh/AppData/Local/Temp/puppeteer-test/`. Chrome cache is at `C:/Users/nateh/.cache/puppeteer/`.
- **Always screenshot from localhost:** `node screenshot.mjs http://localhost:3000`
- Screenshots are saved automatically to `./temporary screenshots/screenshot-N.png` (auto-incremented, never overwritten).
- Optional label suffix: `node screenshot.mjs http://localhost:3000 label` → saves as `screenshot-N-label.png`
- `screenshot.mjs` is located in the project root. Use it as-is.
- After screenshotting, read the PNG from `temporary screenshots/` with the Read tool — Claude can see and analyze the image directly.
- When comparing, be precise: "heading is 32px but reference shows ~24px", "card gap is 16px but should be 24px"
- Verify: spacing/padding, font size/weight/line-height, colors (exact hex), alignment, border-radius, shadows, image sizing

## Output Defaults

- Single `index.html` file, all styles inline, unless the user specifies otherwise
- Tailwind CSS via CDN: `<script src="https://cdn.tailwindcss.com"></script>`
- Placeholder images: `https://placehold.co/WIDTHxHEIGHT`
- Mobile-first responsive design

## Brand Assets

- Always check the `brand_assets/` folder before starting the design. It may contain logos, color guides, style guides, or images.
- If assets exist, use them. Do not use placeholders where real assets are available.
- If a logo is present, use it. If a color palette is defined, apply those exact values — do not invent brand colors.

## Anti-Generic Guardrails

- **Colors:** Never use default Tailwind palette values (indigo-500, blue-600, etc.). Choose a custom brand color and derive shades from it.
- **Shadows:** Never use flat `shadow-md`. Use layered, color-tinted shadows with low opacity.
- **Typography:** Never use the same font for headings and body text. Pair a display/serif font with a clean sans-serif. Apply tight tracking (`-0.03em`) on large headings, generous line-height (`1.7`) on body copy.
- **Gradients:** Layer multiple radial gradients. Add grain/texture via SVG noise filter for depth.
- **Animations:** Only animate `transform` and `opacity`. Never use `transition-all`. Use spring-style easing curves.
- **Interactive states:** Every clickable element must have hover, focus-visible, and active states. No exceptions.
- **Images:** Add a gradient overlay (`bg-gradient-to-t from-black/60`) and a color treatment layer using `mix-blend-multiply`.
- **Spacing:** Use intentional, consistent spacing tokens — avoid random Tailwind steps.
- **Depth:** Surfaces should follow a layering system (base → elevated → floating), not all sit at the same z-plane.

## Hard Rules

- Do not add sections, features, or content not present in the reference
- Do not "improve" a reference design — replicate it
- Do not stop after a single screenshot pass
- Do not use `transition-all`
- Do not use default Tailwind blue/indigo as the primary color
