<?php
/**
 * Creator: Sparky tech
 * API for Deep-Nudes generation (model 2026)
 * Works on Vercel PHP runtime
 */

class DeepNudesAPI
{
    private $emailData       = null;
    private $cookieFile      = null;
    private $baseURL         = 'https://api.deep-nudes.com';
    private $emailServiceURL = 'https://bj-tricks.serv00.net/Hello/v9.php';

    public function __construct()
    {
        $this->cookieFile = sys_get_temp_dir() . '/dn_cookies_' . uniqid() . '.txt';
    }

    private function curlRequest(string $url, array $opts = []): array
    {
        $ch = curl_init();
        $default = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_COOKIEJAR      => $this->cookieFile,
            CURLOPT_COOKIEFILE     => $this->cookieFile,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36',
            CURLOPT_HTTPHEADER     => [
                'Accept: */*',
                'Origin: https://deep-nudes.com',
                'Referer: https://deep-nudes.com/'
            ],
            CURLOPT_TIMEOUT        => 60, // increased for Vercel
        ];
        curl_setopt_array($ch, $opts + $default);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false) throw new Exception("cURL Error: $err");
        if ($httpCode < 200 || $httpCode >= 300) throw new Exception("HTTP $httpCode");

        $json = json_decode($response, true);
        return $json ?? ['_raw' => $response];
    }

    public function makeEmail(): array
    {
        $res = $this->curlRequest($this->emailServiceURL . '?action=create');
        $this->emailData = $res;
        return $res;
    }

    public function reqLink(): array
    {
        return $this->curlRequest("{$this->baseURL}/auth/magic-link", [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['email' => $this->emailData['email']]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']
        ]);
    }

    public function getEmail(): array
    {
        return $this->curlRequest($this->emailServiceURL . '?action=message&email=' . urlencode($this->emailData['email']));
    }

    public function getLink(array $mail): ?string
    {
        preg_match('#https://api\.deep-nudes\.com/auth/magic-login\?token=[^\s"]+#', $mail['data'][0]['text_content'] ?? '', $m);
        return $m[0] ?? null;
    }

    public function authWithLink(string $link): array
    {
        return $this->curlRequest($link);
    }

    public function waitAndAuth(int $max = 12, int $sec = 3): void
    {
        for ($i = 1; $i <= $max; $i++) {
            try {
                $mail = $this->getEmail();
                $link = $this->getLink($mail);
                if ($link) {
                    $this->authWithLink($link);
                    return;
                }
            } catch (Exception $e) {
                // ignore
            }
            if ($i < $max) sleep($sec);
        }
        throw new Exception('Magic link timeout');
    }

    public function init(): void
    {
        $hasAuth = file_exists($this->cookieFile) && strpos(file_get_contents($this->cookieFile), 'accessToken') !== false;
        if (!$hasAuth) {
            $this->makeEmail();
            $this->reqLink();
            $this->waitAndAuth();
        }
    }

    // FIXED: No fileinfo extension needed
    public function imgUrlToBase64(string $url): string
    {
        $raw = $this->curlRequest($url, [CURLOPT_BINARYTRANSFER => true])['_raw'];
        // Default to PNG – works everywhere
        return "data:image/png;base64," . base64_encode($raw);
    }

    public function generate(array $params): string
    {
        $this->init();
        $imageUrl = $params['imageUrl'] ?? '';
        $type = strtoupper($params['type'] ?? 'WOMAN');

        if (!$imageUrl) throw new Exception('imageUrl required');
        $base64 = preg_match('#^https?://#i', $imageUrl)
            ? $this->imgUrlToBase64($imageUrl)
            : (strpos($imageUrl, 'data:') === 0 ? $imageUrl : throw new Exception('Invalid imageUrl'));

        // Model 2026 as requested
        $res = $this->curlRequest("{$this->baseURL}/generation", [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'image' => $base64,
                'type'  => $type,
                'model' => '2026'
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']
        ]);

        return base64_decode($res['_raw'] ?? $res, true) ?: throw new Exception('Failed to decode image');
    }

    public function __destruct()
    {
        @unlink($this->cookieFile);
    }
}

// ============================= MAIN HANDLER =============================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$start = microtime(true);

try {
    $input = $_SERVER['REQUEST_METHOD'] === 'GET'
        ? $_GET
        : json_decode(file_get_contents('php://input'), true);

    if (empty($input['imageUrl'])) {
        throw new Exception('imageUrl is required');
    }

    $api = new DeepNudesAPI();
    $imageData = $api->generate($input);

    // Save to temp file
    $tmpFile = tmpfile();
    $tmpPath = stream_get_meta_data($tmpFile)['uri'];
    fwrite($tmpFile, $imageData);

    // Upload to tmpfiles.org
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

    // Build direct download URL
    $originalUrl = $tmpData['data']['url'];
    $parsed = parse_url($originalUrl);
    $pathParts = explode('/', trim($parsed['path'], '/'));
    $uploadId = $pathParts[0] ?? '';
    $filename = $pathParts[1] ?? 'nude.png';
    $directUrl = "https://tmpfiles.org/dl/$uploadId/$filename";

    $duration = round(microtime(true) - $start, 2);

    echo json_encode([
        "creator"          => "Sparky tech",
        "ok"               => true,
        "image_url"        => $directUrl,
        "duration_seconds" => $duration,
        "tip"              => "Image auto-deletes in 1-7 days"
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "creator" => "Sparky tech",
        "ok"      => false,
        "error"   => $e->getMessage()
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
?>
