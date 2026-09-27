<?php

declare(strict_types=1);

namespace Commerce\Modules\Marketing\Application;

final readonly class NewsletterTokenService
{
    public function __construct(private string $secret){}
    public function sign(string $subscriberPublicId):string{return rtrim(strtr(base64_encode(hash_hmac('sha256','newsletter-unsubscribe:'.$subscriberPublicId,$this->secret,true)),'+/','-_'),'=');}
    public function verify(string $subscriberPublicId,string $signature):bool{return hash_equals($this->sign($subscriberPublicId),$signature);}
}
