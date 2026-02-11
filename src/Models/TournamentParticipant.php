<?php

namespace App\Models;

class TournamentParticipant extends BaseModel
{
    public ?int $tournament_id = null;
    public ?int $team_id = null;

    protected static ?string $table = 'tournament_participants';

    public function __construct(array $data = [])
    {
        parent::__construct($data);
    }
}
