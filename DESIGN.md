---
colors:
  surface: '#fbf8ff'
  surface-dim: '#dad9e3'
  surface-bright: '#fbf8ff'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#f4f2fc'
  surface-container: '#eeedf7'
  surface-container-high: '#e8e7f1'
  surface-container-highest: '#e3e1eb'
  on-surface: '#1a1b22'
  on-surface-variant: '#444653'
  inverse-surface: '#2f3037'
  inverse-on-surface: '#f1f0fa'
  outline: '#757684'
  outline-variant: '#c4c5d5'
  surface-tint: '#1E40AF'
  primary: '#1E40AF'
  on-primary: '#ffffff'
  primary-container: '#1e40af'
  on-primary-container: '#a8b8ff'
  inverse-primary: '#b8c4ff'
  secondary: '#0058be'
  on-secondary: '#ffffff'
  secondary-container: '#2170e4'
  on-secondary-container: '#fefcff'
  tertiary: '#611e00'
  on-tertiary: '#ffffff'
  tertiary-container: '#872d00'
  on-tertiary-container: '#ffa583'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#dde1ff'
  primary-fixed-dim: '#b8c4ff'
  on-primary-fixed: '#001453'
  on-primary-fixed-variant: '#173bab'
  secondary-fixed: '#d8e2ff'
  secondary-fixed-dim: '#adc6ff'
  on-secondary-fixed: '#001a42'
  on-secondary-fixed-variant: '#004395'
  tertiary-fixed: '#ffdbce'
  tertiary-fixed-dim: '#ffb59a'
  on-tertiary-fixed: '#380d00'
  on-tertiary-fixed-variant: '#802a00'
  background: '#fbf8ff'
  on-background: '#1a1b22'
  surface-variant: '#e3e1eb'
typography:
  display-xl:
    fontFamily: Inter, IBM Plex Sans Arabic
    fontSize: 36px
    fontWeight: '700'
    lineHeight: 44px
  headline-lg:
    fontFamily: Inter, IBM Plex Sans Arabic
    fontSize: 28px
    fontWeight: '600'
    lineHeight: 36px
  headline-md:
    fontFamily: Inter, IBM Plex Sans Arabic
    fontSize: 20px
    fontWeight: '600'
    lineHeight: 28px
  body-lg:
    fontFamily: Inter, IBM Plex Sans Arabic
    fontSize: 18px
    fontWeight: '400'
    lineHeight: 26px
  body-md:
    fontFamily: Inter, IBM Plex Sans Arabic
    fontSize: 16px
    fontWeight: '400'
    lineHeight: 24px
  label-sm:
    fontFamily: Inter, IBM Plex Sans Arabic
    fontSize: 14px
    fontWeight: '500'
    lineHeight: 20px
  caption:
    fontFamily: Inter, IBM Plex Sans Arabic
    fontSize: 12px
    fontWeight: '400'
    lineHeight: 16px
rounded:
  sm: 0.25rem
  DEFAULT: 0.5rem
  md: 0.75rem
  lg: 1rem
  xl: 1.5rem
  full: 9999px
spacing:
  unit: 4px
  container-max: 1280px
  gutter: 24px
  margin-mobile: 16px
  margin-desktop: 32px
  stack-sm: 8px
  stack-md: 16px
  stack-lg: 24px
---

## Brand & Style

The design system is engineered for **Alnaasik Print Center**, a business-oriented service that demands precision, reliability, and speed. The visual language communicates a "High-End Corporate" aesthetic, moving away from the cluttered look of traditional print shops toward a streamlined, software-as-a-service (SaaS) experience.

The style leverages **Modern Minimalism** with a focus on structured information hierarchy. It uses ample whitespace to reduce cognitive load during complex print configuration tasks. Every element is designed to feel intentional and high-quality, mirroring the physical output of the center's printing services.

## Colors

The palette is anchored by **Professional Blue (#1E40AF)**, symbolizing stability and corporate expertise. This is complemented by a secondary Blue/Info color (#0058be) for interactive elements and pending states.

The neutral scale utilizes cool grays to maintain a clean, clinical environment for document previews. Loyalty tiers—**Bronze, Silver, and Gold**—are treated as "Metals" and should be used specifically for membership badges, progress bars, and premium user interface affordances. Status colors are vibrant but balanced, ensuring high legibility against white backgrounds.

## Typography

This design system uses a dual-font strategy optimized for bilingual RTL/LTR contexts. **Inter** serves as the primary engine for Latin characters and systematic UI elements, while **IBM Plex Sans Arabic** provides a modern, highly legible Naskh-inspired structure for Arabic text.

The typographic scale emphasizes clarity for technical specifications (paper weight, dimensions, quantities). Weights are used sparingly: **SemiBold (600)** for primary navigation and headings, and **Regular (400)** for all body and descriptive text.

## Layout & Spacing

The layout follows a **Fixed-Fluid Hybrid Grid**. On desktop, the content is contained within a 1280px max-width 12-column grid to prevent line lengths from becoming unreadable. On smaller viewports, the grid transitions to a fluid model with 16px side margins.

The spacing rhythm is based on a **4px baseline**. All vertical margins and paddings between components must be multiples of 4 (e.g., 8, 16, 24, 32). In RTL mode, all horizontal paddings and icons are mirrored—icons indicating "forward" must point to the left.

## Elevation & Depth

To maintain the "Business-Oriented" feel, the design system avoids heavy drop shadows. Instead, it utilizes **Tonal Layers** and **Low-Contrast Outlines**.

Depth is communicated through:
1. **Level 0 (Surface):** Secondary Gray (#F9FAFB) for the main background.
2. **Level 1 (Cards/Content):** Pure White (#FFFFFF) with a 1px border (#E5E7EB).
3. **Level 2 (Interaction):** A subtle ambient shadow (Blur 8px, Y-offset 4px, 5% Opacity) applied only when an element is hovered or active.

This "Flat-Plus" approach ensures that document previews remain the focal point without distraction from excessive 3D effects.

## Shapes

The cornerstone of the visual identity is the **moderate roundedness** of elements. A standard radius is applied to all primary containers, cards, and buttons to soften the corporate Blue and make the interface feel approachable yet modern.

Secondary elements within a card (like input fields or nested buttons) should use a smaller "inner" radius to maintain a nested visual harmony. Tags and status indicators may use the pill-shape for immediate differentiation from actionable buttons.

## Components

### Buttons & Chips
Primary buttons use the Professional Blue (#1E40AF) with white text. Ghost buttons use a 1px border in the same blue. Chips for "Printing Options" (e.g., A4, Glossy, Color) should use consistent rounded corners and a light gray background until selected.

### Input Fields
Inputs must have consistent rounded corners. The active state is signaled by a 2px Professional Blue border and a very subtle Blue glow. Labels are always right-aligned above the input field in RTL mode.

### Cards
Cards are the primary vessel for "Print Orders." They feature a 1px border (#E5E7EB) and standard roundedness. The header of the card should contain the "Status Chip" (e.g., Pending, Printing, Ready) in the top-left (RTL) or top-right (LTR) corner.

### Loyalty Progress
The Loyalty Tiers (Bronze, Silver, Gold) are displayed as a progress bar component with the corresponding tier color. These components use standard roundedness for the track and a pill-shape for the progress indicator.

### Status Indicators
Status colors should always be accompanied by an icon (e.g., Checkmark for Success, Alert for Danger) to ensure accessibility for color-blind users and to reinforce the professional tone.