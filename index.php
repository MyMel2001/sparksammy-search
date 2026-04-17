<?php
session_start();
set_time_limit(120);

class RelaySearch {
    public $results = [];
    public $queue = [];
    public $visited = [];

    private function fetch($url) {
        $ctx = stream_context_create([
            'http' => [
                'header' => "User-Agent: sparkSammyBot/4.0\r\n",
                'timeout' => 3
            ]
        ]);
        return @file_get_contents($url, false, $ctx);
    }

    private function getNearbyText($node) {
        // Try parent text (best signal)
        if ($node->parentNode) {
            $text = trim($node->parentNode->textContent);
            if (strlen($text) > 30) return substr($text, 0, 200);
        }

        return '';
    }

    public function processBatch($urls, $keyword = '', $isSeed = false, $type = 'web') {

        foreach ($urls as $url) {

            if (isset($this->visited[$url])) continue;
            $this->visited[$url] = true;

            $html = $this->fetch($url);
            if (!$html) continue;

            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
            libxml_clear_errors();

            $title = $dom->getElementsByTagName('title')->item(0)?->nodeValue ?? $url;

            // 🔥 keyword scoring
            $score = 0;
            $k = strtolower($keyword);

            if (!empty($k)) {
                $score += substr_count(strtolower($title), $k) * 3;
                $score += substr_count(strtolower($html), $k);
            }

            // 🔵 extract links
            $links = [];
            $count = 0;
            foreach ($dom->getElementsByTagName('a') as $a) {
                if ($count >= 25) break;

                $href = $a->getAttribute('href');

                if (
                    str_starts_with($href, 'http') &&
                    !str_contains($href, '#') &&
                    !str_contains($href, 'javascript:')
                ) {
                    $links[] = $href;
                    $count++;
                }
            }

            // 🟣 IMAGE EXTRACTION WITH CAPTIONS
            $imgs = [];

            // ✅ Open Graph image
            foreach ($dom->getElementsByTagName('meta') as $meta) {
                if ($meta->getAttribute('property') === 'og:image') {
                    $imgs[] = [
                        'src' => $meta->getAttribute('content'),
                        'score' => 100,
                        'caption' => $title
                    ];
                }
            }

            foreach ($dom->getElementsByTagName('img') as $img) {

                $src = $img->getAttribute('src');
                $alt = strtolower($img->getAttribute('alt') ?? '');
                $width = (int)$img->getAttribute('width');
                $height = (int)$img->getAttribute('height');

                if (
                    !$src ||
                    !str_starts_with($src, 'http') ||
                    str_contains($src, 'icon') ||
                    str_contains($src, 'logo') ||
                    str_contains($src, 'sprite') ||
                    str_contains($src, 'ads') ||
                    str_contains($src, 'doubleclick') ||
                    ($width && $width < 120) ||
                    ($height && $height < 120)
                ) continue;

                $filename = strtolower(basename(parse_url($src, PHP_URL_PATH)));

                // 🧠 caption sources
                $caption = $img->getAttribute('alt');

                // try <figcaption>
                if ($img->parentNode && $img->parentNode->nodeName === 'figure') {
                    foreach ($img->parentNode->childNodes as $child) {
                        if ($child->nodeName === 'figcaption') {
                            $caption = trim($child->textContent);
                        }
                    }
                }

                // fallback: nearby paragraph text
                if (empty($caption)) {
                    $caption = $this->getNearbyText($img);
                }

                $captionLower = strtolower($caption);

                // 🔥 scoring
                $imgScore = 0;

                if (!empty($k)) {
                    $imgScore += substr_count($alt, $k) * 5;
                    $imgScore += substr_count($filename, $k) * 4;
                    $imgScore += substr_count(strtolower($title), $k) * 2;
                    $imgScore += substr_count($captionLower, $k) * 3;
                }

                $imgScore += ($width * $height) / 20000;

                if ($imgScore <= 1) continue;

                $imgs[] = [
                    'src' => $src,
                    'score' => $imgScore,
                    'caption' => $caption
                ];
            }

            usort($imgs, fn($a, $b) => $b['score'] <=> $a['score']);
            $imgs = array_slice($imgs, 0, 5);

            // ✅ require relevant page
            $shouldShow = $type === 'images'
                ? ($score > 0 && !empty($imgs))
                : ($score > 0 || empty($keyword));

            if ($shouldShow && !$isSeed) {
                $this->results[$url] = [
                    'title' => $title,
                    'links' => $links,
                    'images' => $imgs,
                    'rank' => max(1, $score)
                ];
            }

            $this->queue = array_merge($this->queue, $links);
        }

        $this->queue = array_values(array_unique($this->queue));
    }

    public function rank() {
        uasort($this->results, fn($a, $b) => $b['rank'] <=> $a['rank']);
    }
}

$engine = new RelaySearch();
$q = $_GET['q'] ?? '';
$type = $_GET['type'] ?? 'web';

if (isset($_GET['new_search']) && !empty($q)) {

    unset($_SESSION['queue']);

    $seeds = [
        "https://en.wikipedia.org/w/index.php?search=" . urlencode($q),
        "https://lite.duckduckgo.com/lite?q=" . urlencode($q),
        "https://www.bing.com/images/search?q=" . urlencode($q)
    ];

    $engine->processBatch($seeds, $q, true, $type);

    $depth = 0;
    $maxDepth = ($type === 'images') ? 3 : 2;

    while ($depth < $maxDepth && count($engine->results) < 40) {

        $batch = array_slice($engine->queue, 0, 30);
        $engine->queue = array_slice($engine->queue, 30);

        if (empty($batch)) break;

        $engine->processBatch($batch, $q, false, $type);

        $depth++;
    }

    $engine->rank();
    $_SESSION['queue'] = $engine->queue;

} elseif (isset($_GET['next'])) {

    $queue = $_SESSION['queue'] ?? [];
    $depth = 0;

    while ($depth < 2 && count($engine->results) < 40) {

        $batch = array_slice($queue, 0, 30);
        $queue = array_slice($queue, 30);

        if (empty($batch)) break;

        $engine->processBatch($batch, $q, false, $type);
        $depth++;
    }

    $_SESSION['queue'] = $queue;
    $engine->rank();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>sparkSammy Search</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 p-6 md:p-12">

<div class="max-w-5xl mx-auto">

<header class="text-center mb-8">
<h1 class="text-5xl font-black text-slate-800">
sparkSammy<span class="text-blue-600"> Search.</span>
</h1>
</header>

<form method="GET" class="bg-white p-4 rounded-3xl shadow-xl flex gap-4 mb-10">
<input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search..." class="flex-grow p-3">

<select name="type" class="bg-slate-100 px-4 rounded-2xl">
<option value="web" <?= $type=='web'?'selected':'' ?>>Web</option>
<option value="images" <?= $type=='images'?'selected':'' ?>>Images</option>
</select>

<button name="new_search" class="bg-blue-600 text-white px-6 rounded-2xl">
FIND
</button>
</form>

<?php if (!empty($engine->results)): ?>

<div class="<?= $type=='web' ? 'grid gap-4' : 'columns-2 md:columns-4 gap-4' ?>">

<?php foreach ($engine->results as $url => $data): ?>

<?php if ($type === 'web'): ?>

<div class="bg-white p-5 rounded-xl shadow">
<div class="text-xs text-green-600">PR: <?= number_format($data['rank'],2) ?></div>

<a href="<?= $url ?>" class="text-blue-700 font-bold" target="_blank">
<?= htmlspecialchars($data['title']) ?>
</a>

<div class="text-xs text-gray-400"><?= $url ?></div>
</div>

<?php else: ?>

<?php foreach ($data['images'] as $img): ?>
<div class="mb-4">
<img src="<?= $img['src'] ?>" class="rounded-xl shadow" onerror="this.remove()">
<?php if (!empty($img['caption'])): ?>
<p class="text-xs text-gray-600 mt-1">
<?= htmlspecialchars($img['caption']) ?>
</p>
<?php endif; ?>
</div>
<?php endforeach; ?>

<?php endif; ?>

<?php endforeach; ?>

</div>

<form method="GET" class="mt-10 text-center">
<input type="hidden" name="q" value="<?= htmlspecialchars($q) ?>">
<input type="hidden" name="type" value="<?= $type ?>">

<button name="next" class="bg-blue-600 text-white px-8 py-3 rounded-full">
NEXT
</button>
</form>

<?php elseif(isset($_GET['new_search'])): ?>

<div class="text-center py-20 bg-white rounded-3xl shadow-inner">
<p class="text-gray-400 font-bold">
No results found for "<?= htmlspecialchars($q) ?>"
</p>
</div>

<?php endif; ?>

</div>
</body>
</html>
