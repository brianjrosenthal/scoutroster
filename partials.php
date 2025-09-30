<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/lib/Application.php';
Application::init();
require_once __DIR__ . '/lib/Files.php';
require_once __DIR__ . '/lib/ApplicationUI.php';

function echo_r($o) {
  echo('<pre>');
  echo(print_r($o, 1));
  echo('</pre>');
}

function debug_expand_sql($sql, $params) {
  $debugSql = $sql;
  foreach ($params as $param) {
    $debugSql = preg_replace('/\?/', "'" . addslashes($param) . "'", $debugSql, 1);
  }
  return $debugSql;
}


function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/**
 * Legacy function wrapper for header_html - calls ApplicationUI::renderHeader
 * Maintains backward compatibility while using the new class-based approach
 */
function header_html(string $title) {
    ApplicationUI::renderHeader($title);
}

/**
 * Legacy function wrapper for footer_html - calls ApplicationUI::renderFooter
 * Maintains backward compatibility while using the new class-based approach
 */
function footer_html() {
    ApplicationUI::renderFooter();
}
