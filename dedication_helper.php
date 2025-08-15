<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Dedication time helper using block_dedication manager
 * 
 * @package local_mikacustomreport
 * @copyright 2024
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Include block_dedication manager if available
if (file_exists($CFG->dirroot . '/blocks/dedication/classes/lib/manager.php')) {
    require_once($CFG->dirroot . '/blocks/dedication/classes/lib/manager.php');
}

/**
 * Helper class to calculate dedication time using block_dedication logic
 */
class dedication_helper {
    
    /**
     * Calculate dedication time for a user in a course
     * 
     * @param int $userid User ID
     * @param int $courseid Course ID
     * @param int $starttime Start timestamp (optional)
     * @param int $endtime End timestamp (optional)
     * @param bool $returnDebug Return debug info instead of just seconds
     * @return int|array Total dedication time in seconds or debug array
     */
    public static function calculate_dedication_time($userid, $courseid, $starttime = null, $endtime = null, $returnDebug = false) {
        global $DB;
        
        // Initialize debug info
        $debugInfo = [
            'functionCalled' => true,
            'userid' => $userid,
            'courseid' => $courseid,
            'blockAvailable' => false,
            'courseFound' => false,
            'timeRange' => null,
            'managerCreated' => false,
            'rawSeconds' => 0,
            'finalResult' => 0,
            'error' => null
        ];
        
        // We now use our own session-based calculation (no block_dedication dependency)
        $debugInfo['blockAvailable'] = true; // Using our own implementation
        
        // Get course object
        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            $debugInfo['error'] = 'Course not found';
            return $returnDebug ? $debugInfo : 0;
        }
        $debugInfo['courseFound'] = true;
        
        // Set default time range if not provided
        if (empty($starttime)) {
            $starttime = $course->startdate ?: (time() - (90 * DAYSECS));
        }
        if (empty($endtime)) {
            $endtime = time();
        }
        
        // Set time range info for debug
        $debugInfo['timeRange'] = [
            'starttime' => $starttime,
            'endtime' => $endtime,
            'startDate' => date('Y-m-d H:i:s', $starttime),
            'endDate' => date('Y-m-d H:i:s', $endtime)
        ];
        
        try {
            // Our own session-based calculation (no block_dedication dependency)
            $debugInfo['managerCreated'] = true;
            
            // Get dedication time using our session-based logic
            $dedicationSeconds = self::calculate_session_based_time($userid, $courseid, $starttime, $endtime, $debugInfo);
            $debugInfo['rawSeconds'] = $dedicationSeconds;
            
            $result = intval($dedicationSeconds);
            $debugInfo['finalResult'] = $result;
            
            return $returnDebug ? $debugInfo : $result;
            
        } catch (Exception $e) {
            $debugInfo['error'] = $e->getMessage();
            return $returnDebug ? $debugInfo : 0;
        }
    }
    
    /**
     * Format dedication time from seconds to HH:MM:SS
     * 
     * @param int $seconds Total seconds
     * @return string Formatted time string
     */
    public static function format_dedication_time($seconds) {
        if (empty($seconds) || $seconds <= 0) {
            return '00:00:00';
        }
        
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }
    
    /**
     * Calculate session-based dedication time using our own logic (no block_dedication dependency)
     * This replicates block_dedication's session grouping algorithm
     * 
     * @param int $userid User ID
     * @param int $courseid Course ID
     * @param int $starttime Start timestamp
     * @param int $endtime End timestamp
     * @param array &$debugInfo Debug information array
     * @return int Total dedication time in seconds
     */
    private static function calculate_session_based_time($userid, $courseid, $starttime, $endtime, &$debugInfo) {
        global $DB;
        
        // Session configuration (matching block_dedication defaults)
        $sessionLimit = 3600; // 1 hour gap between sessions
        $minSessionTime = 60;  // Minimum session duration
        
        $debugInfo['sessionConfig'] = [
            'sessionLimit' => $sessionLimit,
            'minSessionTime' => $minSessionTime
        ];
        
        try {
            // Get log entries for user and course within time range
            $sql = "SELECT timecreated
                    FROM {logstore_standard_log}
                    WHERE userid = :userid
                      AND courseid = :courseid
                      AND timecreated >= :starttime
                      AND timecreated <= :endtime
                      AND origin != 'cli'
                    ORDER BY timecreated ASC";
            
            $params = [
                'userid' => $userid,
                'courseid' => $courseid,
                'starttime' => $starttime,
                'endtime' => $endtime
            ];
            
            $logs = $DB->get_records_sql($sql, $params);
            $debugInfo['logCount'] = count($logs);
            
            if (empty($logs)) {
                $debugInfo['noLogs'] = true;
                return 0;
            }
            
            // Convert to simple array of timestamps
            $timestamps = array_map(function($log) {
                return $log->timecreated;
            }, $logs);
            
            // Group into sessions and calculate total time
            $totalTime = 0;
            $sessionCount = 0;
            $currentSessionStart = null;
            $previousTime = null;
            
            foreach ($timestamps as $currentTime) {
                if ($previousTime === null) {
                    // First log entry - start new session
                    $currentSessionStart = $currentTime;
                    $previousTime = $currentTime;
                    continue;
                }
                
                $timeDiff = $currentTime - $previousTime;
                
                if ($timeDiff > $sessionLimit) {
                    // Gap too large - end current session and start new one
                    $sessionDuration = $previousTime - $currentSessionStart;
                    if ($sessionDuration >= $minSessionTime) {
                        $totalTime += $sessionDuration;
                        $sessionCount++;
                    }
                    
                    // Start new session
                    $currentSessionStart = $currentTime;
                }
                
                $previousTime = $currentTime;
            }
            
            // Handle the last session
            if ($currentSessionStart !== null && $previousTime !== null) {
                $sessionDuration = $previousTime - $currentSessionStart;
                if ($sessionDuration >= $minSessionTime) {
                    $totalTime += $sessionDuration;
                    $sessionCount++;
                }
            }
            
            $debugInfo['sessionCount'] = $sessionCount;
            $debugInfo['calculationMethod'] = 'session-based';
            
            return $totalTime;
            
        } catch (Exception $e) {
            $debugInfo['calculationError'] = $e->getMessage();
            return 0;
        }
    }
    
    /**
     * Check if block_dedication is available
     * 
     * @return bool True if available, false otherwise
     */
    public static function is_block_dedication_available() {
        return class_exists('block_dedication\lib\manager');
    }
}
