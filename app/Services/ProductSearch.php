<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ProductSearch
{
    public const CACHE_KEY = 'storefront-search-documents-v1';

    public function normalize(string $text): string
    {
        return trim(preg_replace('/[^\pL\pN]+/u', ' ', Str::lower(Str::ascii($text))));
    }

    private function documents(): array
    {
        // Cache lightweight catalogue metadata, never descriptions or full models.
        return Cache::remember(self::CACHE_KEY, 60, function () {
            $documents = [];
            Product::where('status', true)->select(['id', 'name', 'sku', 'brand_id', 'category_id'])
                ->with(['brand:id,name,status', 'category:id,name,parent_id,status', 'category.parent:id,name,status'])
                ->chunkById(500, function ($products) use (&$documents) {
                    foreach ($products as $product) {
                        $fields = [
                            $this->normalize($product->name),
                            $this->normalize($product->sku ?? ''),
                            $this->normalize($product->brand?->status ? $product->brand->name : ''),
                            $this->normalize($product->category?->status ? $product->category->name : ''),
                            $this->normalize($product->category?->parent?->status ? $product->category->parent->name : ''),
                        ];
                        $words = array_map(fn ($field) => array_values(array_unique(explode(' ', $field))), $fields);
                        $words[1][] = str_replace(' ', '', $fields[1]);
                        $documents[] = ['id' => $product->id, 'fields' => $fields, 'words' => $words];
                    }
                });
            return $documents;
        });
    }

    private function distance(string $left, string $right): int
    {
        $distance = levenshtein($left, $right);
        // Treat a single adjacent transposition ("smapann") as one typo.
        if ($distance === 2 && strlen($left) === strlen($right)) {
            $different = [];
            for ($i = 0; $i < strlen($left); $i++) if ($left[$i] !== $right[$i]) $different[] = $i;
            if (count($different) === 2 && $different[1] === $different[0] + 1 && $left[$different[0]] === $right[$different[1]] && $left[$different[1]] === $right[$different[0]]) return 1;
        }
        return $distance;
    }

    private function wordScore(string $term, string $word): ?int
    {
        if ($word === '') return null;
        if ($term === $word) return 0;
        if (str_starts_with($word, $term)) return 2;
        if (mb_strlen($term) >= 3 && str_contains($word, $term)) return 5;
        // Do not guess near-miss numerical codes or tiny tokens.
        if (mb_strlen($term) < 3 || preg_match('/\d/u', $term)) return null;
        $allowance = mb_strlen($term) >= 8 ? 2 : 1;
        if (abs(strlen($term) - strlen($word)) <= $allowance) {
            $distance = $this->distance($term, $word);
            if ($distance <= $allowance) return 10 + $distance;
        }
        // Typo tolerance also applies to the unfinished last portion of a word.
        if (mb_strlen($term) >= 4 && strlen($word) > strlen($term)) {
            $distance = $this->distance($term, substr($word, 0, strlen($term)));
            if ($distance <= $allowance) return 14 + $distance;
        }
        return null;
    }

    public function matches(string $query): array
    {
        $normalized = $this->normalize($query);
        if (mb_strlen($normalized) < 3) return [];
        $terms = array_values(array_unique(explode(' ', $normalized)));
        if (count($terms) > 12) return [];
        $results = [];
        $weights = [0, 1, 4, 6, 6];
        // Memoize vocabulary comparisons for repeated brand/category words.
        $scores = [];
        foreach ($this->documents() as $document) {
            $score = 0;
            $typo = false;
            foreach ($terms as $term) {
                $best = null;
                $bestTypo = false;
                foreach ($document['words'] as $field => $words) foreach ($words as $word) {
                    $key = $term.'|'.$word;
                    if (!array_key_exists($key, $scores)) $scores[$key] = $this->wordScore($term, $word);
                    $cost = $scores[$key];
                    if ($cost !== null && ($best === null || $cost + $weights[$field] < $best)) {
                        $best = $cost + $weights[$field];
                        $bestTypo = $cost >= 10;
                    }
                }
                if ($best === null) continue 2;
                $score += $best;
                $typo = $typo || $bestTypo;
            }
            if ($document['fields'][0] === $normalized) $score -= 100;
            elseif (str_starts_with($document['fields'][0], $normalized)) $score -= 15;
            if ($document['fields'][1] === $normalized) $score -= 120;
            $results[] = ['id' => $document['id'], 'score' => $score, 'typo' => $typo, 'name' => $document['fields'][0]];
        }
        usort($results, fn ($a, $b) => ($a['score'] <=> $b['score']) ?: strcmp($a['name'], $b['name']) ?: ($a['id'] <=> $b['id']));
        return $results;
    }

    public function apply(Builder $query, array $matches, bool $rank = true): void
    {
        $ids = array_column($matches, 'id');
        $query->whereIn('products.id', $ids);
        if ($rank && $ids) {
            $cases = implode(' ', array_map(fn ($id, $position) => 'WHEN '.(int) $id.' THEN '.$position, $ids, array_keys($ids)));
            $query->reorder()->orderByRaw('CASE products.id '.$cases.' END');
        }
    }
}
