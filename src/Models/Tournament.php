<?php

namespace App\Models;

use App\Traits\WithValidate;
use App\Database\DB;

class Tournament extends BaseModel
{
    use WithValidate;

    public ?string $name = null;
    public ?string $date = null; // YYYY-MM-DD
    public ?string $location = null;
    public ?int $participants_count = null;
    public ?string $status = 'in_corso';
    public ?int $winner_team_id = null;

    protected static ?string $table = 'tournaments';

    protected static function validationRules(): array
    {
        return [
            'name' => ['required', 'min:1', 'max:200'],
            'date' => ['required'],
            'participants_count' => ['required', 'numeric']
        ];
    }

    /**
     * Recupera i partecipanti del torneo
     * @return array of Team
     */
    public function participants(): array
    {
        $rows = DB::select("SELECT t.* FROM teams t INNER JOIN tournament_participants tp ON t.id = tp.team_id WHERE tp.tournament_id = :tid", ['tid' => $this->id]);
        return array_map(fn($r) => new Team($r), $rows);
    }

    public function rounds(): array
    {
        $rows = DB::select("SELECT * FROM rounds WHERE tournament_id = :tid ORDER BY round_number", ['tid' => $this->id]);
        return array_map(fn($r) => new Round($r), $rows);
    }
}
