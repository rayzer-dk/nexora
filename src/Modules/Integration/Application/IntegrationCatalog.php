<?php

declare(strict_types=1);

namespace Commerce\Modules\Integration\Application;

/**
 * Every outside service the shop can connect to, with the settings it needs. The settings are server variables (the same names as in the .env file);
 * a value typed in the admin (Integrations → Connections) is stored by IntegrationSecretStore and wins over the .env file.
 *
 * field types: secret (password box, never shown again), text, bool ("1" or "0"), select (options)
 */
final class IntegrationCatalog
{
    /** @return array<string,array<string,array{label:string,docs:string,fields:array<string,array{type:string,options?:list<string>,default?:string,optional?:bool}>}>> */
    public static function groups(): array
    {
        return [
            'payments' => [
                'monobank' => ['label' => 'monobank (card, Apple Pay, Google Pay)', 'docs' => 'https://monobank.ua/api-docs/acquiring', 'fields' => [
                    'MONOBANK_ACQUIRING_ENABLED' => ['type' => 'bool'], 'MONOBANK_ACQUIRING_TOKEN' => ['type' => 'secret'],
                ]],
                'mono_parts' => ['label' => 'monobank: Покупка частинами', 'docs' => 'https://monobank.ua/api-docs/chast', 'fields' => [
                    'MONO_PARTS_ENABLED' => ['type' => 'bool'], 'MONO_PARTS_STORE_ID' => ['type' => 'text'], 'MONO_PARTS_SECRET' => ['type' => 'secret'],
                    'MONO_PARTS_PARTS' => ['type' => 'text', 'default' => '3,4,6,10'], 'MONO_PARTS_AUTO_CONFIRM' => ['type' => 'bool', 'default' => '1'],
                    'MONO_PARTS_API_BASE' => ['type' => 'text', 'default' => 'https://u2.monobank.com.ua'],
                ]],
                'privat_parts' => ['label' => 'ПриватБанк: Оплата частинами', 'docs' => 'https://payparts2.privatbank.ua/ipp/sandbox', 'fields' => [
                    'PRIVAT_PARTS_ENABLED' => ['type' => 'bool'], 'PRIVAT_PARTS_STORE_ID' => ['type' => 'text'], 'PRIVAT_PARTS_PASSWORD' => ['type' => 'secret'],
                    'PRIVAT_PARTS_MERCHANT_TYPE' => ['type' => 'select', 'options' => ['PP', 'II'], 'default' => 'PP'], 'PRIVAT_PARTS_PARTS' => ['type' => 'text', 'default' => '2'],
                    'PRIVAT_PARTS_API_BASE' => ['type' => 'text', 'default' => 'https://payparts2.privatbank.ua'],
                ]],
                'liqpay' => ['label' => 'LiqPay', 'docs' => 'https://www.liqpay.ua/documentation/uk', 'fields' => [
                    'LIQPAY_ENABLED' => ['type' => 'bool'], 'LIQPAY_PUBLIC_KEY' => ['type' => 'text'], 'LIQPAY_PRIVATE_KEY' => ['type' => 'secret'], 'LIQPAY_SANDBOX' => ['type' => 'bool'],
                ]],
                'wayforpay' => ['label' => 'WayForPay', 'docs' => 'https://wiki.wayforpay.com/', 'fields' => [
                    'WAYFORPAY_ENABLED' => ['type' => 'bool'], 'WAYFORPAY_MERCHANT_ACCOUNT' => ['type' => 'text'], 'WAYFORPAY_SECRET_KEY' => ['type' => 'secret'], 'WAYFORPAY_DOMAIN' => ['type' => 'text', 'optional' => true],
                ]],
                'stripe' => ['label' => 'Stripe', 'docs' => 'https://docs.stripe.com/keys', 'fields' => [
                    'STRIPE_ENABLED' => ['type' => 'bool'], 'STRIPE_SECRET_KEY' => ['type' => 'secret'], 'STRIPE_WEBHOOK_SECRET' => ['type' => 'secret'],
                ]],
                'paypal' => ['label' => 'PayPal', 'docs' => 'https://developer.paypal.com/api/rest/', 'fields' => [
                    'PAYPAL_ENABLED' => ['type' => 'bool'], 'PAYPAL_CLIENT_ID' => ['type' => 'text'], 'PAYPAL_SECRET' => ['type' => 'secret'], 'PAYPAL_WEBHOOK_ID' => ['type' => 'text', 'optional' => true], 'PAYPAL_SANDBOX' => ['type' => 'bool'],
                ]],
            ],
            'messengers' => [
                'telegram_customers' => ['label' => 'Telegram: bot for customers', 'docs' => 'https://core.telegram.org/bots#how-do-i-create-a-bot', 'fields' => [
                    'CUSTOMER_TELEGRAM_ENABLED' => ['type' => 'bool'], 'CUSTOMER_TELEGRAM_BOT_TOKEN' => ['type' => 'secret'], 'CUSTOMER_TELEGRAM_BOT_USERNAME' => ['type' => 'text'],
                ]],
                'viber_customers' => ['label' => 'Viber: account for customers', 'docs' => 'https://developers.viber.com/docs/api/rest-bot-api/', 'fields' => [
                    'CUSTOMER_VIBER_ENABLED' => ['type' => 'bool'], 'CUSTOMER_VIBER_AUTH_TOKEN' => ['type' => 'secret'], 'CUSTOMER_VIBER_NAME' => ['type' => 'text'], 'CUSTOMER_VIBER_URI' => ['type' => 'text'],
                    'CUSTOMER_VIBER_API_BASE' => ['type' => 'text', 'default' => 'https://chatapi.viber.com'],
                ]],
            ],
            'carriers' => [
                'nova_post' => ['label' => 'Нова пошта', 'docs' => 'https://developers.novaposhta.ua/', 'fields' => [
                    'NOVA_POST_API_KEY' => ['type' => 'secret'], 'NOVA_POST_SENDER_REF' => ['type' => 'text'], 'NOVA_POST_CONTACT_SENDER_REF' => ['type' => 'text'],
                    'NOVA_POST_CITY_SENDER_REF' => ['type' => 'text'], 'NOVA_POST_SENDER_ADDRESS_REF' => ['type' => 'text'], 'NOVA_POST_SENDER_PHONE' => ['type' => 'text'],
                ]],
                'ukrposhta' => ['label' => 'Укрпошта', 'docs' => 'https://www.ukrposhta.ua/ua/ukrposhta-api', 'fields' => ['UKRPOSHTA_BEARER' => ['type' => 'secret']]],
                'delivery_auto' => ['label' => 'Delivery', 'docs' => 'https://www.delivery-auto.com/', 'fields' => ['DELIVERY_AUTO_API_BASE' => ['type' => 'text']]],
                'dhl' => ['label' => 'DHL', 'docs' => 'https://developer.dhl.com/', 'fields' => ['DHL_API_KEY' => ['type' => 'secret']]],
                'gls' => ['label' => 'GLS', 'docs' => 'https://gls-group.com/', 'fields' => ['GLS_API_BASE' => ['type' => 'text'], 'GLS_API_TOKEN' => ['type' => 'secret'], 'GLS_POINTS_PATH' => ['type' => 'text']]],
                'meest' => ['label' => 'Meest', 'docs' => 'https://meest.ua/', 'fields' => ['MEEST_API_BASE' => ['type' => 'text'], 'MEEST_API_TOKEN' => ['type' => 'secret'], 'MEEST_CITIES_PATH' => ['type' => 'text'], 'MEEST_POINTS_PATH' => ['type' => 'text']]],
            ],
        ];
    }

    /** @return array<string,string> every settable server variable => its field type */
    public static function allFields(): array
    {
        $all = [];
        foreach (self::groups() as $providers) {
            foreach ($providers as $provider) {
                foreach ($provider['fields'] as $name => $field) {
                    $all[$name] = $field['type'];
                }
            }
        }

        return $all;
    }

    /** @return array<string,array{type:string,options?:list<string>,default?:string}> */
    public static function fieldsOf(string $group, string $provider): array
    {
        return self::groups()[$group][$provider]['fields'] ?? [];
    }
}
