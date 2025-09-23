<?php
declare(strict_types=1);

final class Text {
  /**
   * Render markdown subset:
   * - [label](https://example.com)
   * - Autolink bare http/https URLs
   * - **bold** and *italic* text
   * - Headers (# ## ###)
   * - Lists (- * and 1. 2.)
   * - Preserve newlines with <br>
   *
   * Storage remains plain text. All non-markup text is HTML-escaped.
   */
  public static function renderMarkup(string $text): string {
    if ($text === '') return '';

    $tokens = [];
    $i = 0;
    $makeToken = function(string $html) use (&$tokens, &$i): string {
      $i++;
      $token = "__MARKUP_TOKEN_{$i}__";
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

    // Pass 3: Bold text **text**
    $text = preg_replace_callback('/\*\*(.+?)\*\*/s', function ($m) use ($makeToken) {
      $content = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
      $html = '<strong>'.$content.'</strong>';
      return $makeToken($html);
    }, $text);

    // Pass 4: Italic text *text* (but not if it's part of ** or surrounded by **)
    $text = preg_replace_callback('/(?<!\*)\*([^*\n]+?)\*(?!\*)/s', function ($m) use ($makeToken) {
      $content = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
      $html = '<em>'.$content.'</em>';
      return $makeToken($html);
    }, $text);

    // Pass 5: Headers (must be at start of line)
    $text = preg_replace_callback('/^(#{1,3})\s+(.+)$/m', function ($m) use ($makeToken) {
      $level = strlen($m[1]);
      $content = htmlspecialchars(trim($m[2]), ENT_QUOTES, 'UTF-8');
      $html = '<h'.$level.'>'.$content.'</h'.$level.'>';
      return $makeToken($html);
    }, $text);

    // Pass 6: Process lists (unordered and ordered)
    $text = self::processLists($text, $makeToken);

    // Escape the remainder safely
    $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    // Replace tokens with their HTML
    if (!empty($tokens)) {
      $escaped = strtr($escaped, $tokens);
    }

    // Preserve newlines
    return nl2br($escaped);
  }

  /**
   * Process markdown lists (both unordered and ordered)
   */
  private static function processLists(string $text, callable $makeToken): string {
    $lines = explode("\n", $text);
    $result = [];
    $inList = false;
    $listType = null; // 'ul' or 'ol'
    $listItems = [];

    foreach ($lines as $line) {
      $trimmed = ltrim($line);
      
      // Check for unordered list item (- or *)
      if (preg_match('/^[-*]\s+(.+)$/', $trimmed, $matches)) {
        if (!$inList || $listType !== 'ul') {
          // Start new unordered list or switch from ordered to unordered
          if ($inList && !empty($listItems)) {
            // Close previous list
            $listHtml = '<'.$listType.'><li>'.implode('</li><li>', $listItems).'</li></'.$listType.'>';
            $result[] = $makeToken($listHtml);
            $listItems = [];
          }
          $inList = true;
          $listType = 'ul';
        }
        $listItems[] = htmlspecialchars(trim($matches[1]), ENT_QUOTES, 'UTF-8');
      }
      // Check for ordered list item (1. 2. etc.)
      elseif (preg_match('/^\d+\.\s+(.+)$/', $trimmed, $matches)) {
        if (!$inList || $listType !== 'ol') {
          // Start new ordered list or switch from unordered to ordered
          if ($inList && !empty($listItems)) {
            // Close previous list
            $listHtml = '<'.$listType.'><li>'.implode('</li><li>', $listItems).'</li></'.$listType.'>';
            $result[] = $makeToken($listHtml);
            $listItems = [];
          }
          $inList = true;
          $listType = 'ol';
        }
        $listItems[] = htmlspecialchars(trim($matches[1]), ENT_QUOTES, 'UTF-8');
      }
      else {
        // Not a list item
        if ($inList && !empty($listItems)) {
          // Close current list
          $listHtml = '<'.$listType.'><li>'.implode('</li><li>', $listItems).'</li></'.$listType.'>';
          $result[] = $makeToken($listHtml);
          $listItems = [];
          $inList = false;
          $listType = null;
        }
        $result[] = $line;
      }
    }

    // Close any remaining list
    if ($inList && !empty($listItems)) {
      $listHtml = '<'.$listType.'><li>'.implode('</li><li>', $listItems).'</li></'.$listType.'>';
      $result[] = $makeToken($listHtml);
    }

    return implode("\n", $result);
  }
}
