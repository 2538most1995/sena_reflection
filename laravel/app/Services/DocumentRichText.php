<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use InvalidArgumentException;
use JsonException;

class DocumentRichText
{
    public const MAX_JSON_BYTES = 120000;

    private const ALLOWED_TAGS = ['b', 'strong', 'i', 'em', 'u', 's', 'strike', 'span', 'font', 'p', 'div', 'br', 'ul', 'ol', 'li'];

    private const DROP_TAGS = ['script', 'style', 'iframe', 'svg', 'math', 'object', 'embed', 'template', 'noscript', 'head', 'link', 'meta', 'audio', 'video', 'canvas'];

    public static function normalize(string $text): string
    {
        return str_replace(["\r\n", "\r", "\u{2028}", "\u{2029}", "\u{00a0}"], ["\n", "\n", "\n", "\n", ' '], $text);
    }

    /** @param array<int, string> $allowedKeys */
    public static function normalizeMap(string $json, array $allowedKeys): string
    {
        if (strlen($json) > self::MAX_JSON_BYTES) {
            throw new InvalidArgumentException('ข้อมูลรูปแบบข้อความมีขนาดใหญ่เกินไป');
        }

        try {
            $map = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('ข้อมูลรูปแบบข้อความไม่ถูกต้อง');
        }

        if (! is_object($map)) {
            throw new InvalidArgumentException('ข้อมูลรูปแบบข้อความต้องเป็นรายการตามช่องเอกสาร');
        }

        $normalized = [];
        foreach (get_object_vars($map) as $key => $entry) {
            if (! in_array($key, $allowedKeys, true)) {
                continue;
            }
            if (! is_object($entry) || ! is_string($entry->text ?? null) || ! is_string($entry->html ?? null)) {
                throw new InvalidArgumentException('ข้อมูลรูปแบบข้อความแต่ละช่องไม่ครบถ้วน');
            }

            $text = self::normalize($entry->text);
            $html = self::sanitize($entry->html);
            if (! self::hasMatchingText($text, $html)) {
                continue;
            }
            $normalized[$key] = ['text' => $text, 'html' => $html];
        }

        $encoded = json_encode((object) $normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > self::MAX_JSON_BYTES) {
            throw new InvalidArgumentException('ข้อมูลรูปแบบข้อความมีขนาดใหญ่เกินไป');
        }

        return $encoded;
    }

    public static function render(string $plain, string $key, string $json): string
    {
        $fallback = nl2br(self::escape(self::normalize($plain)), false);
        if (strlen($json) > self::MAX_JSON_BYTES) {
            return $fallback;
        }

        try {
            $map = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $fallback;
        }
        $entry = is_array($map) ? ($map[$key] ?? null) : null;
        if (! is_array($entry) || ! is_string($entry['text'] ?? null) || ! is_string($entry['html'] ?? null)
            || self::normalize($entry['text']) !== self::normalize($plain)) {
            return $fallback;
        }

        $html = self::sanitize($entry['html']);

        return self::hasMatchingText($plain, $html) ? $html : $fallback;
    }

    public static function sanitize(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML('<?xml encoding="UTF-8"><!doctype html><html><body>'.$html.'</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded) {
            return '';
        }

        $body = $document->getElementsByTagName('body')->item(0);

        return $body ? self::sanitizeChildren($body) : '';
    }

    private static function sanitizeChildren(DOMNode $parent): string
    {
        $html = '';
        foreach ($parent->childNodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                $html .= self::escape($node->nodeValue ?? '');

                continue;
            }
            if (! $node instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($node->tagName);
            if (in_array($tag, self::DROP_TAGS, true)) {
                continue;
            }
            $children = self::sanitizeChildren($node);
            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                $html .= $children;

                continue;
            }
            if ($tag === 'br') {
                $html .= '<br>';

                continue;
            }

            $styles = self::styles($node->getAttribute('style'));
            if ($tag === 'font') {
                $styles = array_merge(self::legacyFontStyles($node), $styles);
                $tag = 'span';
            } elseif ($tag === 'strike') {
                $tag = 's';
            }
            $style = $styles === [] ? '' : ' style="'.self::escape(implode(';', array_map(static fn (string $property, string $value): string => $property.':'.$value, array_keys($styles), $styles))).'"';
            $html .= '<'.$tag.$style.'>'.$children.'</'.$tag.'>';
        }

        return $html;
    }

    /** @return array<string, string> */
    private static function styles(string $source): array
    {
        $styles = [];
        foreach (explode(';', $source) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $property = strtolower(trim($parts[0]));
            $value = trim($parts[1]);
            $normalized = match ($property) {
                'font-size' => self::fontSize($value),
                'font-family' => self::fontFamily($value),
                'color', 'background-color' => self::color($value),
                'text-align' => in_array(strtolower($value), ['left', 'right', 'center', 'justify'], true) ? strtolower($value) : null,
                'text-align-last' => in_array(strtolower($value), ['auto', 'left', 'right', 'center', 'justify'], true) ? strtolower($value) : null,
                'text-justify' => in_array(strtolower($value), ['auto', 'inter-word', 'inter-character'], true) ? strtolower($value) : null,
                'font-weight' => preg_match('/^(normal|bold|[1-9]00)$/i', $value) === 1 ? strtolower($value) : null,
                'font-style' => in_array(strtolower($value), ['normal', 'italic'], true) ? strtolower($value) : null,
                'text-decoration', 'text-decoration-line' => self::textDecoration($value),
                'line-height' => is_numeric($value) && (float) $value >= 1 && (float) $value <= 3 ? (string) (float) $value : null,
                default => null,
            };
            if ($normalized !== null) {
                $styles[$property === 'text-decoration-line' ? 'text-decoration' : $property] = $normalized;
            }
        }

        return $styles;
    }

    /** @return array<string, string> */
    private static function legacyFontStyles(DOMElement $node): array
    {
        $styles = array_filter([
            'font-family' => self::fontFamily($node->getAttribute('face')),
            'color' => self::color($node->getAttribute('color')),
        ], static fn (?string $value): bool => $value !== null);
        $sizes = ['1' => '10pt', '2' => '12pt', '3' => '14pt', '4' => '16pt', '5' => '18pt', '6' => '24pt', '7' => '32pt'];
        if (isset($sizes[$node->getAttribute('size')])) {
            $styles['font-size'] = $sizes[$node->getAttribute('size')];
        }

        return $styles;
    }

    private static function fontSize(string $value): ?string
    {
        if (preg_match('/^([0-9]+(?:\.[0-9]+)?)(pt|px)$/i', $value, $match) !== 1) {
            return null;
        }
        $size = (float) $match[1] * (strtolower($match[2]) === 'px' ? 0.75 : 1);

        return $size >= 10 && $size <= 32 ? (string) round($size, 2).'pt' : null;
    }

    private static function fontFamily(string $value): ?string
    {
        $font = strtolower(trim(explode(',', $value)[0], " \t\n\r\0\x0B\"'"));

        return ['thsarabunnew' => 'THSarabunNew', 'th sarabun new' => 'THSarabunNew', 'sarabun' => 'Sarabun', 'tahoma' => 'Tahoma', 'arial' => 'Arial'][$font] ?? null;
    }

    private static function color(string $value): ?string
    {
        if (preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $value) === 1) {
            return strtolower($value);
        }
        if (preg_match('/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/i', $value, $matches) === 1
            && max((int) $matches[1], (int) $matches[2], (int) $matches[3]) <= 255) {
            return 'rgb('.(int) $matches[1].', '.(int) $matches[2].', '.(int) $matches[3].')';
        }

        return null;
    }

    private static function textDecoration(string $value): ?string
    {
        $tokens = preg_split('/\s+/', strtolower(trim($value))) ?: [];

        return $tokens !== [] && array_diff($tokens, ['none', 'underline', 'line-through']) === [] ? implode(' ', array_unique($tokens)) : null;
    }

    private static function hasMatchingText(string $plain, string $html): bool
    {
        $withoutWhitespace = static fn (string $text): string => preg_replace('/\s+/u', '', self::normalize($text)) ?? $text;
        $htmlText = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $withoutWhitespace($plain) === $withoutWhitespace($htmlText);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
