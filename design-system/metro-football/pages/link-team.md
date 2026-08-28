# Link team page

> Overrides Master for `/link-team` onboarding after first auth.

## Purpose

One job: link the user's FPL entry before dashboard or payment.

## Layout

- Same homepage navbar and footer as join/home
- Logged in: Sign Out only (hide Sign In / Get Started)
- **My Team tabs** under the main header: Live · Pick · AI Transfers · AI Teams · Mini Leagues
  - Compact hover-pill + sliding underline (see `components/team-subnav.md`)
  - No full-width white background bar
  - On this page, **Pick** is active (`aria-current="page"`)
- Centered white form card on soft site background
- No stats strip, no secondary marketing blocks

## Content

- Title: Link your FPL team
- Short lead + FPL ID field + Search / Import CTA (`.mf-btn`)
- Helper text at 12px explaining FPL ID from Points URL
- Results list with Link team action

## Motion

- Card fade/slide on load via Motion `animate` (respect `prefers-reduced-motion`)
- Result rows fade in after search
- Subnav hover/active indicators use CSS transitions (300ms ease-out)
