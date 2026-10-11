<?php

declare(strict_types=1);

namespace Commerce\Modules\ImportExport\Application;

use Commerce\Core\I18n\CanonicalUiText;
use RuntimeException;
use ZipArchive;

/** Reads the first sheet of an .xlsx file or a .csv file (delimiter detected) into rows of strings. */
final class SpreadsheetReader
{
    /** @return list<list<string>> */
    public static function readCsv(string $path,int $limit): array
    {
        $fh=fopen($path,'rb');if($fh===false)throw new RuntimeException(CanonicalUiText::get('import.universal.error.csv_open'));
        $first=fgets($fh);if($first===false){fclose($fh);return [];}$delimiter=self::detectDelimiter($first);rewind($fh);
        $rows=[];while(count($rows)<$limit&&($row=fgetcsv($fh,0,$delimiter,'"',''))!==false)$rows[]=array_map('strval',$row);fclose($fh);return $rows;
    }

    /** @return list<list<string>> */
    public static function readXlsx(string $path,int $limit): array
    {
        if(!class_exists(ZipArchive::class))throw new RuntimeException(CanonicalUiText::get('import.universal.error.xlsx_zip'));
        $zip=new ZipArchive();if($zip->open($path,ZipArchive::RDONLY)!==true)throw new \DomainException(CanonicalUiText::get('import.universal.error.xlsx_invalid'));
        try{
            $shared=[];$sharedXml=$zip->getFromName('xl/sharedStrings.xml');if(is_string($sharedXml)){$xml=@simplexml_load_string($sharedXml);if($xml!==false)foreach($xml->si as $si){$parts=[];if(isset($si->t))$parts[]=(string)$si->t;foreach($si->r as $r)$parts[]=(string)$r->t;$shared[]=implode('',$parts);}}
            $sheetName=null;for($i=0;$i<$zip->numFiles;$i++){$name=(string)$zip->getNameIndex($i);if(preg_match('#^xl/worksheets/sheet\d+\.xml$#',$name)){$sheetName=$name;break;}}
            if($sheetName===null)throw new \DomainException(CanonicalUiText::get('import.universal.error.xlsx_sheet'));
            $sheetRaw=$zip->getFromName($sheetName);if(!is_string($sheetRaw))throw new \DomainException(CanonicalUiText::get('import.universal.error.xlsx_read'));
            $xml=@simplexml_load_string($sheetRaw);if($xml===false)throw new \DomainException(CanonicalUiText::get('import.universal.error.xlsx_structure'));
            $rows=[];foreach($xml->sheetData->row as $rowNode){if(count($rows)>=$limit)break;$row=[];foreach($rowNode->c as $cell){$ref=(string)$cell['r'];$col=self::columnIndex($ref);$type=(string)$cell['t'];$value='';if($type==='inlineStr')$value=(string)$cell->is->t;else{$raw=(string)$cell->v;$value=$type==='s'&&isset($shared[(int)$raw])?$shared[(int)$raw]:$raw;}$row[$col]=$value;}if($row!==[]){$max=max(array_keys($row));$dense=[];for($i=0;$i<=$max;$i++)$dense[]=(string)($row[$i]??'');$rows[]=$dense;}}
            return $rows;
        }finally{$zip->close();}
    }

    public static function columnIndex(string $reference): int{preg_match('/^([A-Z]+)/i',$reference,$m);$letters=strtoupper($m[1]??'A');$n=0;foreach(str_split($letters) as $ch)$n=$n*26+(ord($ch)-64);return max(0,$n-1);}
    public static function detectDelimiter(string $line): string{$scores=[];foreach([','=>',',';'=>';',"\t"=>"\t"] as $k=>$d)$scores[$k]=substr_count($line,$d);arsort($scores);$key=(string)array_key_first($scores);return $key==="\t"?"\t":$key;}
}
