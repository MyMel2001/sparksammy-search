<?php
// /search/index.php
// sparkSammy Search - Web results page (BUGFIXED)
// • DuckDuckGo now uses official lite version (as requested)
// • Robust parsing for lite HTML structure
// • StartPage kept as-is (working)
// • Sources now correctly show DuckDuckGo + StartPage (no more all-StartPage bug)

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
if (empty($q)) {
    header('Location: /');
    exit;
}

$encoded = str_replace(' ', '+', $q);

// Fetch helper
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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $html = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    return $error ? '' : $html;
}

// Parse DuckDuckGo LITE (new endpoint + robust parsing)
function parse_ddg($query) {
    $url = 'https://lite.duckduckgo.com/lite/?q=' . $query;
    $html = fetch_page($url);
    if (empty($html)) return [];
    
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xpath = new DOMXPath($dom);
    
    $results = [];
    // Lite uses uddg= redirect links for titles - very stable
    foreach ($xpath->query('//a[contains(@href, "uddg=")]') as $titleNode) {
        $title = trim($titleNode->textContent);
        if (empty($title) || strlen($title) < 5) continue;
        
        $href = $titleNode->getAttribute('href');
        parse_str(parse_url($href, PHP_URL_QUERY), $params);
        $link = $params['uddg'] ?? $href;
        
        // Extract snippet from nearby text (works on lite table layout)
        $snippet = '';
        $parent = $titleNode->parentNode;
        $candidate = trim($parent->textContent);
        if (strlen($candidate) > strlen($title) + 30) {
            $snippet = trim(str_replace($title, '', $candidate));
            $snippet = preg_replace('/\s+/', ' ', $snippet);
        } else {
            // Fallback: walk siblings for longer text blocks
            $next = $titleNode->nextSibling;
            while ($next) {
                if ($next->nodeType === XML_ELEMENT_NODE && in_array($next->nodeName, ['div', 'p', 'span'])) {
                    $text = trim($next->textContent);
                    if (strlen($text) > 30) {
                        $snippet = $text;
                        break;
                    }
                }
                $next = $next->nextSibling;
            }
        }
        
        $visible_url = parse_url($link, PHP_URL_HOST) ?: '';
        
        $results[] = [
            'title' => htmlspecialchars($title),
            'link' => htmlspecialchars($link),
            'snippet' => htmlspecialchars($snippet ?: 'No description available.'),
            'visible_url' => htmlspecialchars($visible_url),
            'source' => 'DuckDuckGo'
        ];
        
        if (count($results) >= 10) break;
    }
    return $results;
}

// Parse StartPage (unchanged - already working)
function parse_startpage($query) {
    $url = 'https://www.startpage.com/sp/search?q=' . $query;
    $html = fetch_page($url);
    if (empty($html)) return [];
    
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    
    $results = [];
    foreach ($xpath->query('//div[contains(@class,"result") or contains(@class,"search-result") or contains(@class,"w-gl")]') as $node) {
        $titleNode = $xpath->query('.//h2/a | .//h3/a | .//a[contains(@class,"title")]', $node)[0] ?? null;
        if (!$titleNode) continue;
        
        $title = trim($titleNode->textContent);
        $link = $titleNode->getAttribute('href');
        if (!filter_var($link, FILTER_VALIDATE_URL)) continue;
        
        $snippetNode = $xpath->query('.//p[contains(@class,"snippet") or contains(@class,"description")]', $node)[0] ?? 
                       $xpath->query('.//div[contains(@class,"snippet")]', $node)[0] ?? null;
        $snippet = $snippetNode ? trim($snippetNode->textContent) : '';
        
        $results[] = [
            'title' => htmlspecialchars($title),
            'link' => htmlspecialchars($link),
            'snippet' => htmlspecialchars($snippet),
            'visible_url' => parse_url($link, PHP_URL_HOST),
            'source' => 'StartPage'
        ];
        
        if (count($results) >= 10) break;
    }
    return $results;
}

// Merge (deduplicated)
$ddg_results = parse_ddg($encoded);
$sp_results = parse_startpage($encoded);

$all_results = [];
$seen = [];
foreach (array_merge($ddg_results, $sp_results) as $r) {
    $key = $r['link'];
    if (!isset($seen[$key])) {
        $seen[$key] = true;
        $all_results[] = $r;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>sparkSammy Search</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap');
        :root { --bg: #0f0f12; --text: #e0e0e0; --accent: #00d4ff; }
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Inter', system_ui, sans-serif;
            background: var(--bg);
            color: var(--text);
            padding: 20px;
            max-width: 1100px;
            margin: 0 auto;
        }
        .header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }
        .logo { font-size: 28px; font-weight: 700; background: linear-gradient(90deg,#00d4ff,#00ff9d); -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
        form { flex: 1; max-width: 600px; position:relative; }
        input[type="text"] {
            width:100%; padding:16px 24px; padding-right:70px; font-size:18px;
            border:2px solid #222; border-radius:9999px; background:#1a1a1f; color:var(--text);
        }
        input:focus { border-color:var(--accent); }
        button {
            position:absolute; right:6px; top:50%; transform:translateY(-50%);
            background:var(--accent); color:#000; border:none; width:52px; height:52px;
            border-radius:9999px; font-size:22px; cursor:pointer;
        }
        .results { margin-top:40px; }
        .result {
            background:#1a1a1f; border-radius:16px; padding:22px; margin-bottom:16px;
            transition: all .2s; border:1px solid #222;
        }
        .result:hover { border-color:var(--accent); transform:translateY(-2px); }
        .result a { text-decoration:none; color:inherit; }
        .result-title { font-size:20px; font-weight:600; margin-bottom:6px; color:#00d4ff; }
        .result-url { color:#0f0; font-size:14px; margin-bottom:10px; display:block; }
        .result-snippet { color:#bbb; line-height:1.5; }
        .source { font-size:12px; padding:2px 10px; border-radius:9999px; background:#222; display:inline-block; margin-bottom:12px; }
        .meta { text-align:center; margin-top:60px; color:#666; font-size:14px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo">sparkSammy Search</div>
        <form action="/search/" method="get">
            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search again..." autofocus>
            <button type="submit">🔎</button>
        </form>
        <a href="/images/" style="margin-left:auto; color:#00d4ff; text-decoration:none; font-weight:500;">🖼️ Images →</a>
    </div>
    
    <div class="results">
        <h2 style="margin-bottom:20px; font-size:24px;">Results for “<?= htmlspecialchars($q) ?>”</h2>
        
        <?php if (empty($all_results)): ?>
            <p style="text-align:center; padding:60px; background:#1a1a1f; border-radius:16px;">No results found.<br>Try a different query or check your connection.</p>
        <?php else: ?>
            <?php foreach ($all_results as $r): ?>
                <div class="result">
                    <span class="source"><?= $r['source'] ?></span>
                    <a href="<?= $r['link'] ?>" target="_blank" rel="noopener">
                        <div class="result-title"><?= $r['title'] ?></div>
                        <div class="result-url"><?= $r['visible_url'] ?></div>
                        <div class="result-snippet"><?= $r['snippet'] ?></div>
                    </a>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <div class="meta">
        Privacy note: Queries fetched server-side from DuckDuckGo Lite + StartPage.<br>
        Your IP hidden • No cookies • No tracking • No logs
    </div>
</body>
</html>