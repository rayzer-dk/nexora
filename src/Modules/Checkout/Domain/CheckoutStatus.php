<?php

declare(strict_types=1);

namespace Commerce\Modules\Checkout\Domain;

enum CheckoutStatus: string
{
    case Started = 'started';
    case IdentityReady = 'identity_ready';
    case FulfillmentReady = 'fulfillment_ready';
    case PaymentReady = 'payment_ready';
    case Completed = 'completed';
}
