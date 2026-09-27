<?php

declare(strict_types=1);

namespace Commerce\Core\Health;

use Commerce\Core\Platform\PlatformVersion;
use Doctrine\DBAL\Connection;
use Throwable;

final class SystemPreflightInspector
{
    /** @var list<string> */
    private const REQUIRED_EXTENSIONS = [
        'ctype','curl','dom','fileinfo','gd','iconv','intl','json','mbstring','openssl','pcre','pdo','pdo_mysql','session','simplexml','sodium','tokenizer','zip',
    ];

    public function __construct(private readonly string $projectDir) {}

    /** @return list<RequirementResult> */
    public function runtime(): array
    {
        $results = [];
        $phpOk = version_compare(PHP_VERSION, PlatformVersion::MIN_PHP, '>=')
            && version_compare(PHP_VERSION, PlatformVersion::MAX_PHP_EXCLUSIVE, '<');
        $results[] = new RequirementResult('php',\Commerce\Core\I18n\CanonicalUiText::get('php.core.health.systempreflightinspector.versiia_php'),$phpOk,RequirementLevel::Required,PHP_VERSION,
            '>=' . PlatformVersion::MIN_PHP . ' <' . PlatformVersion::MAX_PHP_EXCLUSIVE,
            $phpOk ? null : \Commerce\Core\I18n\CanonicalUiText::get('php.core.health.systempreflightinspector.peremknit_php_dlia_web_i_cli_na_sertyfikovanu_versii'));

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            $loaded = extension_loaded($extension);
            $results[] = new RequirementResult('ext.' . $extension,\Commerce\Core\I18n\CanonicalUiText::get('php.core.health.systempreflightinspector.php_rozshyrennia') . $extension,$loaded,RequirementLevel::Required,$loaded ? \Commerce\Core\I18n\CanonicalUiText::get('php.core.health.systempreflightinspector.zavantazheno') : \Commerce\Core\I18n\CanonicalUiText::get('php.core.health.systempreflightinspector.vidsutnie'),\Commerce\Core\I18n\CanonicalUiText::get('php.core.health.systempreflightinspector.zavantazheno'),
                $loaded ? null : \Commerce\Core\I18n\CanonicalUiText::get('php.core.health.systempreflightinspector.uvimknit_php_rozshyrennia_odnochasno_dlia_fpm_apache'));
        }

        foreach (['opcache','imagick','redis'] as $extension) {
            $loaded = extension_loaded($extension);
            $results[] = new RequirementResult('ext.' . $extension,\Commerce\Core\I18n\CanonicalUiText::get('php.core.health.systempreflightinspector.neoboviazkove_php_rozshyrennia') . $extension,true,RequirementLevel::Recommended,$loaded ? \Commerce\Core\I18n\CanonicalUiText::get('php.core.health.systempreflightinspector.zavantazheno') : \Commerce\Core\I18n\CanonicalUiText::get('php.core.health.systempreflightinspector.ne_zavantazheno'),\Commerce\Core\I18n\CanonicalUiText::get('php.core.health.systempreflightinspector.neoboviazkovo'),
                $loaded ? null : \Commerce\Core\I18n\CanonicalUiText::get('php.core.health.systempreflightinspector.neoboviazkovo_uvimknit_iakshcho_vykorystovuietsia_vi'));
        }

        $memoryBytes = $this->iniBytes((string) ini_get('memory_limit'));
        $memoryOk = $memoryBytes < 0 || $memoryBytes >= 256 * 1024 * 1024;
        $results[] = new RequirementResult('php.memory_limit','PHP memory_limit',$memoryOk,RequirementLevel::Recommended,(string) ini_get('memory_limit'),'>=256M',\Commerce\Core\I18n\CanonicalUiText::get('php.core.health.systempreflightinspector.zbilshte_dlia_velykykh_importiv_i_obrobky_media'));

        $free = @disk_free_space($this->projectDir);
        $diskOk = $free === false || $free >= 1024 * 1024 * 1024;
        $results[] = new RequirementResult('disk.free',\Commerce\Core\I18n\CanonicalUiText::get('php.core.health.systempreflightinspector.vilne_mistse_na_dysku'),$diskOk,RequirementLevel::Recommended,$free === false ? \Commerce\Core\I18n\CanonicalUiText::get('php.core.health.systempreflightinspector.nevidomo') : $this->formatBytes((float)$free),'>=1 GB free',\Commerce\Core\I18n\CanonicalUiText::get('php.core.health.systempreflightinspector.zalyshaite_zapas_dlia_reliziv_rezervnykh_kopii_i_tym'));

        foreach ([$this->projectDir . '/var', $this->projectDir . '/public/media'] as $path) {
            $target = is_dir($path) ? $path : dirname($path);
            $writable = is_writable($target);
            $results[] = new RequirementResult('fs.' . basename($path),basename($path) . \Commerce\Core\I18n\CanonicalUiText::get('php.core.health.systempreflightinspector.dostupnyi_dlia_zapysu'),$writable,RequirementLevel::Required,$writable ? \Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productattributeservice.tak') : \Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productattributeservice.ni'),\Commerce\Core\I18n\CanonicalUiText::get('php.core.health.systempreflightinspector.dostupnyi_dlia_zapysu_2'),\Commerce\Core\I18n\CanonicalUiText::get('php.core.health.systempreflightinspector.vypravte_vlasnyka_prava_bez_vidkryttia_zapysu_dlia_v'));
        }

        return $results;
    }

    /** @return list<RequirementResult> */
    public function database(Connection $connection): array
    {
        $results = [];
        try {
            $version = (string) $connection->fetchOne('SELECT VERSION()');
            $isMaria = stripos($version, 'mariadb') !== false;
            $numeric = preg_replace('/[^0-9.].*$/', '', $version) ?: $version;
            $minimum = $isMaria ? '11.4.0' : '8.4.0';
            $ok = version_compare($numeric, $minimum, '>=');
            $results[] = new RequirementResult('db.version',$isMaria ? \Commerce\Core\I18n\CanonicalUiText::get('php.core.health.systempreflightinspector.versiia_mariadb') : \Commerce\Core\I18n\CanonicalUiText::get('php.core.health.systempreflightinspector.versiia_mysql'),$ok,RequirementLevel::Required,$version,'>=' . $minimum,
                $ok ? null : \Commerce\Core\I18n\CanonicalUiText::get('php.core.health.systempreflightinspector.onovit_subd_do_pidtrymuvanoi_lts_versii'));

            $engine = strtolower((string) $connection->fetchOne('SELECT @@default_storage_engine'));
            $results[] = new RequirementResult('db.engine',\Commerce\Core\I18n\CanonicalUiText::get('php.core.health.systempreflightinspector.skhovyshche_za_zamovchuvanniam'),$engine === 'innodb',RequirementLevel::Required,$engine,'InnoDB',\Commerce\Core\I18n\CanonicalUiText::get('php.core.health.systempreflightinspector.vstanovit_default_storage_engine_innodb'));

            $charset = strtolower((string) $connection->fetchOne('SELECT @@character_set_database'));
            $results[] = new RequirementResult('db.charset',\Commerce\Core\I18n\CanonicalUiText::get('php.core.health.systempreflightinspector.koduvannia_bazy_danykh'),$charset === 'utf8mb4',RequirementLevel::Required,$charset,'utf8mb4',\Commerce\Core\I18n\CanonicalUiText::get('php.core.health.systempreflightinspector.vykorystovuite_utf8mb4_dlia_bazy_mahazynu'));

            $mode = strtoupper((string) $connection->fetchOne('SELECT @@sql_mode'));
            $strict = str_contains($mode,'STRICT_TRANS_TABLES') || str_contains($mode,'STRICT_ALL_TABLES');
            $results[] = new RequirementResult('db.strict',\Commerce\Core\I18n\CanonicalUiText::get('php.core.health.systempreflightinspector.suvoryi_sql_mode'),$strict,RequirementLevel::Required,$mode ?: 'empty','STRICT_TRANS_TABLES or STRICT_ALL_TABLES',\Commerce\Core\I18n\CanonicalUiText::get('php.core.health.systempreflightinspector.uvimknit_strict_sql_mode_shchob_bd_vidkhyliala_obriz'));

            $foreignKeys = (int) $connection->fetchOne('SELECT @@foreign_key_checks');
            $results[] = new RequirementResult('db.foreign_keys',\Commerce\Core\I18n\CanonicalUiText::get('php.core.health.systempreflightinspector.perevirka_zovnishnikh_kliuchiv'),$foreignKeys === 1,RequirementLevel::Required,(string)$foreignKeys,'1',\Commerce\Core\I18n\CanonicalUiText::get('php.core.health.systempreflightinspector.zalyshaite_foreign_key_checks_uvimknenym_poza_kontro'));

            $packet = (int) $connection->fetchOne('SELECT @@max_allowed_packet');
            $results[] = new RequirementResult('db.packet','max_allowed_packet',$packet >= 64*1024*1024,RequirementLevel::Recommended,$this->formatBytes($packet),'>=64 MB',\Commerce\Core\I18n\CanonicalUiText::get('php.core.health.systempreflightinspector.zbilshte_dlia_velykykh_importiv_i_paketiv_metadanykh'));
        } catch (Throwable $e) {
            $results[] = new RequirementResult('db.connection',\Commerce\Core\I18n\CanonicalUiText::get('php.core.health.systempreflightinspector.pidkliuchennia_do_bazy_danykh'),false,RequirementLevel::Required,\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.layoutbuilderadmincontroller.pomylka'),\Commerce\Core\I18n\CanonicalUiText::get('php.core.health.systempreflightinspector.roboche_pidkliuchennia'),$e->getMessage());
        }
        return $results;
    }

    private function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '-1') return -1;
        if ($value === '') return 0;
        $unit = strtolower(substr($value,-1));
        $number = (float)$value;
        return match($unit){'g'=>(int)($number*1024**3),'m'=>(int)($number*1024**2),'k'=>(int)($number*1024),default=>(int)$number};
    }

    private function formatBytes(float|int $bytes): string
    {
        $units=['B','KB','MB','GB','TB']; $i=0; $n=(float)$bytes;
        while($n>=1024 && $i<count($units)-1){$n/=1024;$i++;}
        return number_format($n,$i===0?0:1,'.','') . ' ' . $units[$i];
    }
}
