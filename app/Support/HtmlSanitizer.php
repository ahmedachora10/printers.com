<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Allowlist sanitiser for the small rich-text fields the app stores as HTML
 * (currently the user notes editor). Anything outside the allowlist is either
 * unwrapped (the tag goes, its text stays) or dropped entirely for tags that
 * carry no readable content — so the stored markup is always safe to render
 * with dangerouslySetInnerHTML.
 */
class HtmlSanitizer
{
    /**
     * Tags kept, mapped to the attributes each one may keep.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        'p' => [],
        'br' => [],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'u' => [],
        's' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'h1' => [],
        'h2' => [],
        'h3' => [],
        'h4' => [],
        'blockquote' => [],
        'code' => [],
        'pre' => [],
        'hr' => [],
        'a' => ['href'],
    ];

    /** Tags dropped together with everything inside them. */
    private const STRIPPED = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'template'];

    /**
     * Return the safe version of $html, or null when it carries no content.
     */
    public static function clean(?string $html): ?string
    {
        $html = trim((string) $html);

        if ($html === '') {
            return null;
        }

        $doc = new DOMDocument;

        $loaded = $doc->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'.$html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        if (! $loaded) {
            return null;
        }

        self::cleanChildren($doc);

        $clean = '';

        foreach (iterator_to_array($doc->childNodes) as $child) {
            // The charset hint we prepended is not part of the content.
            if ($child instanceof DOMElement && $child->tagName === 'meta') {
                continue;
            }

            $clean .= $doc->saveHTML($child);
        }

        $clean = trim($clean);

        return self::isBlank($clean) ? null : $clean;
    }

    /**
     * The readable text of $html, with all markup removed. Used for list
     * previews where rendered HTML would not fit.
     */
    public static function toPlainText(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        // Turn block boundaries into spaces so words do not run together.
        $text = preg_replace('/<(br|\/p|\/li|\/h[1-6]|\/blockquote)[^>]*>/i', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $text === '' ? null : $text;
    }

    /**
     * Recursively sanitise every child of $node. Iterating over a snapshot of
     * childNodes keeps the walk stable while nodes are removed or unwrapped.
     */
    private static function cleanChildren(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                // Text and CDATA stay; comments and processing instructions go.
                if ($child->nodeType !== XML_TEXT_NODE && $child->nodeType !== XML_CDATA_SECTION_NODE) {
                    $node->removeChild($child);
                }

                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::STRIPPED, true)) {
                $node->removeChild($child);

                continue;
            }

            self::cleanChildren($child);

            if (! array_key_exists($tag, self::ALLOWED)) {
                self::unwrap($child);

                continue;
            }

            self::cleanAttributes($child, $tag);
        }
    }

    /** Drop every attribute the tag is not allowed to keep. */
    private static function cleanAttributes(DOMElement $element, string $tag): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            if (! in_array(strtolower($attribute->name), self::ALLOWED[$tag], true)) {
                $element->removeAttribute($attribute->name);
            }
        }

        if ($tag !== 'a') {
            return;
        }

        $href = trim($element->getAttribute('href'));

        // Only plain navigable links survive — no javascript:/data: payloads.
        if (! preg_match('#^(https?://|mailto:|tel:|/)#i', $href)) {
            $element->removeAttribute('href');

            return;
        }

        $element->setAttribute('target', '_blank');
        $element->setAttribute('rel', 'noopener noreferrer');
    }

    /** Replace an element with its children, keeping the text it wrapped. */
    private static function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        foreach (iterator_to_array($element->childNodes) as $child) {
            $parent->insertBefore($child, $element);
        }

        $parent->removeChild($element);
    }

    /** Empty paragraphs and whitespace count as no content at all. */
    private static function isBlank(string $html): bool
    {
        if (preg_match('/<(hr|img)\b/i', $html)) {
            return false;
        }

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(str_replace("\xC2\xA0", ' ', $text)) === '';
    }
}
