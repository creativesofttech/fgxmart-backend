<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

class LegalPageStore
{
    public const PAGES = ['terms-and-conditions', 'privacy-policy'];

    public function read(string $slug): array
    {
        abort_unless(in_array($slug, self::PAGES, true), 404);
        $path = config('legal-pages.path').'/'.$slug.'.json';
        if (!File::exists($path)) $path = config('legal-pages.defaults_path').'/'.$slug.'.json';
        try {
            $data = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data) || !is_string($data['title'] ?? null) || !is_string($data['content'] ?? null)) throw new \RuntimeException('Invalid content.');
        } catch (\Throwable $error) {
            report($error);
            abort(503, 'This page is temporarily unavailable.');
        }
        return ['slug' => $slug, 'title' => $data['title'], 'content' => $this->sanitize($data['content']), 'updated_at' => $data['updated_at'] ?? null];
    }

    public function save(string $slug, array $data): array
    {
        abort_unless(in_array($slug, self::PAGES, true), 404);
        $content = $this->sanitize($data['content']);
        $text = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_replace('/[\s\x{00a0}]+/u', '', $text) === '') {
            throw ValidationException::withMessages(['content' => 'Please enter the page content.']);
        }
        $page = ['title' => trim($data['title']), 'content' => $content, 'updated_at' => now()->toIso8601String()];
        File::ensureDirectoryExists(config('legal-pages.path'));
        // Atomic replacement prevents readers from receiving partially written JSON.
        File::replace(config('legal-pages.path').'/'.$slug.'.json', json_encode($page, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
        return ['slug' => $slug, ...$page];
    }

    private function sanitize(string $html): string
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>', LIBXML_NONET);
            $body = $document->getElementsByTagName('body')->item(0);
            if (!$body) return '';
            $clean = function (\DOMNode $parent) use (&$clean) {
                foreach (iterator_to_array($parent->childNodes) as $node) {
                    if ($node instanceof \DOMComment) { $parent->removeChild($node); continue; }
                    if (!$node instanceof \DOMElement) continue;
                    $tag = strtolower($node->tagName);
                    if (in_array($tag, ['script','style','iframe','object','embed','svg','math','template','form','input','button','textarea','select','link','meta','base'], true)) {
                        $parent->removeChild($node); continue;
                    }
                    $clean($node);
                    if (!in_array($tag, ['p','h2','h3','strong','b','em','i','u','s','ul','ol','li','br','span'], true)) {
                        while ($node->firstChild) $parent->insertBefore($node->firstChild, $node);
                        $parent->removeChild($node); continue;
                    }
                    foreach (iterator_to_array($node->attributes) as $attribute) {
                        $keep = $attribute->name === 'start' && $tag === 'ol' && preg_match('/^-?\d{1,6}$/', $attribute->value);
                        if ($attribute->name === 'style' && in_array($tag, ['p','h2','h3'], true) && preg_match('/^\s*font-size\s*:\s*(14|1[6-9]|2[0-2])px\s*;?\s*$/i', $attribute->value, $match)) {
                            $node->setAttribute('style', 'font-size: '.$match[1].'px');
                            $keep = true;
                        }
                        if (!$keep) $node->removeAttribute($attribute->name);
                    }
                }
            };
            $clean($body);
            $result = '';
            foreach ($body->childNodes as $node) $result .= $document->saveHTML($node);
            return $result;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
