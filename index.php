<?php
session_start();
set_time_limit(120);

class RelaySearch {
    public $results = [];
    public $queue = [];
    public $visited = [];
    private $linkGraph = [];
    private $pageRanks = [];

    private $trustedDomains = [
        "wikipedia.org", "britannica.com", "wikidata.org", "wiktionary.org",
        "bbc.com", "reuters.com", "theguardian.com", "apnews.com", "npr.org",
        "cnn.com", "wsj.com", "developer.mozilla.org", "docs.python.org",
        "php.net", "learn.microsoft.com", "gnu.org", "wired.com",
        "khanacademy.org", "edx.org", "coursera.org", "howstuffworks.co",
        "sciencedaily.com", "arxiv.org", "nature.com", "scientificamerican.com",
        "nih.gov", "nasa.gov", "who.int", "usa.gov", "cdc.gov", "nist.gov",
        "data.gov", "gutenberg.org", "archive.org", "openculture.com",
        "news.ycombinator.com", "stackoverflow.com", "github.com",
        "piapro.net", "deviantart.com", "crypton.co.jp"
    ];

    private function fetch($url) {
        $ctx = stream_context_create([
            'http' => [
                'header' => "User-Agent: sparkSammyBot/3.11 (Used for sparkSammy Search)\r\n",
                'timeout' => 5
            ]
        ]);
        return @file_get_contents($url, false, $ctx);
    }

    private function absUrl($rel, $base) {
        if (parse_url($rel, PHP_URL_SCHEME) != '') return $rel;
        if ($rel === '' || $rel[0] == '#' || $rel[0] == '?') return $base . $rel;
        extract(parse_url($base));
        $path = preg_replace('#/[^/]*$#', '', $path ?? '');
        if ($rel[0] == '/') $path = '';
        $abs = "$host$path/$rel";
        $re = array('#(/\.?/)#', '#/(?!\.\.)[^/]+/\.\./#');
        for ($n = 1; $n > 0; $abs = preg_replace($re, '/', $abs, -1, $n));
        return ($scheme ?? 'https') . '://' . $abs;
    }

    private function tokenizeQuery($query) {
        preg_match_all('/"([^"]+)"|\S+/', $query, $matches);
        $tokens = [];
        foreach ($matches[0] as $m) {
            $m = trim($m);
            if (str_starts_with($m,'"')) $tokens[] = ['type'=>'PHRASE','value'=>trim($m,'"')];
            elseif (strtoupper($m)==='OR') $tokens[] = ['type'=>'OR'];
            elseif (str_starts_with($m,'-')) $tokens[] = ['type'=>'NOT','value'=>substr($m,1)];
            else $tokens[] = ['type'=>'TERM','value'=>$m];
        }
        return $tokens;
    }

    private function matchesQuery($text, $tokens) {
        $text = strtolower($text);
        $must = []; $or = []; $not = [];
        foreach ($tokens as $t) {
            if ($t['type']==='TERM' || $t['type']==='PHRASE') $must[] = strtolower($t['value']);
            if ($t['type']==='OR') { $or = $must; $must=[]; }
            if ($t['type']==='NOT') $not[] = strtolower($t['value']);
        }
        foreach ($not as $n) if (str_contains($text,$n)) return false;
        if (!empty($or)) {
            foreach ($or as $t) if (str_contains($text,$t)) return true;
            return false;
        }
        foreach ($must as $t) if (!str_contains($text,$t)) return false;
        return true;
    }

    private function isTrusted($url) {
        $host = parse_url($url, PHP_URL_HOST);
        foreach ($this->trustedDomains as $d) {
            if ($host && str_contains($host, $d)) return true;
        }
        return false;
    }

    private function computePageRank() {
        $rank = [];
        foreach ($this->linkGraph as $from=>$tos) {
            $rank[$from] = $rank[$from] ?? 1;
            foreach ($tos as $to) $rank[$to] = $rank[$to] ?? 1;
        }
        for ($i=0;$i<2;$i++) {
            $new = array_fill_keys(array_keys($rank),0);
            foreach ($this->linkGraph as $from=>$tos) {
                $share = $rank[$from]/max(1,count($tos));
                foreach ($tos as $to) $new[$to] += $share;
            }
            $rank = $new;
        }
        $this->pageRanks = $rank;
    }

    public function processBatch($urls, $query, $saveResults = true, $isSeed = false) {
        global $type; // Access the current search mode (web vs images)
        $tokens = $this->tokenizeQuery($query);
        $queryLower = strtolower($query);

        foreach ($urls as $url) {
            if (isset($this->visited[$url])) continue;
            $this->visited[$url] = true;

            $html = $this->fetch($url);
            if (!$html) continue;

            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
            libxml_clear_errors();

            $title = $dom->getElementsByTagName('title')->item(0)?->nodeValue ?? $url;
            $text = strtolower(strip_tags($html));

            // Scrape Images
            $relevantImgs = [];
            foreach ($dom->getElementsByTagName('img') as $img) {
                $src = $img->getAttribute('src');
                $alt = $img->getAttribute('alt');
                if (!$src || str_starts_with($src, 'data:')) continue;
                
                $fileName = basename($src);
                if ($this->matchesQuery(strtolower($alt . ' ' . $fileName), $tokens)) {
                    $relevantImgs[] = [
                        'src' => $this->absUrl($src, $url),
                        'caption' => $alt ?: $title,
                        'source' => $url
                    ];
                }
            }

            // Save result logic
            if ($saveResults && ($this->matchesQuery($text . ' ' . $title, $tokens) || !empty($relevantImgs))) {
                
                // BUG FIX: If we are in Web mode, do NOT add search engine seed pages to the results list.
                // We only save seeds as results if we are in 'images' mode (so we can display the images).
                if ($isSeed && $type === 'web') {
                    // Skip adding to $this->results, but links/images are already processed
                } else {
                    $relevance = substr_count($text, $queryLower) + substr_count(strtolower($title), $queryLower) * 5;
                    $imageBonus = count($relevantImgs) * 2;
                    $pagerank = $this->pageRanks[$url] ?? 1;
                    $trust = $this->isTrusted($url) ? 2 : 1;
                    $score = round((($relevance * 2) + $imageBonus + (log(1 + $pagerank) * 2)) * $trust);

                    $this->results[$url] = [
                        'title' => $title,
                        'images' => $relevantImgs,
                        'rank' => $score
                    ];
                }
            }

            // Extract links for the queue (always happens, even for seeds)
            foreach ($dom->getElementsByTagName('a') as $a) {
                $href = $a->getAttribute('href');
                if ($href) $this->queue[] = $this->absUrl($href, $url);
            }
        }
        $this->queue = array_values(array_unique($this->queue));
        $this->computePageRank();
    }

    public function rank() {
        uasort($this->results, fn($a, $b) => $b['rank'] <=> $a['rank']);
    }
}

/* =========================
    UI & CONTROLLER
========================= */
$engine = new RelaySearch();
$q = $_GET['q'] ?? '';
$type = $_GET['type'] ?? 'web';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = ($type == 'web') ? 10 : 20;

if (!empty($q)) {
    $seeds = [
        "https://en.wikipedia.org/w/index.php?search=" . urlencode($q),
        "https://lite.duckduckgo.com/lite?q=" . urlencode($q),
        "https://www.startpage.com/sp/search?query=" . urlencode($q),
        "https://www.startpage.com/sp/search?cat=web&pl=opensearch&query=" . urlencode($q),
        "https://www.startpage.com/sp/search?cat=images&pl=opensearch&query=" . urlencode($q),
        "https://www.bing.com/images/search?q=" . urlencode($q)
    ];

    // Pass 'true' as the 4th argument to indicate these are Seeds
    $engine->processBatch($seeds, $q, true, true);

    $depth = 0;
    while ($depth < 2 && count($engine->results) < ($page * $perPage)) {
        $batch = array_slice($engine->queue, 0, 15);
        $engine->queue = array_slice($engine->queue, 15);
        if (empty($batch)) break;
        $engine->processBatch($batch, $q, true, false); // These are discovered links, not seeds
        $depth++;
    }
    $engine->rank();
}

$totalResults = count($engine->results);
$pagedResults = array_slice($engine->results, ($page - 1) * $perPage, $perPage, true);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>sparkSammy Search</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 p-6">
    <div class="max-w-6xl mx-auto">
        <header class="text-center mb-8">
            <h1 class="text-4xl font-black text-slate-800">sparkSammy <span class="text-blue-600">Search</span></h1>
        </header>

        <form method="GET" class="bg-white p-2 rounded-full shadow-lg flex gap-2 mb-10 border">
            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" class="flex-grow pl-6 outline-none" placeholder="Search the relay...">
            <input type="hidden" name="page" value="1">
            <select name="type" class="bg-slate-50 px-4 rounded-full text-sm">
                <option value="web" <?= $type=='web'?'selected':'' ?>>Web</option>
                <option value="images" <?= $type=='images'?'selected':'' ?>>Images</option>
            </select>
            <button class="bg-blue-600 text-white px-8 py-3 rounded-full font-bold">FIND</button>
        </form>

        <?php if (!empty($pagedResults)): ?>
            <?php if ($type == 'web'): ?>
                <div class="grid gap-6">
                    <?php foreach ($pagedResults as $url => $data): ?>
                        <div class="bg-white p-5 rounded-2xl shadow-sm border border-slate-100">
                            <a href="<?= $url ?>" class="text-blue-700 font-bold text-xl hover:underline" target="_blank">
                                <?= htmlspecialchars($data['title']) ?>
                            </a>
                            <div class="text-green-700 text-xs truncate mb-2"><?= $url ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="columns-2 md:columns-4 lg:columns-5 gap-4">
                    <?php foreach ($pagedResults as $url => $data): ?>
                        <?php foreach ($data['images'] as $img): ?>
                            <div class="mb-4 break-inside-avoid group">
                                <a href="<?= htmlspecialchars($img['source']) ?>" 
                                   target="_blank" 
                                   title="Source: <?= htmlspecialchars($img['source']) ?>"
                                   class="block overflow-hidden rounded-lg shadow hover:shadow-xl transition-all">
                                    
                                    <img src="<?= htmlspecialchars($img['src']) ?>" 
                                         alt="<?= htmlspecialchars($img['caption']) ?>"
                                         class="w-full h-auto group-hover:scale-105 transition-transform duration-300" 
                                         onerror="this.closest('.break-inside-avoid').remove()"
                                         loading="lazy">
                                         
                                    <div class="hidden group-hover:block absolute bottom-2 right-2 bg-black/60 text-white text-[10px] px-2 py-1 rounded backdrop-blur-sm">
                                        View Source
                                    </div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="mt-12 flex justify-center items-center gap-6">
                <?php if ($page > 1): ?>
                    <a href="?q=<?= urlencode($q) ?>&type=<?= $type ?>&page=<?= $page - 1 ?>" class="text-blue-600 font-bold">&larr; Back</a>
                <?php endif; ?>
                <span class="bg-slate-200 px-4 py-1 rounded-full text-sm font-bold">Page <?= $page ?></span>
                <?php if ($totalResults > ($page * $perPage)): ?>
                    <a href="?q=<?= urlencode($q) ?>&type=<?= $type ?>&page=<?= $page + 1 ?>" class="bg-blue-600 text-white px-6 py-2 rounded-full font-bold">Next &rarr;</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
