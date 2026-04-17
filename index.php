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
<body class="p-8 bg-slate-950 text-slate-100">
    <div class="max-w-6xl mx-auto">
        <header class="text-center mb-10">
            <h1 class="text-4xl font-black tracking-tight">sparkSammy <span class="text-blue-500">Search</span></h1>
        </header>

        <form method="GET" class="flex gap-4 mb-12 bg-slate-900 p-3 rounded-2xl border border-slate-800 focus-within:border-blue-500/50 transition-colors shadow-2xl">
            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" 
                   class="flex-grow bg-transparent outline-none px-4 text-slate-100 placeholder-slate-500" 
                   placeholder="Search the relay...">
            <select name="type" class="bg-slate-800 text-slate-200 rounded-xl px-3 text-sm font-medium outline-none cursor-pointer hover:bg-slate-700 transition">
                <option value="web" <?= $type=='web'?'selected':'' ?>>Web</option>
                <option value="images" <?= $type=='images'?'selected':'' ?>>Images</option>
            </select>
            <button class="bg-blue-600 hover:bg-blue-500 text-white px-8 py-2 rounded-xl font-bold transition-all active:scale-95 shadow-lg shadow-blue-900/20">
                Search
            </button>
        </form>

        <?php if ($pagedResults): ?>
            <?php if ($type === 'web'): ?>
                <div class="space-y-4">
                    <?php foreach ($pagedResults as $url => $data): ?>
                        <div class="bg-slate-900/50 p-6 rounded-2xl border border-slate-800 hover:border-slate-700 transition-colors group">
                            <a href="<?= $url ?>" target="_blank" class="text-blue-400 text-xl font-bold group-hover:text-blue-300 transition-colors">
                                <?= htmlspecialchars($data['title']) ?>
                            </a>
                            <p class="text-slate-500 text-sm truncate mt-1 font-mono"><?= $url ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="columns-2 md:columns-4 lg:columns-5 gap-4">
                    <?php foreach ($pagedResults as $url => $data): ?>
                        <?php foreach ($data['images'] as $img): ?>
                            <div class="mb-4 break-inside-avoid relative group">
                                <a href="<?= htmlspecialchars($img['source']) ?>" target="_blank" 
                                   class="block rounded-2xl overflow-hidden border border-slate-800 bg-slate-900 shadow-xl transition-transform hover:border-slate-600">
                                    <img src="<?= htmlspecialchars($img['src']) ?>" 
                                         alt="<?= htmlspecialchars($img['caption']) ?>"
                                         class="w-full h-auto group-hover:scale-110 transition duration-700" 
                                         onerror="this.parentElement.remove()">
                                    
                                    <div class="absolute inset-0 bg-gradient-to-t from-slate-950/80 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none flex items-end p-3">
                                        <span class="text-[10px] font-black tracking-widest text-blue-400 uppercase">View Source</span>
                                    </div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="text-center py-20 bg-slate-900/20 rounded-3xl border-2 border-dashed border-slate-800">
                <p class="text-slate-500 font-medium">No results found in the relay.</p>
                <p class="text-slate-600 text-sm">Try a broader query.</p>
            </div>
        <?php endif; ?>
    </div>
</body>
