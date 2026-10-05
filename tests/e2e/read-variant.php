<?php

declare(strict_types=1);

// Prints {"sku":..,"price_minor":..,"stock":..} of the first catalogue variant, or of the SKU given as the first argument.
$url = parse_url((string) getenv('DATABASE_URL'));
if (!is_array($url) || ($url['scheme'] ?? '') !== 'mysql') {
    fwrite(STDERR, "Invalid E2E database configuration.\n");
    exit(2);
}
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', (string) ($url['host'] ?? '127.0.0.1'), (int) ($url['port'] ?? 3306), ltrim((string) ($url['path'] ?? ''), '/')),
    rawurldecode((string) ($url['user'] ?? '')),
    rawurldecode((string) ($url['pass'] ?? '')),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$sku = (string) ($argv[1] ?? '');
$sql = "SELECT v.sku,
        (SELECT pr.amount_minor FROM mc_price pr WHERE pr.variant_id=v.id AND pr.customer_group='default' AND pr.price_list_id IS NULL AND pr.max_quantity IS NULL AND pr.starts_at IS NULL AND pr.ends_at IS NULL ORDER BY pr.priority,pr.min_quantity,pr.id DESC LIMIT 1) AS price_minor,
        (SELECT sl.stocked_quantity FROM mc_variant_inventory_item vii JOIN mc_stock_level sl ON sl.inventory_item_id=vii.inventory_item_id WHERE vii.variant_id=v.id ORDER BY sl.location_id LIMIT 1) AS stock
        FROM mc_product_variant v JOIN mc_product p ON p.id=v.product_id JOIN mc_store_product sp ON sp.product_id=p.id
        WHERE p.product_type='physical' AND v.sku<>'' " . ($sku !== '' ? 'AND v.sku=:sku ' : '') . 'HAVING price_minor IS NOT NULL AND stock IS NOT NULL ORDER BY v.id LIMIT 1';
$stmt = $pdo->prepare($sql);
$stmt->execute($sku !== '' ? ['sku' => $sku] : []);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($row)) {
    fwrite(STDERR, "No variant found.\n");
    exit(3);
}
echo json_encode(['sku' => $row['sku'], 'price_minor' => (int) $row['price_minor'], 'stock' => (float) $row['stock']], JSON_THROW_ON_ERROR);
