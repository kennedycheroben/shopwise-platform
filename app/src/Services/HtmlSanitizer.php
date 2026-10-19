<?php

namespace App\Services;

use DOMDocument;
use DOMElement;

/**
 * Strict allowlist HTML sanitizer for rich text product descriptions
 */
class HtmlSanitizer
{
    private static array $allowedTags = [
        'p', 'br', 'b', 'strong', 'i', 'em', 'u', 's', 'strike',
        'ul', 'ol', 'li', 'h3', 'h4', 'h5', 'h6', 'blockquote',
        'span', 'a', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td'
    ];

    private static array $allowedAttributes = [
        'a'    => ['href', 'title', 'target', 'rel'],
        'span' => ['class'],
        'td'   => ['colspan', 'rowspan'],
        'th'   => ['colspan', 'rowspan'],
    ];

    /**
     * Clean untrusted HTML string using strict tag and attribute allowlists
     */
    public static function clean(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        // Fast preliminary strip of obviously dangerous blocks
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);

        $dom = new DOMDocument();
        // Suppress warnings on malformed fragments, load with UTF-8
        libxml_use_internal_errors(true);
        $wrapped = '<?xml encoding="utf-8" ?><div>' . $html . '</div>';
        $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $root = $dom->getElementsByTagName('div')->item(0);
        if (!$root) {
            return '';
        }

        self::sanitizeNode($root);

        // Extract inner HTML of the wrapper div
        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }

        return trim($output);
    }

    private static function sanitizeNode(\DOMNode $node): void
    {
        $nodesToRemove = [];

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $tagName = strtolower($child->tagName);

                if (!in_array($tagName, self::$allowedTags, true)) {
                    $nodesToRemove[] = $child;
                    continue;
                }

                // Sanitize attributes
                $allowedForTag = self::$allowedAttributes[$tagName] ?? [];
                $attributesToRemove = [];

                if ($child->hasAttributes()) {
                    foreach ($child->attributes as $attr) {
                        $attrName = strtolower($attr->name);
                        $attrVal = trim($attr->value);

                        // If attribute not in allowlist or starts with "on" (e.g. onclick, onerror)
                        if (!in_array($attrName, $allowedForTag, true) || str_starts_with($attrName, 'on')) {
                            $attributesToRemove[] = $attr->name;
                            continue;
                        }

                        // Validate href URLs to prevent javascript: or data: schemes
                        if ($tagName === 'a' && $attrName === 'href') {
                            $parsedScheme = parse_url($attrVal, PHP_URL_SCHEME);
                            if ($parsedScheme !== null && !in_array(strtolower($parsedScheme), ['http', 'https', 'mailto', 'tel'], true)) {
                                $attributesToRemove[] = $attr->name;
                            }
                        }
                    }

                    foreach ($attributesToRemove as $name) {
                        $child->removeAttribute($name);
                    }
                }

                // If anchor tag, enforce rel="noopener noreferrer"
                if ($tagName === 'a') {
                    $child->setAttribute('rel', 'noopener noreferrer');
                }

                // Recurse into children
                self::sanitizeNode($child);
            }
        }

        foreach ($nodesToRemove as $remove) {
            $node->removeChild($remove);
        }
    }
}
