NativePdf
======

[![Latest Version on Packagist](https://img.shields.io/packagist/v/javaabu/nativepdf.svg?style=flat-square)](https://packagist.org/packages/javaabu/nativepdf)
[![Total Downloads](https://img.shields.io/packagist/dt/javaabu/nativepdf.svg?style=flat-square)](https://packagist.org/packages/javaabu/nativepdf)
[![PHP Versions Supported](https://img.shields.io/packagist/dependency-v/javaabu/nativepdf/php?style=flat-square)](https://packagist.org/packages/javaabu/nativepdf)
[![License](https://img.shields.io/packagist/l/javaabu/nativepdf.svg?style=flat-square)](LICENSE.LGPL)

**NativePdf is an HTML to PDF converter**

NativePdf is built on top of [dompdf](https://github.com/dompdf/dompdf). It is a
fork of dompdf that adds support for CSS flexbox, bidirectional text (Arabic and
Thaana), and inline SVG. The HTML and CSS engine underneath is dompdf's work,
and all credit for it goes to the dompdf team (see [Credits](#credits)).

At its heart, nativepdf is (mostly) a [CSS 2.1](http://www.w3.org/TR/CSS2/) compliant
HTML layout and rendering engine written in PHP. It is a style-driven renderer:
it will download and read external stylesheets, inline style tags, and the style
attributes of individual HTML elements. It also supports most presentational
HTML attributes.

*This document applies to the latest stable code which may not reflect the current 
release. For released code please
[navigate to the appropriate tag](https://github.com/Javaabu/nativepdf/releases).*

## Features

 * Handles most CSS 2.1 and many CSS3 properties, including @import, @media &
   @page rules
 * CSS flexbox layout (`display: flex` / `inline-flex`): `flex-direction`
   (row and column, including reverses), `flex-wrap`, `flex`/`flex-grow`/
   `flex-shrink`/`flex-basis`, `order`, `gap`, `justify-content`,
   `align-items`/`align-self` (`baseline` behaves as `flex-start`), and
   `align-content`. Page breaks move an overflowing flex container to the
   next page as a whole.
 * Bidirectional text (full UAX #9 implementation, validated against the
   official Unicode conformance suites) with Arabic contextual shaping
   (presentation forms and the mandatory lam-alef ligatures), the `dir`
   attribute, `<bdo>`/`<bdi>`, `direction`/`unicode-bidi`, and
   right-to-left lists. Disable with
   `$options->setIsBidiEnabled(false)`. Note: PDF text extraction of shaped
   Arabic yields presentation forms (NFKC-normalizable).
 * `box-sizing`, `word-break: break-all`, viewport units (`vw`/`vh`/
   `vmin`/`vmax`, resolved against the first page size) and `ch`,
   `hsl()`/`hsla()`/`hwb()` colors, multi-keyword `text-decoration` with
   `text-decoration-color`/`-style` (solid, double, dotted, dashed, wavy),
   and the `:not()`/`:is()`/`:where()` pseudo-classes (compound-selector
   arguments, Selectors 4 specificity)
 * Supports most presentational HTML 4.0 attributes
 * Supports external stylesheets, either local or through http/ftp (via
   fopen-wrappers)
 * Supports complex tables, including row & column spans, separate & collapsed
   border models, individual cell styling
 * Image support (gif, png (8, 24 and 32 bit with alpha channel), bmp & jpeg)
 * No dependencies on external PDF libraries, thanks to the R&OS PDF class
 * Inline PHP support
 * SVG support (see the support matrix below)

### SVG support matrix

Delivery paths (CPDF backend, true vector output):

 * `<img src="*.svg">` and SVG data URIs: supported
 * Inline `<svg>` elements: supported (converted internally to an image;
   page CSS selectors do not reach the SVG's internals — presentation
   attributes, inline `style`, and `<style>` inside the SVG do work)
 * `background-image: url(*.svg)`: supported, including
   `background-size`/`-position`/`-repeat` (vector tiles)

Feature notes:

 * The root `viewBox` (including non-zero origins) and
   `preserveAspectRatio` (all alignments, `meet`/`slice`, `none`) are
   honored, and content is clipped to the viewport
 * Linear and radial gradient fills: `gradientUnits` (objectBoundingBox
   and userSpaceOnUse), `gradientTransform`, `href`/`xlink:href` template
   inheritance, radial focal points (`fx`/`fy`/`fr`); `spreadMethod`
   `reflect`/`repeat` fall back to `pad`; gradient strokes and gradient
   text fills fall back to the first stop color; `stop-opacity` is ignored
 * Filters, masks, and patterns are not supported
 * The GD backend skips SVG content; PDFLib uses its own SVG importer
 
## Requirements

 * PHP version 7.1 or higher
 * DOM extension
 * MBString extension
 * php-font-lib
 * php-svg-lib
 * GD (for image processing)
   * Additionally, the IMagick or GMagick extension improves image processing performance for certain image types
 
Note that some required dependencies may have further dependencies.

Visit the wiki for more information:
https://github.com/dompdf/dompdf/wiki/Requirements

## About Fonts & Character Encoding

PDF documents internally support the following fonts: Helvetica, Times-Roman,
Courier, Zapf-Dingbats, & Symbol. These fonts only support Windows ANSI
encoding. In order for a PDF to display characters that are not available in
Windows ANSI, you must supply an external font. NativePdf will embed any referenced
font in the PDF so long as it has been pre-loaded or is accessible to nativepdf and
reference in CSS @font-face rules. See the
[font overview](https://github.com/dompdf/dompdf/wiki/About-Fonts-and-Character-Encoding)
for more information on how to use fonts.

The [DejaVu TrueType fonts](https://dejavu-fonts.github.io/) have been pre-installed
to give nativepdf decent Unicode character coverage by default. To use the DejaVu
fonts reference the font in your stylesheet, e.g. `body { font-family: DejaVu
Sans; }` (for DejaVu Sans). The following DejaVu 2.34 fonts are available:
DejaVu Sans, DejaVu Serif, and DejaVu Sans Mono.

## Easy Installation

### Install with composer

To install with [Composer](https://getcomposer.org/), simply require the
latest version of this package.

```bash
composer require javaabu/nativepdf
```

Make sure that the autoload file from Composer is loaded.

```php
// somewhere early in your project's loading, require the Composer autoloader
// see: http://getcomposer.org/doc/00-intro.md
require 'vendor/autoload.php';
```

### Download and install

Download a packaged archive of nativepdf and extract it into the 
directory where nativepdf will reside

 * You can download stable copies of nativepdf from
   https://github.com/Javaabu/nativepdf/releases
 * Or download a nightly (the latest, unreleased code) from
   http://eclecticgeek.com/dompdf

Use the packaged release autoloader to load nativepdf, libraries,
and helper functions in your PHP:

```php
// include autoloader
require_once 'nativepdf/autoload.inc.php';
```

Note: packaged releases are named according using semantic
versioning (_nativepdf_MAJOR-MINOR-PATCH.zip_). So the 1.0.0 
release would be nativepdf_1-0-0.zip. Packaged releases include
the dependency releases available at the time of release
and are not necessarily updated to include updated dependencies.

### Install with git

From the command line, switch to the directory where nativepdf will
reside and run the following commands:

```sh
git clone https://github.com/Javaabu/nativepdf.git
cd nativepdf/lib

git clone https://github.com/PhenX/php-font-lib.git php-font-lib
cd php-font-lib
git checkout 0.5.1
cd ..

git clone https://github.com/PhenX/php-svg-lib.git php-svg-lib
cd php-svg-lib
git checkout v0.3.2
cd ..

git clone https://github.com/sabberworm/PHP-CSS-Parser.git php-css-parser
cd php-css-parser
git checkout 8.1.0
```

Require nativepdf and it's dependencies in your PHP.
For details see the [autoloader in the utils project](https://github.com/dompdf/utils/blob/master/autoload.inc.php).

## Framework Integration

* For Symfony: [nucleos/dompdf-bundle](https://github.com/nucleos/NucleosDompdfBundle)
* For Laravel: [barryvdh/laravel-dompdf](https://github.com/barryvdh/laravel-dompdf)
* For Redaxo: [PdfOut](https://github.com/FriendsOfREDAXO/pdfout)

## Quick Start

Just pass your HTML in to nativepdf and stream the output:

```php
// reference the NativePdf namespace
use NativePdf\NativePdf;

// instantiate and use the nativepdf class
$nativepdf = new NativePdf();
$nativepdf->loadHtml('hello world');

// (Optional) Setup the paper size and orientation
$nativepdf->setPaper('A4', 'landscape');

// Render the HTML as PDF
$nativepdf->render();

// Output the generated PDF to Browser
$nativepdf->stream();
```

### Setting Options

Set options during nativepdf instantiation:

```php
use NativePdf\NativePdf;
use NativePdf\Options;

$options = new Options();
$options->set('defaultFont', 'Courier');
$nativepdf = new NativePdf($options);
```

or at run time

```php
use NativePdf\NativePdf;

$nativepdf = new NativePdf();
$options = $nativepdf->getOptions();
$options->setDefaultFont('Courier');
$nativepdf->setOptions($options);
```

See [NativePdf\Options](src/Options.php) for a list of available options.

### Resource Reference Requirements

In order to protect potentially sensitive information NativePdf imposes 
restrictions on files referenced from the local file system or the web. 

Files accessed through web-based protocols have the following requirements:
 * The NativePdf option "isRemoteEnabled" must be set to "true"
 * PHP must either have the curl extension enabled or the 
   allow_url_fopen setting set to true
   
Files accessed through the local file system have the following requirement:
 * The file must fall within the path(s) specified for the NativePdf "chroot" option

## Limitations (Known Issues)

 * Table cells are not pageable, meaning a table row must fit on a single page: See https://github.com/dompdf/dompdf/issues/98
 * Elements are rendered on the active page when they are parsed.
 * Flexbox: `baseline` alignment behaves as `flex-start`; page breaks do
   not occur inside a flex container (it moves to the next page as a
   whole); wrapping is not supported for column direction
 * Bidirectional text: table columns and `@page :left/:right` are not
   mirrored for `direction: rtl`; shaping is limited to Unicode
   presentation forms (fonts that only provide shaping through OpenType
   GSUB tables are not supported)
 * Does not support CSS Grid: See https://github.com/dompdf/dompdf/issues/2988
 * A single NativePdf instance should not be used to render more than one HTML document
   because persisted parsing and rendering artifacts can impact future renders.

## Credits

NativePdf is built on top of [dompdf](https://github.com/dompdf/dompdf).

Nearly all of this package is dompdf's work: the HTML parser, the CSS 2.1 layout
and rendering engine, the PDF backends, the font handling, and the test suite.
NativePdf only adds flexbox, bidirectional text, and inline SVG on top of that
base. Without dompdf there would be nothing here to build on.

dompdf was created by **Benj Carson** and is maintained today by **Brian Sweeney**
and **Till Berger**, with help from a large community. Thank you to every dompdf
contributor.

 * dompdf project: https://github.com/dompdf/dompdf
 * dompdf contributors: https://github.com/dompdf/dompdf/graphs/contributors
 * Full author list, including the dompdf team and alumni: [AUTHORS.md](AUTHORS.md)

NativePdf keeps dompdf's original LGPL 2.1 license: see [LICENSE.LGPL](LICENSE.LGPL).
