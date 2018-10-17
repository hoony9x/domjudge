<?php declare(strict_types=1);

namespace DOMJudgeBundle\Utils\Scoreboard;

use DOMJudgeBundle\Entity\ContestProblem;
use DOMJudgeBundle\Entity\ScoreCache;
use DOMJudgeBundle\Entity\Team;
use DOMJudgeBundle\Entity\TeamCategory;
use DOMJudgeBundle\Utils\FreezeData;
use DOMJudgeBundle\Utils\Utils;

/**
 * Class Scoreboard
 *
 * This class represents the whole scoreboard
 *
 * @package DOMJudgeBundle\Utils\Scoreboard
 */
class Scoreboard
{
    /**
     * @var Team[]
     */
    protected $teams;

    /**
     * @var TeamCategory[]
     */
    protected $categories;

    /**
     * @var ContestProblem[]
     */
    protected $problems;

    /**
     * @var ScoreCache[]
     */
    protected $scoreCache;

    /**
     * @var FreezeData
     */
    protected $freezeData;

    /**
     * @var bool
     */
    protected $restricted;

    /**
     * @var int
     */
    protected $penaltyTime;

    /**
     * @var bool
     */
    protected $scoreIsInSecods;

    /**
     * @var ScoreboardMatrixItem[][]
     */
    protected $matrix = [];

    /**
     * @var Summary
     */
    protected $summary;

    /**
     * @var TeamScore[]
     */
    protected $scores = [];

    /**
     * Scoreboard constructor.
     * @param Team[] $teams
     * @param TeamCategory[] $categories
     * @param ContestProblem[] $problems
     * @param ScoreCache[] $scoreCache
     * @param FreezeData $freezeData
     * @param bool $jury
     * @param int $penaltyTime
     * @param bool $scoreIsInSecods
     */
    public function __construct(
        array $teams,
        array $categories,
        array $problems,
        array $scoreCache,
        FreezeData $freezeData,
        bool $jury,
        int $penaltyTime,
        bool $scoreIsInSecods
    ) {
        $this->teams           = $teams;
        $this->categories      = $categories;
        $this->problems        = $problems;
        $this->scoreCache      = $scoreCache;
        $this->freezeData      = $freezeData;
        $this->restricted      = $jury || $freezeData->showFinal($jury);
        $this->penaltyTime     = $penaltyTime;
        $this->scoreIsInSecods = $scoreIsInSecods;

        $this->initializeScoreboard();
        $this->calculateScoreboard();
    }

    /**
     * @return Team[]
     */
    public function getTeams(): array
    {
        return $this->teams;
    }

    /**
     * @return TeamCategory[]
     */
    public function getCategories(): array
    {
        return $this->categories;
    }

    /**
     * @return ContestProblem[]
     */
    public function getProblems(): array
    {
        return $this->problems;
    }

    /**
     * @return ScoreboardMatrixItem[][]
     */
    public function getMatrix(): array
    {
        return $this->matrix;
    }

    /**
     * @return Summary
     */
    public function getSummary(): Summary
    {
        return $this->summary;
    }

    /**
     * @return TeamScore[]
     */
    public function getScores(): array
    {
        return $this->scores;
    }

    /**
     * Initialize the scoreboard data
     */
    protected function initializeScoreboard()
    {
        // Initialize summary
        $this->summary = new Summary($this->problems);

        // Initialize scores
        $this->scores = [];
        foreach ($this->teams as $team) {
            $this->scores[$team->getTeamid()] = new TeamScore($team);
        }
    }

    /**
     * Calculate the scoreboard data, filling the summary, matrix and scores properteis
     */
    protected function calculateScoreboard()
    {
        // Calculate matrix and update scores
        $this->matrix = [];
        foreach ($this->scoreCache as $scoreRow) {
            // Skip this row if the team or problem is not known by us
            if (!array_key_exists($scoreRow->getTeamid(), $this->teams) || !array_key_exists($scoreRow->getProbid(), $this->problems)) {
                continue;
            }

            $penalty = Utils::calcPenaltyTime(
                $scoreRow->getIsCorrect($this->restricted), $scoreRow->getSubmissions($this->restricted),
                $this->penaltyTime, $this->scoreIsInSecods
            );

            $this->matrix[$scoreRow->getTeamid()][$scoreRow->getProbid()] = new ScoreboardMatrixItem(
                $scoreRow->getIsCorrect($this->restricted),
                $scoreRow->getSubmissions($this->restricted),
                $scoreRow->getPending($this->restricted),
                $scoreRow->getSolveTime($this->restricted),
                $penalty
            );

            if ($scoreRow->getIsCorrect($this->restricted)) {
                $solveTime = Utils::scoretime($scoreRow->getSolveTime($this->restricted), $this->scoreIsInSecods);
                $this->scores[$scoreRow->getTeamid()]->addNumberOfPoints($scoreRow->getContestProblem()->getPoints());
                $this->scores[$scoreRow->getTeamid()]->addSolveTime($solveTime);
                $this->scores[$scoreRow->getTeamid()]->addTotalTime($solveTime + $penalty);
            }
        }

        // Now sort the scores using the scoreboard sort function
        uasort($this->scores, [static::class, 'scoreboardCompare']);

        // Loop over all teams to calculate ranks and totals
        $prevSortOrder  = -1;
        $rank           = 0;
        $previousTeamId = null;
        foreach ($this->scores as $teamScore) {
            $teamId = $teamScore->getTeam()->getTeamid();
            // rank, team name, total correct, total time
            if ($teamScore->getTeam()->getCategory()->getSortorder() != $prevSortOrder) {
                $prevSortOrder  = $teamScore->getTeam()->getCategory()->getSortorder();
                $rank           = 0; // reset team position on switch to different category
                $previousTeamId = null;
            }
            $rank++;

            // Use previous team rank when scores are equal
            if (isset($previousTeamId) && $this->scoreCompare($this->scores[$previousTeamId], $teamScore) == 0) {
                $teamScore->setRank($rank);
                $teamScore->setRank($this->scores[$previousTeamId]->getRank());
            } else {
                $teamScore->setRank($rank);
            }
            $previousTeamId = $teamId;

            // Keep summary statistics for the bottom row of our table
            // The numberOfPoints summary is useful only if they're all 1-point problems.
            $this->summary->addNumberOfPoints($teamScore->getNumberOfPoints());
            if ($teamScore->getTeam()->getAffiliation()) {
                $this->summary->incrementAffiliationValue($teamScore->getTeam()->getAffiliation()->getAffilid());
                if ($teamScore->getTeam()->getAffiliation()->getCountry()) {
                    $this->summary->incrementCountryValue($teamScore->getTeam()->getAffiliation()->getCountry());
                }
            }

            // Loop over the problems
            foreach ($this->problems as $contestProblem) {
                $problemId = $contestProblem->getProbid();
                // Provide default scores when nothing submitted for this team + problem yet
                if (!isset($this->matrix[$teamId][$problemId])) {
                    $this->matrix[$teamId][$problemId] = new ScoreboardMatrixItem(false, 0, 0, 0, 0);
                }

                $problemMatrixItem = $this->matrix[$teamId][$problemId];
                $problemSummary    = $this->summary->getProblem($problemId);
                $problemSummary->addNumberOfSubmissions($problemMatrixItem->getNumberOfSubmissions());
                $problemSummary->addNumberOfPendingSubmissions($problemMatrixItem->getNumberOfPendingSubmissions());
                $problemSummary->addNumberOfCorrectSubmissions($problemMatrixItem->isCorrect() ? 1 : 0);
                if ($problemMatrixItem->isCorrect()) {
                    $problemSummary->updateBestTime($teamScore->getTeam()->getCategory()->getSortorder(), $problemMatrixItem->getTime());
                }
            }
        }
    }

    /**
     * Scoreboard sorting function. It uses the following
     * criteria:
     * - First, use the sortorder override from the team_category table
     *   (e.g. score regular contestants always over spectators);
     * - Then, use the scoreCompare function to determine the actual ordering
     *   based on number of problems solved and the time it took;
     * - If still equal, order on team name alphabetically.
     * @param TeamScore $a
     * @param TeamScore $b
     * @return int
     */
    protected static function scoreboardCompare(TeamScore $a, TeamScore $b)
    {
        // First order by our predefined sortorder based on category
        if ($a->getTeam()->getCategory()->getSortorder() != $b->getTeam()->getCategory()->getSortorder()) {
            return $a->getTeam()->getCategory()->getSortorder() <=> $b->getTeam()->getCategory()->getSortorder();
        }

        // Then compare scores
        $scoreCompare = static::scoreCompare($a, $b);
        if ($scoreCompare != 0) {
            return $scoreCompare;
        }

        // Else, order by teamname alphabetically
        if ($a->getTeam()->getName() != $b->getTeam()->getName()) {
            return strcasecmp($a->getTeam()->getName(), $b->getTeam()->getName());
        }
        // Undecided, should never happen in practice
        return 0;
    }

    /**
     * Main score comparison function, called from the 'scoreboardCompare' wrapper
     * below. Scores based on the following criteria:
     * - highest points from correct solutions;
     * - least amount of total time spent on these solutions;
     * - the tie-breaker function below
     * @param TeamScore $a
     * @param TeamScore $b
     * @return int
     */
    protected static function scoreCompare(TeamScore $a, TeamScore $b): int
    {
        // More correctness points than someone else means higher rank
        if ($a->getNumberOfPoints() != $b->getNumberOfPoints()) {
            return $b->getNumberOfPoints() <=> $a->getNumberOfPoints();
        }
        // Else, less time spent means higher rank
        if ($a->getTotalTime() != $b->getTotalTime()) {
            return $a->getTotalTime() <=> $b->getTotalTime();
        }
        // Else tie-breaker rule
        return static::scoreTiebreaker($a, $b);
    }

    /**
     * Tie-breaker comparison function, called from the 'scoreCompare' function
     * above. Scores based on the following criterion:
     * - fastest submission time for latest correct problem
     * @param TeamScore $a
     * @param TeamScore $b
     * @return int
     */
    public static function scoreTiebreaker(TeamScore $a, TeamScore $b): int
    {
        $atimes = $a->getSolveTimes();
        $btimes = $b->getSolveTimes();
        rsort($atimes);
        rsort($btimes);
        if (isset($atimes[0])) {
            if ($atimes[0] != $btimes[0]) {
                return $atimes[0] <=> $btimes[0];
            }
        }
        return 0;
    }
}
