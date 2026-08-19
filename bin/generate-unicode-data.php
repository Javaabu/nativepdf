<?php
/**
 * Generates the Unicode data tables used by the bidirectional-text and
 * Arabic-shaping support (src/Text/*) from the Unicode Character Database,
 * and fetches the official UAX #9 conformance test files.
 *
 * Usage: php bin/generate-unicode-data.php
 *
 * The generated files under lib/res/unicode/ and tests/_files/unicode/ are
 * committed to the repository; this script only needs to be re-run when
 * upgrading UNICODE_VERSION.
 */

const UNICODE_VERSION = "16.0.0";

$base = "https://www.unicode.org/Public/" . UNICODE_VERSION . "/ucd";
$outDir = __DIR__ . "/../lib/res/unicode";
$testDir = __DIR__ . "/../tests/_files/unicode";
$cacheDir = sys_get_temp_dir() . "/nativepdf-ucd-" . UNICODE_VERSION;

@mkdir($outDir, 0755, true);
@mkdir($testDir, 0755, true);
@mkdir($cacheDir, 0755, true);

/**
 * @param string $path Path relative to the UCD root
 * @return string File contents
 */
function fetch(string $path): string
{
    global $base, $cacheDir;

    $cache = $cacheDir . "/" . str_replace("/", "_", $path);

    if (is_file($cache)) {
        return file_get_contents($cache);
    }

    echo "Fetching $path\n";
    $data = file_get_contents("$base/$path");

    if ($data === false) {
        fwrite(STDERR, "Failed to download $path\n");
        exit(1);
    }

    file_put_contents($cache, $data);

    return $data;
}

/**
 * Parse UCD lines of the form `XXXX[..YYYY] ; Value # comment` into
 * [start, end, value] triples. Lines starting with `# @missing:` are parsed
 * into a separate list.
 *
 * @param string $data
 * @return array [assigned, missing]
 */
function parseRanges(string $data): array
{
    $assigned = [];
    $missing = [];

    foreach (explode("\n", $data) as $line) {
        if (preg_match('/^# @missing: ([0-9A-F]+)\.\.([0-9A-F]+); ([\w -]+)/', $line, $m)) {
            $missing[] = [hexdec($m[1]), hexdec($m[2]), trim($m[3])];
            continue;
        }

        $line = preg_replace('/#.*$/', '', $line);
        $line = trim($line);
        if ($line === "") {
            continue;
        }

        $parts = array_map("trim", explode(";", $line));
        if (count($parts) < 2) {
            continue;
        }

        if (strpos($parts[0], "..") !== false) {
            [$s, $e] = explode("..", $parts[0]);
        } else {
            $s = $e = $parts[0];
        }

        $assigned[] = [hexdec($s), hexdec($e), $parts[1]];
    }

    return [$assigned, $missing];
}

/**
 * Sort ranges and merge adjacent ranges with equal values.
 *
 * @param array $ranges
 * @return array
 */
function mergeRanges(array $ranges): array
{
    usort($ranges, function ($a, $b) {
        return $a[0] <=> $b[0];
    });

    $merged = [];

    foreach ($ranges as $r) {
        $last = count($merged) - 1;

        if ($last >= 0 && $merged[$last][2] === $r[2] && $merged[$last][1] + 1 >= $r[0]) {
            $merged[$last][1] = max($merged[$last][1], $r[1]);
        } else {
            $merged[] = $r;
        }
    }

    return $merged;
}

/**
 * @param string $file
 * @param mixed  $data
 */
function emit(string $file, $data): void
{
    global $outDir;

    $export = var_export($data, true);
    // Compact the var_export output somewhat
    $export = preg_replace('/^(\s*)array \(/m', '$1[', $export);
    $export = preg_replace('/^(\s*)\),/m', '$1],', $export);
    $export = preg_replace('/\)$/', ']', $export);

    $content = "<?php\n"
        . "// Generated from the Unicode Character Database " . UNICODE_VERSION . "\n"
        . "// by bin/generate-unicode-data.php. Do not edit.\n"
        . "return " . $export . ";\n";

    file_put_contents("$outDir/$file", $content);
    echo "Wrote lib/res/unicode/$file (" . strlen($content) . " bytes)\n";
}

// ---- Bidi classes ----------------------------------------------------------

$longToShort = [
    "Left_To_Right" => "L",
    "Right_To_Left" => "R",
    "Arabic_Letter" => "AL",
    "European_Number" => "EN",
    "European_Separator" => "ES",
    "European_Terminator" => "ET",
    "Arabic_Number" => "AN",
    "Common_Separator" => "CS",
    "Nonspacing_Mark" => "NSM",
    "Boundary_Neutral" => "BN",
    "Paragraph_Separator" => "B",
    "Segment_Separator" => "S",
    "White_Space" => "WS",
    "Other_Neutral" => "ON",
    "Left_To_Right_Embedding" => "LRE",
    "Left_To_Right_Override" => "LRO",
    "Right_To_Left_Embedding" => "RLE",
    "Right_To_Left_Override" => "RLO",
    "Pop_Directional_Format" => "PDF",
    "Left_To_Right_Isolate" => "LRI",
    "Right_To_Left_Isolate" => "RLI",
    "First_Strong_Isolate" => "FSI",
    "Pop_Directional_Isolate" => "PDI",
];

[$assigned, $missing] = parseRanges(fetch("extracted/DerivedBidiClass.txt"));

$defaults = [];
foreach ($missing as $m) {
    $value = isset($longToShort[$m[2]]) ? $longToShort[$m[2]] : $m[2];
    // The global L default is implicit in the lookup
    if ($m[0] === 0 && $m[1] === 0x10FFFF && $value === "L") {
        continue;
    }
    $defaults[] = [$m[0], $m[1], $value];
}

emit("bidi_classes.php", [
    "assigned" => mergeRanges($assigned),
    "defaults" => mergeRanges($defaults),
]);

// ---- Mirror pairs ----------------------------------------------------------

[$mirrors] = parseRanges(fetch("BidiMirroring.txt"));
$mirrorMap = [];
foreach ($mirrors as $m) {
    $mirrorMap[$m[0]] = hexdec($m[2]);
}
ksort($mirrorMap);
emit("bidi_mirror.php", $mirrorMap);

// ---- Bracket pairs ---------------------------------------------------------

$bracketMap = [];
foreach (explode("\n", fetch("BidiBrackets.txt")) as $line) {
    $line = preg_replace('/#.*$/', '', $line);
    $parts = array_map("trim", explode(";", $line));
    if (count($parts) < 3 || $parts[0] === "") {
        continue;
    }
    $bracketMap[hexdec($parts[0])] = [hexdec($parts[1]), $parts[2]];
}
ksort($bracketMap);
emit("bidi_brackets.php", $bracketMap);

// ---- Joining types ---------------------------------------------------------

[$joining] = parseRanges(fetch("extracted/DerivedJoiningType.txt"));
// Default is U; only store the rest
$joining = array_values(array_filter($joining, function ($r) {
    return $r[2] !== "U";
}));
emit("joining_types.php", mergeRanges($joining));

// ---- Arabic presentation forms and lam-alef ligatures ----------------------

$forms = [];
$ligatures = [];

foreach (explode("\n", fetch("UnicodeData.txt")) as $line) {
    $fields = explode(";", $line);
    if (count($fields) < 6) {
        continue;
    }

    $cp = hexdec($fields[0]);

    // Arabic Presentation Forms-A and -B
    if (!(($cp >= 0xFB50 && $cp <= 0xFDFF) || ($cp >= 0xFE70 && $cp <= 0xFEFF))) {
        continue;
    }

    if (!preg_match('/^<(isolated|final|initial|medial)> ([0-9A-F ]+)$/', $fields[5], $m)) {
        continue;
    }

    $slot = ["isolated" => 0, "final" => 1, "initial" => 2, "medial" => 3][$m[1]];
    $sources = array_map("hexdec", explode(" ", trim($m[2])));

    if (count($sources) === 1) {
        $source = $sources[0];
        if (!isset($forms[$source])) {
            $forms[$source] = [null, null, null, null];
        }
        $forms[$source][$slot] = $cp;
    } elseif (count($sources) === 2 && $sources[0] === 0x0644 && $slot <= 1
        && in_array($sources[1], [0x0622, 0x0623, 0x0625, 0x0627], true)
    ) {
        // The four mandatory lam-alef ligatures (isolated and final forms);
        // optional ligatures are not applied, as font coverage is unreliable
        if (!isset($ligatures[$sources[1]])) {
            $ligatures[$sources[1]] = [null, null];
        }
        $ligatures[$sources[1]][$slot] = $cp;
    }
}

ksort($forms);
ksort($ligatures);
emit("arabic_forms.php", ["forms" => $forms, "ligatures" => $ligatures]);

// ---- Conformance test data -------------------------------------------------

foreach (["BidiTest.txt", "BidiCharacterTest.txt"] as $file) {
    $data = fetch($file);
    file_put_contents("$testDir/$file.gz", gzencode($data, 9));
    echo "Wrote tests/_files/unicode/$file.gz (" . strlen(gzencode($data, 9)) . " bytes)\n";
}

echo "Done.\n";
