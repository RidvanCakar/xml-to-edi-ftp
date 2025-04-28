<?php
require __DIR__ . '/vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use phpseclib3\Net\SFTP;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Logger başlat
$log = new Logger('sftp.log');
$log->pushHandler(new StreamHandler($_ENV['LOG_FILE'], Logger::DEBUG));
$log->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG)); // terminale de yaz

$log->info(message: "işlem başlatıldı.");

$tempDir = __DIR__ . '/temp';
if (!file_exists($tempDir)) {
    mkdir($tempDir, 0777, true);
}

// SFTP ayarları
$sftpHost = $_ENV['SFTP_HOST'];
$sftpUsername = $_ENV['SFTP_USERNAME'];
$sftpPassword = $_ENV['SFTP_PASSWORD'];
$sftpPort = $_ENV['SFTP_PORT'];

// Klasör yolları
$inbox = $_ENV['INBOX_DIR'];
$outbox = $_ENV['OUTBOX_DIR'];
$archive = $_ENV['ARCHIVE_DIR'];
$error = $_ENV['ERROR_DIR'];

// SFTP ile bağlantı kur
$sftp = new SFTP($sftpHost, $sftpPort);
if (!$sftp->login($sftpUsername, $sftpPassword)) {
    $log->error("SFTP'ye giriş yapılamadı.");
    exit("SFTP bağlantısı başarısız.\n");
}
$log->info(message: " sftp ile bağlantı kuruldu");


// XML dosyalarını inbox klasöründen al
$log->info("Inbox klasörü kontrol ediliyor");
$files = $sftp->nlist($inbox);

if (empty($files)) {
    $log->warning("Inbox klasöründe işlenecek XML dosyası bulunamadı.");
    exit("Inbox boş, işlem yok.\n");
}

foreach ($files as $file) {
    if (pathinfo($file, PATHINFO_EXTENSION) != 'XML') {
        continue; // Sadece XML dosyalarını işleme al
    }

    $filename = basename($file);
    $log->info("İşleme alınıyor alınan dosya: $filename");

    try {
        // XML dosyasını SFTP'den indir
        $localFilePath = __DIR__ . "/temp/$filename";
        if (!$sftp->get($inbox . '/' . $filename, $localFilePath)) {
            throw new Exception("XML dosyası indirilemedi.");
        }

        $xml = simplexml_load_file($localFilePath);
        if (!$xml) {
            throw new Exception("XML geçersiz veya okunamadı.");
        }

        $log->info("XML başarıyla yüklendi: $filename");

        $ediString = convertXmlToEdifact($xml, $log);

        $ediFilename = basename($file, '.XML') . '.edi';
        $ediPath = __DIR__ . "/temp/$ediFilename";

        file_put_contents($ediPath, $ediString);
        $log->info("EDIFACT dosyası outbox'a yazıldı: $ediFilename");

        // EDIFACT dosyasını SFTP'ye yükle
        if (!$sftp->put($outbox . '/' . $ediFilename, $ediPath)) {
            throw new Exception("EDIFACT dosyası yüklenemedi.");
        }

        // XML dosyasını archive klasörüne taşı
        if (!$sftp->rename($inbox . '/' . $filename, $archive . '/' . $filename)) {
            throw new Exception("XML dosyası archive klasörüne taşınamadı.");
        }

        $log->info("XML dosyası archive klasörüne taşındı: $filename");

        // Geçici dosyayı sil
        unlink($localFilePath);
        unlink($ediPath);

    } catch (Exception $e) {
        $log->error("Hata oluştu: " . $e->getMessage());

        // Hatalı dosyayı error klasörüne taşı
        if (!$sftp->rename($inbox . '/' . $filename, $error . '/' . $filename)) {
            $log->warning("Hatalı dosya error klasörüne taşınamadı: $filename");
        }

        $log->info("XML dosyası error klasörüne taşındı: $filename");
    }
}


$log->info("Tüm işlemler tamamlandı.");

// TEK TEK EDIFACT ÜRETEN FONKSİYON
function convertXmlToEdifact(SimpleXMLElement $xml, Logger $log): string {
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
    $segments[] = "TDT+12++:'";

    $lineCount = 0;
    foreach ($details as $detail) {
        $lineCount++;
        $segments[] = "LIN+{$lineCount}++{$detail->ItemEanBarcode}:EN'";
        $segments[] = "PIA+1+:IN::92'";
        $segments[] = "PIA+1+{$detail->ItemReceiverCode}:SA::91'";
        $segments[] = "IMD+F++:::{$detail->ItemDescription}'";
        $segments[] = "QTY+21:" . number_format((float)$detail->ItemOrderedQuantity, 2, '.', '') . ":{$detail->ItemOrderedQuantityUom}'";
        $segments[] = "DTM+2:{$header->DeliveryDate}:102'";
        $segments[] = "PRI+AAA:{$detail->ItemNetPrice}::::{$detail->ItemOrderedQuantityUom}'";
    }

    $segments[] = "UNS+S'";
    $segments[] = "CNT+2:$lineCount'";
    $segments[] = "UNT+" . (count($segments) - 2) . "+$messageRef'";
    $segments[] = "UNZ+1+$interchangeRef'";

    return implode("\n", $segments);
}
