# My Team tabs

Compact secondary tabs under the main header (not a full-width white bar).

Port of the provided React `Tabs` pattern: floating hover pill + sliding dark underline.

## Items

1. Live
2. Pick
3. AI Tips
4. AI Teams
5. Mini Leagues

AI Tips lives at `/ai-tips`. Legacy `/ai-transfers` redirects there.

## Behaviour

- Transparent page background (no full-bleed white strip)
- Hover: soft ink pill (`rgba(14,15,17,0.08)`, 6px radius, 30px tall) slides to the hovered tab
- Active: 2px `#0e0f11` underline under the active tab
- Inactive text `rgba(14,15,17,0.6)`; active/hover `#0e0e10`
- Init via `initTeamTabs()` in `public/js/team-tabs.js`

## Markup

```html
<nav class="team-tabs" aria-label="My Team" data-team-tabs>
  <div class="container team-tabs__shell">
    <div class="team-tabs__root" data-team-tabs-root>
      <div class="team-tabs__hover" data-team-tabs-hover aria-hidden="true"></div>
      <div class="team-tabs__active" data-team-tabs-active aria-hidden="true"></div>
      <div class="team-tabs__list">
        <a class="team-tabs__tab" href="#" data-team-tab>Live</a>
        <a class="team-tabs__tab is-active" href="./link-team" aria-current="page" data-team-tab>Pick</a>
        …
      </div>
    </div>
  </div>
</nav>
```
