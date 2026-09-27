<?php

declare(strict_types=1);

namespace Commerce\Modules\Tax\Domain;

enum TaxDisplayMode: string
{
    /** Default B2C display: only the final payable price, without VAT wording. */
    case PriceOnly = 'price_only';

    /** Backward-compatible alias semantics for older saved policies: final gross price only. */
    case Gross = 'gross';

    /** Final payable price plus an explicit included-tax line. */
    case GrossWithBreakdown = 'gross_with_breakdown';

    /** B2B-oriented: net price is prominent, gross payable price remains visible. */
    case NetWithGross = 'net_with_gross';

    /** Restricted to markets/use-cases where a tax-exclusive public price is lawful. */
    case Net = 'net';
}
