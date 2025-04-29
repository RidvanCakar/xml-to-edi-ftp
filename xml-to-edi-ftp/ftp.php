<?php
// Required libraries
require __DIR__ . '/vendor/autoload.php';
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Dotenv\Dotenv;

// Load .env file
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Check if the log directory exists, if not, create it
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

$logFile = $logDir . '/ftp.log';

// Start logger
$log = new Logger('ftp_edifact');
$log->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG)); // write to terminal
$log->pushHandler(new StreamHandler($logFile, Logger::DEBUG)); // write to log file

$log->info("İşlem başlatıldı.");


// FTP server information
$ftp_host = $_ENV['FTP_HOST'];
$ftp_user_name = $_ENV['FTP_USERNAME'];
$ftp_user_pass = $_ENV['FTP_PASSWORD'];
$ftp_port = $_ENV['FTP_PORT'];

// FTP folder information
$inbox_dir = $_ENV['INBOX_DIR'];
$outbox_dir = $_ENV['OUTBOX_DIR'];
$archive_dir = $_ENV['ARCHIVE_DIR'];
$error_dir = $_ENV['ERROR_DIR'];

// Establish FTP connection
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



// Check if the archive directory exists, if not, create it
if (!ftp_chdir($conn, $archive_dir)) {  // Try to change to the archive directory
    $log->info("Archive klasörü bulunamadı. Yeni klasör oluşturuluyor.");
    if (!ftp_mkdir($conn, $archive_dir)) {  // Create the archive directory
        $log->error("Archive klasörü oluşturulamadı.");
        exit("Archive klasörü oluşturulamadı.\n");
    }
    $log->info("Archive klasörü oluşturuldu.");
}


//Check if the outbox directory exists, if not, create it
if (!ftp_chdir($conn, $outbox_dir)){
    $log->info("outbox klasörü bulunamadı. yeni klasör oluşturuluyor");
    if(!ftp_mkdir($conn,$outbox_dir)){
        $log->error("outbox klasörü oluşturulamadı.");
        exit("outbox klasörü oluşturulmadı. \n");
    }
    $log->info("outbox klasörü oluşturuldu");
}

//Check if the inbox directory exists, if not, create it
if (!ftp_chdir($conn, $inbox_dir)){
    $log->info("inbox klasörü bulunamadı. yeni klasör oluşturuluyor");
    if(!ftp_mkdir($conn,$inbox_dir)){
        $log->error("inbox klasörü oluşturulamadı.");
        exit("inbox klasörü oluşturulmadı. \n");
    }
    $log->info("inbox klasörü oluşturuldu");

}

// List XML files in the inbox folder
$files = ftp_nlist($conn, $inbox_dir);
if (empty($files)) {
    $log->warning("Inbox klasöründe işlenecek dosya bulunamadı.");
    exit("Inbox klasöründe dosya bulunamadı.\n");
}

foreach ($files as $file) {
    if (pathinfo($file, PATHINFO_EXTENSION) != 'xml' && pathinfo($file, PATHINFO_EXTENSION) != 'XML') {
        continue; // Only get XML files
    }

    $filename = basename($file);
    $log->info("İşleme alınıyor: $filename");

    try {
        // Download the XML file from FTP
        $local_file_path = __DIR__ . "/temp/$filename";
        if (!ftp_get($conn, $local_file_path, "$inbox_dir/$filename", FTP_BINARY)) {
            throw new Exception("XML dosyası FTP'den indirilemedi.");
        }

        $log->info("XML dosyası indirildi: $filename");

        // Convert XML to EDIFACT
        $xml = simplexml_load_file($local_file_path);
        if (!$xml) {
            throw new Exception("XML geçersiz veya okunamadı.");
        }

        // EDIFACT conversion function
        $edi_string = convertXmlToEdifact($xml);

        // Create the EDIFACT file with a unique name timestamp
        $timestamp = date('Ymd_His');
        $edi_filename = pathinfo($filename, PATHINFO_FILENAME) . "_$timestamp.edi";
        $edi_file_path = __DIR__ . "/temp/$edi_filename";
        file_put_contents($edi_file_path, $edi_string);
        $log->info("EDIFACT dosyası oluşturuldu: $edi_filename");

        // Upload the EDIFACT file to FTP
        if (!ftp_put($conn, "$outbox_dir/$edi_filename", $edi_file_path, FTP_BINARY)) {
            throw new Exception("EDIFACT dosyası FTP'ye yüklenemedi.");
        }
        $log->info("EDIFACT dosyası outbox'a yüklendi: $edi_filename");

        // Move the XML file to the archive folder with timestamp
        $archived_filename = pathinfo($filename, PATHINFO_FILENAME) . "_$timestamp." . pathinfo($filename, PATHINFO_EXTENSION);
        if (!ftp_rename($conn, "$inbox_dir/$filename", "$archive_dir/$archived_filename")) {
            throw new Exception("XML dosyası archive klasörüne taşınamadı.");
        }
        $log->info("XML dosyası archive klasörüne taşındı: $archived_filename");

        // Delete temporary files
        unlink($local_file_path);
        unlink($edi_file_path);

    } catch (Exception $e) {
        $log->error("Hata oluştu: " . $e->getMessage());
        $log->info("Hatalı dosya error klasörüne taşındı: $filename");
    }
}

// Close FTP connection
ftp_close($conn);
$log->info("Tüm işlemler tamamlandı.");

// Function to convert XML to EDIFACT
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
