<?php

declare(strict_types=1);

namespace Commerce\Modules\Supplier\Application;

/**
 * Reads a supplier's price list into plain rows: sku, name, price, stock (null when the feed does not say) and gtin.
 * Formats: "yml" (Yandex Market / Prom / Hotline style &lt;offer&gt; feeds), "xml" (any list of repeating items, mapped by tag names) and "csv".
 * The file is read as a stream, so a feed of tens of thousands of offers does not need much memory.
 */
final class SupplierFeedParser
{
    public const FORMATS = ['yml', 'xml', 'csv'];

    /**
     * @param array<string,string> $mapping tag/column names for sku, name, price, stock, gtin (and "item" for generic xml)
     * @return \Generator<int,array{sku:string,name:string,price:float,stock:?float,available:?bool,gtin:string}>
     */
    public function parse(string $path, string $format, array $mapping = [], int $limit = 200000): \Generator
    {
        return match ($format) {
            'csv' => $this->csv($path, $mapping, $limit),
            'xml' => $this->xml($path, $mapping, $mapping['item'] ?? 'item', $limit),
            default => $this->yml($path, $limit),
        };
    }

    /** @return \Generator<int,array{sku:string,name:string,price:float,stock:?float,available:?bool,gtin:string}> */
    private function yml(string $path, int $limit): \Generator
    {
        $reader = new \XMLReader();
        if (!$reader->open($path, null, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT)) {
            throw new \DomainException('The feed is not readable XML.');
        }
        $count = 0;
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->name !== 'offer') {
                    continue;
                }
                $id = (string) $reader->getAttribute('id');
                $availableAttr = $reader->getAttribute('available');
                $node = @simplexml_load_string($reader->readOuterXml(), 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
                if (!$node instanceof \SimpleXMLElement) {
                    continue;
                }
                $sku = trim((string) ($node->vendorCode ?? '')) ?: trim((string) ($node->article ?? '')) ?: $id;
                $price = $this->number((string) ($node->price ?? ''));
                if ($sku === '' || $price === null) {
                    continue;
                }
                $stock = null;
                foreach (['stock_quantity', 'quantity', 'quantity_in_stock'] as $tag) {
                    if (isset($node->{$tag}) && trim((string) $node->{$tag}) !== '') {
                        $stock = $this->number((string) $node->{$tag});
                        break;
                    }
                }
                yield ['sku' => $sku, 'name' => trim((string) ($node->name ?? $node->model ?? '')), 'price' => $price, 'stock' => $stock, 'available' => $availableAttr === null ? null : in_array(strtolower($availableAttr), ['true', '1', 'yes'], true), 'gtin' => trim((string) ($node->barcode ?? ''))];
                if (++$count >= $limit) {
                    break;
                }
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * @param array<string,string> $mapping
     * @return \Generator<int,array{sku:string,name:string,price:float,stock:?float,available:?bool,gtin:string}>
     */
    private function xml(string $path, array $mapping, string $itemTag, int $limit): \Generator
    {
        $tags = ['sku' => $mapping['sku'] ?? 'sku', 'name' => $mapping['name'] ?? 'name', 'price' => $mapping['price'] ?? 'price', 'stock' => $mapping['stock'] ?? 'stock', 'gtin' => $mapping['gtin'] ?? 'gtin'];
        $reader = new \XMLReader();
        if (!$reader->open($path, null, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT)) {
            throw new \DomainException('The feed is not readable XML.');
        }
        $count = 0;
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->name !== $itemTag) {
                    continue;
                }
                $node = @simplexml_load_string($reader->readOuterXml(), 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
                if (!$node instanceof \SimpleXMLElement) {
                    continue;
                }
                $pick = static fn (string $tag): string => trim((string) ($node->{$tag} ?? $node[$tag] ?? ''));
                $sku = $pick($tags['sku']);
                $price = $this->number($pick($tags['price']));
                if ($sku === '' || $price === null) {
                    continue;
                }
                $stockText = $pick($tags['stock']);
                yield ['sku' => $sku, 'name' => $pick($tags['name']), 'price' => $price, 'stock' => $stockText === '' ? null : $this->number($stockText), 'available' => null, 'gtin' => $pick($tags['gtin'])];
                if (++$count >= $limit) {
                    break;
                }
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * @param array<string,string> $mapping
     * @return \Generator<int,array{sku:string,name:string,price:float,stock:?float,available:?bool,gtin:string}>
     */
    private function csv(string $path, array $mapping, int $limit): \Generator
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new \DomainException('The feed is not readable.');
        }
        try {
            $first = (string) fgets($fh);
            rewind($fh);
            $delimiter = ',';
            $best = 0;
            foreach ([',', ';', "\t", '|'] as $candidate) {
                $n = substr_count($first, $candidate);
                if ($n > $best) {
                    $best = $n;
                    $delimiter = $candidate;
                }
            }
            $header = fgetcsv($fh, 0, $delimiter);
            if (!is_array($header)) {
                return;
            }
            $header[0] = (string) preg_replace('/^\xEF\xBB\xBF/', '', (string) ($header[0] ?? ''));
            $header = array_map(static fn ($h): string => mb_strtolower(trim((string) $h), 'UTF-8'), $header);
            $col = static function (string $key, array $aliases) use ($mapping, $header): ?int {
                foreach (array_merge([mb_strtolower($mapping[$key] ?? '', 'UTF-8')], $aliases) as $name) {
                    $i = $name === '' ? false : array_search($name, $header, true);
                    if ($i !== false) {
                        return (int) $i;
                    }
                }

                return null;
            };
            $iSku = $col('sku', ['sku', 'vendorcode', "\u{0430}\u{0440}\u{0442}\u{0438}\u{043a}\u{0443}\u{043b}", "\u{043a}\u{043e}\u{0434}", 'article', 'id']);
            $iPrice = $col('price', ['price', "\u{0446}\u{0435}\u{043d}\u{0430}", "\u{0446}\u{0456}\u{043d}\u{0430}"]);
            $iName = $col('name', ['name', "\u{043d}\u{0430}\u{0437}\u{0432}\u{0430}\u{043d}\u{0438}\u{0435}", "\u{043d}\u{0430}\u{0437}\u{0432}\u{0430}"]);
            $iStock = $col('stock', ['stock', 'quantity', 'qty', "\u{043e}\u{0441}\u{0442}\u{0430}\u{0442}\u{043e}\u{043a}", "\u{0437}\u{0430}\u{043b}\u{0438}\u{0448}\u{043e}\u{043a}", "\u{043d}\u{0430}\u{043b}\u{0438}\u{0447}\u{0438}\u{0435}", "\u{043d}\u{0430}\u{044f}\u{0432}\u{043d}\u{0456}\u{0441}\u{0442}\u{044c}"]);
            $iGtin = $col('gtin', ['gtin', 'barcode', 'ean', "\u{0448}\u{0442}\u{0440}\u{0438}\u{0445}\u{043a}\u{043e}\u{0434}"]);
            if ($iSku === null || $iPrice === null) {
                throw new \DomainException('The CSV needs a sku and a price column.');
            }
            $count = 0;
            while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
                $sku = trim((string) ($row[$iSku] ?? ''));
                $price = $this->number((string) ($row[$iPrice] ?? ''));
                if ($sku === '' || $price === null) {
                    continue;
                }
                $stockText = $iStock === null ? '' : trim((string) ($row[$iStock] ?? ''));
                $stock = null;
                $available = null;
                if ($stockText !== '') {
                    $stock = $this->number($stockText);
                    if ($stock === null) {
                        $available = in_array(mb_strtolower($stockText, 'UTF-8'), ['yes', 'true', "\u{0454}", "\u{0432} \u{043d}\u{0430}\u{043b}\u{0438}\u{0447}\u{0438}\u{0438}", "\u{0432} \u{043d}\u{0430}\u{044f}\u{0432}\u{043d}\u{043e}\u{0441}\u{0442}\u{0456}", "\u{0434}\u{0430}", "\u{0442}\u{0430}\u{043a}"], true);
                    }
                }
                yield ['sku' => $sku, 'name' => $iName === null ? '' : trim((string) ($row[$iName] ?? '')), 'price' => $price, 'stock' => $stock, 'available' => $available, 'gtin' => $iGtin === null ? '' : trim((string) ($row[$iGtin] ?? ''))];
                if (++$count >= $limit) {
                    break;
                }
            }
        } finally {
            fclose($fh);
        }
    }

    private function number(string $text): ?float
    {
        $text = str_replace(["\xC2\xA0", ' '], '', trim($text));
        if ($text === '') {
            return null;
        }
        if (str_contains($text, ',') && str_contains($text, '.')) {
            $text = str_replace(',', '', $text);
        } else {
            $text = str_replace(',', '.', $text);
        }
        $text = preg_replace('/[^0-9.\-]/', '', $text) ?? '';

        return is_numeric($text) ? (float) $text : null;
    }
}
