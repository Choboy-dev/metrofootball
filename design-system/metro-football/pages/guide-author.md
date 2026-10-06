# Guide author byline + profile

## Purpose
Nairametrics-inspired author attribution for FPL Guides: share icons, clickable author, estimated read time, and a public author page for SEO / AdSense transparency.

## Author
- Name: Chosen Esiwe
- Role: Founder
- Slug: `/author/chosen-esiwe`
- Email: chosen@metrofootball.co.uk
- Instagram: https://www.instagram.com/chosenesiwe/
- Photo: `/images/authors/chosen-esiwe.jpg`
- Config: `app/GuideAuthor.php` (default for all guides, past and future)

## Article layout (after H1)
1. Share row: X, Facebook, LinkedIn, WhatsApp, Copy link
2. Author bar: circular avatar + name (links to author page) + role + “N min read”
3. Deck / description

## Files
- `public/guide-author.php` (pretty URL `/author/{slug}` via root + public `.htaccess`)
- `public/partials/guide-byline.php`
- `public/fpl-guide.php`
- `public/css/fpl-guides.css`
- `public/js/fpl-guides.js`
- Note: do not name the PHP file `author.php` on this host; `/author/...` path-info conflicts with that filename. Use `guide-author.php`.

## SEO
- `meta name="author"`
- JSON-LD `author` as `Person` with `url`, `image`, `email`, `sameAs`
- Author `ProfilePage` JSON-LD on `/author/chosen-esiwe`
- Sitemap entry in `sitemap-pages.xml`
