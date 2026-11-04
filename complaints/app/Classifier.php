<?php
/*
 * The "AI" in the system: a Naive Bayes text classifier.
 *
 * How it works, in plain words:
 *  1. It learns from example complaints (the training_samples table). Each example already has the right category.
 *  2. For every category it counts how often each word appears in that category's examples.
 *  3. When a new complaint arrives, it asks: "Which category's examples use these words the most?"
 *     It multiplies the word chances together (we add logarithms instead, which is the same thing but safer for the
 *     computer) and picks the category with the highest score.
 *  4. The scores are turned into percentages, so the system also knows how SURE it is.
 *
 * Because the model is rebuilt from the table every time, it learns straight away when an officer corrects a category.
 */
class Classifier
{
    /** Words that carry no meaning for the decision. */
    private const STOP_WORDS = [
        'the', 'and', 'for', 'that', 'this', 'with', 'was', 'were', 'are', 'have', 'has', 'had', 'not', 'but', 'you',
        'your', 'from', 'they', 'them', 'their', 'there', 'then', 'than', 'been', 'being', 'will', 'would', 'could',
        'should', 'can', 'cannot', 'did', 'does', 'don', 'about', 'because', 'when', 'what', 'which', 'who', 'whom',
        'into', 'out', 'any', 'all', 'some', 'one', 'also', 'just', 'very', 'too', 'still', 'again', 'now', 'its',
        'our', 'his', 'her', 'she', 'him', 'myself', 'please', 'want', 'need', 'help', 'since', 'over', 'after',
        'before', 'while', 'where', 'how', 'why', 'much', 'many', 'more', 'most', 'other', 'such', 'own', 'same',
        'get', 'got', 'been', 'am', 'is', 'it', 'in', 'on', 'at', 'to', 'of', 'a', 'an', 'as', 'by', 'or', 'if', 'so',
        'my', 'me', 'we', 'us', 'he', 'be', 'do', 'no', 'up', 'i',
    ];

    /** If any of these pieces of text appear, the case is treated as urgent. */
    private const URGENT_WORDS = [
        'harass', 'assault', 'rape', 'sexual', 'threat', 'abus', 'violen', 'weapon', 'stolen', 'theft', 'robb',
        'unsafe', 'not safe', 'danger', 'emergency', 'accident', 'injur', 'bleeding', 'hospital', 'suicid',
        'self harm', 'self-harm', 'kidnap', 'blackmail', 'stalk', 'on fire', 'fight',
    ];

    /** Smoothing: how much credit a word gets in a category where it was never seen. Smaller = trust seen words more. */
    public static float $alpha = 0.1;

    private static ?array $stopSet = null;

    /** Turns text into a list of cleaned-up words. */
    public static function tokenize(string $text): array
    {
        if (self::$stopSet === null) {
            self::$stopSet = array_flip(self::STOP_WORDS);
        }
        $text = mb_strtolower($text, 'UTF-8');
        $text = str_replace(['wi-fi', 'wi fi'], 'wifi', $text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
        $words = preg_split('/\s+/u', trim((string)$text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($words as $w) {
            if (mb_strlen($w) < 3 || isset(self::$stopSet[$w])) {
                continue;
            }
            $out[] = self::stem($w);
        }
        return $out;
    }

    /** A very light stemmer so that "lecturers" and "lecturer", or "paid" and "paying", are close. */
    private static function stem(string $w): string
    {
        $len = mb_strlen($w);
        if ($len > 5 && mb_substr($w, -3) === 'ing') {
            return mb_substr($w, 0, -3);
        }
        if ($len > 4 && mb_substr($w, -2) === 'ed') {
            return mb_substr($w, 0, -2);
        }
        if ($len > 3 && mb_substr($w, -1) === 's' && mb_substr($w, -2) !== 'ss') {
            return mb_substr($w, 0, -1);
        }
        return $w;
    }

    /** A percentage for display. It never shows 100%, because a classifier is never completely certain. */
    public static function percent(float $probability): int
    {
        return (int)min(99, floor($probability * 100));
    }

    public static function loadSamples(PDO $pdo): array
    {
        return $pdo->query(
            'SELECT t.id, t.category_id, t.text
             FROM training_samples t JOIN categories c ON c.id = t.category_id AND c.is_active = 1'
        )->fetchAll();
    }

    /** Builds the model (the word counts) from a list of samples. $skipId leaves one sample out for testing. */
    public static function trainFromRows(array $rows, ?int $skipId = null): array
    {
        $m = ['docs' => [], 'tokens' => [], 'freq' => [], 'vocab' => [], 'total' => 0];
        foreach ($rows as $r) {
            if ($skipId !== null && (int)$r['id'] === $skipId) {
                continue;
            }
            $cat = (int)$r['category_id'];
            $m['docs'][$cat] = ($m['docs'][$cat] ?? 0) + 1;
            $m['total']++;
            foreach (self::tokenize($r['text']) as $t) {
                $m['freq'][$cat][$t] = ($m['freq'][$cat][$t] ?? 0) + 1;
                $m['tokens'][$cat] = ($m['tokens'][$cat] ?? 0) + 1;
                $m['vocab'][$t] = true;
            }
        }
        return $m;
    }

    public static function train(PDO $pdo): array
    {
        return self::trainFromRows(self::loadSamples($pdo));
    }

    /**
     * Predicts the category of a text.
     * Returns the category id, the confidence (0 to 1) and the chance of every category.
     */
    public static function predict(array $m, string $text): array
    {
        $empty = ['category_id' => null, 'confidence' => 0.0, 'probabilities' => [], 'known_words' => 0];
        if (empty($m['docs'])) {
            return $empty;
        }
        $tokens = self::tokenize($text);
        $known = 0;
        foreach ($tokens as $t) {
            if (isset($m['vocab'][$t])) {
                $known++;
            }
        }
        $vocabSize = max(1, count($m['vocab']));
        $scores = [];
        foreach ($m['docs'] as $cat => $n) {
            $score = log($n / $m['total']);                          // how common the category is
            $denominator = ($m['tokens'][$cat] ?? 0) + self::$alpha * $vocabSize;   // smoothing
            foreach ($tokens as $t) {
                if (!isset($m['vocab'][$t])) {
                    continue;                                          // a word we never saw tells us nothing
                }
                $score += log((($m['freq'][$cat][$t] ?? 0) + self::$alpha) / $denominator);
            }
            $scores[$cat] = $score;
        }
        $top = max($scores);
        $sum = 0.0;
        $prob = [];
        foreach ($scores as $cat => $s) {
            $prob[$cat] = exp($s - $top);
            $sum += $prob[$cat];
        }
        foreach ($prob as $cat => $v) {
            $prob[$cat] = $v / $sum;
        }
        arsort($prob);
        $best = (int)array_key_first($prob);
        return [
            'category_id'   => $best,
            'confidence'    => $known === 0 ? 0.0 : round($prob[$best], 4),
            'probabilities' => $prob,
            'known_words'   => $known,
        ];
    }

    /** Looks for danger words. Urgent cases get a shorter time limit. */
    public static function urgency(string $text): array
    {
        $t = mb_strtolower($text, 'UTF-8');
        $hits = [];
        foreach (self::URGENT_WORDS as $word) {
            // The danger word must START a word, so "abus" matches "abuse" but not "syllabus".
            if (preg_match('/\b' . preg_quote($word, '/') . '/u', $t)) {
                $hits[] = $word;
            }
        }
        return ['urgent' => !empty($hits), 'matched' => $hits];
    }

    /**
     * Tests the model on its own examples with "leave one out": take one example away, train on the rest,
     * and see if the model gets that example right. Repeat for every example.
     */
    public static function evaluate(PDO $pdo): array
    {
        $rows = self::loadSamples($pdo);
        $perCategory = [];
        $correct = 0;
        foreach ($rows as $r) {
            $model = self::trainFromRows($rows, (int)$r['id']);
            $p = self::predict($model, $r['text']);
            $cat = (int)$r['category_id'];
            $perCategory[$cat]['total'] = ($perCategory[$cat]['total'] ?? 0) + 1;
            if ($p['category_id'] === $cat) {
                $correct++;
                $perCategory[$cat]['correct'] = ($perCategory[$cat]['correct'] ?? 0) + 1;
            }
        }
        $total = count($rows);
        return [
            'total'        => $total,
            'correct'      => $correct,
            'accuracy'     => $total ? $correct / $total : 0,
            'per_category' => $perCategory,
        ];
    }
}
