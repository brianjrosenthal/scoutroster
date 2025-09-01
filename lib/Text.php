<?php
declare(strict_types=1);

final class Text {
  /**
   * Render a small "Markdown-lite" subset:
   * - [label](https://example.com)
   * - Autolink bare http/https URLs
   * - Preserve newlines with <br>
   *
   * Storage remains plain text. All non-link text is HTML-escaped.
   */
  public static function renderMarkup(string $text): string {
    if ($text === '') return '';

    $tokens = [];
    $i = 0;
    $makeToken = function(string $html) use (&$tokens, &$i): string {
      $i++;
      $token = "__LINK_TOKEN_{$i}__";
      $tokens[$token] = $html;
      return $token;
    };

    // Pass 1: [label](https://url)
    $text = preg_replace_callback('/\[(.+?)\]\((https?:\/\/[^\s)]+)\)/i', function ($m) use ($makeToken) {
      $label = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
      $hrefRaw = $m[2];
      if (!preg_match('/^https?:\/\//i', $hrefRaw)) {
        // Fallback: just return the label if scheme not allowed
        return $label;
      }
      $href = htmlspecialchars($hrefRaw, ENT_QUOTES, 'UTF-8');
      $html = '<a href="'.$href.'" target="_blank" rel="noopener noreferrer">'.$label.'</a>';
      return $makeToken($html);
    }, $text);

    // Pass 2: Autolink bare URLs (simple pattern; avoids common breakers)
    $text = preg_replace_callback('/(?<!["\'\]])\bhttps?:\/\/[^\s<>()]+/i', function ($m) use ($makeToken) {
      $urlRaw = $m[0];
      $href = htmlspecialchars($urlRaw, ENT_QUOTES, 'UTF-8');
      $label = $href;
      $html = '<a href="'.$href.'" target="_blank" rel="noopener noreferrer">'.$label.'</a>';
      return $makeToken($html);
    }, $text);

    // Escape the remainder safely
    $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    // Replace tokens with their HTML
    if (!empty($tokens)) {
      $escaped = strtr($escaped, $tokens);
    }

    // Preserve newlines
    return nl2br($escaped);
  }
}
