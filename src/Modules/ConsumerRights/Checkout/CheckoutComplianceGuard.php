<?php

declare(strict_types=1);

namespace Commerce\Modules\ConsumerRights\Checkout;

final class CheckoutComplianceGuard
{
    /**
     * @param array<string,array{selected:bool,default_selected:bool}> $optionalExtras
     */
    public function validate(
        string $submitLabel,
        array $optionalExtras,
        bool $digitalPerformanceStartsImmediately = false,
        bool $digitalPerformanceConsent = false,
        bool $digitalWithdrawalAcknowledged = false,
    ): CheckoutComplianceResult {
        $violations = [];
        $normalized = mb_strtolower(trim($submitLabel));
        $paymentWords = ['pay', 'buy', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.consumerrights.checkout.checkoutcomplianceguard.oplat'), \Commerce\Core\I18n\CanonicalUiText::get('php.modules.consumerrights.checkout.checkoutcomplianceguard.kup'), \Commerce\Core\I18n\CanonicalUiText::get('php.modules.consumerrights.checkout.checkoutcomplianceguard.splat'), 'zahlung', 'bezahlen', 'betal'];
        $clearlyPaying = false;
        foreach ($paymentWords as $word) {
            if (str_contains($normalized, $word)) {
                $clearlyPaying = true;
                break;
            }
        }
        if (!$clearlyPaying) {
            $violations[] = 'submit_label_must_make_payment_obligation_clear';
        }

        foreach ($optionalExtras as $code => $extra) {
            if (($extra['default_selected'] ?? false) === true) {
                $violations[] = 'optional_extra_must_not_be_preselected:' . $code;
            }
        }

        if ($digitalPerformanceStartsImmediately && (!$digitalPerformanceConsent || !$digitalWithdrawalAcknowledged)) {
            $violations[] = 'digital_content_requires_express_performance_and_withdrawal_acknowledgement';
        }

        return new CheckoutComplianceResult($violations === [], $violations);
    }
}
