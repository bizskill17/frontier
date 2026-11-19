<?php
defined('ABSPATH') || exit;

final class Frontier_Boq_Workbook {
    public const HEADERS = ['Item Name','Quantity','Unit'];

    public static function available() {
        return class_exists('ZipArchive') && function_exists('simplexml_load_string');
    }

    private static function xml($zip,$path,$limit,$optional=false) {
        $part = $zip->statName($path);
        if (!$part && $optional) {
            return null;
        }
        if (!$part || $part['size'] > $limit) {
            throw new DomainException('The Excel file is missing a worksheet or exceeds the worksheet size limit.');
        }
        $contents = $zip->getFromName($path);
        if ($contents === false || strpos($contents,"\0") !== false || stripos($contents,'<!DOCTYPE') !== false) {
            throw new DomainException('The Excel workbook contains unsupported XML.');
        }
        $xml = @simplexml_load_string($contents,'SimpleXMLElement',LIBXML_NONET);
        if (!$xml) {
            throw new DomainException('The Excel workbook could not be read.');
        }
        return $xml;
    }

    public static function read($path) {
        if (!self::available()) {
            throw new DomainException('Excel support requires PHP ZipArchive and SimpleXML on this site.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path,ZipArchive::RDONLY) !== true) {
            throw new DomainException('Select a valid .xlsx workbook.');
        }
        try {
            $book = self::xml($zip,'xl/workbook.xml',300000);
            $rels = self::xml($zip,'xl/_rels/workbook.xml.rels',300000);
            $sheets = $book->xpath('//*[local-name()="sheet"]');
            $links = $rels->xpath('//*[local-name()="Relationship"]');
            $target = '';
            if ($sheets) {
                $relId = (string)$sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                foreach ($links as $link) {
                    if ((string)$link['Id'] === $relId) {
                        $target = (string)$link['Target'];
                        break;
                    }
                }
            }
            if (strpos($target,'/xl/') === 0) {
                $target = substr($target,4);
            }
            if (!preg_match('#^worksheets/[A-Za-z0-9_.-]+\.xml$#D',$target)) {
                throw new DomainException('The first workbook tab must be a standard Excel worksheet.');
            }
            $shared = [];
            $strings = self::xml($zip,'xl/sharedStrings.xml',4000000,true);
            if ($strings !== null) {
                foreach ($strings->xpath('//*[local-name()="si"]') as $node) {
                    $shared[] = implode('',array_map('strval',$node->xpath('.//*[local-name()="t"]')));
                }
            }
            $sheet = self::xml($zip,'xl/'.$target,4000000);
            $rows = [];
            $columnMap = [];
            foreach ($sheet->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
                $cells = [];
                foreach ($row->xpath('./*[local-name()="c"]') as $cell) {
                    if (!preg_match('/^([A-Z]+)[0-9]+$/D',(string)$cell['r'],$match)) {
                        continue;
                    }
                    $valueNodes = $cell->xpath('./*[local-name()="v"]');
                    $value = (string)($valueNodes[0] ?? '');
                    $type = (string)$cell['t'];
                    if ($type === 's') {
                        $value = $shared[(int)$value] ?? '';
                    } elseif ($type === 'inlineStr') {
                        $value = implode('',array_map('strval',$cell->xpath('.//*[local-name()="t"]')));
                    }
                    $cells[$match[1]] = trim($value);
                }
                if (!array_filter($cells,'strlen')) {
                    continue;
                }
                if (!$columnMap) {
                    foreach (self::HEADERS as $header) {
                        $hits = array_keys(array_map('strtolower',$cells),strtolower($header),true);
                        if (count($hits) !== 1) {
                            throw new DomainException('The first row must contain Item Name, Quantity and Unit.');
                        }
                        $columnMap[] = $hits[0];
                    }
                    continue;
                }
                $values = array_map(static function ($column) use ($cells) { return $cells[$column] ?? ''; },$columnMap);
                if (!array_filter($values,'strlen')) {
                    continue;
                }
                $rows[] = ['name'=>$values[0],'qty'=>$values[1],'unit'=>$values[2]];
                if (count($rows) > 500) {
                    throw new DomainException('Upload up to 500 BOQ item rows per workbook.');
                }
            }
            if (!$rows) {
                throw new DomainException('Add at least one item below the Excel headings.');
            }
            return $rows;
        } finally {
            $zip->close();
        }
    }

    public static function template($path) {
        $zip = new ZipArchive();
        if ($zip->open($path,ZipArchive::OVERWRITE) !== true) {
            return false;
        }
        $files = [
            '[Content_Types].xml'=>'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels'=>'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml'=>'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="BOQ Items" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels'=>'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
        ];
        $headings = '';
        foreach (self::HEADERS as $index=>$heading) {
            $headings .= '<c r="'.chr(65+$index).'1" t="inlineStr"><is><t>'.$heading.'</t></is></c>';
        }
        $files['xl/worksheets/sheet1.xml'] = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1">'.$headings.'</row></sheetData></worksheet>';
        foreach ($files as $name=>$xml) {
            if (!$zip->addFromString($name,'<?xml version="1.0" encoding="UTF-8"?>'.$xml)) {
                $zip->close();
                return false;
            }
        }
        return $zip->close();
    }
}
