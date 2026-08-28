# Contact page override

**Page:** Contact  
**Pattern:** Split support layout with email first, self serve links, and form panel

## Layout

### Mobile (default)
1. Hero headline and short lead
2. Dark email card with tap friendly mailto link
3. Message form with reason dropdown
4. Self serve help links below the form

### Desktop (960px+)
Two columns: left column stacks email card and help links, right column holds the form card

## Copy tone
- We / Metro Football voice
- No phone number or live chat
- Email and form only
- Simple language, no exclamation marks, no dashes used as punctuation

## Components
- `.mf-btn` for submit and success CTA
- `.mf-select` for reason dropdown (never native select)
- `.auth-input` base for fields

## Motion
- Hero fade in on load
- Staggered reveal for cards and help links on scroll
- Success state scale and fade after submit
- Honor `prefers-reduced-motion`
