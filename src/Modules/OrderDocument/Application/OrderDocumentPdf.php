<?php

declare(strict_types=1);

namespace Commerce\Modules\OrderDocument\Application;

use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/** The PDF of an issued invoice, packing slip or credit note, for download and for the e-mail attachment. */
final readonly class OrderDocumentPdf
{
    public function __construct(private Environment $twig)
    {
    }

    /** @param array<string,mixed> $document */
    public function render(array $document): string
    {
        if (!class_exists(Dompdf::class)) {
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.19ce105d6ea9'));
        }
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml($this->twig->render('@storefront/order_document/document.html.twig', ['document' => $document, 'pdf_mode' => true]), 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();

        return $pdf->output();
    }

    /** @param array<string,mixed> $document */
    public function filename(array $document): string
    {
        return (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $document['document_number']) . '.pdf';
    }
}
