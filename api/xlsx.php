<?php

declare(strict_types=1);
/**
 * Minimal Excel (.xlsx) writer and reader. Needs PHP's zip extension (ZipArchive) and SimpleXML.
 * No third-party code: an .xlsx file is a zip of XML parts.
 */
function xlsx_available(): bool
{
    return class_exists('ZipArchive') && function_exists('simplexml_load_string');
}

function x_esc(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}
function x_col(int $i): string
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
        $s = chr(65 + ($i - 1) % 26) . $s;
    } return $s;
}

/**
 * @param list<array{name:string,rows:list<list<string>>,widths?:list<int>,textCols?:list<int>,header?:bool,validations?:list<array{ref:string,formula:string}>}> $sheets
 * @return string binary .xlsx
 */
function xlsx_build(array $sheets): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'xl');
    $z = new ZipArchive();
    if ($z->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot create the Excel file.');
    }
    $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
    $head = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $ct = $wbSheets = $wbRels = '';
    foreach ($sheets as $i => $sh) {
        $n = $i + 1;
        $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $wbSheets .= '<sheet name="' . x_esc($sh['name']) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $wbRels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        $textCols = $sh['textCols'] ?? [];
        $cols = '';
        foreach (($sh['widths'] ?? []) as $c => $w) {
            $cols .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $w . '" customWidth="1"' . (in_array($c, $textCols, true) ? ' style="2"' : '') . '/>';
        }
        $rowsXml = '';
        foreach ($sh['rows'] as $r => $cells) {
            $isHead = !empty($sh['header']) && $r === 0;
            $rowsXml .= '<row r="' . ($r + 1) . '"' . ($isHead ? ' ht="32" customHeight="1"' : '') . '>';
            foreach ($cells as $c => $v) {
                $style = $isHead ? ' s="1"' : (($sh['titleRow'] ?? -1) === $r ? ' s="3"' : '');
                $rowsXml .= '<c r="' . x_col($c) . ($r + 1) . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . x_esc((string)$v) . '</t></is></c>';
            }
            $rowsXml .= '</row>';
        }
        $dv = '';
        if (!empty($sh['validations'])) {
            $dv = '<dataValidations count="' . count($sh['validations']) . '">';
            foreach ($sh['validations'] as $v) {
                $dv .= '<dataValidation type="list" allowBlank="1" showErrorMessage="1" errorTitle="Not allowed" error="Choose a value from the list." sqref="' . $v['ref'] . '"><formula1>' . x_esc($v['formula']) . '</formula1></dataValidation>';
            }
            $dv .= '</dataValidations>';
        }
        $pane = !empty($sh['header']) ? '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>' : '';
        $z->addFromString("xl/worksheets/sheet$n.xml", $head . "<worksheet $ns><sheetViews><sheetView workbookViewId=\"0\"" . ($i === 0 ? ' tabSelected="1"' : '') . ">$pane</sheetView></sheetViews><sheetFormatPr defaultRowHeight=\"15\"/>" . ($cols ? "<cols>$cols</cols>" : '') . "<sheetData>$rowsXml</sheetData>$dv</worksheet>");
    }
    $sn = count($sheets) + 1;
    $z->addFromString('[Content_Types].xml', $head . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . $ct . '</Types>');
    $z->addFromString('_rels/.rels', $head . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $z->addFromString('xl/workbook.xml', $head . "<workbook $ns xmlns:r=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships\"><sheets>$wbSheets</sheets></workbook>");
    $z->addFromString('xl/_rels/workbook.xml.rels', $head . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbRels . '<Relationship Id="rId' . $sn . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $z->addFromString('xl/styles.xml', $head . "<styleSheet $ns><fonts count=\"3\"><font><sz val=\"11\"/><name val=\"Calibri\"/></font><font><b/><sz val=\"11\"/><color rgb=\"FFFFFFFF\"/><name val=\"Calibri\"/></font><font><b/><sz val=\"12\"/><name val=\"Calibri\"/></font></fonts><fills count=\"3\"><fill><patternFill patternType=\"none\"/></fill><fill><patternFill patternType=\"gray125\"/></fill><fill><patternFill patternType=\"solid\"><fgColor rgb=\"FF14213D\"/><bgColor indexed=\"64\"/></patternFill></fill></fills><borders count=\"1\"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count=\"1\"><xf numFmtId=\"0\" fontId=\"0\" fillId=\"0\" borderId=\"0\"/></cellStyleXfs><cellXfs count=\"4\"><xf numFmtId=\"0\" fontId=\"0\" fillId=\"0\" borderId=\"0\" xfId=\"0\"/><xf numFmtId=\"0\" fontId=\"1\" fillId=\"2\" borderId=\"0\" xfId=\"0\" applyFont=\"1\" applyFill=\"1\" applyAlignment=\"1\"><alignment horizontal=\"center\" vertical=\"center\" wrapText=\"1\"/></xf><xf numFmtId=\"49\" fontId=\"0\" fillId=\"0\" borderId=\"0\" xfId=\"0\" applyNumberFormat=\"1\"/><xf numFmtId=\"0\" fontId=\"2\" fillId=\"0\" borderId=\"0\" xfId=\"0\" applyFont=\"1\"/></cellXfs><cellStyles count=\"1\"><cellStyle name=\"Normal\" xfId=\"0\" builtinId=\"0\"/></cellStyles></styleSheet>");
    $z->close();
    $bin = (string)file_get_contents($tmp);
    @unlink($tmp);
    return $bin;
}

/** Read the FIRST worksheet of an .xlsx file into a list of rows (list of strings). */
function xlsx_read(string $path): array
{
    $z = new ZipArchive();
    if ($z->open($path) !== true) {
        throw new RuntimeException('That file is not a valid Excel (.xlsx) workbook.');
    }
    $get = function (string $name) use ($z): ?string {
        $i = $z->locateName($name);
        if ($i === false) {
            return null;
        }
        $st = $z->statIndex($i);
        if (!$st || $st['size'] > 25 * 1048576) {
            throw new RuntimeException('The workbook is too large.');
        }   // zip-bomb guard
        $x = $z->getFromIndex($i);
        if ($x === false || stripos($x, '<!DOCTYPE') !== false) {
            throw new RuntimeException('The workbook contains unsafe content.');
        }
        return $x;
    };
    $load = fn (string $x) => simplexml_load_string($x, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
    $wb = $get('xl/workbook.xml');
    if ($wb === null) {
        throw new RuntimeException('That file is not a valid Excel (.xlsx) workbook.');
    }
    $wbx = $load($wb);
    $first = $wbx->sheets->sheet[0] ?? null;
    if (!$first) {
        throw new RuntimeException('The workbook has no sheets.');
    }
    $rid = (string)$first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
    $target = 'worksheets/sheet1.xml';
    if (($rels = $get('xl/_rels/workbook.xml.rels')) !== null) {
        foreach ($load($rels)->Relationship as $r) {
            if ((string)$r['Id'] === $rid) {
                $target = ltrim((string)$r['Target'], '/');
            }
        }
    }
    $sheetXml = $get(str_starts_with($target, 'xl/') ? $target : 'xl/' . $target);
    if ($sheetXml === null) {
        throw new RuntimeException('The first worksheet could not be read.');
    }
    $shared = [];
    if (($ss = $get('xl/sharedStrings.xml')) !== null) {
        foreach ($load($ss)->si as $si) {
            $t = '';
            if (isset($si->t)) {
                $t = (string)$si->t;
            }
            foreach ($si->r as $run) {
                $t .= (string)$run->t;
            }
            $shared[] = $t;
        }
    }
    $rows = [];
    foreach ($load($sheetXml)->sheetData->row ?? [] as $row) {
        $line = [];
        foreach ($row->c as $c) {
            preg_match('/^([A-Z]+)/', (string)$c['r'], $m);
            $col = 0;
            foreach (str_split($m[1] ?? 'A') as $ch) {
                $col = $col * 26 + ord($ch) - 64;
            }
            $t = (string)$c['t'];
            $v = '';
            if ($t === 's') {
                $v = $shared[(int)$c->v] ?? '';
            } elseif ($t === 'inlineStr') {
                $v = isset($c->is->t) ? (string)$c->is->t : '';
                foreach ($c->is->r ?? [] as $run) {
                    $v .= (string)$run->t;
                }
            } else {
                $v = (string)$c->v;
                if ($t === 'b') {
                    $v = $v === '1' ? 'TRUE' : 'FALSE';
                } elseif ($t !== 'str' && $v !== '' && is_numeric($v) && stripos($v, 'E') !== false) {
                    $v = sprintf('%.0f', (float)$v);
                } elseif ($t !== 'str' && preg_match('/^-?\d+\.0+$/', $v)) {
                    $v = (string)(int)$v;
                }
            }
            $line[$col - 1] = trim($v);
        }
        if ($line) {
            $max = max(array_keys($line));
            $full = [];
            for ($i = 0; $i <= $max; $i++) {
                $full[$i] = $line[$i] ?? '';
            } $rows[] = $full;
        } else {
            $rows[] = [];
        }
        if (count($rows) > 2100) {
            throw new RuntimeException('The file has more than 2000 rows. Split it into smaller files.');
        }
    }
    $z->close();
    return $rows;
}
