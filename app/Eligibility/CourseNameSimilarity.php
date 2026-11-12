<?php

namespace App\Eligibility;

/**
 * Tells how alike two course names are, using TF-IDF vectors and cosine similarity.
 *
 * A name is cleaned first: it's lowercased, words every name has (like "Sarjana Muda" or "Dengan Kepujian") are taken
 * out, and English words are turned into their Malay ones, so "Computer Science" reads as "komputer sains". Each name
 * then becomes two vectors: one of its words and word pairs, and one of its three-letter pieces, which matches words
 * that share a root, like "kimia" and "biokimia". A word or piece found in few names, like "komputer", weighs more than
 * one found in many, like "sains". The similarity is the average cosine of the two vectors, from 0 (nothing alike) to
 * 1 (the same name).
 */
final class CourseNameSimilarity
{
    /**
     * Words that say what kind of qualification a course leads to, or join words, rather than what it's about.
     */
    private const GENERIC_WORDS = [
        'sarjana', 'muda', 'ijazah', 'bacelor', 'kepujian', 'dengan', 'diploma', 'asasi', 'sijil', 'program', 'dan',
        'dalam', 'bagi', 'untuk', 'serta', 'bachelor', 'degree', 'hons', 'honours', 'foundation', 'certificate',
        'certification', 'certified', 'of', 'in', 'and', 'the', 'with', 'for',
    ];

    /**
     * English words in course names and the Malay words other course names use for them.
     */
    private const MALAY_WORDS = [
        'accounting' => 'perakaunan', 'accountancy' => 'perakaunan', 'agriculture' => 'pertanian',
        'analytics' => 'analitik', 'animation' => 'animasi', 'architectural' => 'senibina', 'architecture' => 'senibina',
        'art' => 'seni', 'arts' => 'seni', 'beverage' => 'minuman', 'biology' => 'biologi', 'business' => 'perniagaan',
        'chemical' => 'kimia', 'chemistry' => 'kimia', 'civil' => 'awam', 'communication' => 'komunikasi',
        'computer' => 'komputer', 'computing' => 'pengkomputeran', 'creative' => 'kreatif', 'culinary' => 'kulinari',
        'design' => 'rekabentuk', 'economics' => 'ekonomi', 'education' => 'pendidikan', 'electrical' => 'elektrik',
        'electronic' => 'elektronik', 'electronics' => 'elektronik', 'engineering' => 'kejuruteraan',
        'english' => 'inggeris', 'finance' => 'kewangan', 'financial' => 'kewangan', 'food' => 'makanan',
        'games' => 'permainan', 'hotel' => 'hotel', 'industry' => 'industri', 'information' => 'maklumat',
        'instrumentation' => 'instrumentasi', 'international' => 'antarabangsa', 'islamic' => 'islam',
        'law' => 'undang', 'lighting' => 'pencahayaan', 'logistics' => 'logistik', 'management' => 'pengurusan',
        'marketing' => 'pemasaran', 'mathematics' => 'matematik', 'mechanical' => 'mekanikal', 'medicine' => 'perubatan',
        'network' => 'rangkaian', 'nursing' => 'kejururawatan', 'operational' => 'operasi', 'operations' => 'operasi',
        'pharmacy' => 'farmasi', 'physics' => 'fizik', 'production' => 'penerbitan', 'psychology' => 'psikologi',
        'rail' => 'rel', 'recreational' => 'rekreasi', 'research' => 'penyelidikan', 'retail' => 'runcit',
        'science' => 'sains', 'sciences' => 'sains', 'service' => 'perkhidmatan', 'signalling' => 'isyarat',
        'software' => 'perisian', 'sound' => 'bunyi', 'studies' => 'pengajian', 'technology' => 'teknologi',
        'tourism' => 'pelancongan',
    ];

    /**
     * How much of the similarity comes from words, and how much from three-letter pieces.
     */
    private const WORD_WEIGHT = 0.5;

    /**
     * @var array<string, float> How rare each word or piece is among the course names.
     */
    private array $inverseFrequencies = [];

    /**
     * @var array<string, array{words: array<string, float>, pieces: array<string, float>}> Each name's vectors, once worked out.
     */
    private array $vectors = [];

    /**
     * @param  list<string>  $names  The course names to compare, which decide how rare each word is.
     */
    public function __construct(array $names)
    {
        $names = array_values(array_unique($names));
        $documentFrequencies = [];

        foreach ($names as $name) {
            foreach (array_keys(self::terms($name)) as $term) {
                $documentFrequencies[$term] = ($documentFrequencies[$term] ?? 0) + 1;
            }
        }

        foreach ($documentFrequencies as $term => $frequency) {
            $this->inverseFrequencies[$term] = log((1 + count($names)) / (1 + $frequency)) + 1;
        }
    }

    /**
     * How alike two course names are, from 0 to 1.
     */
    public function between(string $firstName, string $secondName): float
    {
        $first = $this->vectorsFor($firstName);
        $second = $this->vectorsFor($secondName);

        return self::WORD_WEIGHT * self::cosine($first['words'], $second['words'])
            + (1 - self::WORD_WEIGHT) * self::cosine($first['pieces'], $second['pieces']);
    }

    /**
     * The words of a name that say what the course is about, in Malay.
     *
     * @return list<string>
     */
    public static function words(string $name): array
    {
        // A campus or university named after the course says where it's taught, not what it's about
        $name = preg_replace(['/\s+-\s+.*$/u', '/\(\s*kampus[^)]*\)/iu'], '', $name);

        // Hyphens join repeated words in Malay, as in "undang-undang"
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY);
        $words = array_map(fn (string $word): string => self::MALAY_WORDS[$word] ?? $word, $words);

        return array_values(array_unique(array_filter(
            $words,
            fn (string $word): bool => mb_strlen($word) > 1 && ! in_array($word, self::GENERIC_WORDS, true),
        )));
    }

    /**
     * @return array{words: array<string, float>, pieces: array<string, float>}
     */
    private function vectorsFor(string $name): array
    {
        if (! isset($this->vectors[$name])) {
            $words = [];
            $pieces = [];

            foreach (self::terms($name) as $term => $count) {
                $weight = $count * ($this->inverseFrequencies[$term] ?? log(1 + count($this->inverseFrequencies)) + 1);

                if (str_starts_with($term, '#')) {
                    $pieces[$term] = $weight;
                } else {
                    $words[$term] = $weight;
                }
            }

            $this->vectors[$name] = ['words' => self::normalise($words), 'pieces' => self::normalise($pieces)];
        }

        return $this->vectors[$name];
    }

    /**
     * Counts a name's words, word pairs and three-letter pieces. Pieces start with "#" to keep them apart from words.
     *
     * @return array<string, int>
     */
    private static function terms(string $name): array
    {
        $words = self::words($name);
        $terms = [];

        foreach ($words as $index => $word) {
            $terms[] = $word;

            if (isset($words[$index + 1])) {
                $terms[] = $word.' '.$words[$index + 1];
            }

            $padded = ' '.$word.' ';

            for ($start = 0; $start <= mb_strlen($padded) - 3; $start++) {
                $terms[] = '#'.mb_substr($padded, $start, 3);
            }
        }

        return array_count_values($terms);
    }

    /**
     * @param  array<string, float>  $vector
     * @return array<string, float>
     */
    private static function normalise(array $vector): array
    {
        $length = sqrt(array_sum(array_map(fn (float $weight): float => $weight ** 2, $vector)));

        return $length > 0 ? array_map(fn (float $weight): float => $weight / $length, $vector) : $vector;
    }

    /**
     * @param  array<string, float>  $first
     * @param  array<string, float>  $second
     */
    private static function cosine(array $first, array $second): float
    {
        if (count($first) > count($second)) {
            [$first, $second] = [$second, $first];
        }

        $sum = 0.0;

        foreach ($first as $term => $weight) {
            $sum += $weight * ($second[$term] ?? 0.0);
        }

        return $sum;
    }
}
