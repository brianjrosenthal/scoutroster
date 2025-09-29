<?php
declare(strict_types=1);

require_once __DIR__ . '/../settings.php';

class GradeCalculator {
  /**
   * Returns the month (1-12) the school year starts. Default August (8).
   * You can later store this in Settings if you want to make it configurable.
   */
  public static function schoolYearStartMonth(): int {
    // If later you add Settings::get('school_year_start_month', '8'), use that here.
    return 8;
  }

  /**
   * Computes the "end year" for the current school year, given a timezone-aware DateTime.
   * If school year starts in August, then:
   * - For dates Aug-Dec 2025, end year is 2026 (school year 2025-2026).
   * - For dates Jan-Jul 2026, end year is 2026.
   */
  public static function schoolYearEndYear(?DateTime $now = null): int {
    if ($now === null) {
      $now = new DateTime('now', new DateTimeZone(Settings::timezoneId()));
    } else {
      $now->setTimezone(new DateTimeZone(Settings::timezoneId()));
    }
    $startMonth = self::schoolYearStartMonth();
    $y = (int)$now->format('Y');
    $m = (int)$now->format('n');
    // If we're in or after the start month, the school year ends next calendar year
    return ($m >= $startMonth) ? ($y + 1) : $y;
  }

  /**
   * Assumption documented:
   * - youth.class_of is the year they will complete 5th grade for their current cohort.
   *   Example: Current 5th graders in school year 2025-2026 have class_of=2026.
   *   Then grade = 5 - (class_of - currentFifthClassOf).
   *   class_of == currentFifthClassOf => grade 5
   *   class_of == currentFifthClassOf + 1 => grade 4
   *   ...
   *   class_of == currentFifthClassOf + 5 => grade 0 (Kindergarten)
   *
   * Returns an integer grade 0..5. Values outside this range are clamped to 0..5.
   */
  public static function gradeForClassOf(int $classOf, ?DateTime $now = null): int {
    $currentFifthClassOf = self::schoolYearEndYear($now);
    // General formula: grade = 5 - (class_of - currentFifthClassOf)
    // This can yield:
    //   -3..-1 => Pre-K (K in 3..1 years)
    //    0     => K
    //    1..12 => Grades 1..12
    return 5 - ($classOf - $currentFifthClassOf);
  }

  /**
   * Returns a human label for a grade number (0..5).
   */
  public static function gradeLabel(int $grade): string {
    if ($grade <= -1) {
      $years = abs($grade);
      // Cap display at 3 years for label consistency
      if ($years > 3) $years = 3;
      return 'Pre-K (K in ' . $years . ' ' . ($years === 1 ? 'year' : 'years') . ')';
    }
    if ($grade === 0) return 'K';
    // Allow up through high school labels; beyond 12 just show the number
    return (string)$grade;
  }

  /**
   * Parse a label ('K','0','1'..'5') into an integer grade 0..5. Returns null if invalid.
   */
  public static function parseGradeLabel(string $label): ?int {
    $raw = trim($label);
    $upper = strtoupper($raw);

    // K
    if ($upper === 'K') return 0;

    // Numeric (supports negative for Pre-K, e.g., -1, -2, -3)
    if (preg_match('/^-?\d+$/', $raw)) {
      $n = (int)$raw;
      // Accept a reasonable range for our app
      if ($n >= -12 && $n <= 20) return $n;
    }

    // PRE-K variants (optionally with "K in N years")
    if (strpos($upper, 'PRE-K') === 0 || strpos($upper, 'PREK') === 0) {
      if (preg_match('/K\s+IN\s+(\d+)\s+YEAR/i', $upper, $m)) {
        $years = max(1, min(12, (int)$m[1]));
        return -$years;
      }
      // Default to 1 year if unspecified
      return -1;
    }

    return null;
  }
}
