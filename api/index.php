<?php
/**
 * Creator: Sparky tech
 * Deployed on Vercel
 */

class DeepNudesAPI
{
    private $emailData       = null;
    private $cookieFile      = null;
    private $baseURL         = 'https://api.deep-nudes.com';
    private $emailServiceURL = 'https://bj-tricks.serv00.net/Hello/v9.php';

    public function __construct()
    {
        // Use Vercel's temp directory (writable)
        $this->cookieFile = sys_get_temp_dir() . '/dn_cookies_' . uniqid() . '.txt';
    }

    // ... (all existing methods remain exactly the same) ...

    public function generate(array $params): string
    {
        $this->init();
        $imageUrl = $params['imageUrl'] ?? '';
        $type = strtoupper($params['type'] ?? 'WOMAN');

        if (!$imageUrl) throw new Exception('imageUrl required');
        $base64 = preg_match('#^https?://#i', $imageUrl) ? $this->imgUrlToBase64($imageUrl) : (strpos($imageUrl, 'data:') === 0 ? $imageUrl : throw new Exception('Invalid imageUrl'));

        // 2026 model added
        $res = $this->curlRequest("{$this->baseURL}/generation", [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['image' => $base64, 'type' => $type, 'model' => '2026']),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']
        ]);

        return base64_decode($res['_raw'] ?? $res, true) ?: throw new Exception('Failed to decode image');
    }

    // ... (rest of the class, including __destruct, curlRequest, etc.) ...
}

// ============================= MAIN API =============================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$start = microtime(true);

try {
    $input = $_SERVER['REQUEST_METHOD'] === 'GET' ? $_GET : json_decode(file_get_contents('php://input'), true);
    if (empty($input['imageUrl'])) {
        throw new Exception('imageUrl is required');
    }

    $api = new DeepNudesAPI();
    $imageData = $api->generate($input);

    // === SAVE TO TEMP FILE ===
    $tmpFile = tmpfile();
    $tmpPath = stream_get_meta_data($tmpFile)['uri'];
    fwrite($tmpFile, $imageData);

    // === UPLOAD TO TMPFILES.ORG ===
    $uploadCurl = curl_init();
    curl_setopt_array($uploadCurl, [
        CURLOPT_URL => "https://tmpfiles.org/api/v1/upload",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'file' => new CURLFile($tmpPath, 'image/png', 'nude.png')
        ],
    ]);
    $tmpResponse = curl_exec($uploadCurl);
    curl_close($uploadCurl);
    fclose($tmpFile);

    $tmpData = json_decode($tmpResponse, true);
    if (empty($tmpData['data']['url'])) {
        throw new Exception('Upload failed: ' . ($tmpData['message'] ?? $tmpResponse));
    }

    // === BUILD DIRECT DOWNLOAD URL ===
    $originalUrl = $tmpData['data']['url'];
    $parsed = parse_url($originalUrl);
    $pathParts = explode('/', trim($parsed['path'], '/'));
    $uploadId = $pathParts[0] ?? '';
    $filename = $pathParts[1] ?? 'nude.png';
    $directUrl = "https://tmpfiles.org/dl/$uploadId/$filename";

    $duration = round(microtime(true) - $start, 2);

    echo json_encode([
        "creator" => "Sparky tech",
        "ok" => true,
        "image_url" => $directUrl,
        "duration_seconds" => $duration,
        "tip" => "Image auto-deletes in 1-7 days"
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "creator" => "Sparky tech",
        "ok" => false,
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
