<?php

declare(strict_types=1);

namespace Commerce\Core\Event;

final class EventNames
{
    public const CHECKOUT_STARTED = 'checkout.started';
    public const CHECKOUT_CUSTOMER_VALIDATING = 'checkout.customer.validating';
    public const CHECKOUT_SHIPPING_COLLECT = 'checkout.shipping.collect';
    public const CHECKOUT_PAYMENT_COLLECT = 'checkout.payment.collect';
    public const CHECKOUT_BEFORE_ORDER_CREATE = 'checkout.before_order_create';
    public const CHECKOUT_ORDER_CREATED = 'checkout.order_created';

    public const PRODUCT_LOADED = 'product.loaded';
    public const PRODUCT_PRICE_RESOLVED = 'product.price.resolved';
    public const PRODUCT_STOCK_RESOLVED = 'product.stock.resolved';
    public const PRODUCT_BEFORE_SAVE = 'product.before_save';
    public const PRODUCT_SAVED = 'product.saved';
    public const PRODUCT_DELETED = 'product.deleted';

    public const ORDER_CREATED = 'order.created';
    public const ORDER_STATUS_CHANGED = 'order.status.changed';
    public const ORDER_REFUNDED = 'order.refunded';
    public const ORDER_SHIPPED = 'order.shipped';
    public const ORDER_PLACED = 'commerce.order.placed';
    public const ORDER_CANCELLED = 'commerce.order.cancelled';
    public const ORDER_COMPLETED = 'commerce.order.completed';
    public const PAYMENT_STATUS_CHANGED = 'commerce.payment.status_changed';

    public const CUSTOMER_CREATED = 'customer.created';
    public const CUSTOMER_UPDATED = 'customer.updated';
    public const CUSTOMER_DELETED = 'customer.deleted';
    public const CUSTOMER_REGISTERED = 'commerce.customer.registered';

    public const CONTENT_PAGE_RENDERING = 'content.page.rendering';
    public const LAYOUT_BLOCKS_COLLECT = 'layout.blocks.collect';
    public const NAVIGATION_ITEMS_COLLECT = 'navigation.items.collect';

    public const PRODUCT_CREATED = 'commerce.catalog.product_created';
    public const PRODUCT_UPDATED = 'commerce.catalog.product_updated';

    private function __construct()
    {
    }
}
