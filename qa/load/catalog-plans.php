<?php

declare(strict_types=1);

// Read-only plan samples on the disposable CI catalog, not a claim about full application queries.
$url = parse_url((string) getenv('DATABASE_URL'));
if (!is_array($url) || !isset($url['host'], $url['path'])) {
    throw new RuntimeException('DATABASE_URL required');
}
$pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $url['host'], $url['port'] ?? 3306, ltrim($url['path'], '/')), urldecode($url['user'] ?? ''), urldecode($url['pass'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$scope = $pdo->query("SELECT sp.store_id,mp.market_id,pt.locale,sr.path AS product_path,p.id AS product_id FROM mc_product p JOIN mc_store_product sp ON sp.product_id=p.id JOIN mc_store s ON s.id=sp.store_id AND s.status='active' JOIN mc_market_product mp ON mp.product_id=p.id AND mp.market_id=(SELECT MIN(m.id) FROM mc_market m WHERE m.store_id=s.id AND m.status='active') JOIN mc_product_translation pt ON pt.product_id=p.id AND pt.store_id=sp.store_id AND pt.locale=s.default_locale JOIN mc_seo_route sr ON sr.entity_public_id=p.public_id AND sr.entity_type='product' AND sr.store_id=sp.store_id AND sr.locale=pt.locale WHERE p.status='published' AND sp.status='active' AND mp.status='active' ORDER BY p.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$scope) {
    throw new RuntimeException('Published fixture product required');
}
$store = (int) $scope['store_id'];
$market = (int) $scope['market_id'];
$product = (int) $scope['product_id'];
$queries = [
    'visible_count' => "SELECT COUNT(DISTINCT p.id) FROM mc_product p JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=$store AND sp.status='active' JOIN mc_market_product mp ON mp.product_id=p.id AND mp.market_id=$market AND mp.status='active' WHERE p.status='published' AND NOT EXISTS (SELECT 1 FROM mc_product_extra x WHERE x.product_id=p.id AND x.hidden=1)",
    'newest_page' => "SELECT p.id FROM mc_product p JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=$store AND sp.status='active' JOIN mc_market_product mp ON mp.product_id=p.id AND mp.market_id=$market AND mp.status='active' WHERE p.status='published' ORDER BY p.id DESC LIMIT 24",
    'primary_variant' => "SELECT id FROM mc_product_variant WHERE product_id=$product AND status='active' AND sort_order=0",
    'product_route' => "SELECT path FROM mc_seo_route WHERE store_id=$store AND entity_type='product' AND entity_public_id=(SELECT public_id FROM mc_product WHERE id=$product)",
];
$report = ['products' => (int) $pdo->query('SELECT COUNT(*) FROM mc_product')->fetchColumn(), 'product_path' => '/' . ltrim((string) $scope['product_path'], '/'), 'locale' => $scope['locale'], 'queries' => []];
foreach ($queries as $name => $sql) {
    $plan = $pdo->query('EXPLAIN FORMAT=JSON ' . $sql)->fetchColumn();
    $samples = [];
    for ($i = 0; $i < 20; ++$i) {
        $start = hrtime(true);
        $pdo->query($sql)->fetchAll();
        $samples[] = (hrtime(true) - $start) / 1e6;
    }
    sort($samples);
    $report['queries'][$name] = ['p95_ms' => $samples[18], 'plan' => json_decode((string) $plan, true, 512, JSON_THROW_ON_ERROR)];
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
