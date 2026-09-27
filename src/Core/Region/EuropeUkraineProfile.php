<?php

declare(strict_types=1);

namespace Commerce\Core\Region;

final class EuropeUkraineProfile
{
    public static function create(): RegionProfile
    {
        return new RegionProfile(
            region: CommerceRegion::EuropeUkraine,
            countries: [
                'AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE','IT','LV','LT','LU',
                'MT','NL','PL','PT','RO','SK','SI','ES','SE','UA','NO','IS','LI','CH',
            ],
            currencies: ['EUR','UAH','DKK','SEK','NOK','PLN','CZK','HUF','RON','BGN','CHF'],
            languages: ['uk-UA','en-GB','da-DK','de-DE','pl-PL','fr-FR','it-IT','es-ES','nl-NL','sv-SE','no-NO'],
            features: [
                RegionalFeature::EuVat,
                RegionalFeature::UkraineTax,
                RegionalFeature::PrivacyConsent,
                RegionalFeature::StrongCustomerAuthentication,
                RegionalFeature::IbanSepa,
                RegionalFeature::UkraineCarriers,
                RegionalFeature::EuropeCarriers,
                RegionalFeature::GoogleMerchantEurope,
                RegionalFeature::GoogleMerchantUkraine,
                RegionalFeature::MultiCurrency,
                RegionalFeature::MultiLanguage,
            ],
        );
    }
}
