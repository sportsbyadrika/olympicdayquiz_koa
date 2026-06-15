<?php
/**
 * Minimal spreadsheet reader for bulk uploads.
 * Supports .csv and .xlsx (Office Open XML) using only built-in PHP
 * extensions (ZipArchive + SimpleXML) — no third-party library required.
 *
 * Returns rows as a list of arrays of trimmed string cell values.
 */
declare(strict_types=1);

/** Read a .csv or .xlsx file into rows. Throws RuntimeException on failure. */
function read_spreadsheet_rows(string $path, string $originalName): array
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext === 'xlsx') {
        return read_xlsx_rows($path);
    }
    return read_csv_rows($path);
}

/** Read a CSV file into rows. */
function read_csv_rows(string $path): array
{
    $rows = [];
    $h = fopen($path, 'r');
    if ($h === false) {
        throw new RuntimeException('Could not read the file.');
    }
    while (($row = fgetcsv($h)) !== false) {
        $rows[] = array_map(fn($c) => trim((string) $c), $row);
    }
    fclose($h);
    return $rows;
}

/** Read the first worksheet of an .xlsx file into rows. */
function read_xlsx_rows(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Could not open the Excel file.');
    }

    // Shared strings (cell text is often stored here, referenced by index).
    $shared = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false) {
        $xml = @simplexml_load_string($ss);
        if ($xml !== false) {
            foreach ($xml->si as $si) {
                $shared[] = xlsx_si_text($si);
            }
        }
    }

    // First worksheet (standard path for the first sheet).
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('Could not read the first worksheet.');
    }
    $xml = @simplexml_load_string($sheetXml);
    if ($xml === false || !isset($xml->sheetData)) {
        throw new RuntimeException('The Excel sheet could not be parsed.');
    }

    $rows = [];
    foreach ($xml->sheetData->row as $row) {
        $cells = [];
        $maxIdx = -1;
        foreach ($row->c as $c) {
            $ref = (string) $c['r'];
            $idx = $ref !== '' ? xlsx_col_index($ref) : count($cells);
            $type = (string) $c['t'];
            if ($type === 's') {
                $val = $shared[(int) $c->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $val = isset($c->is) ? xlsx_si_text($c->is) : '';
            } else {
                $val = (string) $c->v;
            }
            $cells[$idx] = trim($val);
            $maxIdx = max($maxIdx, $idx);
        }
        $rowArr = [];
        for ($i = 0; $i <= $maxIdx; $i++) {
            $rowArr[$i] = $cells[$i] ?? '';
        }
        $rows[] = $rowArr;
    }
    return $rows;
}

/** Concatenate the text of a shared-string / inline-string node (incl. rich runs). */
function xlsx_si_text(SimpleXMLElement $si): string
{
    if (isset($si->t)) {
        return (string) $si->t;
    }
    $txt = '';
    foreach ($si->r as $r) {
        $txt .= (string) $r->t;
    }
    return $txt;
}

/** Convert a cell reference like "B12" to a zero-based column index. */
function xlsx_col_index(string $ref): int
{
    if (!preg_match('/^([A-Za-z]+)/', $ref, $m)) {
        return 0;
    }
    $letters = strtoupper($m[1]);
    $n = 0;
    for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return $n - 1;
}
