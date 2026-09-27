<?php

declare(strict_types=1);

namespace Commerce\Modules\Compliance\Application;

use Commerce\Modules\Compliance\Domain\ComplianceRequirement;

final class ComplianceRequirementResolver
{
    /** @return list<ComplianceRequirement> */
    public function resolve(
        bool $euMarket,
        bool $physicalProduct,
        bool $manufacturerOutsideEu = false,
        bool $ceRegulated = false,
    ): array {
        if (!$physicalProduct) {
            return [];
        }

        $requirements = [ComplianceRequirement::ManufacturerIdentity];

        if ($euMarket) {
            $requirements[] = ComplianceRequirement::SafetyInformation;
            if ($manufacturerOutsideEu) {
                $requirements[] = ComplianceRequirement::EuResponsiblePerson;
            }
            if ($ceRegulated) {
                $requirements[] = ComplianceRequirement::DeclarationOfConformity;
            }
        }


        return $requirements;
    }
}
