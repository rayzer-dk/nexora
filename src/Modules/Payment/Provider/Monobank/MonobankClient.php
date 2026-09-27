<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Provider\Monobank;

use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class MonobankClient
{
    public function __construct(private HttpClientInterface $httpClient, private string $apiBase, private string $token) {}

    /** @return array<string,mixed> */
    public function createInvoice(array $payload): array { return $this->request('POST','/api/merchant/invoice/create',$payload); }
    /** @return array<string,mixed> */
    public function refund(string $invoiceId,int $amount,string $extRef): array { return $this->request('POST','/api/merchant/invoice/cancel',['invoiceId'=>$invoiceId,'amount'=>$amount,'extRef'=>$extRef]); }
    public function removeInvoice(string $invoiceId): void { $this->requestAllowEmpty('POST','/api/merchant/invoice/remove',['invoiceId'=>$invoiceId]); }
    public function publicKey(): string
    {
        $r=$this->httpClient->request('GET',rtrim($this->apiBase,'/').'/api/merchant/pubkey',['headers'=>$this->headers(),'timeout'=>5.0]);
        if($r->getStatusCode()!==200) throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.d4142c64c5b3').$r->getStatusCode());
        $body=trim($r->getContent(false));
        if($body==='') throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.28c931bd85e4'));
        if($body[0]==='"' || $body[0]==='{') { $decoded=json_decode($body,true); if(is_string($decoded)) return $decoded; if(is_array($decoded)){ foreach(['key','pubkey','publicKey'] as $field){ if(isset($decoded[$field]) && is_string($decoded[$field]) && $decoded[$field]!=='') return $decoded[$field]; } } }
        return $body;
    }
    /** @return array<string,mixed> */
    private function request(string $method,string $path,array $json): array
    {
        if($this->token==='') throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.b1b848655ae3'));
        $r=$this->httpClient->request($method,rtrim($this->apiBase,'/').$path,['headers'=>$this->headers(),'json'=>$json,'timeout'=>8.0]);
        $status=$r->getStatusCode(); $data=$r->toArray(false);
        if($status<200 || $status>=300) throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.a74a0e75c12f').$status.'.');
        if(!is_array($data)) throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.3400d0580d88'));
        return $data;
    }
    private function requestAllowEmpty(string $method,string $path,array $json): void
    {
        if($this->token==='') throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.b1b848655ae3'));
        $r=$this->httpClient->request($method,rtrim($this->apiBase,'/').$path,['headers'=>$this->headers(),'json'=>$json,'timeout'=>8.0]);
        $status=$r->getStatusCode(); if($status<200 || $status>=300) throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.a74a0e75c12f').$status.'.');
    }
    /** @return array<string,string> */
    private function headers(): array { return ['Accept'=>'application/json','X-Token'=>$this->token,'X-Cms'=>'Nexora Commerce','X-Cms-Version'=>\Commerce\Core\Platform\PlatformVersion::VERSION]; }
}
