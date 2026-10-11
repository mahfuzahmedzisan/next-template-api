<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Keeps the drawing and drops anything that can run in the browser.
 */
final class SvgSanitizer
{
    /** @var list<string> */
    private const REMOVED_TAGS = [
        'script',
        'foreignobject',
        'iframe',
        'embed',
        'object',
        'link',
        'meta',
        'handler',
        'set',
    ];

    /** Animations that rewrite a link can turn a safe `<a>` into `javascript:`. */
    private const ANIMATION_TAGS = [
        'animate',
        'animatemotion',
        'animatetransform',
    ];

    /** Script schemes, CSS expressions, imports, external `url()`, and CSS escapes that can hide either. */
    private const UNSAFE_CSS = '/javascript:|vbscript:|expression\s*\(|@import|behavior\s*:|-moz-binding|\\\\|url\s*\(\s*[\'"]?\s*(?!#)/i';

    public function isSvg(UploadedFile|string $source): bool
    {
        $path = $source instanceof UploadedFile ? $source->getRealPath() : $source;

        if (! is_string($path) || ! is_file($path)) {
            return false;
        }

        $head = file_get_contents($path, false, null, 0, 512);

        if (! is_string($head)) {
            return false;
        }

        $sample = strtolower(ltrim((string) preg_replace('/^\xEF\xBB\xBF/', '', $head)));

        return str_contains(substr($sample, 0, 300), '<svg');
    }

    public function sanitize(string $sourcePath): string
    {
        $xml = file_get_contents($sourcePath);

        if (! is_string($xml) || trim($xml) === '') {
            throw new RuntimeException('This image could not be sanitized.');
        }

        $xml = (string) preg_replace('/^\xEF\xBB\xBF/', '', $xml);

        // DTDs carry entity expansion (billion laughs) and entity/markup collisions.
        if (preg_match('/<!(DOCTYPE|ENTITY|ATTLIST|ELEMENT)/i', $xml) === 1) {
            throw new RuntimeException('This image could not be sanitized.');
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->documentElement;

        if (! $loaded || $dom->doctype !== null || ! $root instanceof DOMElement || strtolower($root->localName) !== 'svg') {
            throw new RuntimeException('This image could not be sanitized.');
        }

        $this->strip($dom);

        $clean = $dom->saveXML($root);

        if (! is_string($clean) || $clean === '') {
            throw new RuntimeException('This image could not be sanitized.');
        }

        $target = tempnam(sys_get_temp_dir(), 'media-svg');

        if ($target === false) {
            throw new RuntimeException('Unable to prepare a sanitized image.');
        }

        $destination = $target.'.svg';
        @unlink($target);

        if (file_put_contents($destination, $clean) === false) {
            @unlink($destination);

            throw new RuntimeException('This image could not be sanitized.');
        }

        return $destination;
    }

    private function strip(DOMDocument $dom): void
    {
        /** @var list<DOMNode> $remove */
        $remove = [];

        foreach ((new DOMXPath($dom))->query('//processing-instruction() | //comment()') ?: [] as $node) {
            $remove[] = $node;
        }

        foreach ($dom->getElementsByTagName('*') as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($element->localName);

            if (in_array($tag, self::REMOVED_TAGS, true)
                || ($tag === 'style' && preg_match(self::UNSAFE_CSS, $element->textContent) === 1)
                || (in_array($tag, self::ANIMATION_TAGS, true) && str_ends_with(strtolower($element->getAttribute('attributeName')), 'href'))) {
                $remove[] = $element;

                continue;
            }

            $drop = [];

            foreach ($element->attributes as $attribute) {
                $name = strtolower($attribute->nodeName);
                $value = trim($attribute->nodeValue ?? '');

                if (str_starts_with($name, 'on')
                    || $this->hasScriptScheme($value)
                    || ($name === 'style' && preg_match(self::UNSAFE_CSS, $value) === 1)
                    || (str_ends_with($name, 'href') && $value !== '' && ! str_starts_with($value, '#'))) {
                    $drop[] = $attribute->nodeName;
                }
            }

            foreach ($drop as $name) {
                $element->removeAttribute($name);
            }
        }

        foreach ($remove as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    /** Browsers ignore whitespace and control characters inside a scheme, e.g. `java\tscript:`. */
    private function hasScriptScheme(string $value): bool
    {
        $compact = strtolower((string) preg_replace('/[\x00-\x20]+/', '', $value));

        return str_contains($compact, 'javascript:') || str_contains($compact, 'vbscript:');
    }
}
