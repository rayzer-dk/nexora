<?php

declare(strict_types=1);

namespace Commerce\Core\Extension;

enum ExtensionPoint: string
{
    case ProductBeforeGallery = 'product.before_gallery';
    case ProductAfterGallery = 'product.after_gallery';
    case ProductBeforeTitle = 'product.before_title';
    case ProductAfterTitle = 'product.after_title';
    case ProductAfterPrice = 'product.after_price';
    case ProductBeforeBuy = 'product.before_buy';
    case ProductAfterBuy = 'product.after_buy';
    case ProductBeforeDescription = 'product.before_description';
    case ProductAfterDescription = 'product.after_description';
    case ProductAfterAttributes = 'product.after_attributes';
    case ProductAfterReviews = 'product.after_reviews';
    case CategoryBeforeGrid = 'category.before_grid';
    case CategoryAfterGrid = 'category.after_grid';
    case CartBeforeSummary = 'cart.before_summary';
    case CartAfterSummary = 'cart.after_summary';
    case AccountDashboard = 'account.dashboard';
    case CheckoutBeforeContact = 'checkout.before_contact';
    case CheckoutAfterContact = 'checkout.after_contact';
    case CheckoutBeforeShipping = 'checkout.before_shipping';
    case CheckoutAfterShipping = 'checkout.after_shipping';
    case CheckoutBeforePayment = 'checkout.before_payment';
    case CheckoutAfterPayment = 'checkout.after_payment';
    case CheckoutBeforeSummary = 'checkout.before_summary';
    case CheckoutAfterSummary = 'checkout.after_summary';
    case AdminProductSidebar = 'admin.product.sidebar';
    case AdminProductAfterMain = 'admin.product.after_main';
    case AdminCategoryAfterMain = 'admin.category.after_main';
    case AdminOrderActions = 'admin.order.actions';
    case AdminOrderSidebar = 'admin.order.sidebar';
    case AdminCustomerSidebar = 'admin.customer.sidebar';
    case AdminDashboard = 'admin.dashboard';
    case AdminExtensionsToolbar = 'admin.extensions.toolbar';
    case NavigationItems = 'navigation.items';
    case ContentPage = 'content.page';
    case LegacyProductStorefrontAfterPrice = 'storefront.product.after_price';
    case LegacyCheckoutBeforePayment = 'storefront.checkout.before_payment';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn(self $point): string => $point->value, self::cases());
    }
}
