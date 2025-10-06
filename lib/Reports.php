<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

class Reports {
  private static function pdo(): PDO {
    return pdo();
  }

  /**
   * Get list of registered adult leaders with their BSA registration and safeguarding information.
   * Returns adults who have a BSA membership number (indicating registered leadership).
   * 
   * @return array Array of adults with id, first_name, last_name, bsa_membership_number, 
   *               bsa_registration_expires_on, safeguarding_training_expires_on
   */
  public static function getRegisteredAdultLeaders(): array {
    $sql = "
      SELECT 
        id,
        first_name,
        last_name,
        bsa_membership_number,
        bsa_registration_expires_on,
        safeguarding_training_expires_on
      FROM users
      WHERE bsa_membership_number IS NOT NULL 
        AND bsa_membership_number <> ''
      ORDER BY last_name, first_name
    ";
    
    $st = self::pdo()->prepare($sql);
    $st->execute();
    return $st->fetchAll();
  }

  /**
   * Export registered adult leaders data to CSV format.
   * 
   * @return string CSV content
   */
  public static function exportRegisteredAdultLeadersCSV(): string {
    $leaders = self::getRegisteredAdultLeaders();
    
    $output = fopen('php://temp', 'r+');
    
    // Write header
    fputcsv($output, [
      'Name',
      'BSA Registration Number',
      'BSA Registration Expiration',
      'Safeguarding Youth Expires'
    ]);
    
    // Write data rows
    foreach ($leaders as $leader) {
      $name = trim(($leader['first_name'] ?? '') . ' ' . ($leader['last_name'] ?? ''));
      
      fputcsv($output, [
        $name,
        $leader['bsa_membership_number'] ?? '',
        $leader['bsa_registration_expires_on'] ?? '',
        $leader['safeguarding_training_expires_on'] ?? ''
      ]);
    }
    
    rewind($output);
    $csv = stream_get_contents($output);
    fclose($output);
    
    return $csv;
  }
}
