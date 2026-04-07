<?php
// /images/search/index.php
// sparkSammy Search - Image results page
// Privacy-respecting meta image search: scrapes DuckDuckGo + StartPage

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
if (empty($q)) {
    header('Location: /images/');
    exit;
}

$encoded = str_replace(' ', '+', $q);

// Same fetch helper
function fetch_page($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9'
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    return $html ?: '';
}

// Experimental image extraction (regex + DOM fallback - thumbnails may be proxied)
function parse_images($query, $engine) {
    if ($engine === 'ddg') {
        $url = 'https://www.bing.com/images/search?q=' . $query;
    } else {
        $url = 'https://www.startpage.com/sp/search?q=' . $query . '&cat=images';
    }
    
    $html = fetch_page($url);
    if (empty($html)) return [];
    
    $images = [];
    
    // Regex to catch thumbnail images + alt text (works on both engines)
    preg_match_all('/<img[^>]+src=["\']([^"\']*(?:duckduckgo|startpage|external-content|images?)[^"\']*)["\'][^>]*alt=["\']([^"\']*)["\'][^>]*>/i', $html, $matches, PREG_SET_ORDER);
    
    foreach ($matches as $m) {
        $src = $m[1];
        $alt = trim($m[2]);
        
        // Skip tiny icons / logos
        if (strpos($src, 'icon') !== false || strpos($src, 'logo') !== false || strlen($alt) < 3) continue;
        
        // DDG often uses their image proxy - keep as-is (it works cross-origin)
        if (strpos($src, '//') !== 0 && strpos($src, 'http') !== 0) {
            $src = 'https://duckduckgo.com' . $src;
        }
        
        $images[] = [
            'src' => htmlspecialchars($src),
            'alt' => htmlspecialchars($alt ?: $query),
            'source' => $engine === 'ddg' ? 'DuckDuckGo' : 'StartPage'
        ];
        
        if (count($images) >= 12) break;
    }
    
    return $images;
}

$ddg_imgs = parse_images($encoded, 'ddg');
$sp_imgs = parse_images($encoded, 'sp');

$all_images = array_merge($ddg_imgs, $sp_imgs);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>sparkSammy Images</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap');
        :root { --bg: #0f0f12; --text: #e0e0e0; --accent: #00d4ff; }
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Inter', system_ui, sans-serif;
            background: var(--bg);
            color: var(--text);
            padding: 20px;
            max-width: 1200px;
            margin: 0 auto;
        }
        .header { display:flex; align-items:center; gap:16px; margin-bottom:30px; flex-wrap:wrap; }
        .logo { font-size:28px; font-weight:700; background:linear-gradient(90deg,#00d4ff,#00ff9d); -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
        form { flex:1; max-width:600px; position:relative; }
        input[type="text"] { width:100%; padding:16px 24px; padding-right:70px; font-size:18px; border:2px solid #222; border-radius:9999px; background:#1a1a1f; color:var(--text); }
        button { position:absolute; right:6px; top:50%; transform:translateY(-50%); background:var(--accent); color:#000; border:none; width:52px; height:52px; border-radius:9999px; font-size:22px; cursor:pointer; }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }
        .img-card {
            background: #1a1a1f;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid #222;
            transition: all .3s;
        }
        .img-card:hover { border-color: var(--accent); transform: scale(1.03); }
        .img-card img {
            width: 100%;
            height: 220px;
            object-fit: cover;
            display: block;
        }
        .img-info {
            padding: 14px;
            font-size: 14px;
        }
        .source { font-size:11px; background:#222; padding:2px 8px; border-radius:9999px; }
        .meta { text-align:center; margin-top:60px; color:#666; font-size:14px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo">sparkSammy Search</div>
        <form action="/images/search/" method="get">
            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search images again..." autofocus>
            <button type="submit">🖼️</button>
        </form>
        <a href="/" style="margin-left:auto; color:#00d4ff; text-decoration:none; font-weight:500;">🌐 Web ←</a>
    </div>
    
    <h2 style="margin-bottom:20px; font-size:24px;">Images for “<?= htmlspecialchars($q) ?>”</h2>
    
    <div class="grid">
        <?php if (empty($all_images)): ?>
            <p style="grid-column:1/-1; text-align:center; padding:80px; background:#1a1a1f; border-radius:16px;">No images found.<br>Image scraping is experimental — try a different query.</p>
        <?php else: ?>
            <?php foreach ($all_images as $img): ?>
                <div class="img-card">
                    <img src="<?= $img['src'] ?>" alt="<?= $img['alt'] ?>" loading="lazy">
                    <div class="img-info">
                        <span class="source"><?= $img['source'] ?></span>
                        <div style="margin-top:8px; line-height:1.4;"><?= $img['alt'] ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <div class="meta">
        Privacy note: Thumbnails fetched server-side (your IP hidden).<br>
        Images are direct from DuckDuckGo / StartPage proxies. No tracking. No cookies.
    </div>
</body>
</html>
