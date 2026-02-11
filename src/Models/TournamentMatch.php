<?php

namespace App\Models;

use App\Database\DB;

class TournamentMatch extends BaseModel
{
    public ?int $round_id = null;
    public ?int $home_team_id = null;
    public ?int $away_team_id = null;
    public ?int $home_goals = null;
    public ?int $away_goals = null;
    public ?int $winner_team_id = null;
    public ?int $match_order = 0;

    protected static ?string $table = 'matches';

    public function __construct(array $data = [])
    {
        parent::__construct($data);
    }

    public function homeTeam(): ?Team
    {
        return $this->home_team_id ? Team::find($this->home_team_id) : null;
    }

    public function awayTeam(): ?Team
    {
        return $this->away_team_id ? Team::find($this->away_team_id) : null;
    }

    public function setResult(?int $homeGoals, ?int $awayGoals): void
    {
        $this->home_goals = $homeGoals;
        $this->away_goals = $awayGoals;
        if ($homeGoals === null || $awayGoals === null) {
            $this->winner_team_id = null;
        } else {
            if ($homeGoals > $awayGoals) {
                $this->winner_team_id = $this->home_team_id;
            } else if ($awayGoals > $homeGoals) {
                $this->winner_team_id = $this->away_team_id;
            } else {
                throw new \Exception('Il risultato non può essere pareggio; registrare un vincitore.');
            }
        }
        $this->save();

        // dopo aver salvato il risultato, promuovi il vincitore al match successivo (se esiste)
        $this->promoteWinner();
    }

    /**
     * Recupera la riga della round per questa partita
     * @return array|null
     */
    protected function getRoundRow(): ?array
    {
        $rows = DB::select('SELECT * FROM rounds WHERE id = :rid', ['rid' => $this->round_id]);
        return $rows[0] ?? null;
    }

    /**
     * Promuove il vincitore della partita al match successivo nel torneo
     */
    protected function promoteWinner(): void
    {
        if ($this->winner_team_id === null) {
            return; // niente da promuovere
        }

        $round = $this->getRoundRow();
        if (!$round) return;

        $tournamentId = $round['tournament_id'];
        $currentRoundNumber = (int)$round['round_number'];

        // trova round successivo
        $nextRoundNumber = $currentRoundNumber + 1;
        $nextRounds = DB::select('SELECT * FROM rounds WHERE tournament_id = :tid AND round_number = :rn', ['tid' => $tournamentId, 'rn' => $nextRoundNumber]);
        if (empty($nextRounds)) {
            // finale raggiunta: marca torneo come concluso e imposta vincitore
            DB::update('UPDATE tournaments SET status = :st, winner_team_id = :wid, updated_at = now() WHERE id = :id', [
                'st' => 'concluso',
                'wid' => $this->winner_team_id,
                'id' => $tournamentId,
            ]);
            return;
        }

        $nextRound = $nextRounds[0];

        // calcola match_order nella prossima fase (gruppo di due match -> 1 match)
        $targetOrder = (int) ceil($this->match_order / 2);

        // cerca match esistente con quel order
        $existing = DB::select('SELECT * FROM matches WHERE round_id = :rid AND match_order = :mo', ['rid' => $nextRound['id'], 'mo' => $targetOrder]);

        if (empty($existing)) {
            // crea nuovo match e assegna il vincitore come home o away in base alla parità dell'ordine attuale
            $home = ($this->match_order % 2 === 1) ? $this->winner_team_id : null;
            $away = ($this->match_order % 2 === 0) ? $this->winner_team_id : null;

            self::create([
                'round_id' => $nextRound['id'],
                'home_team_id' => $home,
                'away_team_id' => $away,
                'match_order' => $targetOrder
            ]);
        } else {
            $row = $existing[0];
            $match = new self($row);

            // se il posto home è vuoto e il vincitore deve andarci
            if ($this->match_order % 2 === 1) {
                if ($match->home_team_id === null) {
                    $match->home_team_id = $this->winner_team_id;
                }
            } else {
                if ($match->away_team_id === null) {
                    $match->away_team_id = $this->winner_team_id;
                }
            }

            $match->save();
        }
    }
}
