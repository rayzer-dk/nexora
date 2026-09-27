<?php

declare(strict_types=1);

namespace Commerce\Modules\Compliance\Domain;

enum ComplianceRequirement: string
{
    case ManufacturerIdentity = 'manufacturer_identity';
    case EuResponsiblePerson = 'eu_responsible_person';
    case SafetyInformation = 'safety_information';
    case DeclarationOfConformity = 'eu_declaration_conformity';
}
