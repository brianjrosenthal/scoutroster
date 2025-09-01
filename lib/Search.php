<?php
declare(strict_types=1);

/**
 * Search helpers for tokenized LIKE queries.
 *
 * Tokenization rules:
 * - Keep letters (Unicode), digits, and apostrophes (') together.
 * - Split on whitespace and most punctuation (commas, periods, slashes, etc.).
 * - Example: "Brian Rosenthal" -> ["Brian","Rosenthal"]
 *            "O'Malley, Brian" -> ["O'Malley","Brian"]
 */
final class Search {
  /**
   * Split query into tokens, keeping apostrophes within tokens.
   * @return string[] Non-empty tokens
   */
  public static function tokenize(?string $q): array {
    if ($q === null) return [];
    $q = trim($q);
    if ($q === '') return [];
    // Split on any run of characters that are NOT letters, digits or apostrophes.
    $parts = preg_split('/[^\p{L}\p{N}\']+/u', $q);
    if ($parts === false) return [];
    $tokens = [];
    foreach ($parts as $p) {
      $p = trim($p);
      if ($p !== '') $tokens[] = $p;
    }
    return $tokens;
  }

  /**
   * Build an AND-of-ORs LIKE clause for the given tokens across fields.
   *
   * For each token t:
   *   (field1 LIKE ? ESCAPE '\' OR field2 LIKE ? ESCAPE '\' OR ...)
   * and all token-groups are AND'ed together.
   *
   * @param string[] $fields List of fully-qualified column names
   * @param string[] $tokens Token list (should already be sanitized token strings)
   * @param array    $params Output params array to append bound values to
   * @return string  SQL snippet beginning with " AND (...)" or empty string if no tokens
   */
  public static function buildAndLikeClause(array $fields, array $tokens, array &$params): string {
    if (empty($tokens) || empty($fields)) return '';
    $groups = [];
    foreach ($tokens as $tok) {
      // Escape LIKE wildcards in the value so user tokens with % or _ do not act as wildcards.
      $val = self::escapeLikeToken($tok);
      $likes = [];
      foreach ($fields as $f) {
        $likes[] = "$f LIKE ? ESCAPE '\\\\'";
        $params[] = "%{$val}%";
      }
      $groups[] = '(' . implode(' OR ', $likes) . ')';
    }
    return ' AND (' . implode(' AND ', $groups) . ')';
  }

  /**
   * Escape % and _ and backslash in LIKE values.
   */
  public static function escapeLikeToken(string $s): string {
    // First escape backslash, then % and _
    $s = str_replace('\\', '\\\\', $s);
    $s = str_replace('%', '\\%', $s);
    $s = str_replace('_', '\\_', $s);
    return $s;
  }
}
