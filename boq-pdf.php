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

    private static function line($text,$x,$y,$size=10,$font='F1') {
        $escaped = strtr($text,['\\'=>'\\\\','('=>'\(',')'=>'\)']);
        return "BT /$font $size Tf $x $y Td ($escaped) Tj ET\n";
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

        return self::document($pages);
    }

    public static function buildBoq($number,$site,$date,$prepared,$rows) {
        $start = static function () use ($number,$site,$date,$prepared) {
            $stream = "0 g 0 G 0.5 w\n";
            $header = [
                'Frontier Sales Corporation',
                '( A unit of FRONTIER CORPORATION LLP )',
                '3rd Floor, Temple Tower, opp News Live,',
                'Christian Basti, G S Road, Guwahati-781005',
            ];
            foreach ($header as $index=>$text) {
                $stream .= self::poAligned($text,396,565-$index*17,14,'center',true);
            }
            $stream .= "40 503 m 752 503 l S\n";
            $stream .= self::poAligned('BOQ # '.$number,396,471,23,'center',true);

            $siteLines = explode("\n",wordwrap(self::text($site),37,"\n",true));
            $preparedLines = explode("\n",wordwrap(self::text($prepared),34,"\n",true));
            $top = 438;
            foreach ([[['Date:'],[self::text($date)],['Site Name'],$siteLines],
                [['BOQ Prepared By'],$preparedLines,[''],['']]] as $cells) {
                $height = max(array_map('count',$cells))*13+9;
                $stream .= self::boqCellBorders($top,$height,[40,154,396,503,752]);
                foreach ($cells as $column=>$lines) {
                    foreach ($lines as $index=>$line) {
                        $stream .= self::line($line,[47,161,403,510][$column],$top-14-$index*13,10,
                            $column % 2 === 0 ? 'F2' : 'F1');
                    }
                }
                $top -= $height;
            }
            $top -= 14;
            $stream .= self::boqCellBorders($top,24,[40,100,561,661,752]);
            foreach (['Sl No.','Item Name','Quantity','Unit'] as $column=>$text) {
                $stream .= self::line($text,[47,107,568,668][$column],$top-16,10,'F2');
            }
            return [$stream,$top-24];
        };

        $pages = [];
        [$stream,$top] = $start();
        foreach ($rows as [$line,$name,$qty,$unit]) {
            $names = explode("\n",wordwrap(self::text($name),45,"\n",true));
            $units = explode("\n",wordwrap(self::text($unit),8,"\n",true));
            $height = max(count($names),count($units))*13+9;
            if ($top-$height < 41) {
                $pages[] = $stream;
                [$stream,$top] = $start();
            }
            $stream .= self::boqCellBorders($top,$height,[40,100,561,661,752]);
            $stream .= self::poAligned($line,47,$top-14,10,'left')
                .self::poAligned($qty,654,$top-14,10,'right');
            foreach ($names as $index=>$text) {
                $stream .= self::line($text,107,$top-14-$index*13);
            }
            foreach ($units as $index=>$text) {
                $stream .= self::line($text,668,$top-14-$index*13);
            }
            $top -= $height;
        }
        $pages[] = $stream;
        return self::document($pages,[],792,612);
    }

    private static function boqCellBorders($top,$height,$edges) {
        $left = $edges[0];
        $width = end($edges)-$left;
        $bottom = $top-$height;
        $stream = "$left $bottom $width $height re S\n";
        foreach (array_slice($edges,1,-1) as $x) {
            $stream .= "$x $bottom m $x $top l S\n";
        }
        return $stream;
    }

    public static function buildIndent($number,$site,$date,$requested,$rows) {
        return self::buildItemTable('Indent '.$number,'MATERIAL INDENT',
            ['Site'=>$site,'Indent Date'=>$date,'Requested By'=>$requested],$rows);
    }

    private static function buildItemTable($reference,$title,$details,$rows) {
        $navy = '0.07 0.20 0.36';
        $grid = static function ($top,$height,$color) {
            $bottom = $top-$height;
            $stream = "$color RG 0.5 w 32 $bottom 531 $height re S\n";
            foreach ([82,385,453] as $x) {
                $stream .= "$x $bottom m $x $top l S\n";
            }
            return $stream;
        };
        $start = static function () use ($navy,$reference,$title,$details,$grid) {
            $stream = "$navy rg 32 740 531 70 re f\n1 g\n"
                .self::poAligned($reference,297.5,787,11,'center',true)
                .self::poAligned($title,297.5,758,22,'center',true);
            $y = 721;
            $stream .= "$navy rg\n";
            foreach ($details as $label=>$value) {
                $stream .= self::poAligned($label,46,$y,10,'left',true);
                foreach (explode("\n",wordwrap(self::text($value),53,"\n",true)) as $part) {
                    $stream .= self::line($part,131,$y,10);
                    $y -= 14;
                }
                $y -= 4;
            }
            $y -= 5;
            $stream .= "$navy rg 32 ".($y-24)." 531 24 re f\n1 g\n"
                .self::poAligned('Sl No.',44,$y-16,10,'left',true)
                .self::poAligned('Item Name',94,$y-16,10,'left',true)
                .self::poAligned('Quantity',445,$y-16,10,'right',true)
                .self::poAligned('Unit',461,$y-16,10,'left',true)
                .$grid($y,24,'0.43 0.54 0.66');
            return [$stream,$y-24];
        };

        [$stream,$y] = $start();
        $pages = [];
        foreach (array_values($rows) as $index=>[$line,$name,$qty,$unit]) {
            $names = explode("\n",wordwrap(self::text($name),29,"\n",true));
            $units = explode("\n",wordwrap(self::text($unit),10,"\n",true));
            $height = max(count($names),count($units))*13+10;
            if ($y-$height < 54) {
                $pages[] = $stream;
                [$stream,$y] = $start();
            }
            if ($index % 2 === 0) {
                $stream .= "0.94 0.96 0.98 rg 32 ".($y-$height)." 531 $height re f\n";
            }
            $stream .= $grid($y,$height,'0.73 0.79 0.85')."$navy rg\n".self::poAligned($line,44,$y-15,10,'left')
                .self::poAligned($qty,445,$y-15,10,'right');
            foreach ($names as $offset=>$part) {
                $stream .= self::line($part,94,$y-15-$offset*13);
            }
            foreach ($units as $offset=>$part) {
                $stream .= self::line($part,461,$y-15-$offset*13);
            }
            $y -= $height;
        }
        $pages[] = $stream;
        $total = count($pages);
        foreach ($pages as $index=>&$page) {
            $page .= "0.75 0.81 0.88 RG 32 47 m 563 47 l S\n$navy rg\n"
                .self::poAligned($reference,32,32,9,'left')
                .self::poAligned('Page '.($index+1).' of '.$total,563,32,9,'right');
        }
        unset($page);
        return self::document($pages);
    }

    public static function buildPurchaseOrder($reference,$details,$rows,$total,$terms,$logo=[]) {
        $navy = '0.04 0.15 0.27';
        $rule = "$navy RG 32 75 m 563 75 l S\n";
        $footer = $rule;
        $contact = [
            'Frontier Sales Corporation (A unit of FRONTIER CORPORATION LLP)',
            '3rd Floor, Temple Tower, opp News Live, Christian Basti, G S Road, Guwahati-781005, Assam',
            '0361-2341301, +91 70860 21001, +91 95080 15000, +91 94350 10287',
            'Email: info@frontier.in.net, sanyam@frontier.in.net',
        ];
        foreach ($contact as $index=>$value) {
            $footer .= self::poAligned($value,297.5,61-$index*12,$index === 0 ? 9 : 8,'center',$index === 0);
        }
        $header = static function () use ($reference,$navy,$logo) {
            $stream = "$navy rg\n".self::poAligned($reference,563,811,12,'right',true);
            $y = 793;
            if ($logo) {
                $scale = min(128/$logo['width'],48/$logo['height']);
                $w = $logo['width']*$scale;
                $h = $logo['height']*$scale;
                $x = (595-$w)/2;
                $stream .= "q $w 0 0 $h $x ".($y-$h)." cm /Logo Do Q\n";
                $y -= $h + 15;
            }
            $stream .= self::poAligned('PURCHASE ORDER',297.5,$y,22,'center',true);
            $stream .= "$navy RG 32 ".($y-12)." m 563 ".($y-12)." l S\n0 g\n";
            return [$stream,$y-27];
        };
        $pages = [];
        [$stream,$y] = $header();
        $pageBreak = static function () use (&$stream,&$y,&$pages,$header,$footer) {
            $pages[] = $stream.$footer;
            [$stream,$y] = $header();
        };
        $blocks = [[],[]];
        foreach ($details as $label=>$value) {
            if ($value === '') {
                continue;
            }
            $column = in_array($label,['PO Date','Target Date','Supplier'],true) ? 0 : 1;
            $first = true;
            foreach (explode("\n",wordwrap(self::text($value),25,"\n",true)) as $part) {
                $blocks[$column][] = [$first ? $label : '',$part];
                $first = false;
            }
        }
        $lineCount = max(count($blocks[0]),count($blocks[1]));
        for ($offset=0;$offset<$lineCount;) {
            $length = min($lineCount-$offset,(int)floor(($y-114)/15));
            if ($length < 1) {
                $pageBreak();
                continue;
            }
            $height = $length*15+16;
            foreach ($blocks as $column=>$block) {
                $x = $column === 0 ? 32 : 304;
                $stream .= "0.96 0.97 0.98 rg 0.73 0.79 0.85 RG $x ".($y-$height)." 259 $height re B\n0 g\n";
                foreach (array_slice($block,$offset,$length) as $index=>[$label,$value]) {
                    $baseline = $y-18-$index*15;
                    $stream .= self::poAligned($label,$x+10,$baseline,9,'left',true);
                    $stream .= self::line($value,$x+113,$baseline,9);
                }
            }
            $y -= $height+18;
            $offset += $length;
        }
        $columns = [32,77,293,352,412,478,563];
        $tableHeader = static function () use (&$stream,&$y,$columns,$navy) {
            $stream .= "$navy rg 32 ".($y-22)." 531 22 re f\n1 g\n";
            foreach (['Sl No.','Item Name','Unit','Order Qty','Rate','Amount'] as $index=>$label) {
                $stream .= self::poAligned($label,$columns[$index]+6,$y-15,9,'left',true);
            }
            $stream .= "0 g\n";
            $y -= 22;
        };
        if ($y < 150) {
            $pageBreak();
        }
        $tableHeader();
        foreach ($rows as $row) {
            [$number,$name,$unit,$qty,$rate,$amount] = $row;
            $names = [];
            foreach (explode("\n",$name) as $part) {
                $names = array_merge($names,explode("\n",wordwrap(self::text($part),36,"\n",true)));
            }
            $units = explode("\n",wordwrap(self::text($unit),9,"\n",true));
            $lines = max(count($names),count($units),1);
            $height = $lines*12+10;
            if ($y-$height < 98) {
                $pageBreak();
                $tableHeader();
            }
            for ($line=0;$line<$lines;$line++) {
                if ($y < 110) {
                    $pageBreak();
                    $tableHeader();
                }
                $stream .= self::line($names[$line] ?? '',83,$y-12,9);
                $stream .= self::line($units[$line] ?? '',299,$y-12,9);
                if ($line === 0) {
                    $stream .= self::line(self::text($number),38,$y-12,9);
                    foreach ([$qty,$rate,$amount] as $index=>$value) {
                        $stream .= self::poAligned($value,$columns[$index+4]-6,$y-12,9,'right');
                    }
                }
                $y -= 12;
            }
            $y -= 10;
            $stream .= "0.78 0.82 0.86 RG 32 $y m 563 $y l S\n";
        }
        if ($y < 126) {
            $pageBreak();
        }
        $y -= 10;
        $stream .= "0.87 0.92 0.97 rg 32 ".($y-25)." 531 25 re f\n0 g\n";
        $y -= 17;
        $stream .= self::poAligned('Total Amount',420,$y,11,'right',true);
        $stream .= self::poAligned('INR '.number_format($total,2),563,$y,11,'right',true);
        if ($terms !== '') {
            $y -= 30;
            if ($y < 125) {
                $pageBreak();
            }
            $stream .= self::poAligned('Supplier Terms',32,$y,13,'left',true);
            $stream .= "$navy RG 32 ".($y-6)." m 563 ".($y-6)." l S\n";
            $y -= 22;
            foreach (preg_split('/\R/u',$terms) ?: [] as $line) {
                foreach (explode("\n",wordwrap(self::text($line),86,"\n",true)) as $part) {
                    if ($y < 100) {
                        $pageBreak();
                    }
                    $stream .= self::line($part,32,$y,10);
                    $y -= 13;
                }
            }
        }
        $pages[] = $stream.$footer;
        return self::document($pages,$logo);
    }

    private static function poAligned($value,$x,$y,$size,$alignment,$bold=false) {
        $text = self::text($value);
        if ($alignment !== 'left') {
            $width = 0;
            for ($index=0;$index<strlen($text);$index++) {
                $character = $text[$index];
                $width += strpos(' ilI.,:!|',$character) !== false ? 0.26
                    : (strpos('MWmw@%',$character) !== false ? 0.84 : 0.56);
            }
            $x -= $width*$size*($alignment === 'center' ? 0.5 : 1);
        }
        return self::line($text,$x,$y,$size,$bold ? 'F2' : 'F1');
    }

    private static function document($pages,$logo=[],$width=595,$height=842) {
        $objects = [
            1=>'<< /Type /Catalog /Pages 2 0 R >>',
            2=>'',
            3=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $imageResource = '';
        if ($logo) {
            $color = $logo['channels'] === 1 ? '/DeviceGray' : '/DeviceRGB';
            $objects[5] = '<< /Type /XObject /Subtype /Image /Width '.$logo['width'].' /Height '.$logo['height']
                .' /ColorSpace '.$color.' /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($logo['bytes'])
                ." >>\nstream\n".$logo['bytes']."\nendstream";
            $imageResource = '/XObject << /Logo 5 0 R >>';
        }
        $kids = [];
        foreach ($pages as $stream) {
            $pageId = count($objects)+1;
            $streamId = $pageId+1;
            $kids[] = "$pageId 0 R";
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $width $height] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> $imageResource >> /Contents $streamId 0 R >>";
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
