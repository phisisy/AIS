<?php
declare(strict_types=1);

namespace Database\Tools;

use App\Auth\Models\User;
use App\Enrollment\Models\Enrollment;
use App\Enrollment\Models\Section;
use App\Enrollment\Models\Term;
use App\Faculty\Models\Grade;
use App\Faculty\Services\GradeScale;
use App\Students\Models\Program;
use App\Students\Models\Student;
use PDO;
use Throwable;

/** Read-only, whole-schema integrity checks. Values never leave this class. */
final class IntegrityVerifier
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<string,list<int>> Check name to offending numeric ids. */
    public function verify(): array
    {
        $ownSnapshot = !$this->pdo->inTransaction();
        if ($ownSnapshot) {
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->pdo->exec('START TRANSACTION READ ONLY, WITH CONSISTENT SNAPSHOT');
        }
        try {
            $checks = array_fill_keys([
                'user_model', 'user_roles', 'student_model', 'program_model', 'student_history_seq',
                'student_history_replay', 'student_year_level', 'term_model', 'current_term',
                'section_model', 'scheduled_meetings', 'meeting_self_overlap',
                'assignment_history_seq', 'assignment_history_replay', 'assignment_assigned_seq',
                'instructor_cap', 'instructor_overlap', 'faculty_model', 'faculty_history_seq',
                'faculty_history_replay', 'audit_ids', 'repository_last_seq',
                'enrollment_model', 'enrollment_history_seq', 'enrollment_history_replay',
                'enrollment_approved_count', 'enrollment_duplicate_active', 'enrollment_schedule_clash',
                'grade_model', 'grade_value_scale', 'grade_history_seq', 'grade_history_replay', 'grade_history_value_scale',
                'grade_enrollment', 'finalized_section_missing_grade',
            ], []);
            $users = $this->rows('SELECT id,email,status,password_hash,auth_version FROM users ORDER BY id');
            $roles = $this->rows('SELECT user_id,role FROM user_roles ORDER BY user_id,role');
            $audit = $this->rows('SELECT id,at,actor_id,target_id,action,outcome,changes FROM audit_logs ORDER BY id');
            $programs = $this->rows('SELECT code,name,max_year_level FROM programs ORDER BY code');
            $students = $this->rows('SELECT * FROM students ORDER BY id');
            $studentHistory = $this->rows('SELECT * FROM student_academic_history ORDER BY student_id,seq');
            $terms = $this->rows('SELECT id,code,name,start_date,end_date,is_current,enrollment_open,created_at,updated_at FROM terms ORDER BY id');
            $sections = $this->rows('SELECT id,term_id,course_code,course_title,units,section_code,capacity,approved_count,delivery_mode,created_at,updated_at FROM sections ORDER BY id');
            $meetings = $this->rows('SELECT section_id,position,day,start_time,end_time,room FROM section_meetings ORDER BY section_id,position');
            $assignments = $this->rows('SELECT section_id,faculty_id,assigned_seq FROM section_instructors ORDER BY assigned_seq');
            $assignmentHistory = $this->rows('SELECT seq,at,action,section_id,faculty_id,actor_id FROM section_instructor_history ORDER BY seq');
            $faculty = $this->rows('SELECT * FROM faculty ORDER BY id');
            $facultyHistory = $this->rows('SELECT * FROM faculty_history ORDER BY seq');
            $locks = $this->rows('SELECT name,last_seq FROM repository_locks ORDER BY name');
            $enrollments = $this->rows('SELECT id,student_id,section_id,status,requested_at,decided_at,decided_by,dropped_at,reason FROM enrollments ORDER BY id');
            $enrollmentHistory = $this->rows('SELECT enrollment_id,seq,at,action,actor_id,reason FROM enrollment_history ORDER BY enrollment_id,seq');
            $grades = $this->rows('SELECT id,section_id,student_id,value,remarks,status,created_at,updated_at FROM grades ORDER BY id');
            $gradeHistory = $this->rows('SELECT seq,at,action,grade_id,section_id,student_id,old_value,new_value,old_remarks,new_remarks,reason,actor_id FROM grade_history ORDER BY seq');

            $rolesByUser = [];
            foreach ($roles as $row) { $rolesByUser[(int) $row['user_id']][] = $row['role']; }
            foreach ($users as $row) {
                $id = (int) $row['id'];
                if (($rolesByUser[$id] ?? []) === []) { $checks['user_roles'][] = $id; }
                try {
                    User::fromArray(['id' => $id, 'email' => $row['email'], 'roles' => $rolesByUser[$id] ?? [],
                        'status' => $row['status'], 'password_hash' => $row['password_hash'],
                        'auth_version' => (int) $row['auth_version']]);
                } catch (Throwable) { $checks['user_model'][] = $id; }
            }
            foreach ($programs as $row) {
                try { Program::fromArray(['code' => $row['code'], 'name' => $row['name'], 'max_year_level' => (int) $row['max_year_level']]); }
                catch (Throwable) { $checks['program_model'][] = -1; }
            }
            $maxYear = array_column($programs, 'max_year_level', 'code');
            $studentById = [];
            foreach ($students as $row) {
                $id = (int) $row['id']; $studentById[$id] = $row;
                try { Student::fromArray(self::studentRow($row)); }
                catch (Throwable) { $checks['student_model'][] = $id; }
                if (!isset($maxYear[$row['program_code']]) || (int) $row['year_level'] > (int) $maxYear[$row['program_code']]) {
                    $checks['student_year_level'][] = $id;
                }
            }
            $historyByStudent = [];
            foreach ($studentHistory as $row) { $historyByStudent[(int) $row['student_id']][] = $row; }
            foreach ($studentById as $id => $student) {
                $entries = $historyByStudent[$id] ?? [];
                $state = [];
                if ($entries === []) { $checks['student_history_replay'][] = $id; }
                foreach ($entries as $index => $entry) {
                    if ((int) $entry['seq'] !== $index + 1) { $checks['student_history_seq'][] = $id; }
                    $field = $entry['field'];
                    if (!in_array($field, ['program_code', 'year_level', 'academic_status'], true)
                        || ($state[$field] ?? null) !== $entry['from_value']) {
                        $checks['student_history_replay'][] = $id;
                    }
                    $state[$field] = $entry['to_value'];
                }
                foreach (['program_code', 'year_level', 'academic_status'] as $field) {
                    if (!array_key_exists($field, $state) || $state[$field] !== (string) $student[$field]) {
                        $checks['student_history_replay'][] = $id;
                    }
                }
            }
            foreach ($historyByStudent as $id => $_) {
                if (!isset($studentById[$id])) { $checks['student_history_replay'][] = $id; }
            }
            $current = [];
            foreach ($terms as $row) {
                $id = (int) $row['id'];
                if ((int) $row['is_current'] === 1) { $current[] = $id; }
                try { Term::fromArray(self::termRow($row)); }
                catch (Throwable) { $checks['term_model'][] = $id; }
            }
            if (count($current) > 1) { $checks['current_term'] = $current; }
            $meetingsBySection = [];
            foreach ($meetings as $row) { $meetingsBySection[(int) $row['section_id']][] = $row; }
            $sectionById = [];
            foreach ($sections as $row) {
                $id = (int) $row['id']; $sectionById[$id] = $row;
                $items = $meetingsBySection[$id] ?? [];
                if ($row['delivery_mode'] === 'scheduled' && $items === []) { $checks['scheduled_meetings'][] = $id; }
                foreach ($items as $i => $a) {
                    if ((int) $a['position'] !== $i) { $checks['section_model'][] = $id; }
                    for ($j = 0; $j < $i; $j++) {
                        $b = $items[$j];
                        if (self::overlap($a, $b)) { $checks['meeting_self_overlap'][] = $id; }
                    }
                }
                try { Section::fromArray(self::sectionRow($row, $items)); }
                catch (Throwable) { $checks['section_model'][] = $id; }
            }
            $replay = []; $latest = [];
            foreach ($assignmentHistory as $index => $entry) {
                $seq = (int) $entry['seq']; $sid = (int) $entry['section_id']; $fid = (int) $entry['faculty_id'];
                if ($seq !== $index + 1) { $checks['assignment_history_seq'][] = $seq; }
                $key = $sid . ':' . $fid;
                if ($entry['action'] === 'assigned') {
                    if (isset($replay[$key])) { $checks['assignment_history_replay'][] = $sid; }
                    $replay[$key] = true; $latest[$key] = $seq;
                } elseif ($entry['action'] === 'unassigned') {
                    if (!isset($replay[$key])) { $checks['assignment_history_replay'][] = $sid; }
                    unset($replay[$key]);
                } else { $checks['assignment_history_replay'][] = $sid; }
            }
            $actual = []; $byFaculty = []; $perSection = [];
            foreach ($assignments as $row) {
                $sid = (int) $row['section_id']; $fid = (int) $row['faculty_id']; $key = $sid . ':' . $fid;
                $actual[$key] = true; $byFaculty[$fid][] = $sid;
                $perSection[$sid] = ($perSection[$sid] ?? 0) + 1;
                if (($latest[$key] ?? null) !== (int) $row['assigned_seq']) { $checks['assignment_assigned_seq'][] = $sid; }
            }
            foreach (array_unique(array_merge(array_keys(array_diff_key($actual, $replay)), array_keys(array_diff_key($replay, $actual)))) as $key) {
                $checks['assignment_history_replay'][] = (int) explode(':', $key)[0];
            }
            foreach ($perSection as $sid => $count) { if ($count > 5) { $checks['instructor_cap'][] = $sid; } }
            foreach ($byFaculty as $sids) {
                for ($i = 0; $i < count($sids); $i++) for ($j = 0; $j < $i; $j++) {
                    $a = $sids[$i]; $b = $sids[$j];
                    if (!isset($sectionById[$a], $sectionById[$b]) || $sectionById[$a]['term_id'] !== $sectionById[$b]['term_id']) { continue; }
                    foreach ($meetingsBySection[$a] ?? [] as $am) foreach ($meetingsBySection[$b] ?? [] as $bm) {
                        if (self::overlap($am, $bm)) { $checks['instructor_overlap'][] = $a; $checks['instructor_overlap'][] = $b; }
                    }
                }
            }
            foreach ($faculty as $row) {
                $id = (int) $row['id'];
                if ($id < 1 || (int) $row['user_id'] < 1 || !in_array($row['status'], ['active','inactive'], true)
                    || $row['employee_number'] === '' || $row['first_name'] === '' || $row['last_name'] === '' || $row['department'] === '') {
                    $checks['faculty_model'][] = $id;
                }
            }
            $facultyById = array_column($faculty, null, 'id'); $facultyState = [];
            foreach ($facultyHistory as $index => $entry) {
                $seq = (int) $entry['seq']; $fid = (int) $entry['faculty_id']; $field = $entry['field'];
                if ($seq !== $index + 1) { $checks['faculty_history_seq'][] = $seq; }
                if (!isset($facultyById[$fid]) || !in_array($field, ['status','department'], true)
                    || ($facultyState[$fid][$field] ?? null) !== $entry['from_value']) {
                    $checks['faculty_history_replay'][] = $fid;
                }
                $facultyState[$fid][$field] = $entry['to_value'];
            }
            foreach ($facultyState as $fid => $fields) foreach ($fields as $field => $value) {
                if (!isset($facultyById[$fid]) || $value !== $facultyById[$fid][$field]) { $checks['faculty_history_replay'][] = (int) $fid; }
            }
            // T-22 enrollments. Ids reported: enrollment ids, except approved_count and schedule_clash (section ids).
            $transitions = ['' => ['requested' => 'pending'], 'pending' => ['approved' => 'approved', 'rejected' => 'rejected', 'dropped' => 'dropped'],
                'approved' => ['dropped' => 'dropped']];
            $enrollmentById = []; $approvedBySection = []; $activePairs = []; $approvedByStudent = [];
            foreach ($enrollments as $row) {
                $id = (int) $row['id']; $enrollmentById[$id] = $row;
                $term = isset($sectionById[(int) $row['section_id']]) ? (int) $sectionById[(int) $row['section_id']]['term_id'] : 0;
                try { Enrollment::fromArray(self::enrollmentRow($row), $term); }
                catch (Throwable) { $checks['enrollment_model'][] = $id; }
                if (in_array($row['status'], ['pending', 'approved'], true)) {
                    $pair = $row['student_id'] . ':' . $row['section_id'];
                    if (isset($activePairs[$pair])) { $checks['enrollment_duplicate_active'][] = $id; $checks['enrollment_duplicate_active'][] = $activePairs[$pair]; }
                    $activePairs[$pair] = $id;
                }
                if ($row['status'] === 'approved') {
                    $approvedBySection[(int) $row['section_id']] = ($approvedBySection[(int) $row['section_id']] ?? 0) + 1;
                    $approvedByStudent[(int) $row['student_id']][$term][] = (int) $row['section_id'];
                }
            }
            $historyByEnrollment = [];
            foreach ($enrollmentHistory as $entry) { $historyByEnrollment[(int) $entry['enrollment_id']][] = $entry; }
            foreach ($historyByEnrollment as $id => $entries) {
                if (!isset($enrollmentById[$id])) { $checks['enrollment_history_replay'][] = $id; }
                $state = '';
                foreach ($entries as $index => $entry) {
                    if ((int) $entry['seq'] !== $index + 1) { $checks['enrollment_history_seq'][] = $id; }
                    $state = $transitions[$state][$entry['action']] ?? null;
                    if ($state === null || ($entry['action'] === 'rejected' && $entry['reason'] === null)) { $checks['enrollment_history_replay'][] = $id; break; }
                }
                if (isset($enrollmentById[$id]) && $state !== $enrollmentById[$id]['status']) { $checks['enrollment_history_replay'][] = $id; }
            }
            foreach ($enrollmentById as $id => $row) { if (!isset($historyByEnrollment[$id])) { $checks['enrollment_history_replay'][] = $id; } }
            foreach ($sectionById as $sid => $row) {
                if ((int) $row['approved_count'] !== ($approvedBySection[$sid] ?? 0)) { $checks['enrollment_approved_count'][] = $sid; }
            }
            foreach ($approvedByStudent as $terms) foreach ($terms as $sids) {
                for ($i = 0; $i < count($sids); $i++) for ($j = 0; $j < $i; $j++) {
                    foreach ($meetingsBySection[$sids[$i]] ?? [] as $am) foreach ($meetingsBySection[$sids[$j]] ?? [] as $bm) {
                        if (self::overlap($am, $bm)) { $checks['enrollment_schedule_clash'][] = $sids[$i]; $checks['enrollment_schedule_clash'][] = $sids[$j]; }
                    }
                }
            }
            // T-32 grades. Ids reported: grade ids; grade_history_seq reports seq; finalized_section_missing_grade reports section ids (report-only data).
            $gradeNext = ['entered' => [null, 'draft'], 'updated' => ['draft', 'draft'], 'submitted' => ['draft', 'submitted'],
                'returned' => ['submitted', 'draft'], 'finalized' => ['submitted', 'finalized'], 'corrected' => ['finalized', 'finalized']];
            $demoScale = GradeScale::demo();
            $gradeById = []; $gradePairs = []; $finalizedSections = [];
            foreach ($grades as $row) {
                $id = (int) $row['id']; $gradeById[$id] = $row; $gradePairs[$row['section_id'] . ':' . $row['student_id']] = true;
                if ($row['status'] === 'finalized') { $finalizedSections[(int) $row['section_id']] = true; }
                if (!$demoScale->allows($row['value'])) { $checks['grade_value_scale'][] = $id; }
                try { Grade::fromArray(self::gradeRow($row)); }
                catch (Throwable) { $checks['grade_model'][] = $id; }
            }
            // Cutover: history rows at or after V0009's recorded finish (UTC, both DATETIME strings) are strict, so NULL remarks mean really NULL.
            // shortcut: `at` is app-supplied, so a backdated row would be tolerated; upgrade with a per-row marker column if that matters.
            $cutover = $this->rows("SELECT finished_at FROM schema_migrations WHERE version = 9 AND state = 'applied'")[0]['finished_at'] ?? null;
            $gradeState = [];
            foreach ($gradeHistory as $index => $e) {
                $strict = $cutover !== null && $e['at'] >= $cutover;
                $gid = (int) $e['grade_id']; $a = $e['action']; $s = $gradeState[$gid] ?? null;
                if (($e['old_value'] !== null && !$demoScale->allows($e['old_value']))
                    || ($e['new_value'] !== null && !$demoScale->allows($e['new_value']))) {
                    $checks['grade_history_value_scale'][] = (int) $e['seq'];
                }
                if ((int) $e['seq'] !== $index + 1) { $checks['grade_history_seq'][] = (int) $e['seq']; }
                [$need, $next] = $gradeNext[$a] ?? [null, null];
                $valued = in_array($a, ['entered', 'updated', 'corrected'], true);
                if (!isset($gradeNext[$a]) || !isset($gradeById[$gid]) || ($s['status'] ?? null) !== $need
                    || ($a !== 'entered' && ($s['section_id'] !== (int) $e['section_id'] || $s['student_id'] !== (int) $e['student_id']))
                    || ($valued ? $e['new_value'] === null : ($e['old_value'] !== null || $e['new_value'] !== null))
                    || ($a === 'entered' && $e['old_value'] !== null)
                    || (in_array($a, ['updated', 'corrected'], true) && ($e['old_value'] !== $s['value'] || ($a === 'corrected' && $e['new_value'] === $s['value'])))
                    || (in_array($a, ['corrected', 'returned'], true) !== ($e['reason'] !== null))
                    // Remarks (V0009). Both NULL on a valued row may be pre-V0009: it asserts nothing and leaves the remarks state unknown (false) until a row records them.
                    || $e['old_remarks'] === '' || $e['new_remarks'] === ''
                    || (!$valued && ($e['old_remarks'] !== null || $e['new_remarks'] !== null))
                    || ($a === 'entered' && $e['old_remarks'] !== null)
                    || ($valued && ($strict || $e['old_remarks'] !== null || $e['new_remarks'] !== null) && in_array($a, ['updated', 'corrected'], true)
                        && ($s['remarks'] !== false && $e['old_remarks'] !== $s['remarks']))
                    || ($a === 'corrected' && $e['new_remarks'] !== $e['old_remarks'])) {
                    $checks['grade_history_replay'][] = $gid; continue;
                }
                $bothNull = !$strict && $e['old_remarks'] === null && $e['new_remarks'] === null;
                $gradeState[$gid] = ['status' => $next, 'value' => $valued ? $e['new_value'] : $s['value'],
                    'remarks' => !$valued ? $s['remarks'] : ($bothNull ? false : $e['new_remarks']),
                    'section_id' => (int) $e['section_id'], 'student_id' => (int) $e['student_id']];
            }
            foreach ($gradeById as $gid => $row) {
                $s = $gradeState[$gid] ?? null;
                if ($s === null || $s['status'] !== $row['status'] || $s['value'] !== $row['value']
                    || ($s['remarks'] !== false && $s['remarks'] !== $row['remarks'])
                    || $s['section_id'] !== (int) $row['section_id'] || $s['student_id'] !== (int) $row['student_id']) { $checks['grade_history_replay'][] = $gid; }
            }
            $approvedPairs = [];
            foreach ($enrollmentHistory as $entry) {
                $en = $enrollmentById[(int) $entry['enrollment_id']] ?? null;
                if ($entry['action'] === 'approved' && $en !== null) { $approvedPairs[$en['student_id'] . ':' . $en['section_id']] = true; }
            }
            foreach ($gradeById as $gid => $row) {
                if ($row['status'] !== 'draft' && !isset($approvedPairs[$row['student_id'] . ':' . $row['section_id']])) { $checks['grade_enrollment'][] = $gid; }
            }
            foreach ($enrollments as $row) {
                if ($row['status'] === 'approved' && isset($finalizedSections[(int) $row['section_id']]) && !isset($gradePairs[$row['section_id'] . ':' . $row['student_id']])) {
                    $checks['finalized_section_missing_grade'][] = (int) $row['section_id'];
                }
            }
            foreach ($audit as $index => $row) { if ((int) $row['id'] !== $index + 1) { $checks['audit_ids'][] = (int) $row['id']; } }
            $max = ['auth' => $audit === [] ? 0 : (int) end($audit)['id'],
                'students' => 0, 'enrollment' => $assignmentHistory === [] ? 0 : (int) end($assignmentHistory)['seq'],
                'faculty' => $facultyHistory === [] ? 0 : (int) end($facultyHistory)['seq'],
                'grades' => $gradeHistory === [] ? 0 : (int) end($gradeHistory)['seq']];
            foreach ($locks as $row) {
                if (!array_key_exists($row['name'], $max) || (int) $row['last_seq'] !== $max[$row['name']]) {
                    $checks['repository_last_seq'][] = self::lockId($row['name']);
                }
                unset($max[$row['name']]);
            }
            foreach (array_keys($max) as $name) { $checks['repository_last_seq'][] = self::lockId($name); }
            foreach ($checks as &$ids) { $ids = array_values(array_unique(array_map('intval', $ids))); sort($ids, SORT_NUMERIC); }
            unset($ids);
            return $checks;
        } finally {
            if ($ownSnapshot) { $this->pdo->exec('ROLLBACK'); }
        }
    }

    private function rows(string $sql): array { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    private static function overlap(array $a, array $b): bool
    { return $a['day'] === $b['day'] && $a['start_time'] < $b['end_time'] && $b['start_time'] < $a['end_time']; }
    private static function iso(string $value): string { return str_replace(' ', 'T', $value) . 'Z'; }
    /** Exact calendar round trip; anything else becomes a non-timestamp so the model refuses it. */
    private static function enrollmentTime(?string $v): ?string
    { if ($v === null) { return null; }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $v, new \DateTimeZone('UTC'));
        return $d !== false && $d->format('Y-m-d H:i:s') === $v && $d->format('Y') !== '0000' ? self::iso($v) : 'invalid'; }
    private static function enrollmentRow(array $r): array
    { return ['id'=>(int)$r['id'],'student_id'=>(int)$r['student_id'],'section_id'=>(int)$r['section_id'],'status'=>$r['status'],
        'requested_at'=>self::enrollmentTime($r['requested_at']),'decided_at'=>self::enrollmentTime($r['decided_at']),
        'decided_by'=>$r['decided_by']===null?null:(int)$r['decided_by'],'dropped_at'=>self::enrollmentTime($r['dropped_at']),
        'reason'=>$r['reason']]; }
    private static function gradeRow(array $r): array
    { return ['id'=>(int)$r['id'],'section_id'=>(int)$r['section_id'],'student_id'=>(int)$r['student_id'],'value'=>$r['value'],
        'remarks'=>$r['remarks'],'status'=>$r['status'],'created_at'=>self::enrollmentTime($r['created_at']),'updated_at'=>self::enrollmentTime($r['updated_at'])]; }
    private static function termRow(array $r): array
    { return ['id'=>(int)$r['id'],'code'=>$r['code'],'name'=>$r['name'],'start_date'=>$r['start_date'],'end_date'=>$r['end_date'],
        'is_current'=>(int)$r['is_current']===1,'enrollment_open'=>(int)$r['enrollment_open']===1,
        'created_at'=>self::iso($r['created_at']),'updated_at'=>self::iso($r['updated_at'])]; }
    private static function studentRow(array $r): array
    { foreach (['id','user_id','year_level'] as $key) { $r[$key]=(int)$r[$key]; }
        if ($r['admission_year'] !== null) { $r['admission_year']=(int)$r['admission_year']; }
        $r['created_at']=self::iso($r['created_at']); $r['updated_at']=self::iso($r['updated_at']); return $r; }
    private static function sectionRow(array $r, array $meetings): array
    { foreach (['id','term_id','units','capacity','approved_count'] as $key) { $r[$key]=(int)$r[$key]; }
        $r = array_slice($r,0,9,true)+['meetings'=>array_map(static fn(array $m):array =>
            ['day'=>$m['day'],'start'=>$m['start_time'],'end'=>$m['end_time'],'room'=>$m['room']],$meetings)]
            +['created_at'=>self::iso($r['created_at']),'updated_at'=>self::iso($r['updated_at'])]; return $r; }
    // Programs and lock rows have string keys. -1 marks failure internally; the CLI omits it.
    private static function lockId(string $name): int { return -1; }
}
