# j_dom_pdf demo pack

Four sample PDFs that show what the CSS3 work in this fork can do:
flexbox layout, inline SVG (including recolouring), and right-to-left
Dhivehi text.

## The samples

| File | Shows |
| --- | --- |
| `out/01-flexbox.pdf` | `display: flex`: grow/shrink, `justify-content`, `align-items`, wrap + `gap`, `order`, `flex-direction: column`, and everyday patterns (toolbar, progress rows, media object) |
| `out/02-inline-svg.pdf` | Inline `<svg>` as real vector art: four ways to set a colour, linear/radial gradients, `gradientTransform`, an SVG file used as a background image, a donut and a bar chart, and a two-tone logo recoloured for a dark panel |
| `out/03-rtl-dhivehi.pdf` | A Dhivehi news page (Thaana, Faruma font): RTL headline and body, digits inside RTL lines, RTL lists, RTL flex rows, and a box comparing `dir="rtl"`, `dir="ltr"` and `<bdo>` |
| `out/04-dhivehi-report.pdf` | All three together: a one-page Dhivehi dashboard built from flex rows with coloured SVG icons and charts |

The Dhivehi text is taken from real mihaaru.com articles published on
18-19 August 2026, and is credited on the page.

## Rebuilding

```bash
composer install          # once, from the repo root
php demo/build.php        # renders every page in demo/pages into demo/out
php demo/build.php 01-flexbox   # or just one
```

`demo/.cache/` holds the font metrics dompdf generates; it is safe to delete.

## Things worth knowing (checked while building these pages)

Works:

* `display: flex` on both LTR and RTL containers, nested flex, percentage
  and `pt` bases, `gap`, `order`, `align-content`, `space-evenly`
* Inline `<svg>`, `<use>`, gradients, `stroke-dasharray`, `transform` on a
  group, `text` inside SVG
* CSS custom properties (`var(--x)`) in the page stylesheet
* `border-radius`, `opacity`, `transform: rotate()`, `hsl()`, wavy
  underlines, `box-sizing`, `vw` units, `word-break`, `:is()` / `:not()`
* `counter(page)` in a fixed-position element

Does not work, with the workaround used here:

| Not supported | Workaround |
| --- | --- |
| CSS `linear-gradient()` / `radial-gradient()` backgrounds | put the gradient in an `.svg` file and use `background-image: url(...)` with `background-size` |
| `box-shadow` | a hairline border |
| Page CSS reaching inside an inline `<svg>` | colour from inside the SVG: presentation attributes, its own `style`, a `<style>` element, or `currentColor` fed by a `color` attribute on the root |
| `var()` and `hsl()` *inside* SVG markup | hex, `rgb()` or colour names |
| Gradient on a `stroke` | a plain stroke colour |
| RTL tables reversing their column order | lay the row out with flexbox, which does honour `direction: rtl` |
| `counter(pages)` (always 0) | `$canvas->page_text()` with `{PAGE_COUNT}`, as `build.php` does |

## Fonts

`fonts/Faruma.ttf` is the Thaana face used by the Dhivehi pages, copied from
the system font directory so the demo builds anywhere. Faruma has a single
weight, so it is registered for both 400 and 700; without the bold
registration, any heading falls back to a Latin-only face and the Thaana
turns into `?????`.
