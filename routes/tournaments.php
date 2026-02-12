<?php

use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\Team;
use App\Models\Round;
use App\Models\TournamentMatch;
use App\Utils\Request;
use App\Utils\Response;
use App\Database\DB;
use Pecee\SimpleRouter\SimpleRouter as Router;

function isPowerOfTwo(int $n): bool {
    return ($n > 0) && (($n & ($n - 1)) === 0);
}

/**
 * GET /api/tournaments
 */
Router::get('/tournaments', function() {
    try {
        $tournaments = Tournament::all();
        Response::success($tournaments)->send();
    } catch (\Exception $e) {
        Response::error('Errore nel recupero tornei: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
    }
});

/**
 * GET /api/tournaments/{id}
 */
Router::get('/tournaments/{id}', function($id) {
    try {
        $t = Tournament::find((int)$id);
        if ($t === null) Response::error('Torneo non trovato', Response::HTTP_NOT_FOUND)->send();

        // carica rounds e matches
        $rounds = DB::select('SELECT * FROM rounds WHERE tournament_id = :tid ORDER BY round_number', ['tid' => $t->id]);
        $payload = $t->toArray();
        $payload['rounds'] = [];
        $rc = (int) (log((int)$t->participants_count, 2));
        $labels = [];
        if ($rc === 4) $labels = ['Ottavi', 'Quarti', 'Semifinali', 'Finale'];
        else if ($rc === 3) $labels = ['Quarti', 'Semifinali', 'Finale'];
        else if ($rc === 2) $labels = ['Semifinali', 'Finale'];
        else if ($rc === 1) $labels = ['Finale'];

        foreach ($rounds as $r) {
            $matches = DB::select('SELECT * FROM matches WHERE round_id = :rid ORDER BY match_order', ['rid' => $r['id']]);
            $label = $labels[($r['round_number'] ?? 0) - 1] ?? ('Round ' . $r['round_number']);
            $payload['rounds'][] = array_merge($r, ['label' => $label, 'matches' => $matches]);
        }

        Response::success($payload)->send();
    } catch (\Exception $e) {
        Response::error('Errore: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
    }
});

/**
 * POST /api/tournaments - crea torneo e genera bracket}
 */
Router::post('/tournaments', function() {
    try {
        $request = new Request();
        $data = $request->json();

        $participants = $data['participants'] ?? [];
        if (!is_array($participants) || empty($participants)) {
            Response::error('Occorre specificare participants come array di team id', Response::HTTP_BAD_REQUEST)->send();
            return;
        }

        $count = count($participants);
        if ($count > 16) {
            Response::error('Il numero massimo di partecipanti è 16', Response::HTTP_BAD_REQUEST)->send();
            return;
        }

        if (!isPowerOfTwo($count)) {
            Response::error('Il numero di partecipanti deve essere potenza di 2 (4,8,16)', Response::HTTP_BAD_REQUEST)->send();
            return;
        }

        // verifica esistenza team e che non siano cancellati
        $placeholders = [];
        $bindings = [];
        foreach ($participants as $idx => $pid) {
            $key = 'pid' . $idx;
            $placeholders[] = ':' . $key;
            $bindings[$key] = (int)$pid;
        }
        $sql = 'SELECT id FROM teams WHERE id IN (' . implode(',', $placeholders) . ') AND deleted_at IS NULL';
        $teamRows = DB::select($sql, $bindings);
        if (count($teamRows) !== $count) {
            Response::error('Alcuni team non esistono o sono stati eliminati', Response::HTTP_BAD_REQUEST)->send();
            return;
        }

        // valida torneo
        $tData = [
            'name' => $data['name'] ?? null,
            'date' => $data['date'] ?? null,
            'location' => $data['location'] ?? null,
            'participants_count' => $count
        ];

        $errors = Tournament::validate($tData);
        if (!empty($errors)) {
            Response::error('Errore di validazione torneo', Response::HTTP_BAD_REQUEST, $errors)->send();
            return;
        }

        // crea torneo
        $tournament = Tournament::create($tData);

        // inserisci partecipanti
        foreach ($participants as $pid) {
            TournamentParticipant::create(['tournament_id' => $tournament->id, 'team_id' => (int)$pid]);
        }

        // genera rounds
        $roundsCount = (int) (log($count, 2));
        $roundIds = [];
        for ($r = 1; $r <= $roundsCount; $r++) {
            $round = Round::create(['tournament_id' => $tournament->id, 'round_number' => $r]);
            $roundIds[$r] = $round->id;
        }

        // genera accoppiamenti primo turno
        shuffle($participants);
        $order = 0;
        for ($i = 0; $i < count($participants); $i += 2) {
            $home = (int)$participants[$i];
            $away = (int)$participants[$i + 1];
            $order++;
            TournamentMatch::create([
                'round_id' => $roundIds[1],
                'home_team_id' => $home,
                'away_team_id' => $away,
                'match_order' => $order
            ]);
        }


        $payload = $tournament->toArray();
        $payload['rounds'] = [
            'round_1' => DB::select('SELECT * FROM matches WHERE round_id = :rid ORDER BY match_order', ['rid' => $roundIds[1]])
        ];

        Response::success($payload, Response::HTTP_CREATED, 'Torneo creato e bracket generato')->send();
    } catch (\Exception $e) {
        Response::error('Errore durante la creazione del torneo: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
    }
});
