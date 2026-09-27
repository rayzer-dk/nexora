#!/usr/bin/env php
<?php

declare(strict_types=1);

$root=dirname(__DIR__);$errors=[];$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src',FilesystemIterator::SKIP_DOTS));
foreach($iterator as $file){if(!$file->isFile()||$file->getExtension()!=='php'||!str_contains(str_replace('\\','/',$file->getPathname()),'/Http/'))continue;$s=(string)file_get_contents($file->getPathname());$rel=substr($file->getPathname(),strlen($root)+1);
 if(preg_match('/createAccessDeniedException\(\s*[\'\"][^\'\"]+[\'\"]\s*\)/',$s))$errors[]=$rel.': hardcoded access-denied message';
 if(preg_match('/[\'\"]error[\'\"]\s*=>\s*[\'\"][^\'\"]*\s+[^\'\"]*[\'\"]/', $s))$errors[]=$rel.': hardcoded human-readable JSON error';
 // Unexpected Throwable must never be rendered raw to an HTTP response/flash.
 if(preg_match_all('/catch\s*\(\\\\Throwable\s+\$(\w+)\)\s*\{/', $s,$matches,PREG_OFFSET_CAPTURE)){
  foreach($matches[0] as $i=>$match){$var=$matches[1][$i][0];$start=$match[1]+strlen($match[0]);$depth=1;$pos=$start;$len=strlen($s);while($pos<$len&&$depth>0){if($s[$pos]==='{')$depth++;elseif($s[$pos]==='}')$depth--;$pos++;}$body=substr($s,$start,max(0,$pos-$start-1));if(str_contains($body,'$'.$var.'->getMessage()')&&!str_contains($body,'instanceof \\DomainException')&&!str_contains($body,'instanceof \\InvalidArgumentException'))$errors[]=$rel.': unexpected Throwable message exposed';}
 }
}
if($errors){fwrite(STDERR,"HTTP error boundary check FAILED\n- ".implode("\n- ",array_unique($errors))."\n");exit(1);}echo "HTTP error boundary check: OK\n";
