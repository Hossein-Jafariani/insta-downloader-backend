<?php
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");

if (!isset($_GET['url']) || empty($_GET['url'])) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "url param missing"]);
    exit;
}

$inputUrl = $_GET['url'];

// normalize
$inputUrl = trim($inputUrl);

// cURL init
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $inputUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_MAXREDIRS, 8);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
curl_setopt($ch, CURLOPT_TIMEOUT, 25);

// headers to mimic a real browser
$ua = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36";
curl_setopt($ch, CURLOPT_USERAGENT, $ua);

// accept compressed
curl_setopt($ch, CURLOPT_ENCODING, "");

// accept insecure optionally (only if you trust host). comment out in prod.
// curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
// curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

$html = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($html === false || empty($html)) {
    http_response_code(502);
    echo json_encode([
        "success" => false,
        "message" => "Failed to fetch IG page",
        "curl_error" => $curlErr,
        "http_code" => $httpCode
    ]);
    exit;
}

// 1) try find "video_url" in HTML (common)
if (preg_match('/"video_url":"([^"]+)"/i', $html, $m)) {
    $videoUrl = stripslashes($m[1]);
    echo json_encode(["success" => true, "url" => $videoUrl]);
    exit;
}

// 2) try og:video meta
if (preg_match('/<meta[^>]+property=["\']og:video["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m2)) {
    $videoUrl = $m2[1];
    echo json_encode(["success" => true, "url" => $videoUrl]);
    exit;
}

// 3) JSON-LD or window._sharedData (older)
if (preg_match('/window\._sharedData\s*=\s*(\{.*?\});/s', $html, $m3)) {
    $json = $m3[1];
    $decoded = json_decode($json, true);
    if ($decoded) {
        // try descend to find video_url in common paths
        $paths = [
            ['entry_data','PostPage',0,'graphql','shortcode_media','video_url'],
            ['entry_data','PostPage',0,'graphql','shortcode_media','video_versions',0,'url']
        ];
        foreach ($paths as $p) {
            $tmp = $decoded;
            foreach ($p as $k) {
                if (is_array($tmp) && array_key_exists($k, $tmp)) $tmp = $tmp[$k];
                else { $tmp = null; break; }
            }
            if (!empty($tmp)) {
                echo json_encode(["success" => true, "url" => $tmp]);
                exit;
            }
        }
    }
}

// 4) last fallback: try to find any https .mp4 URL in HTML
if (preg_match('/https:\\/\\/[^\s"\']+\\.mp4[^\s"\']*/i', $html, $m4)) {
    $videoUrl = $m4[0];
    echo json_encode(["success" => true, "url" => $videoUrl]);
    exit;
}

// nothing found
http_response_code(404);
echo json_encode(["success" => false, "message" => "No MP4 found", "http_code_fetched" => $httpCode]);
exit;
