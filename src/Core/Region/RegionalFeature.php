<?php

declare(strict_types=1);

namespace Commerce\Core\Region;

/** @api Public feature identifiers for regional modules. */
enum RegionalFeature: string
{
    case EuVat = 'eu_vat';
    case UkraineTax = 'ua_tax';
    case PrivacyConsent = 'privacy_consent';
    case StrongCustomerAuthentication = 'strong_customer_authentication';
    case IbanSepa = 'iban_sepa';
    case UkraineCarriers = 'ua_carriers';
    case EuropeCarriers = 'eu_carriers';
    case GoogleMerchantEurope = 'google_merchant_europe';
    case GoogleMerchantUkraine = 'google_merchant_ukraine';
    case MultiCurrency = 'multi_currency';
    case MultiLanguage = 'multi_language';
}
