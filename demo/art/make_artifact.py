"""Builds the proof-sheet page, with every rendered PDF page embedded.

Usage: python3 demo/art/make_artifact.py [output.html]
Defaults to demo/art/proof-sheet.html.
"""

import base64, io, os, pathlib, sys

ART = pathlib.Path(__file__).resolve().parent
OUT = pathlib.Path(sys.argv[1]) if len(sys.argv) > 1 else ART / "proof-sheet.html"
OUT.parent.mkdir(parents=True, exist_ok=True)

def uri(name):
    return "data:image/jpeg;base64," + base64.b64encode((ART / name).read_bytes()).decode()

def kb(p):
    return f"{os.path.getsize(p)/1024:.0f} KB"

PLATES = [
  dict(num="01", slug="flexbox", file="01-flexbox.pdf", title="Flexbox layout",
       blurb="Every box on these two pages is positioned by the flex reflower — no floats, no layout tables.",
       specs=["flex-grow / flex-shrink / flex-basis", "justify-content, all six values",
              "align-items and align-self", "flex-wrap with gap", "order",
              "flex-direction: column", "nested flex containers"],
       shots=["01-flexbox-1.jpg", "01-flexbox-2.jpg"]),
  dict(num="02", slug="svg", file="02-inline-svg.pdf", title="Inline SVG and colour",
       blurb="Vector artwork written straight into the HTML, drawn into the PDF as real paths. The same icon appears in six colours, and the same logo is recoloured for a dark panel.",
       specs=["fill attribute, inline style, &lt;style&gt; block, currentColor",
              "linearGradient, radialGradient, gradientTransform",
              "an .svg file as a background-image", "stroke-dasharray donut chart",
              "gradient-filled bar chart", "16 solid and outline icons"],
       shots=["02-inline-svg-1.jpg", "02-inline-svg-2.jpg"]),
  dict(num="03", slug="rtl", file="03-rtl-dhivehi.pdf", title="Right-to-left Dhivehi",
       blurb="A Mihaaru news story typeset in Thaana with the Faruma face. The headline opens on މ, and the box at the end compares the same characters under three direction settings.",
       specs=["full UAX #9 bidi over every paragraph", "digits keep LTR order inside an RTL line",
              "dir=\"rtl\" vs dir=\"ltr\" vs &lt;bdo&gt;", "RTL lists, bullets on the right",
              "RTL flex rows for the figure cards", "Faruma registered for both weights"],
       shots=["03-rtl-dhivehi-1.jpg", "03-rtl-dhivehi-2.jpg"]),
  dict(num="04", slug="report", file="04-dhivehi-report.pdf", title="All three at once",
       blurb="A one-page Dhivehi dashboard: RTL text, flex rows and coloured SVG working together the way a real report would use them.",
       specs=["RTL flex KPI row", "coloured SVG icons, one via currentColor",
              "gradient bar chart and donut", "elastic progress rails",
              "SVG gradient as the header band", "LTR spans inside RTL text"],
       shots=["04-dhivehi-report-1.jpg"]),
]

MATRIX_OK = [
  ("display: flex", "Row and column, both reverses, on LTR and RTL containers"),
  ("gap, order, align-content", "Including space-evenly and nested flex"),
  ("Inline &lt;svg&gt;", "Paths, groups, transform, use/href, text, dashed strokes"),
  ("SVG gradients", "Linear, radial, gradientTransform, multi-stop"),
  ("Bidirectional text", "UAX #9, dir, &lt;bdo&gt;, RTL lists"),
  ("Justified RTL text", "The last line of a justified block falls to the start edge, i.e. the right edge under direction: rtl"),
  ("Thaana fili and Arabic harakat", "Non-spacing marks of RTL scripts are emitted ahead of their base letter, at any embedding level, so they paint on the right consonant"),
  ("CSS custom properties", "var() works in the page stylesheet"),
  ("border-radius, opacity, rotate", "Plus hsl(), wavy underlines, box-sizing, vw, word-break"),
  ("counter(page)", "In a fixed-position element"),
]

MATRIX_NO = [
  ("CSS linear-gradient() backgrounds", "Put the gradient in an .svg file and use background-image with background-size"),
  ("box-shadow", "A hairline border"),
  ("Page CSS reaching inside an inline &lt;svg&gt;", "Colour from inside the SVG: attributes, its own style, a &lt;style&gt; block, or currentColor"),
  ("var() and hsl() inside SVG", "Hex, rgb() or colour names"),
  ("Gradient on a stroke", "A plain stroke colour"),
  ("RTL tables reversing columns", "Build the row with flexbox, which does honour direction: rtl"),
  ("counter(pages)", "$canvas-&gt;page_text() with {PAGE_COUNT}"),
]

def plate_html(p):
    shots = "\n".join(
        f'''          <figure class="sheet">
            <img src="{uri(s)}" alt="{p['title']} page {i+1}" loading="lazy">
            <figcaption>page {i+1} of {len(p['shots'])}</figcaption>
          </figure>''' for i, s in enumerate(p["shots"]))
    specs = "\n".join(f"            <li>{x}</li>" for x in p["specs"])
    size = kb(ART.parent / "out" / p["file"])
    return f'''      <section class="plate" id="{p['slug']}">
        <div class="plate-head">
          <span class="plate-num">{p['num']}</span>
          <div class="plate-title">
            <h2>{p['title']}</h2>
            <p class="blurb">{p['blurb']}</p>
          </div>
        </div>
        <div class="plate-body">
          <ul class="specs">
{specs}
          </ul>
          <p class="filemeta"><code>demo/out/{p['file']}</code> <span class="dot">·</span> {size}</p>
        </div>
        <div class="sheets">
{shots}
        </div>
      </section>'''

nav = "\n".join(
    f'      <a href="#{p["slug"]}"><span class="n">{p["num"]}</span> {p["title"]}</a>' for p in PLATES)
plates = "\n".join(plate_html(p) for p in PLATES)
ok_rows = "\n".join(f"          <tr><td>{a}</td><td>{b}</td></tr>" for a, b in MATRIX_OK)
no_rows = "\n".join(f"          <tr><td>{a}</td><td>{b}</td></tr>" for a, b in MATRIX_NO)

HTML = f'''<title>j_dom_pdf Proof Sheet</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Newsreader:ital,opsz,wght@0,6..72,400;0,6..72,600;1,6..72,400&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
<style>
:root {{
  --ground:   #F2F5F5;
  --surface:  #FFFFFF;
  --sunken:   #E8EDED;
  --ink:      #0E1A1F;
  --ink-soft: #3A4C50;
  --muted:    #66797C;
  --rule:     #D3DCDC;
  --rule-soft:#E3EAEA;
  --accent:   #0B6E77;
  --accent-ink:#08525A;
  --flag:     #9E1230;
  --sheet-shadow: 0 1px 2px rgba(14,26,31,.10), 0 10px 28px rgba(14,26,31,.10);
}}
@media (prefers-color-scheme: dark) {{
  :root:not([data-theme="light"]) {{
    --ground:   #0B1214;
    --surface:  #121C1F;
    --sunken:   #0F181A;
    --ink:      #E7EFEF;
    --ink-soft: #BCCBCB;
    --muted:    #86999A;
    --rule:     #233236;
    --rule-soft:#1A2629;
    --accent:   #4FC3CB;
    --accent-ink:#8AE0E5;
    --flag:     #F2637F;
    --sheet-shadow: 0 1px 2px rgba(0,0,0,.5), 0 14px 34px rgba(0,0,0,.45);
  }}
}}
:root[data-theme="dark"] {{
  --ground:   #0B1214;
  --surface:  #121C1F;
  --sunken:   #0F181A;
  --ink:      #E7EFEF;
  --ink-soft: #BCCBCB;
  --muted:    #86999A;
  --rule:     #233236;
  --rule-soft:#1A2629;
  --accent:   #4FC3CB;
  --accent-ink:#8AE0E5;
  --flag:     #F2637F;
  --sheet-shadow: 0 1px 2px rgba(0,0,0,.5), 0 14px 34px rgba(0,0,0,.45);
}}

* {{ box-sizing: border-box; }}

body {{
  margin: 0;
  background: var(--ground);
  color: var(--ink);
  font-family: "IBM Plex Sans", ui-sans-serif, system-ui, sans-serif;
  font-size: 16px;
  line-height: 1.65;
  -webkit-font-smoothing: antialiased;
}}

.wrap {{ width: min(100% - 2.5rem, 900px); margin-inline: auto; }}

/* ---- masthead ---- */
.masthead {{ padding: clamp(2.5rem, 7vw, 5rem) 0 1.75rem; }}
.eyebrow {{
  font-family: "IBM Plex Mono", ui-monospace, monospace;
  font-size: .72rem; letter-spacing: .16em; text-transform: uppercase;
  color: var(--accent-ink); margin: 0 0 1rem;
}}
h1 {{
  font-family: Newsreader, Georgia, serif;
  font-weight: 600; font-size: clamp(2.3rem, 6vw, 3.6rem);
  line-height: 1.06; letter-spacing: -.015em; margin: 0 0 1rem;
  text-wrap: balance;
}}
.standfirst {{
  font-family: Newsreader, Georgia, serif;
  font-size: clamp(1.08rem, 2.2vw, 1.32rem); line-height: 1.55;
  color: var(--ink-soft); max-width: 62ch; margin: 0 0 1.75rem;
}}
.facts {{
  display: flex; flex-wrap: wrap; gap: .5rem 2.25rem;
  padding-top: 1.25rem; border-top: 1px solid var(--rule);
  font-size: .82rem; color: var(--muted);
  font-variant-numeric: tabular-nums;
}}
.facts b {{ color: var(--ink); font-weight: 500; }}

/* ---- sticky index ---- */
nav.index {{
  position: sticky; top: 0; z-index: 5;
  background: color-mix(in srgb, var(--ground) 88%, transparent);
  backdrop-filter: blur(8px);
  border-bottom: 1px solid var(--rule);
  margin-top: 2.5rem;
}}
nav.index .wrap {{ display: flex; flex-wrap: wrap; gap: .25rem 1.5rem; padding: .7rem 0; }}
nav.index a {{
  color: var(--muted); text-decoration: none; font-size: .82rem;
  display: inline-flex; gap: .45rem; align-items: baseline;
  padding: .15rem 0; border-bottom: 1px solid transparent;
}}
nav.index a .n {{ font-family: "IBM Plex Mono", monospace; font-size: .72rem; color: var(--accent); }}
nav.index a:hover, nav.index a:focus-visible {{ color: var(--ink); border-bottom-color: var(--accent); }}

/* ---- plates ---- */
.plate {{ padding: clamp(3rem, 7vw, 5rem) 0 1rem; border-bottom: 1px solid var(--rule-soft); scroll-margin-top: 3.5rem; }}
.plate-head {{ display: flex; gap: 1.25rem; align-items: flex-start; }}
.plate-num {{
  font-family: "IBM Plex Mono", monospace; font-size: .8rem; font-weight: 500;
  color: var(--surface); background: var(--accent);
  border-radius: 2px; padding: .2rem .5rem; margin-top: .5rem; flex: none;
  letter-spacing: .04em;
}}
.plate h2 {{
  font-family: Newsreader, Georgia, serif; font-weight: 600;
  font-size: clamp(1.6rem, 3.6vw, 2.15rem); line-height: 1.15;
  margin: 0 0 .45rem; letter-spacing: -.01em;
}}
.blurb {{ margin: 0; color: var(--ink-soft); max-width: 62ch; }}
.plate-body {{ margin: 1.5rem 0 2rem 0; padding-left: calc(.8rem + 1.25rem + .5rem); }}
.specs {{
  list-style: none; margin: 0; padding: 0;
  display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: .3rem 1.5rem;
  font-size: .88rem; color: var(--ink-soft);
}}
.specs li {{ padding-left: 1rem; position: relative; }}
.specs li::before {{
  content: ""; position: absolute; left: 0; top: .62em;
  width: 5px; height: 5px; background: var(--accent); border-radius: 50%;
}}
.filemeta {{
  margin: 1.1rem 0 0; font-size: .8rem; color: var(--muted);
  font-variant-numeric: tabular-nums;
}}
.dot {{ opacity: .5; padding: 0 .2rem; }}

code {{
  font-family: "IBM Plex Mono", ui-monospace, monospace;
  font-size: .86em; background: var(--sunken); color: var(--ink-soft);
  padding: .1em .38em; border-radius: 3px;
}}

/* ---- sheets on the light table ---- */
.sheets {{ display: flex; flex-direction: column; gap: 2.25rem; align-items: center; }}
.sheet {{ margin: 0; position: relative; width: 100%; max-width: 820px; padding: 14px; }}
.sheet::before, .sheet::after {{
  content: ""; position: absolute; width: 14px; height: 14px; opacity: .5;
}}
.sheet::before {{ top: 0; left: 0; border-top: 1px solid var(--accent); border-left: 1px solid var(--accent); }}
.sheet::after  {{ bottom: 1.9rem; right: 0; border-bottom: 1px solid var(--accent); border-right: 1px solid var(--accent); }}
.sheet img {{
  display: block; width: 100%; height: auto;
  background: #fff; border: 1px solid var(--rule);
  box-shadow: var(--sheet-shadow);
}}
.sheet figcaption {{
  margin-top: .7rem; font-family: "IBM Plex Mono", monospace;
  font-size: .7rem; letter-spacing: .1em; text-transform: uppercase; color: var(--muted);
}}

/* ---- support matrix ---- */
.matrix {{ padding: clamp(3rem, 7vw, 5rem) 0 0; }}
.matrix h2 {{
  font-family: Newsreader, Georgia, serif; font-weight: 600;
  font-size: clamp(1.6rem, 3.6vw, 2.15rem); margin: 0 0 .5rem; letter-spacing: -.01em;
}}
.matrix > .wrap > p {{ color: var(--ink-soft); max-width: 62ch; margin: 0 0 2rem; }}
.tablewrap {{ overflow-x: auto; margin-bottom: 2.5rem; }}
h3.tablelabel {{
  font-family: "IBM Plex Mono", monospace; font-weight: 500;
  font-size: .72rem; letter-spacing: .14em; text-transform: uppercase;
  margin: 0 0 .75rem; display: flex; align-items: center; gap: .55rem;
}}
h3.tablelabel .pip {{ width: 8px; height: 8px; border-radius: 50%; display: inline-block; }}
h3.works .pip {{ background: var(--accent); }}
h3.gaps  .pip {{ background: var(--flag); }}
h3.works {{ color: var(--accent-ink); }}
h3.gaps  {{ color: var(--flag); }}
table {{ width: 100%; border-collapse: collapse; font-size: .88rem; min-width: 520px; }}
th, td {{ text-align: left; padding: .6rem .9rem; border-bottom: 1px solid var(--rule-soft); vertical-align: top; }}
thead th {{
  font-family: "IBM Plex Mono", monospace; font-weight: 500;
  font-size: .68rem; letter-spacing: .12em; text-transform: uppercase;
  color: var(--muted); border-bottom: 1px solid var(--rule);
}}
tbody tr:last-child td {{ border-bottom: none; }}
td:first-child {{ color: var(--ink); width: 38%; }}
td:last-child {{ color: var(--ink-soft); }}

/* ---- rebuild ---- */
.rebuild {{ padding: 1rem 0 clamp(4rem, 9vw, 6rem); }}
pre {{
  margin: 0; padding: 1.1rem 1.25rem; overflow-x: auto;
  background: var(--surface); border: 1px solid var(--rule); border-radius: 4px;
  font-family: "IBM Plex Mono", ui-monospace, monospace; font-size: .82rem;
  line-height: 1.8; color: var(--ink-soft);
}}
pre .c {{ color: var(--muted); }}
footer {{ border-top: 1px solid var(--rule); padding: 1.5rem 0 3rem; font-size: .8rem; color: var(--muted); }}
footer a {{ color: var(--accent-ink); }}

@media (prefers-reduced-motion: reduce) {{
  * {{ animation: none !important; transition: none !important; scroll-behavior: auto !important; }}
}}
a:focus-visible {{ outline: 2px solid var(--accent); outline-offset: 3px; }}
</style>

<header class="masthead">
  <div class="wrap">
    <p class="eyebrow">j_dom_pdf · demo pack · 19 August 2026</p>
    <h1>Four proofs from the CSS3 branch</h1>
    <p class="standfirst">
      Flexbox layout, inline SVG with real colour control, and right-to-left Dhivehi —
      rendered by the fork itself, then photographed page by page. Everything below is
      the actual PDF output, not a browser preview.
    </p>
    <div class="facts">
      <span><b>4</b> sample PDFs</span>
      <span><b>7</b> pages</span>
      <span>Thaana set in <b>Faruma</b></span>
      <span>Text from <b>mihaaru.com</b></span>
    </div>
  </div>
</header>

<nav class="index">
  <div class="wrap">
{nav}
      <a href="#matrix"><span class="n">··</span> What works</a>
  </div>
</nav>

<main class="wrap">
{plates}
</main>

<section class="matrix" id="matrix">
  <div class="wrap">
    <h2>What works, and what to do instead</h2>
    <p>
      Each line below was rendered and checked against the output while the samples were built,
      not taken from documentation.
    </p>

    <h3 class="tablelabel works"><span class="pip"></span>Supported</h3>
    <div class="tablewrap">
      <table>
        <thead><tr><th>Feature</th><th>Notes</th></tr></thead>
        <tbody>
{ok_rows}
        </tbody>
      </table>
    </div>

    <h3 class="tablelabel gaps"><span class="pip"></span>Not supported</h3>
    <div class="tablewrap">
      <table>
        <thead><tr><th>Gap</th><th>Workaround used in these samples</th></tr></thead>
        <tbody>
{no_rows}
        </tbody>
      </table>
    </div>
  </div>
</section>

<section class="rebuild">
  <div class="wrap">
    <h3 class="tablelabel works"><span class="pip"></span>Rebuilding the pack</h3>
    <pre><span class="c"># once, from the repo root</span>
composer install

<span class="c"># renders every page in demo/pages into demo/out</span>
php demo/build.php

<span class="c"># or just one sample</span>
php demo/build.php 03-rtl-dhivehi</pre>
  </div>
</section>

<footer>
  <div class="wrap">
    Sources in <code>demo/pages/</code>, shared stylesheet in <code>demo/assets/demo.css</code>,
    Thaana face in <code>demo/fonts/</code>. Dhivehi copy quoted from mihaaru.com articles of
    18–19 August 2026 and credited on the page it appears on.
  </div>
</footer>
'''

OUT.write_text(HTML, encoding="utf-8")
print(OUT, f"{OUT.stat().st_size/1024:.0f} KB")
