<?php

namespace GitExercises\services;

use GitExercises\hook\utils\ConsoleUtils;

/**
 * This service is used in classes at my university. I investigate gamification influence on education for my PhD
 * thesis. I use the Git Exercises platform during classes and calculate points for students based on their attempt.
 * It is disabled in "live" platform.
 */
class GamificationService
{
    /**
     * @var \PDO
     */
    private $pdo;

    private $commiterId;

    private $passedExerciseTimes;

    private $passedExerciseAttempts;

    private $inSessionCondition;

    private $sessionId;

    private $orderInSession;

    public static $EXERCISE_VALUES = [
        'master' => 0,
        'commit-one-file' => 1,
        'commit-one-file-staged' => 1,
        'ignore-them' => 2,
        'chase-branch' => 2,
        'merge-conflict' => 3,
        'save-your-work' => 3,
        'change-branch-history' => 4,
        'remove-ignored' => 2,
        'fix-typo' => 3,
        'fix-old-typo' => 4,
        'commit-lost' => 4,
        'split-commit' => 4,
        'too-many-commits' => 4,
        'commit-parts' => 5,
        'pick-your-features' => 4,
        'rebase-complex' => 4,
        'invalid-order' => 4,
        'find-swearwords' => 5,
        'find-bug' => 6,
    ];

    public function __construct($commiterId)
    {
        $this->pdo = require __DIR__ . '/../db.php';
        $this->commiterId = $commiterId;
        $this->isGamificationSessionActive();
    }

    public function getTotalPoints()
    {
        $total = 0;
        foreach ($this->getPassedExerciseAttempts() as $passed => $_) {
            $total += $this->getPointsForExercise($passed);
        }
        return $total;
    }

    public function getPointsForExercise($exercise)
    {
        return $this->getPointsForOrder($exercise) + $this->getPointsForAttempts($exercise) + $this->getPointsForTime($exercise);
    }

    private function ordinal($number)
    {
        if ($number == 1) return '1st';
        if ($number == 2) return '2nd';
        if ($number == 3) return '3rd';
        return $number . 'th';
    }

    public function printExerciseSummary($exercise)
    {
        echo 'Exercise summary:', PHP_EOL;
        $points = [];
        $points[] = [
            'achievment' => 'Pass as the ' . $this->ordinal($this->getOrderInSession()[$exercise] + 1) . ' person in the group',
            'points' => number_format($this->getPointsForOrder($exercise), 1),
        ];
        $points[] = [
            'achievment' => 'Pass in the ' . $this->ordinal($this->getPassedExerciseAttempts()[$exercise]) . ' attempt',
            'points' => number_format($this->getPointsForAttempts($exercise), 1),
        ];
        $time = $this->getPassedExerciseTimes()[$exercise];
        $value = self::$EXERCISE_VALUES[$exercise];
        if ($time && $value) {
            if ($time < $value) {
                $points[] = [
                    'achievment' => 'Pass within the time limit',
                    'points' => number_format($value, 1),
                ];
            } else {
                $points[] = [
                    'achievment' => 'Pass within ' . ceil($time) . ' minutes',
                    'points' => number_format($this->getPointsForTime($exercise), 1),
                ];
            }
        }
        $renderer = new ArrayToTextTable($points);
        $renderer->showHeaders(true);
        $renderer->render();
        echo PHP_EOL, "Total points scored for $exercise: " . number_format($this->getPointsForExercise($exercise), 1);
        echo PHP_EOL;
    }

    public function getGamificationStatus()
    {
        $summary = "Total points overall: " . number_format($this->getTotalPoints(), 1);
        $summary .= PHP_EOL;
        if (($place = $this->getMyPlaceInGroup()) == 1) {
            $summary .= ConsoleUtils::blue("You are the best in your group!");
        } else if ($place) {
            $summary = 'You have the ' . $this->ordinal($place) . ' place in your group.';
        }
        $summary .= PHP_EOL;
        return $summary;
    }

    private function getPointsForOrder($exercise)
    {
        $order = $this->getOrderInSession()[$exercise];
        return max(0, 6 - $order) / 2;
    }

    private function getPointsForAttempts($exercise)
    {
        $attempts = $this->getPassedExerciseAttempts()[$exercise];
        return max(0, 4 - $attempts);
    }

    private function getPointsForTime($exercise)
    {
        $time = $this->getPassedExerciseTimes()[$exercise];
        $value = self::$EXERCISE_VALUES[$exercise];
        if ($time < $value) {
            return $value;
        } else {
            $time -= $value;
            return max(0, $value - $time);
        }
    }

    public function getMyPlaceInGroup()
    {
        if ($this->sessionId) {
            $place = $this->query('SELECT COUNT(*) FROM gamification_stats WHERE session_id=:session AND points >= (SELECT points FROM gamification_stats WHERE session_id=:session AND commiter_id=:id)', [':session' => $this->sessionId])
                ->fetchColumn();
            return $place;
        }
    }

    public function isGamificationSessionActive()
    {
        $activeSession = $this->pdo
            ->query('SELECT * FROM gamification_session WHERE CURRENT_TIMESTAMP BETWEEN start AND end LIMIT 0,1')
            ->fetch(\PDO::FETCH_ASSOC);
        if ($activeSession) {
            $this->setGamificationSession($activeSession);
            return true;
        }
        return false;
    }

    public function setGamificationSession($from, $to = null)
    {
        if (is_array($from)) {
            $this->sessionId = $from['id'];
            return $this->setGamificationSession($from['start'], $from['end']);
        }
        $this->inSessionCondition = "timestamp BETWEEN '$from' AND '$to'";
    }

    public function newAttempt($passed)
    {
        if ($this->sessionId) {
            $this->query('INSERT IGNORE INTO gamification_stats (session_id, commiter_id) VALUES(:session, :id)', [':session' => $this->sessionId]);
            if ($passed) {
                $this->query('UPDATE gamification_stats SET passed=passed+1, points=:points WHERE session_id=:session AND commiter_id=:id AND points<:points',
                    [':session' => $this->sessionId, ':points' => $this->getTotalPoints()]);
            } else {
                $this->query('UPDATE gamification_stats SET failed=failed+1 WHERE session_id=:session AND commiter_id=:id', [':session' => $this->sessionId]);
            }
        }
    }

    public function getOrderInSession()
    {
        if ($this->orderInSession) {
            return $this->orderInSession;
        }
        $order = $this->query("SELECT t.exercise, COUNT(DISTINCT commiter_id) count FROM (SELECT commiter_id me, exercise, MIN(timestamp) minTimestamp FROM attempt
                                WHERE $this->inSessionCondition AND commiter_id = :id AND passed = 1 GROUP BY exercise) AS t INNER JOIN attempt ON attempt.exercise = t.exercise
                                AND $this->inSessionCondition AND attempt.commiter_id != me AND passed = 1 AND timestamp < minTimestamp GROUP BY exercise;");
        $order = array_reduce($order->fetchAll(\PDO::FETCH_ASSOC), function ($carry, $item) {
            $carry[$item['exercise']] = intval($item['count']);
            return $carry;
        }, []);
        foreach ($this->getPassedExerciseAttempts() as $passed => $_) {
            if (!isset($order[$passed])) {
                $order[$passed] = 0; // the first one, yai!
            }
        }
        return $this->orderInSession = $order;
    }

    public function getPassedExerciseAttempts()
    {
        if ($this->passedExerciseAttempts) {
            return $this->passedExerciseAttempts;
        }
        $attempts = $this->query("SELECT exercise, COUNT(*) attempts FROM attempt WHERE $this->inSessionCondition AND commiter_id = :id AND timestamp <= (
                                SELECT MIN(timestamp) timestamp FROM attempt a WHERE a.commiter_id = attempt.commiter_id
                                AND a.exercise = attempt.exercise AND passed = 1) GROUP BY exercise");
        $attempts = array_reduce($attempts->fetchAll(\PDO::FETCH_ASSOC), function ($carry, $item) {
            $carry[$item['exercise']] = intval($item['attempts']);
            return $carry;
        }, []);
        return $this->passedExerciseAttempts = $attempts;
    }

    public function getPassedExerciseTimes()
    {
        if ($this->passedExerciseTimes) {
            return $this->passedExerciseTimes;
        }
        $times = $this->query("SELECT exercise, MIN(timestamp) timestamp FROM attempt WHERE $this->inSessionCondition AND commiter_id = :id AND passed = 1 GROUP BY exercise ORDER BY timestamp");
        $lastPassTime = 0;
        $result = [];
        foreach ($times as $time) {
            $minutes = strtotime($time['timestamp']) / 60;
            $result[$time['exercise']] = $minutes - $lastPassTime;
            $lastPassTime = $minutes;
        }
        $result[key($result)] = 0; // time of the first task passed can't be guessed
        return $this->passedExerciseTimes = $result;
    }

    public function printResultBoard()
    {
        if (!$this->sessionId) {
            return 'No active gamification session.';
        }
        echo $this->inSessionCondition . PHP_EOL;
        $stmt = $this->pdo->prepare('SELECT commiter_id, failed, passed, points FROM gamification_stats WHERE session_id=:session ORDER BY points DESC');
        $stmt->execute([':session' => $this->sessionId]);
        $commiterService = new CommiterService();
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $results = array_map(function ($e) use ($commiterService) {
            $e = ['commiter_name' => $commiterService->getMostFrequentName($e['commiter_id'])] + $e;
            unset($e['commiter_id']);
            return $e;
        }, $results);
        $renderer = new ArrayToTextTable($results);
        $renderer->showHeaders(true);
        $renderer->render();
    }

    private function query($query, array $params = [])
    {
        $stmt = $this->pdo->prepare($query);
        $stmt->execute(array_merge($params, [':id' => $this->commiterId]));
        return $stmt;
    }
}