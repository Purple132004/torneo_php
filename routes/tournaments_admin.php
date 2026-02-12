<?php

use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\Round;
use App\Models\TournamentMatch;
use App\Models\Team;
use App\Utils\Request;
use App\Utils\Response;
use App\Database\DB;
use Pecee\SimpleRouter\SimpleRouter as Router;


function regenerateBracket(int $tournamentId, array $participants)
{
    DB::delete('DELETE FROM rounds WHERE tournament_id = :tid', ['tid' => $tournamentId]);

    $count = count($participants);
    if ($count === 0) return [];

    $roundsCount = (int) (log($count, 2));
    $roundIds = [];
    for ($r = 1; $r <= $roundsCount; $r++) {
        $round = Round::create(['tournament_id' => $tournamentId, 'round_number' => $r]);
        $roundIds[$r] = $round->id;
    }

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

    return $roundIds;
}


/**
 * DELETE /api/tournaments/{id} - elimina torneo e dati collegati
 */
Router::delete('/tournaments/{id}', function($id) {
    try {
        $t = Tournament::find((int)$id);
        if ($t === null) { Response::error('Torneo non trovato', Response::HTTP_NOT_FOUND)->send(); return; }

        
        $t->delete();
        Response::success(null, Response::HTTP_OK, 'Torneo eliminato')->send();
    } catch (\Exception $e) {
        Response::error('Errore durante l\'eliminazione del torneo: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
    }
});


/**
 * POST /api/tournaments/{id}/participants - aggiungi partecipanti e rigenera bracket
 */
Router::post('/tournaments/{id}/participants', function($id) {
    try {
        $req = new Request();
        $data = $req->json();
        $adds = $data['participants'] ?? [];
        if (!is_array($adds) || empty($adds)) { Response::error('Array participants obbligatorio', Response::HTTP_BAD_REQUEST)->send(); return; }

        $t = Tournament::find((int)$id);
        if ($t === null) { Response::error('Torneo non trovato', Response::HTTP_NOT_FOUND)->send(); return; }

        // non permettere se torneo già iniziato
        $hasResults = DB::select('SELECT 1 FROM matches m INNER JOIN rounds r ON m.round_id = r.id WHERE r.tournament_id = :tid AND m.winner_team_id IS NOT NULL LIMIT 1', ['tid' => $t->id]);
        if (!empty($hasResults)) { Response::error('Impossibile aggiungere partecipanti: torneo già iniziato', Response::HTTP_BAD_REQUEST)->send(); return; }

        // recupera attuali partecipanti
        $current = DB::select('SELECT team_id FROM tournament_participants WHERE tournament_id = :tid', ['tid' => $t->id]);
        $currentIds = array_map(fn($r) => (int)$r['team_id'], $current);
        $newIds = array_unique(array_merge($currentIds, array_map('intval', $adds)));

        $count = count($newIds);
        if ($count > 16) { Response::error('Il numero massimo di partecipanti è 16', Response::HTTP_BAD_REQUEST)->send(); return; }
        if (!($count > 0 && (($count & ($count - 1)) === 0))) { Response::error('Il numero di partecipanti deve essere potenza di 2', Response::HTTP_BAD_REQUEST)->send(); return; }

        
        $in = DB::buildInClause($newIds, 'id');
        $sql = 'SELECT id FROM teams WHERE id IN (' . $in['clause'] . ') AND deleted_at IS NULL';
        $rows = DB::select($sql, $in['bindings']);
        if (count($rows) !== $count) { Response::error('Alcuni team non esistono o sono eliminati', Response::HTTP_BAD_REQUEST)->send(); return; }

        
        DB::delete('DELETE FROM tournament_participants WHERE tournament_id = :tid', ['tid' => $t->id]);
        foreach ($newIds as $pid) {
            TournamentParticipant::create(['tournament_id' => $t->id, 'team_id' => (int)$pid]);
        }

        DB::update('UPDATE tournaments SET participants_count = :pc, updated_at = now() WHERE id = :id', ['pc' => $count, 'id' => $t->id]);

        $rounds = regenerateBracket($t->id, $newIds);
        Response::success(['participants' => $newIds, 'rounds' => $rounds])->send();
    } catch (\Exception $e) {
        Response::error('Errore durante l\'aggiunta partecipanti: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
    }
});


/**
 * DELETE /api/tournaments/{id}/participants/{team_id}
 */
Router::delete('/tournaments/{id}/participants/{team_id}', function($id, $team_id) {
    try {
        $t = Tournament::find((int)$id);
        if ($t === null) { Response::error('Torneo non trovato', Response::HTTP_NOT_FOUND)->send(); return; }

        $hasResults = DB::select('SELECT 1 FROM matches m INNER JOIN rounds r ON m.round_id = r.id WHERE r.tournament_id = :tid AND m.winner_team_id IS NOT NULL LIMIT 1', ['tid' => $t->id]);
        if (!empty($hasResults)) { Response::error('Impossibile rimuovere partecipante: torneo già iniziato', Response::HTTP_BAD_REQUEST)->send(); return; }

        DB::delete('DELETE FROM tournament_participants WHERE tournament_id = :tid AND team_id = :team', ['tid' => $t->id, 'team' => (int)$team_id]);

        $rows = DB::select('SELECT team_id FROM tournament_participants WHERE tournament_id = :tid', ['tid' => $t->id]);
        $ids = array_map(fn($r) => (int)$r['team_id'], $rows);
        $count = count($ids);
        if ($count > 0 && (($count & ($count - 1)) !== 0)) { Response::error('Rimozione non consentita: il numero rimanente non è potenza di due', Response::HTTP_BAD_REQUEST)->send(); return; }

        DB::update('UPDATE tournaments SET participants_count = :pc, updated_at = now() WHERE id = :id', ['pc' => $count, 'id' => $t->id]);

        regenerateBracket($t->id, $ids);
        Response::success(['participants' => $ids])->send();
    } catch (\Exception $e) {
        Response::error('Errore durante la rimozione del partecipante: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
    }
});


/**
 * POST /api/tournaments/{id}/simulate - simula il torneo completando match mancanti
 */
Router::post('/tournaments/{id}/simulate', function($id) {
    try {
        $t = Tournament::find((int)$id);
        if ($t === null) { Response::error('Torneo non trovato', Response::HTTP_NOT_FOUND)->send(); return; }

        $rounds = DB::select('SELECT * FROM rounds WHERE tournament_id = :tid ORDER BY round_number', ['tid' => $t->id]);
        foreach ($rounds as $r) {
            $matches = DB::select('SELECT * FROM matches WHERE round_id = :rid ORDER BY match_order', ['rid' => $r['id']]);
            foreach ($matches as $mrow) {
                $m = new TournamentMatch($mrow);
                if ($m->winner_team_id !== null) continue;
                $home = rand(0,5);
                $away = rand(0,5);
                if ($home === $away) $away = $home + 1;
                $m->setResult($home, $away);
            }
        }

        Response::success('Torneo simulato')->send();
    } catch (\Exception $e) {
        Response::error('Errore durante la simulazione: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
    }
});


/**
 * GET /api/tournaments/{id}/winner - ritorna vincitore se torneo completato
 */
Router::get('/tournaments/{id}/winner', function($id) {
    try {
        // preferisci valore memorizzato sul torneo se presente
        $t = DB::select('SELECT winner_team_id FROM tournaments WHERE id = :id', ['id' => (int)$id]);
        if (!empty($t) && $t[0]['winner_team_id'] !== null) {
            $winner = Team::find((int)$t[0]['winner_team_id']);
            Response::success($winner)->send();
            return;
        }

        
        $last = DB::select('SELECT * FROM rounds WHERE tournament_id = :tid ORDER BY round_number DESC LIMIT 1', ['tid' => (int)$id]);
        if (empty($last)) { Response::error('Nessun round trovato', Response::HTTP_NOT_FOUND)->send(); return; }
        $finalRound = $last[0];
        $finalMatch = DB::select('SELECT * FROM matches WHERE round_id = :rid LIMIT 1', ['rid' => $finalRound['id']]);
        if (empty($finalMatch) || $finalMatch[0]['winner_team_id'] === null) { Response::error('Torneo non completato', Response::HTTP_BAD_REQUEST)->send(); return; }
        $winner = Team::find((int)$finalMatch[0]['winner_team_id']);
        Response::success($winner)->send();
    } catch (\Exception $e) {
        Response::error('Errore: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
    }
});
