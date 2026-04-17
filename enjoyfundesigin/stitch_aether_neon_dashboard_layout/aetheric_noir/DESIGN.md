# Design System Specification: Immersive Cyber-Editorial

## 1. Overview & Creative North Star
**Creative North Star: The Neon Curator**
This design system moves beyond the generic "dark mode" by blending the high-energy aesthetics of cyberpunk neon with the sophisticated precision of editorial typography. It is designed to feel like a premium digital cockpit—advanced, intelligent, and deeply immersive.

Rather than relying on traditional boxed layouts, this system utilizes **Tonal Layering** and **Atmospheric Depth**. By leveraging glassmorphism and subtle light leaks, we create a UI that doesn't just sit on the screen but feels like a physical environment of glowing light and frosted glass. We break the grid through intentional asymmetry, allowing content to breathe and "float" within a structured yet fluid digital space.

---

## 2. Colors & Surface Logic
The palette is rooted in the deep void of `surface` (#0B0F19), punctuated by hyper-vibrant energy source colors.

### The "No-Line" Rule
To achieve a high-end feel, **prohibit the use of 1px solid borders for sectioning.** Boundaries must be defined through:
1.  **Background Shifts:** Use `surface-container-low` against the `background` to define regions.
2.  **Tonal Transitions:** Use gradients like `from-cyan-500/10 to-purple-500/10` to highlight card backgrounds without structural lines.

### Surface Hierarchy (The Stacking Principle)
Treat the UI as a series of nested layers.
*   **Lowest:** `surface-container-lowest` (#0a0e18) for the main viewport background.
*   **Base:** `surface` (#0f131d) for the primary content areas.
*   **Elevated:** `surface-container-high` (#262a35) for floating interactive elements.
*   **The Glass Rule:** Use `bg-slate-900/50` with `backdrop-blur-md` for modals and overlays. This "frosted glass" effect allows the underlying neon glows to bleed through, softening the interface.

### Signature Textures
*   **Neon Shadows:** Use `shadow-[0_0_15px_rgba(0,240,255,0.15)]` for primary CTAs. The shadow should feel like an ambient glow, not a drop shadow.
*   **AI Elements:** Exclusively reserve `secondary` (#8A2BE2) for intelligence-driven features, creating a distinct visual language for "smart" components.

---

## 3. Typography
The system uses a dual-font approach to balance brutalist impact with high-legibility utility.

*   **Display & Headlines (Space Grotesk):** This is our "Editorial Voice." Large, wide, and tech-forward. Use `display-lg` (3.5rem) for hero statements to command attention.
*   **Body & Labels (Inter):** Our "Utility Voice." Clean and neutral. The high x-height of Inter ensures readability against dark, glowing backgrounds.

**Hierarchy as Identity:**
*   **Headline-LG:** Used for section headers. Always paired with a `secondary` accent or a `primary-container` underline for "soul."
*   **Body-MD:** The workhorse. Set in `text-slate-200` to maintain high contrast without the eye strain of pure white (#FFFFFF).
*   **Label-SM:** Set in `text-slate-400` for metadata, ensuring primary information always takes precedence.

---

## 4. Elevation & Depth
We eschew traditional structural lines in favor of **Ambient Light.**

### The Layering Principle
Depth is achieved by "stacking" surface tiers. Place a `surface-container-highest` card atop a `surface-container-low` section. The change in hex value provides enough contrast for the human eye to perceive depth without needing a border.

### The "Ghost Border" Fallback
If accessibility requires a container boundary, use a **Ghost Border**:
*   `border-slate-700/50` (Standard)
*   `border-cyan-500/30` (Highlight/Active)
*   **Never use 100% opacity borders.**

### Ambient Shadows
Floating elements (Modals/Popovers) must use extra-diffused shadows. The shadow color should be a tinted version of `primary` or `secondary` at 4-8% opacity, mimicking the way a neon light would naturally cast a glow on a dark surface.

---

## 5. Components

### Buttons & Interaction
*   **Primary Button:** `bg-gradient-to-r from-cyan-500 to-cyan-400`. Text: `slate-950` (high-contrast legibility). On hover: `scale-[1.05]` and increased shadow glow.
*   **Secondary Button:** `border-slate-700` with `text-slate-300`. Hover state triggers a `border-cyan-500/50` transition.
*   **Tertiary/Ghost:** No background or border. Text: `text-cyan-400`. Used for low-priority actions to avoid visual clutter.

### Cards & Containers
*   **Rule:** Forbid divider lines within cards.
*   **Structure:** Use `gap-6` (24px) spacing to separate header, body, and footer content.
*   **Glass Card:** `bg-slate-900/60 backdrop-blur-md border-slate-800/60 rounded-xl`.

### Inputs & Forms
*   **Surface:** `bg-slate-800/50`.
*   **States:** On focus, the border transitions to `cyan-500` with a `ring-cyan-500/20` glow.
*   **Error:** Use `border-red-500/30` with `text-red-400`. Avoid high-saturation reds; keep them "washed" to fit the neon aesthetic.

### Status Badges
Badges should feel like light indicators on a dashboard.
*   **Active:** `bg-green-400/10 text-green-400`.
*   **AI/Premium:** `bg-purple-500/10 text-purple-400` with a subtle `animate-pulse`.

---

## 6. Do's and Don'ts

### Do:
*   **Embrace Negative Space:** Use aggressive padding (`p-8` or `p-12`) to allow the glow effects to feel premium rather than cluttered.
*   **Animate Transitions:** Use `transition-all duration-300` for all hover states. Motion is a core pillar of the "Aether" feel.
*   **Use Subtle Gradients:** Use gradients in text headers (`bg-clip-text text-transparent bg-gradient-to-r from-white to-slate-400`) for an editorial, high-end look.

### Don't:
*   **Don't use Solid White:** Pure `#FFFFFF` is too harsh for this dark environment. Stick to `slate-200`.
*   **Don't use 1px Dividers:** Never use `<hr />` or solid borders to separate list items. Use vertical space or a `hover:bg-slate-800/30` background shift.
*   **Don't Over-Glow:** If everything glows, nothing glows. Limit the `shadow-[0_0_15px]` to only one primary action per screen.