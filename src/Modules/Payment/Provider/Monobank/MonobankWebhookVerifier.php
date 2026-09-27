<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Provider\Monobank;

use Psr\Cache\CacheItemPoolInterface;

final readonly class MonobankWebhookVerifier
{
    public function __construct(private MonobankClient $client, private CacheItemPoolInterface $cache) {}

    public function verify(string $rawBody,string $signatureBase64): bool
    {
        $signature=base64_decode($signatureBase64,true); if($signature===false || $signature==='') return false;
        $key=$this->key();
        if($this->verifyWithKey($rawBody,$signature,$key)) return true;
        $this->cache->deleteItem('commerce.monobank.public_key');
        return $this->verifyWithKey($rawBody,$signature,$this->key());
    }
    private function key(): string
    {
        $item=$this->cache->getItem('commerce.monobank.public_key');
        if($item->isHit() && is_string($item->get()) && $item->get()!=='') return (string)$item->get();
        $key=$this->client->publicKey(); $item->set($key); $item->expiresAfter(21600); $this->cache->save($item); return $key;
    }
    private function verifyWithKey(string $body,string $signature,string $keyBase64): bool
    {
        $pem=base64_decode($keyBase64,true); if($pem===false || $pem==='') return false;
        $public=openssl_pkey_get_public($pem); if($public===false) return false;
        return openssl_verify($body,$signature,$public,OPENSSL_ALGO_SHA256)===1;
    }
}
