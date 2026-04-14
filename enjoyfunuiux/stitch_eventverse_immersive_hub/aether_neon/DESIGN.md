# Design System Strategy: The Celestial Frontier

## 1. Overview & Creative North Star
**Creative North Star: "The Galactic Concierge"**
This design system rejects the "standard dashboard" aesthetic in favor of a high-end, immersive environment. We are building a space that feels like a private VIP lounge aboard a deep-space cruiser. It is a fusion of **Glassmorphism Brutalism**—where the raw, heavy structural power of brutalist layouts meets the ethereal, light-refracting quality of frosted glass.

We break the "template" look by utilizing intentional asymmetry and overlapping elements. Headers should feel like monumental monoliths, while interactive cards float like holographic projections. The goal is a "Zero-G" layout where depth is communicated through light and transparency rather than rigid lines.

---

## 2. Colors
Our palette is rooted in the void of deep space, punctuated by high-energy neon pulses.

### Surface Hierarchy & Nesting
To achieve a premium look, we move away from flat surfaces. We treat the UI as a series of physical, translucent layers.
- **The Base:** `surface` (#150629) is the infinite void.
- **The Layering:** Use `surface-container-low` for large sectioning and `surface-container-highest` for interactive elements. 
- **The "No-Line" Rule:** 1px solid borders are strictly prohibited for sectioning. Boundaries must be defined solely through background shifts. For example, a `surface-container-low` section sitting on a `surface` background provides all the definition needed.

### The "Glass & Gradient" Rule
Floating elements (modals, popovers, navigation bars) must use **Glassmorphism**.
- **Recipe:** Use `surface-variant` at 40-60% opacity with a `backdrop-filter: blur(20px)`.
- **Signature Textures:** Main CTAs should not be flat. Apply a subtle linear gradient from `primary` (#b79fff) to `primary-container` (#ab8ffe) at a 135-degree angle to give the element "soul" and dimension.

---

## 3. Typography
We use a high-contrast pairing to balance technical precision with raw impact.

*   **Display & Headlines (Space Grotesk):** This is our "Brutalist" voice. Use `display-lg` for hero sections with tight letter-spacing (-2%). The heavy weight reflects the "monolithic" architecture of our space station vibe.
*   **Body & Titles (Manrope):** This is our "Concierge" voice. Manrope provides a sleek, modern, and highly legible counterpoint to the aggressive headers. 
*   **Scale Usage:** Always prioritize the `headline-lg` for page titles to establish authority. Use `label-sm` in all-caps with 10% letter-spacing for metadata to mimic technical readouts on a spaceship HUD.

---

## 4. Elevation & Depth
In deep space, there is no single light source. Depth is achieved through **Tonal Layering** and **Luminescence**.

*   **The Layering Principle:** Stacking tiers creates natural lift. Place a `surface-container-lowest` card inside a `surface-container-high` section. The contrast in value creates depth without the clutter of shadows.
*   **Ambient Shadows:** When an element must "float" (e.g., a hovered game card), use a **Spatial Colored Shadow**. Instead of black, use `primary` at 12% opacity with a 40px blur. This mimics the neon glow of the UI reflecting off the hull of the station.
*   **The "Ghost Border" Fallback:** If a border is required for accessibility, use the `outline-variant` token at **15% opacity**. This creates a "glint" on the edge of the glass rather than a hard boundary.
*   **Neon Glows:** Use `secondary` (#68fcbf) for success states and "Active Now" indicators. Apply a `drop-shadow` effect with the same color to make the element appear as if it’s emitting light.

---

## 5. Components

### Buttons
*   **Primary:** Gradient fill (`primary` to `primary-container`). Roundedness: `md` (0.375rem). No border.
*   **Secondary:** Ghost style. `outline-variant` border at 20% opacity. Text color: `primary`.
*   **Tertiary:** No background. Underline on hover using a 2px `secondary` pulse.

### Cards & Lists
*   **Strict Rule:** No divider lines. Separate content using `surface-container` shifts or vertical white space from the spacing scale.
*   **Game Cards:** Use `surface-container-low`. On hover, transition to `surface-container-highest` and apply the spatial neon shadow.

### Input Fields
*   **Style:** `surface-container-lowest` background. 
*   **Focus State:** The border glows with a 1px `primary` stroke and a subtle `primary` outer glow. Helper text uses `label-md` in `on-surface-variant`.

### Chips
*   **Action Chips:** Glassmorphic (`surface-variant` at 30% opacity). When selected, they flip to `primary` with `on-primary` text.

---

## 6. Do's and Don'ts

### Do
*   **Do** use asymmetrical layouts (e.g., a large display heading offset to the left with body text pushed to the right).
*   **Do** overlap elements. Let a glass card partially cover a background gradient or image to create a sense of three-dimensional space.
*   **Do** use `secondary` (#68fcbf) sparingly as a "high-energy" accent for win states or notifications.

### Don't
*   **Don't** use pure black (#000000) for backgrounds; it kills the depth. Use `surface` (#150629).
*   **Don't** use standard 1px borders or drop shadows. If it looks like a standard Bootstrap component, it’s wrong.
*   **Don't** clutter the screen. Space is infinite; let the elements breathe. If a section feels tight, increase the margin to the next `xl` step in the spacing scale.
*   **Don't** use rounded corners larger than `xl` (0.75rem) for main containers. We want "Sleek," not "Bubbly." Keep the Brutalist edge.