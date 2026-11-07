<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;
use ZipArchive;

/**
 * A one-sheet Excel workbook, written row by row to a temp file so a long list stays under the memory limit. An xlsx is a
 * zip of XML files, so it only needs PHP's zip extension. Text cells stay text: contact numbers keep their digits, and a
 * value starting with "=" is never run as a formula.
 */
class XlsxFile
{
    private const STYLE_DATE = 1;

    private const STYLE_HEADER = 2;

    private const COLUMN_WIDTH = 20;

    /**
     * @param  list<string>  $headers
     * @param  iterable<int, list<string|int|float|CarbonInterface|null>>  $rows
     * @return string the path of the temp file; the caller removes it
     */
    public static function write(array $headers, iterable $rows): string
    {
        $path = self::tempFile();
        $sheetPath = self::writeSheet($headers, $rows);

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            unlink($sheetPath);

            throw new RuntimeException('Could not create the Excel file.');
        }

        $zip->addFromString('[Content_Types].xml', self::xmlDeclaration().'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', self::xmlDeclaration().'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', self::xmlDeclaration().'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Leads" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::xmlDeclaration().'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', self::xmlDeclaration().'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="14" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs></styleSheet>');
        $zip->addFile($sheetPath, 'xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($sheetPath);

        return $path;
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<int, list<string|int|float|CarbonInterface|null>>  $rows
     */
    private static function writeSheet(array $headers, iterable $rows): string
    {
        $path = self::tempFile();
        $sheet = fopen($path, 'wb');

        fwrite($sheet, self::xmlDeclaration().'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">');
        fwrite($sheet, '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>');
        fwrite($sheet, '<cols><col min="1" max="'.max(1, count($headers)).'" width="'.self::COLUMN_WIDTH.'" customWidth="1"/></cols><sheetData>');
        fwrite($sheet, '<row>'.implode('', array_map(fn (string $header) => self::textCell($header, self::STYLE_HEADER), $headers)).'</row>');

        foreach ($rows as $row) {
            fwrite($sheet, '<row>'.implode('', array_map(self::cell(...), $row)).'</row>');
        }

        fwrite($sheet, '</sheetData></worksheet>');
        fclose($sheet);

        return $path;
    }

    private static function cell(string|int|float|CarbonInterface|null $value): string
    {
        return match (true) {
            $value === null => '<c/>',
            $value instanceof CarbonInterface => '<c s="'.self::STYLE_DATE.'"><v>'.self::dateSerial($value).'</v></c>',
            is_string($value) => self::textCell($value),
            default => '<c><v>'.$value.'</v></c>',
        };
    }

    private static function textCell(string $text, int $style = 0): string
    {
        // XML can't hold control characters, and one in a customer's name would make Excel reject the whole file.
        $clean = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';
        $styleAttribute = $style > 0 ? ' s="'.$style.'"' : '';

        return '<c t="inlineStr"'.$styleAttribute.'><is><t xml:space="preserve">'.htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES).'</t></is></c>';
    }

    /**
     * Excel stores a date as its count of days, with 25569 being Jan 1, 1970.
     */
    private static function dateSerial(CarbonInterface $date): int
    {
        return (int) (CarbonImmutable::parse($date->toDateString(), 'UTC')->getTimestamp() / 86400) + 25569;
    }

    private static function tempFile(): string
    {
        return tempnam(sys_get_temp_dir(), 'xlsx') ?: throw new RuntimeException('Could not create a temp file.');
    }

    private static function xmlDeclaration(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    }
}
