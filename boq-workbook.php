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
        return self::export($path,self::HEADERS,[]);
    }

    public static function export($path,$headers,$rows) {
        $zip = new ZipArchive();
        if ($zip->open($path,ZipArchive::OVERWRITE) !== true) {
            return false;
        }
        $main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $package = 'http://schemas.openxmlformats.org/package/2006/relationships';
        $files = [
            '[Content_Types].xml'=>'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels'=>'<Relationships xmlns="'.$package.'"><Relationship Id="rId1" Type="'.$rel.'/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml'=>'<workbook xmlns="'.$main.'" xmlns:r="'.$rel.'"><sheets><sheet name="Records" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels'=>'<Relationships xmlns="'.$package.'"><Relationship Id="rId1" Type="'.$rel.'/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="'.$rel.'/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml'=>'<styleSheet xmlns="'.$main.'"><numFmts count="2"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/><numFmt numFmtId="165" formatCode="dd/mm/yyyy hh:mm:ss"/></numFmts><fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
        ];
        $links = $relations = $sheet = '';
        $linkCount = 0;
        array_unshift($rows,$headers);
        foreach ($rows as $index=>$row) {
            $number = $index+1;
            $sheet .= '<row r="'.$number.'">';
            $column = 0;
            foreach ($headers as $key=>$header) {
                $reference = self::columnName(++$column).$number;
                $value = $row[$key] ?? '';
                if (is_array($value)) {
                    if (!empty($value['url'])) {
                        $id = 'rId'.(++$linkCount);
                        $links .= '<hyperlink ref="'.$reference.'" r:id="'.$id.'"/>';
                        $relations .= '<Relationship Id="'.$id.'" Type="'.$rel.'/hyperlink" Target="'.self::escape($value['url']).'" TargetMode="External"/>';
                    }
                    $value = $value['text'] ?? '';
                }
                if ($number > 1 && preg_match('/\bdate\b|timestamp/i',$header)
                    && preg_match('~^(\d{2})/(\d{2})/(\d{4})(?: (\d{2}):(\d{2}):(\d{2}))?$~D',(string)$value,$parts)
                    && checkdate((int)$parts[2],(int)$parts[1],(int)$parts[3])) {
                    $date = DateTimeImmutable::createFromFormat('!Y-m-d',$parts[3].'-'.$parts[2].'-'.$parts[1],new DateTimeZone('UTC'));
                    $serial = $date->getTimestamp()/86400 + 25569;
                    $time = isset($parts[4]);
                    if ($time) {
                        $serial += ((int)$parts[4]*3600+(int)$parts[5]*60+(int)$parts[6])/86400;
                    }
                    $sheet .= '<c r="'.$reference.'" s="'.($time ? 2 : 1).'"><v>'.$serial.'</v></c>';
                } elseif ($number > 1 && (is_int($value) || is_float($value) || (preg_match('/quantity|qty|amount|rate|pending|received|ordered|total quantity/i',$header)
                    && preg_match('/^-?(?:\d+|\d{1,3}(?:,\d{3})+)(?:\.\d+)?$/D',(string)$value)))
                    && !preg_match('/\b(?:no\.?|number|code|id|mobile|phone|pin)\b/i',$header)) {
                    $sheet .= '<c r="'.$reference.'"><v>'.str_replace(',','',(string)$value).'</v></c>';
                } else {
                    $sheet .= '<c r="'.$reference.'" t="inlineStr"><is><t xml:space="preserve">'.self::escape($value).'</t></is></c>';
                }
            }
            $sheet .= '</row>';
        }
        $files['xl/worksheets/sheet1.xml'] = '<worksheet xmlns="'.$main.'" xmlns:r="'.$rel.'"><sheetData>'.$sheet.'</sheetData>'
            .($links === '' ? '' : '<hyperlinks>'.$links.'</hyperlinks>').'</worksheet>';
        if ($links !== '') {
            $files['xl/worksheets/_rels/sheet1.xml.rels'] = '<Relationships xmlns="'.$package.'">'.$relations.'</Relationships>';
        }
        foreach ($files as $name=>$xml) {
            if (!$zip->addFromString($name,'<?xml version="1.0" encoding="UTF-8"?>'.$xml)) {
                $zip->close();
                return false;
            }
        }
        return $zip->close();
    }

    private static function columnName($column) {
        $name = '';
        while ($column > 0) {
            $column--;
            $name = chr(65+$column%26).$name;
            $column = intdiv($column,26);
        }
        return $name;
    }

    private static function escape($value) {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/','',(string)$value);
        return htmlspecialchars($value,ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE,'UTF-8');
    }
}
