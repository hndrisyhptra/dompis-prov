---
name: area3-design-system
description: Build or revise the its Area 3 interface with its specific enterprise design tokens, responsive shell, tables, and accessible interaction states. Do not apply its brand to Dompis Prov.
---

# area3-design-system

## Purpose
Create a consistent interface for the construction administration prototype `area3-prototype`. This brand belongs to it's Area 3; the existing Laravel Dompis Prov branding is separate.

## Constraints
- Primary `#9FA1FF`, hover `#898CF0`, surface `#F2F2FF`; white default, canvas `#F8F9FB`, border `#E5E7EB`, main text `#111827`, muted `#667085`.
- Solid colors only. No gradients, glass, glows, decorative imagery, emoji, large shadows or oversized pills. Baseline radius 6px.
- Primary labels and errors use Bahasa Indonesia. Use Manager Konstruksi and BA Rekonsiliasi Pekerjaan for partial documents.
- Dense readable tables retain every component and unit, align numeric columns right and use tabular numerals. Preserve data with horizontal table scrolling, never page overflow.
- Signatures are evidence from an actual document. Never invent a signature in a preview.

## Implementation checklist
1. Reuse `src/styles.css` and primitives in `src/ui.tsx`: Button, Field, Badge, Table, Modal, Steps, DocumentPreview.
2. Keep navigation, breadcrumb, role demo selector and data persistence status in the shared shell. Distinguish operational document actions from approval inbox actions.
3. At widths below 768px, use a sidebar drawer; keep table overflow inside panels and stack forms/cards. At 768–1023px retain a compact sidebar. Restore desktop viewport after QA.
4. Every visible action must navigate, save, validate, calculate, export, preview or open a meaningful dialog. Explain disabled actions with a nearby message or title.
5. Label form controls, show visible keyboard focus, trap modal focus, restore focus and support Escape. Do not rely on color alone for state.
6. Keep light and dark backgrounds, borders, semantic badges and disabled states legible. Primary button text must contrast with the light brand fill.

## Acceptance
- Check 375/768/1440px and dark/light; no document-level horizontal overflow.
- Sidebar routes and contextual links work. Keyboard opens and closes dialogs. Numeric values agree with domain services.
- No gradient styles, generated signatures, nonfunctional controls or unlabelled icon actions.
