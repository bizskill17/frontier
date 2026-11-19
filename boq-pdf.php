<?php
defined('ABSPATH') || exit;

final class Frontier_Boq_Pdf {
    private static function text($value) {
        $value = preg_replace('/\s+/u',' ',(string)$value) ?? '';
        if (function_exists('iconv')) {
            $encoded = @iconv('UTF-8','Windows-1252//TRANSLIT',$value);
            if ($encoded !== false) {
                return $encoded;
            }
        }
        return preg_replace('/[^\x20-\x7E]/u','?',$value) ?? '';
    }

    private static function line($text,$x,$y,$size=10) {
        $escaped = strtr($text,['\\'=>'\\\\','('=>'\(',')'=>'\)']);
        return "BT /F1 $size Tf $x $y Td ($escaped) Tj ET\n";
    }

    public static function build($number,$site,$date,$prepared,$rows,$title='BOQ',$ownerLabel='Prepared By',$extraHeading=[],$footer=[]) {
        $heading = [
            self::text($title.' '.$number), self::text('Site: '.$site),
            self::text('Date: '.$date), self::text($ownerLabel.': '.$prepared),
        ];
        foreach ($extraHeading as $line) {
            $heading[] = self::text($line);
        }
        $pages = [];
        $start = static function () use ($heading) {
            $content = '';
            $headerLines = [];
            foreach ($heading as $index=>$text) {
                foreach (explode("\n",wordwrap($text,82,"\n",true)) as $part) {
                    $headerLines[] = [$part,$index === 0 ? 15 : 10];
                }
            }
            $y = 795;
            foreach ($headerLines as [$text,$size]) {
                $content .= self::line($text,36,$y,$size);
                $y -= 18;
            }
            $content .= self::line('Sl No.',36,$y).self::line('Item Name',82,$y)
                .self::line('Quantity',405,$y).self::line('Units',477,$y);
            return [$content,$y-20];
        };
        [$content,$y] = $start();
        foreach ($rows as $row) {
            [$line,$name,$qty,$unit] = $row;
            $names = explode("\n",wordwrap(self::text($name),50,"\n",true));
            $units = explode("\n",wordwrap(self::text($unit),16,"\n",true));
            $height = max(count($names),count($units))*14 + 4;
            if ($y-$height < 40) {
                $pages[] = $content;
                [$content,$y] = $start();
            }
            $content .= self::line(self::text($line),36,$y).self::line(self::text($qty),405,$y);
            foreach ($names as $index=>$part) {
                $content .= self::line($part,82,$y-$index*14);
            }
            foreach ($units as $index=>$part) {
                $content .= self::line($part,477,$y-$index*14);
            }
            $y -= $height;
        }
        foreach ($footer as $line) {
            foreach (explode("\n",wordwrap(self::text($line),82,"\n",true)) as $part) {
                if ($y < 40) {
                    $pages[] = $content;
                    $content = self::line($heading[0],36,795,15);
                    $y = 773;
                }
                $content .= self::line($part,36,$y);
                $y -= 14;
            }
        }
        $pages[] = $content;

        $objects = [
            1=>'<< /Type /Catalog /Pages 2 0 R >>',
            2=>'',
            3=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];
        $kids = [];
        foreach ($pages as $stream) {
            $pageId = count($objects)+1;
            $streamId = $pageId+1;
            $kids[] = "$pageId 0 R";
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents $streamId 0 R >>";
            $objects[$streamId] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream."endstream";
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ',$kids).'] /Count '.count($kids).' >>';
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id=>$object) {
            $offsets[] = strlen($pdf);
            $pdf .= "$id 0 obj\n$object\nendobj\n";
        }
        $xref = strlen($pdf);
        $count = count($objects)+1;
        $pdf .= "xref\n0 $count\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n",$offset);
        }
        return $pdf."trailer\n<< /Size $count /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }
}
