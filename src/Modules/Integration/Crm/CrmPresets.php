<?php

declare(strict_types=1);

namespace Commerce\Modules\Integration\Crm;

/**
 * Starting body templates for the usual receivers of an order. A template is Twig that must produce JSON; the variables are
 * order, customer, delivery, payment, items, store and event (see OrderSnapshotBuilder). Presets for other people's systems follow the
 * public description of their order API and are meant to be checked against the account of the shop owner (field names, ids of sources and statuses).
 */
final class CrmPresets
{
    public const EVENTS = ['placed', 'paid', 'shipped', 'completed', 'cancelled'];

    /** @return array<string,array{label:string,url_hint:string,headers_hint:string,events:list<string>,template:string}> */
    public static function all(): array
    {
        return [
            'json' => [
                'label' => 'Nexora JSON (any receiver, n8n, Make, Zapier)',
                'url_hint' => 'https://example.com/hooks/orders',
                'headers_hint' => '',
                'events' => self::EVENTS,
                'template' => <<<'TWIG'
{
  "event": {{ event|json_encode }},
  "order": {
    "number": {{ order.number|json_encode }}, "id": {{ order.public_id|json_encode }}, "status": {{ order.status|json_encode }},
    "payment_status": {{ order.payment_status|json_encode }}, "fulfillment_status": {{ order.fulfillment_status|json_encode }},
    "currency": {{ order.currency|json_encode }}, "total": {{ order.total|json_encode }}, "subtotal": {{ order.subtotal|json_encode }},
    "discount": {{ order.discount|json_encode }}, "shipping": {{ order.shipping|json_encode }}, "comment": {{ order.comment|json_encode }},
    "created_at": {{ order.created_at|json_encode }}, "admin_url": {{ order.admin_url|json_encode }}
  },
  "customer": {{ customer|json_encode }},
  "delivery": {{ delivery|json_encode }},
  "payment": {{ payment|json_encode }},
  "items": {{ items|json_encode }}
}
TWIG,
            ],
            'keycrm' => [
                'label' => 'KeyCRM (new order)',
                'url_hint' => 'https://openapi.keycrm.app/v1/order',
                'headers_hint' => 'Authorization: Bearer <KeyCRM API key>',
                'events' => ['placed'],
                'template' => <<<'TWIG'
{
  "source_id": 1,
  "source_uuid": {{ order.number|json_encode }},
  "buyer_comment": {{ order.comment|json_encode }},
  "manager_comment": {{ ('Nexora: ' ~ order.admin_url)|json_encode }},
  "ordered_at": {{ order.created_at|json_encode }},
  "buyer": { "full_name": {{ customer.name|json_encode }}, "email": {{ customer.email|json_encode }}, "phone": {{ customer.phone|json_encode }} },
  "shipping": {
    "recipient_full_name": {{ customer.name|json_encode }}, "recipient_phone": {{ customer.phone|json_encode }},
    "shipping_address_city": {{ delivery.city|json_encode }}, "shipping_receive_point": {{ delivery.point|json_encode }},
    "shipping_secondary_line": {{ delivery.address_line|json_encode }}
  },
  "products": [{% for i in items %}
    { "sku": {{ i.sku|json_encode }}, "name": {{ i.name|json_encode }}, "price": {{ i.price }}, "quantity": {{ i.quantity }}, "unit_type": {{ i.unit|json_encode }} }{{ loop.last ? '' : ',' }}{% endfor %}
  ],
  "payments": [{% if order.payment_status == 'paid' %}{ "payment_method": {{ payment.provider|json_encode }}, "amount": {{ order.total }}, "status": "paid" }{% endif %}]
}
TWIG,
            ],
            'salesdrive' => [
                'label' => 'SalesDrive (new order)',
                'url_hint' => 'https://<company>.salesdrive.me/handler/',
                'headers_hint' => 'Form-Api-Key: <form key from SalesDrive>',
                'events' => ['placed'],
                'template' => <<<'TWIG'
{
  "form": "<form key from SalesDrive>",
  "getResultData": "1",
  "fName": {{ customer.first_name|json_encode }}, "lName": {{ customer.last_name|json_encode }},
  "phone": {{ customer.phone|json_encode }}, "email": {{ customer.email|json_encode }},
  "comment": {{ order.comment|json_encode }}, "externalId": {{ order.number|json_encode }}, "prodex24source_full": "nexora",
  "products": [{% for i in items %}
    { "id": {{ i.sku|json_encode }}, "name": {{ i.name|json_encode }}, "costPerItem": {{ i.price }}, "amount": {{ i.quantity }} }{{ loop.last ? '' : ',' }}{% endfor %}
  ],
  "shipping_method": {{ delivery.provider|json_encode }}, "shipping_city": {{ delivery.city|json_encode }}, "shipping_address": {{ delivery.address_line|json_encode }},
  "payment_method": {{ payment.provider|json_encode }}
}
TWIG,
            ],
            'bitrix24' => [
                'label' => 'Bitrix24 (new deal)',
                'url_hint' => 'https://<portal>.bitrix24.ua/rest/<user>/<webhook code>/crm.deal.add.json',
                'headers_hint' => '',
                'events' => ['placed'],
                'template' => <<<'TWIG'
{
  "fields": {
    "TITLE": {{ ('Order ' ~ order.number)|json_encode }}, "CURRENCY_ID": {{ order.currency|json_encode }}, "OPPORTUNITY": {{ order.total }},
    "COMMENTS": {{ (order.comment ~ ' | ' ~ customer.name ~ ' | ' ~ customer.phone ~ ' | ' ~ customer.email ~ ' | ' ~ delivery.address_line)|json_encode }},
    "SOURCE_DESCRIPTION": "Nexora", "ORIGIN_ID": {{ order.number|json_encode }}
  },
  "params": { "REGISTER_SONET_EVENT": "Y" }
}
TWIG,
            ],
        ];
    }
}
