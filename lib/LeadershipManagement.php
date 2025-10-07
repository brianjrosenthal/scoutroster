<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/GradeCalculator.php';
require_once __DIR__ . '/ActivityLog.php';

class LeadershipManagement {
    private static function pdo(): PDO {
        return pdo();
    }

    // Activity logging helper
    private static function log(string $action, ?int $targetUserId, array $details = []): void {
        try {
            $ctx = UserContext::getLoggedInUserContext();
            $meta = $details;
            if ($targetUserId !== null && !array_key_exists('target_user_id', $meta)) {
                $meta['target_user_id'] = (int)$targetUserId;
            }
            ActivityLog::log($ctx, $action, $meta);
        } catch (Throwable $e) {
            // Best-effort logging; never disrupt the main flow.
        }
    }

    private static function assertAdmin(?UserContext $ctx): void {
        if (!$ctx || !$ctx->admin) {
            throw new RuntimeException('Admins only');
        }
    }

    private static function assertCanManagePositions(?UserContext $ctx, int $targetUserId): void {
        if (!$ctx) {
            throw new RuntimeException('Login required');
        }
        if (!$ctx->admin && $ctx->id !== $targetUserId) {
            throw new RuntimeException('Forbidden - cannot manage leadership positions for this user');
        }
    }

    // =========================
    // Pack Leadership Positions
    // =========================

    public static function listPackPositions(): array {
        $sql = "SELECT id, name, sort_priority, description 
                FROM adult_leadership_positions 
                ORDER BY sort_priority ASC, name ASC";
        $st = self::pdo()->prepare($sql);
        $st->execute();
        return $st->fetchAll();
    }

    public static function listPackPositionsWithCounts(): array {
        $sql = "SELECT 
                    alp.id,
                    alp.name,
                    alp.sort_priority,
                    alp.description,
                    COUNT(alpa.adult_id) as assignment_count
                FROM adult_leadership_positions alp
                LEFT JOIN adult_leadership_position_assignments alpa ON alp.id = alpa.adult_leadership_position_id
                GROUP BY alp.id, alp.name, alp.sort_priority, alp.description
                ORDER BY alp.sort_priority ASC, alp.name ASC";
        $st = self::pdo()->prepare($sql);
        $st->execute();
        return $st->fetchAll();
    }

    public static function getPackPosition(int $positionId): ?array {
        $sql = "SELECT id, name, sort_priority, description 
                FROM adult_leadership_positions 
                WHERE id = ? LIMIT 1";
        $st = self::pdo()->prepare($sql);
        $st->execute([$positionId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function createPackPosition(?UserContext $ctx, string $name, int $sortPriority = 0, ?string $description = null): int {
        self::assertAdmin($ctx);
        
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Position name is required');
        }

        // Check for duplicates
        $existing = self::pdo()->prepare("SELECT 1 FROM adult_leadership_positions WHERE name = ? LIMIT 1");
        $existing->execute([$name]);
        if ($existing->fetchColumn()) {
            throw new InvalidArgumentException('A position with this name already exists');
        }

        $sql = "INSERT INTO adult_leadership_positions (name, sort_priority, description, created_by, created_at) 
                VALUES (?, ?, ?, ?, NOW())";
        $st = self::pdo()->prepare($sql);
        $st->execute([$name, $sortPriority, $description, $ctx->id]);
        
        $id = (int)self::pdo()->lastInsertId();
        self::log('leadership.create_position', null, ['position_id' => $id, 'name' => $name]);
        return $id;
    }

    public static function updatePackPosition(?UserContext $ctx, int $positionId, string $name, int $sortPriority = 0, ?string $description = null): bool {
        self::assertAdmin($ctx);
        
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Position name is required');
        }

        // Check for duplicates (excluding current position)
        $existing = self::pdo()->prepare("SELECT 1 FROM adult_leadership_positions WHERE name = ? AND id != ? LIMIT 1");
        $existing->execute([$name, $positionId]);
        if ($existing->fetchColumn()) {
            throw new InvalidArgumentException('A position with this name already exists');
        }

        $sql = "UPDATE adult_leadership_positions 
                SET name = ?, sort_priority = ?, description = ? 
                WHERE id = ?";
        $st = self::pdo()->prepare($sql);
        $ok = $st->execute([$name, $sortPriority, $description, $positionId]);
        
        if ($ok && $st->rowCount() > 0) {
            self::log('leadership.update_position', null, ['position_id' => $positionId, 'name' => $name]);
        }
        
        return $ok;
    }

    public static function deletePackPosition(?UserContext $ctx, int $positionId): bool {
        self::assertAdmin($ctx);

        // Get position details for logging
        $position = self::getPackPosition($positionId);
        if (!$position) {
            throw new InvalidArgumentException('Position does not exist');
        }

        // Get assignment count for logging
        $countSql = "SELECT COUNT(*) as count FROM adult_leadership_position_assignments WHERE adult_leadership_position_id = ?";
        $countSt = self::pdo()->prepare($countSql);
        $countSt->execute([$positionId]);
        $count = (int)($countSt->fetch()['count'] ?? 0);

        // Delete position (assignments will cascade delete via foreign key)
        $sql = "DELETE FROM adult_leadership_positions WHERE id = ?";
        $st = self::pdo()->prepare($sql);
        $ok = $st->execute([$positionId]);
        
        if ($ok && $st->rowCount() > 0) {
            self::log('leadership.delete_position', null, [
                'position_id' => $positionId, 
                'name' => $position['name'],
                'assignments_deleted' => $count
            ]);
        }
        
        return $ok;
    }

    // =========================
    // Pack Position Assignments
    // =========================

    public static function listAdultPackPositions(int $adultId): array {
        $sql = "SELECT alp.id, alp.name, alp.description, alpa.created_at
                FROM adult_leadership_position_assignments alpa
                JOIN adult_leadership_positions alp ON alp.id = alpa.adult_leadership_position_id
                WHERE alpa.adult_id = ?
                ORDER BY alp.sort_priority ASC, alp.name ASC";
        $st = self::pdo()->prepare($sql);
        $st->execute([$adultId]);
        return $st->fetchAll();
    }

    public static function assignPackPosition(?UserContext $ctx, int $adultId, int $positionId): void {
        self::assertCanManagePositions($ctx, $adultId);

        // Verify position exists
        $position = self::getPackPosition($positionId);
        if (!$position) {
            throw new InvalidArgumentException('Position does not exist');
        }

        // Check if already assigned
        $existing = self::pdo()->prepare(
            "SELECT 1 FROM adult_leadership_position_assignments 
             WHERE adult_id = ? AND adult_leadership_position_id = ? LIMIT 1"
        );
        $existing->execute([$adultId, $positionId]);
        if ($existing->fetchColumn()) {
            throw new InvalidArgumentException('Position already assigned to this adult');
        }

        // Insert assignment
        $sql = "INSERT INTO adult_leadership_position_assignments 
                (adult_leadership_position_id, adult_id, created_by, created_at) 
                VALUES (?, ?, ?, NOW())";
        $st = self::pdo()->prepare($sql);
        $st->execute([$positionId, $adultId, $ctx->id]);

        self::log('leadership.assign_position', $adultId, [
            'position_id' => $positionId,
            'position_name' => $position['name']
        ]);
    }

    public static function removePackPosition(?UserContext $ctx, int $adultId, int $positionId): void {
        self::assertCanManagePositions($ctx, $adultId);

        // Get position name for logging
        $position = self::getPackPosition($positionId);
        $positionName = $position ? $position['name'] : 'Unknown';

        $sql = "DELETE FROM adult_leadership_position_assignments 
                WHERE adult_id = ? AND adult_leadership_position_id = ?";
        $st = self::pdo()->prepare($sql);
        $st->execute([$adultId, $positionId]);

        if ($st->rowCount() > 0) {
            self::log('leadership.remove_position', $adultId, [
                'position_id' => $positionId,
                'position_name' => $positionName
            ]);
        }
    }

    // =========================
    // Den Leader Assignments
    // =========================

    public static function listAdultDenLeaderAssignments(int $adultId): array {
        $sql = "SELECT class_of, created_at
                FROM adult_den_leader_assignments
                WHERE adult_id = ?
                ORDER BY class_of DESC";
        $st = self::pdo()->prepare($sql);
        $st->execute([$adultId]);
        $rows = $st->fetchAll();
        
        // Add grade information
        foreach ($rows as &$row) {
            $classOf = (int)$row['class_of'];
            $grade = GradeCalculator::gradeForClassOf($classOf);
            $row['grade'] = $grade;
            $row['grade_label'] = GradeCalculator::gradeLabel($grade);
        }
        
        return $rows;
    }

    public static function assignDenLeader(?UserContext $ctx, int $adultId, int $grade): void {
        self::assertCanManagePositions($ctx, $adultId);

        if ($grade < 0 || $grade > 5) {
            throw new InvalidArgumentException('Grade must be between K (0) and 5');
        }

        // Convert grade to class_of
        $classOf = GradeCalculator::schoolYearEndYear() + (5 - $grade);

        // Check if already assigned
        $existing = self::pdo()->prepare(
            "SELECT 1 FROM adult_den_leader_assignments 
             WHERE adult_id = ? AND class_of = ? LIMIT 1"
        );
        $existing->execute([$adultId, $classOf]);
        if ($existing->fetchColumn()) {
            $gradeLabel = GradeCalculator::gradeLabel($grade);
            throw new InvalidArgumentException("Already assigned as den leader for grade $gradeLabel");
        }

        // Insert assignment
        $sql = "INSERT INTO adult_den_leader_assignments 
                (class_of, adult_id, created_by, created_at) 
                VALUES (?, ?, ?, NOW())";
        $st = self::pdo()->prepare($sql);
        $st->execute([$classOf, $adultId, $ctx->id]);

        $gradeLabel = GradeCalculator::gradeLabel($grade);
        self::log('leadership.assign_den_leader', $adultId, [
            'class_of' => $classOf,
            'grade' => $grade,
            'grade_label' => $gradeLabel
        ]);
    }

    public static function removeDenLeader(?UserContext $ctx, int $adultId, int $grade): void {
        self::assertCanManagePositions($ctx, $adultId);

        if ($grade < 0 || $grade > 5) {
            throw new InvalidArgumentException('Grade must be between K (0) and 5');
        }

        // Convert grade to class_of
        $classOf = GradeCalculator::schoolYearEndYear() + (5 - $grade);

        $sql = "DELETE FROM adult_den_leader_assignments 
                WHERE adult_id = ? AND class_of = ?";
        $st = self::pdo()->prepare($sql);
        $st->execute([$adultId, $classOf]);

        if ($st->rowCount() > 0) {
            $gradeLabel = GradeCalculator::gradeLabel($grade);
            self::log('leadership.remove_den_leader', $adultId, [
                'class_of' => $classOf,
                'grade' => $grade,
                'grade_label' => $gradeLabel
            ]);
        }
    }

    // =========================
    // Combined Position Display (for backward compatibility)
    // =========================

    public static function listAdultAllPositions(int $adultId): array {
        $positions = [];
        
        // Get pack positions
        $packPositions = self::listAdultPackPositions($adultId);
        foreach ($packPositions as $pos) {
            $positions[] = [
                'type' => 'pack',
                'id' => (int)$pos['id'],
                'name' => $pos['name'],
                'description' => $pos['description'],
                'display_name' => $pos['name'],
                'created_at' => $pos['created_at']
            ];
        }
        
        // Get den leader positions
        $denPositions = self::listAdultDenLeaderAssignments($adultId);
        foreach ($denPositions as $pos) {
            $gradeLabel = $pos['grade'] === 0 ? 'K' : (string)$pos['grade'];
            $positions[] = [
                'type' => 'den_leader',
                'class_of' => (int)$pos['class_of'],
                'grade' => (int)$pos['grade'],
                'grade_label' => $pos['grade_label'],
                'name' => 'Den Leader',
                'display_name' => "Den Leader Grade $gradeLabel",
                'created_at' => $pos['created_at']
            ];
        }
        
        // Sort by created_at
        usort($positions, function($a, $b) {
            return strcmp($a['created_at'], $b['created_at']);
        });
        
        return $positions;
    }

    // Helper method to get position names as comma-separated string (for display compatibility)
    public static function getAdultPositionString(int $adultId): string {
        $positions = self::listAdultAllPositions($adultId);
        $names = array_map(function($pos) {
            return $pos['display_name'];
        }, $positions);
        return implode(', ', $names);
    }

    // =========================
    // Public Display Methods (for public leadership page)
    // =========================

    public static function getPackLeadershipForDisplay(): array {
        $sql = "SELECT 
                    alp.id,
                    alp.name,
                    alp.description,
                    alp.sort_priority,
                    u.id as adult_id,
                    u.first_name,
                    u.last_name,
                    u.photo_public_file_id
                FROM adult_leadership_positions alp
                LEFT JOIN adult_leadership_position_assignments alpa ON alp.id = alpa.adult_leadership_position_id
                LEFT JOIN users u ON alpa.adult_id = u.id
                ORDER BY alp.sort_priority ASC, alp.name ASC, u.last_name ASC, u.first_name ASC";
        
        $st = self::pdo()->prepare($sql);
        $st->execute();
        $rows = $st->fetchAll();
        
        // Group by position
        $positions = [];
        foreach ($rows as $row) {
            $posId = (int)$row['id'];
            if (!isset($positions[$posId])) {
                $positions[$posId] = [
                    'id' => $posId,
                    'name' => $row['name'],
                    'description' => $row['description'],
                    'sort_priority' => (int)$row['sort_priority'],
                    'holders' => []
                ];
            }
            
            if (!empty($row['adult_id'])) {
                $positions[$posId]['holders'][] = [
                    'id' => (int)$row['adult_id'],
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'photo_public_file_id' => $row['photo_public_file_id']
                ];
            }
        }
        
        return $positions;
    }

    public static function getDenLeadersForDisplay(): array {
        $sql = "SELECT 
                    adla.class_of,
                    u.id as adult_id,
                    u.first_name,
                    u.last_name,
                    u.photo_public_file_id
                FROM adult_den_leader_assignments adla
                JOIN users u ON adla.adult_id = u.id
                ORDER BY adla.class_of DESC, u.first_name ASC, u.last_name ASC";
        
        $st = self::pdo()->prepare($sql);
        $st->execute();
        $rows = $st->fetchAll();
        
        // Group by grade
        $denLeaders = [];
        foreach ($rows as $row) {
            $classOf = (int)$row['class_of'];
            $grade = GradeCalculator::gradeForClassOf($classOf);
            
            if (!isset($denLeaders[$classOf])) {
                $denLeaders[$classOf] = [
                    'class_of' => $classOf,
                    'grade' => $grade,
                    'grade_label' => GradeCalculator::gradeLabel($grade),
                    'leaders' => []
                ];
            }
            
            $denLeaders[$classOf]['leaders'][] = [
                'id' => (int)$row['adult_id'],
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'photo_public_file_id' => $row['photo_public_file_id']
            ];
        }
        
        return $denLeaders;
    }

    /**
     * Get all leadership email addresses for export (admin-only feature)
     * Returns array of unique email addresses from both pack positions and den leaders
     * Skips leaders without email addresses
     */
    public static function getAllLeaderEmailsForExport(): array {
        $emails = [];
        
        // Get pack position holders with emails
        $packSql = "SELECT DISTINCT u.email
                    FROM adult_leadership_position_assignments alpa
                    JOIN users u ON alpa.adult_id = u.id
                    WHERE u.email IS NOT NULL AND u.email != ''";
        
        $st = self::pdo()->prepare($packSql);
        $st->execute();
        while ($row = $st->fetch()) {
            $emails[] = trim(strtolower($row['email']));
        }
        
        // Get den leaders with emails
        $denSql = "SELECT DISTINCT u.email
                   FROM adult_den_leader_assignments adla
                   JOIN users u ON adla.adult_id = u.id
                   WHERE u.email IS NOT NULL AND u.email != ''";
        
        $st = self::pdo()->prepare($denSql);
        $st->execute();
        while ($row = $st->fetch()) {
            $emails[] = trim(strtolower($row['email']));
        }
        
        // Remove duplicates and sort
        $emails = array_unique($emails);
        sort($emails);
        
        return array_values($emails);
    }
}
