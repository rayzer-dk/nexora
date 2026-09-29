#!/usr/bin/env php
<?php

declare(strict_types=1);
$root=dirname(__DIR__);$errors=[];
$install=(string)file_get_contents($root.'/src/Core/Install/InstallCommand.php');
$health=(string)file_get_contents($root.'/src/Core/Install/InstallationHealthVerifier.php');
$web=(string)file_get_contents($root.'/src/Core/Install/Http/WebInstallController.php');
$migr=(string)file_get_contents($root.'/src/Core/Update/CoreUpdateMigrationRunner.php');
foreach([
 ['file'=>'InstallCommand','src'=>$install,'bad'=>['seed($request);\n        } catch (\\Throwable $e) {\n            $io->error($e->getMessage())','$demoWarning = $e->getMessage()']],
 ['file'=>'InstallationHealthVerifier','src'=>$health,'bad'=>['false, $e->getMessage()']],
] as $row){foreach($row['bad'] as $bad)if(str_contains($row['src'],$bad))$errors[]=$row['file'].' exposes raw exception text';}
foreach(['wipeRequestFile','--no-interaction','doctrine:migrations:migrate','InstallationHealthVerifier'] as $token)if(!str_contains($web.$install,$token))$errors[]='Installer missing '.$token;
foreach(['loadPlan','assertSafeCoreSql','markDoctrineMigration','LEDGER_TABLE'] as $token)if(!str_contains($migr,$token))$errors[]='Upgrade runner missing '.$token;
$versions=[];foreach(glob($root.'/migrations/Version*.php')?:[] as $file){if(preg_match('/Version(\d+)\.php$/',basename($file),$m)){$versions[]=$m[1];if(!str_contains((string)file_get_contents($file),'function down('))$errors[]='Migration missing down(): '.basename($file);}}
if(count($versions)!==count(array_unique($versions)))$errors[]='Duplicate migration version detected';
if($errors){fwrite(STDERR,"Install/upgrade safety check FAILED\n- ".implode("\n- ",$errors)."\n");exit(1);}echo "Install/upgrade safety check: OK\n";echo 'migrations='.count($versions)." unique=yes reversible_contract=yes raw_install_errors=no\n";
