<?php

declare(strict_types=1);

namespace Commerce\Modules\Automation\Application;

use Commerce\Core\Event\EventNames;

/** Triggers and actions the rule builder offers. Anything outside these lists is rejected on save. */
final class AutomationCatalog
{
    public const EVENT_ORDER_PLACED = 'order_placed';
    public const EVENT_ORDER_COMPLETED = 'order_completed';
    public const EVENT_ORDER_CANCELLED = 'order_cancelled';
    public const EVENT_CUSTOMER_REGISTERED = 'customer_registered';
    public const EVENT_INQUIRY_CREATED = 'inquiry_created';
    public const EVENT_REVIEW_CREATED = 'review_created';
    public const EVENT_RETURN_REQUESTED = 'return_requested';
    public const EVENT_STOCK_WAITING = 'stock_waiting';

    public const EVENTS = [
        self::EVENT_ORDER_PLACED, self::EVENT_ORDER_COMPLETED, self::EVENT_ORDER_CANCELLED,
        self::EVENT_CUSTOMER_REGISTERED, self::EVENT_INQUIRY_CREATED, self::EVENT_REVIEW_CREATED, self::EVENT_RETURN_REQUESTED, self::EVENT_STOCK_WAITING,
    ];

    /** Events that carry an order total and therefore support the minimum-total condition. */
    public const ORDER_EVENTS = [self::EVENT_ORDER_PLACED, self::EVENT_ORDER_COMPLETED, self::EVENT_ORDER_CANCELLED];

    public const ACTION_EMAIL = 'email';
    public const ACTION_TELEGRAM = 'telegram';
    public const ACTION_PUSH = 'push';
    public const ACTION_WEBHOOK = 'webhook';

    public const ACTIONS = [self::ACTION_EMAIL, self::ACTION_TELEGRAM, self::ACTION_PUSH, self::ACTION_WEBHOOK];

    /** Domain event name => rule trigger. */
    public const DOMAIN_MAP = [
        EventNames::ORDER_PLACED => self::EVENT_ORDER_PLACED,
        EventNames::ORDER_COMPLETED => self::EVENT_ORDER_COMPLETED,
        EventNames::ORDER_CANCELLED => self::EVENT_ORDER_CANCELLED,
        EventNames::CUSTOMER_REGISTERED => self::EVENT_CUSTOMER_REGISTERED,
    ];

    private function __construct()
    {
    }
}
