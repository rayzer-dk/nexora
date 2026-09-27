<?php

declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
require_once $root . '/src/Core/Platform/PlatformVersion.php';
$need=[
 'migrations/Version20260925090000.php'=>['mc_order_document','mc_order_document_sequence','tax_number','iban'],
 'src/Modules/OrderDocument/Application/OrderDocumentService.php'=>['issueInvoice','issuePackingSlip','issueCreditNote','INSERT IGNORE INTO mc_order_document_sequence','snapshot_json'],
 'src/Modules/OrderDocument/Http/AdminOrderDocumentController.php'=>['admin_order_document_issue','admin_order_document_pdf','Dompdf'],
 'src/Modules/OrderDocument/Http/CustomerOrderDocumentController.php'=>['documentForCustomer','customer_order_document_pdf'],
 'themes/default/templates/order_document/document.html.twig'=>["ui_text('document_invoice')","ui_text('document_packing_slip')","ui_text('document_credit_note')","ui_text('document_snapshot_notice')"],
];
foreach($need as $file=>$tokens){$body=(string)@file_get_contents($root.'/'.$file);if($body===''){$fail[]='missing '.$file;continue;}foreach($tokens as $t)if(!str_contains($body,$t))$fail[]=$file.' missing '.$t;}
$release=json_decode((string)@file_get_contents($root.'/resources/platform/release.json'),true);if(($release['version']??'')!==\Commerce\Core\Platform\PlatformVersion::VERSION)$fail[]='version';if((string)($release['database_schema']??'')!==(string)\Commerce\Core\Platform\PlatformVersion::DATABASE_SCHEMA)$fail[]='schema';
$composer=json_decode((string)@file_get_contents($root.'/composer.json'),true);if(($composer['require']['dompdf/dompdf']??'')!=='^3.1.6')$fail[]='dompdf dependency';
if($fail){fwrite(STDERR,"ORDER DOCUMENT CHECK FAILED\n- ".implode("\n- ",$fail)."\n");exit(1);}echo "Order document check: OK\n";
