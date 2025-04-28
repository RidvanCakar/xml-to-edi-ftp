<?php
// Gerekli kütüphaneler
require __DIR__ . '/vendor/autoload.php';
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Dotenv\Dotenv;

// .env dosyasını yükle
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Log dosyasının dizini var mı kontrol et, yoksa oluştur
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

$logFile = $logDir . '/ftp.log';

// Logger başlat
$log = new Logger('ftp_edifact');
$log->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG)); // terminale yazma
$log->pushHandler(new StreamHandler($logFile, Logger::DEBUG)); // log dosyasına yazma

$log->info("İşlem başlatıldı.");


// FTP sunucu bilgileri
$ftp_host = $_ENV['FTP_HOST'];
$ftp_user_name = $_ENV['FTP_USERNAME'];
$ftp_user_pass = $_ENV['FTP_PASSWORD'];
$ftp_port = $_ENV['FTP_PORT'];

// FTP klasör bilgileri
$inbox_dir = $_ENV['INBOX_DIR'];
$outbox_dir = $_ENV['OUTBOX_DIR'];
$archive_dir = $_ENV['ARCHIVE_DIR'];
$error_dir = $_ENV['ERROR_DIR'];


// FTP bağlantısı kurma
$conn = ftp_connect($ftp_host, $ftp_port);
if (!$conn) {
    $log->error("FTP bağlantısı sağlanamadı.");
    exit("FTP bağlantısı başarısız.\n");
}

$login_result = ftp_login($conn, $ftp_user_name, $ftp_user_pass);
if (!$login_result) { 
    $log->error("FTP'ye giriş yapılamadı.");
    exit("FTP'ye giriş yapılamadı.\n");
}

$log->info("FTP bağlantısı başarılı.");

// Inbox klasöründeki XML dosyalarını listele
$files = ftp_nlist($conn, $inbox_dir);
if (empty($files)) {
    $log->warning("Inbox klasöründe işlenecek dosya bulunamadı.");
    exit("Inbox klasöründe dosya bulunamadı.\n");
}

foreach ($files as $file) {
    if (pathinfo($file, PATHINFO_EXTENSION) != 'xml' && pathinfo($file, PATHINFO_EXTENSION) != 'XML') {
        continue; // Sadece XML dosyalarını  al
    }

    $filename = basename($file);
    $log->info("İşleme alınıyor: $filename");

    try {
        // XML dosyasını FTP'den indir
        $local_file_path = __DIR__ . "/temp/$filename";
        if (!ftp_get($conn, $local_file_path, "$inbox_dir/$filename", FTP_BINARY)) {
            throw new Exception("XML dosyası FTP'den indirilemedi.");
        }

        $log->info("XML dosyası indirildi: $filename");

        // XML'i EDIFACT'a dönüştür
        $xml = simplexml_load_file($local_file_path);
        if (!$xml) {
            throw new Exception("XML geçersiz veya okunamadı.");
        }

        // EDIFACT dönüşüm fonksiyonu
        $edi_string = convertXmlToEdifact($xml);

        // EDIFACT dosyasını oluştur
        $edi_filename = pathinfo($filename, PATHINFO_FILENAME) . '.edi';
        $edi_file_path = __DIR__ . "/temp/$edi_filename";
        file_put_contents($edi_file_path, $edi_string);
        $log->info("EDIFACT dosyası oluşturuldu: $edi_filename");

        // EDIFACT dosyasını FTP'ye yükle
        if (!ftp_put($conn, "$outbox_dir/$edi_filename", $edi_file_path, FTP_BINARY)) {
            throw new Exception("EDIFACT dosyası FTP'ye yüklenemedi.");
        }
        $log->info("EDIFACT dosyası outbox'a yüklendi: $edi_filename");

        // XML dosyasını archive klasörüne taşı
        if (!ftp_rename($conn, "$inbox_dir/$filename", "$archive_dir/$filename")) {
            throw new Exception("XML dosyası archive klasörüne taşınamadı.");
        }
        $log->info("XML dosyası archive klasörüne taşındı: $filename");

        // Geçici dosyalar siliniyor
        unlink($local_file_path);
        unlink($edi_file_path);

    } catch (Exception $e) {
        $log->error("Hata oluştu: " . $e->getMessage());

       

        $log->info("Hatalı dosya error klasörüne taşındı: $filename");
    }
}

// FTP bağlantısını kapat
ftp_close($conn);
$log->info("Tüm işlemler tamamlandı.");

// XML'i EDIFACT'a dönüştüren fonksiyon
function convertXmlToEdifact(SimpleXMLElement $xml): string {
    $header = $xml->OrderHeader;
    $details = $xml->OrderDetails->Detail;
    $interchangeRef = rand(1000000, 9999999);
    $messageRef = $interchangeRef;

    $segments = [];
    $segments[] = "UNA:+.? '";
    $segments[] = "UNB+UNOC:2+{$header->SenderMailboxId}:14+{$header->ReceiverMailboxId}:14+" . 
                  date('ymd') . ":" . date('Hi') . "+$interchangeRef++ORDERS'";
    $segments[] = "UNH+$messageRef+ORDERS:D:96A:UN:EAN008'";
    $segments[] = "BGM+220+{$header->OrderNumber}+9'";
    $segments[] = "DTM+137:{$header->OrderDate}:102'";
    $segments[] = "FTX+ZZZ+++{$header->FreeTextField}'";
    $segments[] = "NAD+BY+{$header->GLNBuyer}::9++BRICOSTORE ROMANIA S.A.+Calea Giulesti, Nr. 1-3, Sector 6+BUCURESTI++060251+RO'";
    $segments[] = "NAD+DP+{$header->GLNShipTo}::9++DEPOZIT BANEASA \\ 1616+Soseaua Bucuresti-Ploiesti, nr. 42-+BUCURESTI++013696+RO'";
    $segments[] = "NAD+SU+{$header->GLNSupplier}::9++STANLEY BLACK & DECKER ROMANIA SRL +TURTURELELOR, PHOENICIA BUSSINESS C+BUCURESTI++30881+RO'";
    $segments[] = "RFF+API:47362'";
    $segments[] = "CUX+2:{$header->Currency}:9'";

    $lineCount = 0;
    foreach ($details as $detail) {
        $lineCount++;
        $segments[] = "LIN+{$lineCount}++{$detail->ItemEanBarcode}:EN'";
        $segments[] = "PIA+1+{$detail->ItemReceiverCode}:SA::91'";
        $segments[] = "IMD+F++:::{$detail->ItemDescription}'";
        $segments[] = "QTY+21:" . number_format((float)$detail->ItemOrderedQuantity, 2, '.', '') . ":{$detail->ItemOrderedQuantityUom}'";
        $segments[] = "DTM+2:{$header->DeliveryDate}:102'";
        $segments[] = "PRI+AAA:" . number_format((float)$detail->ItemNetPrice, 2, '.', '') . "'";
    }

    $segments[] = "UNS+S'";
    $segments[] = "CNT+2:$lineCount'";
    $segments[] = "UNT+" . (count($segments) - 2) . "+$messageRef'";
    $segments[] = "UNZ+1+$interchangeRef'";

    return implode("\n", $segments);
}