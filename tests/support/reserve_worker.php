<?php

declare(strict_types=1);

// One buyer of the concurrency test: waits for the common start time, then tries to reserve one unit and prints the outcome.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Commerce\Core\Id\PublicIdFactory;
use Commerce\Modules\Inventory\Domain\ReservationRequest;
use Commerce\Modules\Inventory\Infrastructure\DbalInventoryReservationService;
use Commerce\Modules\Inventory\Infrastructure\InsufficientInventory;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

[, $url, $itemId, $locationId, $buyer, $startAt] = $argv;
$db = DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql']))->parse($url));
$db->executeStatement('SET FOREIGN_KEY_CHECKS=0');
$service = new DbalInventoryReservationService($db, new PublicIdFactory());
while (microtime(true) < (float) $startAt) {
    usleep(200);
}
try {
    $service->reserve(new ReservationRequest((int) $itemId, (int) $locationId, 1_000_000, 'race-' . $startAt . '-' . $buyer, null, 900000 + (int) $buyer));
    echo "OK\n";
} catch (InsufficientInventory) {
    echo "SOLD_OUT\n";
} catch (Throwable $e) {
    echo 'ERROR ' . $e::class . ': ' . $e->getMessage() . "\n";
}
