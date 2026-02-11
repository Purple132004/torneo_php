<?php

namespace App\Models;

class Round extends BaseModel
{
    public ?int $tournament_id = null;
    public ?int $round_number = null;

    protected static ?string $table = 'rounds';

    public function matches(): array
    {
        return TournamentMatch::where('round_id', '=', $this->id);
    }
}
