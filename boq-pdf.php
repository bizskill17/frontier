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
        return self::landscapeRegister('BOQ # '.$number,[
            ['Date:',$date,'Site Name',$site],['BOQ Prepared By',$prepared,'',''],
        ],['Sl No.','Item Name','Quantity','Unit'],[40,100,561,661,752],$rows);
    }

    public static function buildIndent($number,$site,$date,$requested,$rows) {
        return self::landscapeRegister('Indent # '.$number,[
            ['Date:',$date,'Site Name',$site],['Requested By',$requested,'',''],
        ],['Sl No.','Item','Units','Quantity as per BOQ','Quantity Already Indented',
            'Quantity Required Now','Whether Excess Indented','Item Type'],
            [40,83,299,348,429,514,596,680,752],$rows);
    }

    public static function buildGrn($number,$details,$rows) {
        return self::landscapeRegister('GRN - '.(int)$number,$details,
            ['Sl No.','PO Item No.','Item Name','Quantity Ordered','Quantity Pending to Receive','Quantity Received'],
            [40,90,179,473,556,671,752],$rows);
    }

    private static function landscapeRegister($reference,$details,$headings,$edges,$rows) {
        $header = static function ($first) use ($reference,$details) {
            $stream = "0 g 0 G 0.5 w\n";
            $letterhead = [
                'Frontier Sales Corporation',
                '( A unit of FRONTIER CORPORATION LLP )',
                '3rd Floor, Temple Tower, opp News Live,',
                'Christian Basti, G S Road, Guwahati-781005',
            ];
            foreach ($letterhead as $index=>$text) {
                $stream .= self::poAligned($text,396,565-$index*17,14,'center',true);
            }
            $stream .= "40 503 m 752 503 l S\n";
            $top = 487;
            if ($first) {
                $stream .= self::poAligned($reference,396,471,23,'center',true);
                $top = 448;
                foreach ($details as $detail) {
                    [$block,$height] = self::registerRow($detail,[40,154,396,503,752],$top,[0,2]);
                    $stream .= $block;
                    $top -= $height;
                }
                $stream .= self::poAligned('ITEM TABLE',396,$top-20,11,'center',true);
                $top -= 30;
            }
            return [$stream,$top];
        };
        $pages = [];
        [$stream,$top] = $header(true);
        [$heading,$headingHeight] = self::registerRow($headings,$edges,$top,array_keys($headings));
        $stream .= $heading;
        $top -= $headingHeight;
        foreach ($rows as $row) {
            [$block,$height] = self::registerRow($row,$edges,$top);
            if ($top-$height < 40) {
                $pages[] = $stream;
                [$stream,$top] = $header(false);
                [$heading,$headingHeight] = self::registerRow($headings,$edges,$top,array_keys($headings));
                $stream .= $heading;
                $top -= $headingHeight;
                [$block,$height] = self::registerRow($row,$edges,$top);
            }
            $stream .= $block;
            $top -= $height;
        }
        $pages[] = $stream;
        return self::document($pages,[],792,612);
    }

    private static function registerRow($values,$edges,$top,$bold=[]) {
        $height = 0;
        $stream = '';
        foreach ($values as $column=>$value) {
            $max = max(1,(int)floor(($edges[$column+1]-$edges[$column]-12)/7));
            $lines = explode("\n",wordwrap(self::text($value),$max,"\n",true));
            $height = max($height,count($lines)*12+8);
            foreach ($lines as $index=>$line) {
                $stream .= self::line($line,$edges[$column]+6,$top-13-$index*12,9,
                    in_array($column,$bold,true) ? 'F2' : 'F1');
            }
        }
        $left = $edges[0];
        $width = end($edges)-$left;
        $bottom = $top-$height;
        $stream .= "$left $bottom $width $height re S\n";
        foreach (array_slice($edges,1,-1) as $x) {
            $stream .= "$x $bottom m $x $top l S\n";
        }
        return [$stream,$height];
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
        $cellBorders = static function ($top,$bottom) use ($columns) {
            $paths = "q 0.64 0.70 0.77 RG 0.65 w 32 $bottom  m 563 $bottom l\n";
            foreach ($columns as $x) {
                $paths .= "$x $top m $x $bottom l\n";
            }
            return $paths."S Q\n";
        };
        $tableHeader = static function () use (&$stream,&$y,$columns,$navy,$cellBorders) {
            $stream .= "$navy rg 32 ".($y-22)." 531 22 re f\n1 g\n";
            foreach (['Sl No.','Item Name','Unit','Order Qty','Rate','Amount'] as $index=>$label) {
                $stream .= self::poAligned($label,$columns[$index]+6,$y-15,9,'left',true);
            }
            $stream .= $cellBorders($y,$y-22)."0 g\n";
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
            $rowTop = $y;
            for ($line=0;$line<$lines;$line++) {
                if ($y < 110) {
                    $stream .= $cellBorders($rowTop,$y);
                    $pageBreak();
                    $tableHeader();
                    $rowTop = $y;
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
            $stream .= $cellBorders($rowTop,$y);
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
