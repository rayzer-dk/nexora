<?php

declare(strict_types=1);
namespace Commerce\Core\Update;

use Commerce\Core\Health\RequirementLevel;
use Commerce\Core\Health\SystemPreflightInspector;
use Doctrine\DBAL\Connection;
use Throwable;

final class UpdateSafetyGate
{
    public function __construct(
        private readonly SignedUpdateManifestVerifier $signatureVerifier,
        private readonly UpdatePreflight $updatePreflight,
        private readonly SystemPreflightInspector $systemPreflight,
        private readonly Connection $connection,
    ) {}

    /** @return list<string> */
    public function validate(UpdateManifest $manifest,string $packagePath,string $base64PublicKey): array
    {
        $errors=$this->updatePreflight->validate($manifest);
        try { $this->signatureVerifier->verify($manifest,$base64PublicKey); $this->signatureVerifier->verifyPackageHash($packagePath,$manifest->sha256); }
        catch(Throwable $e){$errors[]=$e->getMessage();}
        foreach([...$this->systemPreflight->runtime(),...$this->systemPreflight->database($this->connection)] as $result){
            if(!$result->passed && $result->level===RequirementLevel::Required) $errors[]=$result->label . ': ' . ($result->action ?? $result->required);
        }
        return array_values(array_unique($errors));
    }
}
